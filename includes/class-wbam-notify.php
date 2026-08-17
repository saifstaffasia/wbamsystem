<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer + vendor notifications. Email via wp_mail (your SMTP);
 * SMS via a pluggable driver (Twilio included, disabled until configured).
 * All sends are queued-on-failure so a provider blip never blocks the shop.
 */
class WBAM_Notify {

    public static function ticket_status(array $t, string $status, string $note = ''): void {
        $tpl = self::ticket_templates($t, $status, $note);
        if (!$tpl) return;
        [$subject, $body_email, $body_sms] = $tpl;
        if (!empty($t['email']) && is_email($t['email'])) {
            self::email($t['email'], $subject, $body_email, (int) $t['id']);
        }
        if (!empty($t['phone'])) {
            self::sms($t['phone'], $body_sms, (int) $t['id']);
        }
    }

    private static function ticket_templates(array $t, string $status, string $note): ?array {
        $s = WBAM_Settings::all();
        $name  = $s['business_name'];
        $code  = $t['ticket_code'];
        $model = $t['device_model'];
        $track = home_url('/track/?t=' . rawurlencode($code));
        $phone = $s['business_phone'] ? " Call us: {$s['business_phone']}." : '';
        $type  = trim((string) ($t['repair_type'] ?? ''));
        $eta   = !empty($t['due_date']) ? mysql2date('j M Y', $t['due_date']) : '';
        $held  = !isset($t['device_held']) || (int) $t['device_held'] === 1;
        $extra = ($type ? "Repair: $type\n" : '')
               . ($eta ? "Estimated completion: $eta\n" : '')
               . (!$held ? "You're keeping your device for now — we'll call you to bring it in as soon as the parts arrive.\n" : '');
        $map = [
            'booked'        => ["Repair booked — $code",
                "Hi {$t['customer_name']},\n\nYour repair is booked.\nTicket: $code\nDevice: $model\n$extra" . ($t['quote'] !== null ? 'Quote: £' . number_format((float) $t['quote'], 2) . "\n" : '') . "\nTrack progress any time: $track\n\n$name",
                "$name: repair $code booked for your $model." . ($eta ? " Est: $eta." : '') . " Track: $track"],
            'diagnosed'     => ["Diagnosis ready — $code",
                "Hi {$t['customer_name']},\n\nWe've diagnosed your $model." . ($note ? "\n\n$note" : '') . ($t['quote'] !== null ? "\nQuote: £" . number_format((float) $t['quote'], 2) : '') . "\n\nTrack: $track\n\n$name",
                "$name: your $model is diagnosed." . ($t['quote'] !== null ? ' Quote £' . number_format((float) $t['quote'], 2) . '.' : '') . " Track: $track"],
            'awaiting_parts'=> ["Parts on order — $code",
                "Hi {$t['customer_name']},\n\nParts for your $model are on order — we'll update you as soon as they arrive." . (!$held ? " We'll give you a call then so you can bring the device in." : '') . "\n\nTrack: $track\n\n$name",
                "$name: parts for your $model are on order." . (!$held ? " We'll call you to bring the device in when they arrive." : '') . " Track: $track"],
            'ready'         => ["Ready for collection — $code",
                "Hi {$t['customer_name']},\n\nGood news — your $model is repaired and ready to collect!" . $phone . "\n\nTicket: $code\n\n$name",
                "$name: your $model is READY to collect. Ticket $code."],
            'collected'     => ["Thanks for choosing $name — $code",
                "Hi {$t['customer_name']},\n\nThanks for collecting your $model. Your repair is covered by a " . (int) $t['warranty_days'] . "-day warranty (keep this email).\n\n$name",
                "$name: thanks! Your $model repair has a " . (int) $t['warranty_days'] . "-day warranty. Ticket $code."],
        ];
        return $map[$status] ?? null;
    }

    /* ---------------- channels ---------------- */

    public static function email(string $to, string $subject, string $body, int $ticket_id = 0): bool {
        $s = WBAM_Settings::all();
        $headers = ['From: ' . $s['business_name'] . ' <' . $s['email_from'] . '>'];
        $ok = wp_mail($to, $subject, $body, $headers);
        if ($ticket_id) WBAM_Tickets::event($ticket_id, 'notify_email', ($ok ? 'sent' : 'FAILED') . ": $subject");
        if (!$ok) WBAM_Sync::queue('notify', ['channel' => 'email', 'to' => $to, 'subject' => $subject, 'body' => $body], 'wp_mail failed');
        return $ok;
    }

    public static function sms(string $to, string $body, int $ticket_id = 0): bool {
        $s = WBAM_Settings::all();
        if (($s['sms_provider'] ?? '') !== 'twilio' || !$s['twilio_sid'] || !$s['twilio_token'] || !$s['twilio_from']) {
            return false; // SMS not configured — silently skip (email still goes).
        }
        $to_e164 = self::uk_e164($to);
        if (!$to_e164) return false;
        $res = wp_remote_post(
            "https://api.twilio.com/2010-04-01/Accounts/{$s['twilio_sid']}/Messages.json",
            [
                'timeout' => 20,
                'headers' => ['Authorization' => 'Basic ' . base64_encode($s['twilio_sid'] . ':' . $s['twilio_token'])],
                'body'    => ['From' => $s['twilio_from'], 'To' => $to_e164, 'Body' => $body],
            ]
        );
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $ok = $code >= 200 && $code < 300;
        if ($ticket_id) WBAM_Tickets::event($ticket_id, 'notify_sms', ($ok ? 'sent' : "FAILED ($code)") . ' to ' . $to_e164);
        if (!$ok) WBAM_Sync::queue('notify', ['channel' => 'sms', 'to' => $to, 'body' => $body], 'twilio ' . $code);
        return $ok;
    }

    public static function run_task(array $payload): void {
        if (($payload['channel'] ?? '') === 'email') {
            if (!wp_mail($payload['to'], $payload['subject'], $payload['body'])) {
                throw new RuntimeException('wp_mail failed again');
            }
        } elseif (($payload['channel'] ?? '') === 'sms') {
            if (!self::sms($payload['to'], $payload['body'])) throw new RuntimeException('sms failed again');
        }
    }

    public static function po_email(array $po): bool {
        $s = WBAM_Settings::all();
        $lines = "Purchase order {$po['po_code']} from {$s['business_name']}\n\n";
        foreach ($po['lines'] as $l) {
            $lines .= sprintf("- %s ×%d @ £%s\n", $l['description'], $l['qty'], number_format((float) $l['unit_cost'], 2));
            if (!empty($l['product_url'])) $lines .= '  ' . $l['product_url'] . "\n";
        }
        if (!empty($po['notes'])) $lines .= "\nNotes: {$po['notes']}\n";
        $lines .= "\nPlease confirm availability and dispatch.\n{$s['business_name']}" . ($s['business_phone'] ? " · {$s['business_phone']}" : '');
        return self::email($po['vendor']['email'], "{$s['business_name']} — {$po['po_code']}", $lines);
    }

    private static function uk_e164(string $raw): ?string {
        $d = preg_replace('/\D/', '', $raw);
        if (str_starts_with($d, '44')) return '+' . $d;
        if (str_starts_with($d, '07') && strlen($d) === 11) return '+44' . substr($d, 1);
        if (str_starts_with($raw, '+')) return $raw;
        return strlen($d) >= 10 ? '+' . $d : null;
    }
}
