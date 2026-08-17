<?php
if (!defined('ABSPATH')) exit;

/**
 * Repair tickets: booking (web/walk-in), status flow with notifications,
 * deposits/balances that flow through Shopify orders, parts usage, warranty history.
 */
class WBAM_Tickets {

    const STATUSES = ['booked', 'received', 'diagnosed', 'awaiting_parts', 'in_repair', 'ready', 'collected', 'cancelled'];

    // Statuses that trigger a customer notification by default.
    const NOTIFY_ON = ['booked', 'diagnosed', 'awaiting_parts', 'ready', 'collected'];

    public static function create(array $in, string $source = 'walkin'): array {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}wbam_tickets", [
            'ticket_code'   => '',
            'branch_id'     => (int) ($in['branch_id'] ?? 0),
            'customer_name' => sanitize_text_field($in['customer_name'] ?? ''),
            'phone'         => sanitize_text_field($in['phone'] ?? ''),
            'email'         => sanitize_email($in['email'] ?? ''),
            'device_model'  => sanitize_text_field($in['device_model'] ?? ''),
            'imei'          => preg_replace('/\D/', '', (string) ($in['imei'] ?? '')),
            'passcode'      => sanitize_text_field($in['passcode'] ?? ''),
            'fault'         => sanitize_textarea_field($in['fault'] ?? ''),
            'repair_type'   => sanitize_text_field($in['repair_type'] ?? ''),
            'due_date'      => self::clean_date($in['due_date'] ?? '') ?: date('Y-m-d', strtotime('+7 days', current_time('timestamp'))),
            'device_held'   => array_key_exists('device_held', $in) ? (int) !!$in['device_held'] : 1,
            'quote'         => $in['quote'] !== '' && isset($in['quote']) ? (float) $in['quote'] : null,
            'status'        => 'booked',
            'source'        => $source,
            'created_by'    => get_current_user_id(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        $code = 'T-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $wpdb->update("{$wpdb->prefix}wbam_tickets", ['ticket_code' => $code], ['id' => $id]);
        self::event($id, 'created', "via $source");
        $t = self::get($id);
        WBAM_Notify::ticket_status($t, 'booked');
        return $t;
    }

    public static function get(int $id): ?array {
        global $wpdb;
        $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_tickets WHERE id=%d", $id), ARRAY_A);
        return $t ?: null;
    }

    public static function by_code(string $code): ?array {
        global $wpdb;
        $t = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_tickets WHERE ticket_code=%s", strtoupper(trim($code))
        ), ARRAY_A);
        return $t ?: null;
    }

    public static function event(int $id, string $event, string $detail = ''): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wbam_ticket_events", [
            'ticket_id' => $id, 'event' => $event, 'detail' => $detail,
            'user_id' => get_current_user_id(), 'created_at' => current_time('mysql'),
        ]);
    }

    public static function set_status(int $id, string $status, string $note = ''): array {
        global $wpdb;
        if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Bad status.');
        $t = self::get($id);
        if (!$t) throw new RuntimeException('Unknown ticket.');
        $upd = ['status' => $status, 'updated_at' => current_time('mysql')];
        if ($status === 'collected') $upd['collected_at'] = current_time('mysql');
        $wpdb->update("{$wpdb->prefix}wbam_tickets", $upd, ['id' => $id]);
        self::event($id, 'status', $status . ($note ? " — $note" : ''));
        $t = self::get($id);
        if (in_array($status, self::NOTIFY_ON, true)) {
            WBAM_Notify::ticket_status($t, $status, $note);
        }
        return $t;
    }

    public static function update_fields(int $id, array $fields): void {
        global $wpdb;
        $allowed = ['diagnosis', 'quote', 'assigned_user', 'warranty_days', 'phone', 'email', 'customer_name', 'device_model', 'imei', 'branch_id', 'repair_type', 'due_date', 'device_held'];
        $upd = [];
        foreach ($allowed as $k) if (array_key_exists($k, $fields)) $upd[$k] = is_string($fields[$k]) ? sanitize_text_field($fields[$k]) : $fields[$k];
        if ($upd) {
            $upd['updated_at'] = current_time('mysql');
            $wpdb->update("{$wpdb->prefix}wbam_tickets", $upd, ['id' => $id]);
        }
    }

    /**
     * Remote payment: create a Shopify draft order ("Repair deposit — T-0042" /
     * "Repair balance — T-0042") and return the invoice URL to send the customer.
     * In-store, staff instead ring a POS custom sale with the same title — the
     * webhook importer links either by the T-code in the line title.
     */
    public static function draft_payment(int $id, float $amount, string $which = 'deposit'): string {
        $t = self::get($id);
        if (!$t) throw new RuntimeException('Unknown ticket.');
        $title = sprintf('Repair %s — %s (%s)', $which === 'deposit' ? 'deposit' : 'balance', $t['ticket_code'], $t['device_model']);
        $m = 'mutation($input: DraftOrderInput!) {
            draftOrderCreate(input: $input) {
                draftOrder { id invoiceUrl }
                userErrors { field message }
            }
        }';
        $input = [
            'lineItems' => [[ 'title' => $title, 'quantity' => 1, 'originalUnitPrice' => number_format($amount, 2, '.', '') ]],
            'note'      => 'WBAM Hub ' . $t['ticket_code'],
            'tags'      => ['wbam-repair', $t['ticket_code']],
        ];
        if ($t['email']) $input['email'] = $t['email'];
        $res = WBAM_Shopify::i()->graphql($m, ['input' => $input]);
        $errs = $res['data']['draftOrderCreate']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Draft order failed: ' . wp_json_encode($errs));
        $draft = $res['data']['draftOrderCreate']['draftOrder'];
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}wbam_tickets",
            ['draft_order_id' => WBAM_Shopify::gid_to_id($draft['id'])], ['id' => $id]);
        self::event($id, 'draft_order', $which . ' £' . number_format($amount, 2) . ' → ' . $draft['invoiceUrl']);
        return (string) $draft['invoiceUrl'];
    }

    /** Called by the warehouse when an order line references T-{id}. */
    public static function attach_payment(int $ticket_id, int $order_id, string $kind, float $amount): void {
        global $wpdb;
        $t = self::get($ticket_id);
        if (!$t) return;
        $field = $kind === 'repair_deposit' ? 'deposit_order_id' : 'balance_order_id';
        if ((int) $t[$field] !== $order_id) {
            $upd = [$field => $order_id, 'updated_at' => current_time('mysql')];
            if ($kind === 'repair_deposit') $upd['deposit'] = $amount;
            $wpdb->update("{$wpdb->prefix}wbam_tickets", $upd, ['id' => $ticket_id]);
            self::event($ticket_id, 'payment', "$kind £" . number_format($amount, 2) . " (order #$order_id)");
        }
    }

    private static function clean_date(string $d): string {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($d)) ? trim($d) : '';
    }

    /** Warranty / repeat-repair lookup by IMEI (tickets + owned-stock history). */
    public static function history(string $imei): array {
        global $wpdb;
        $imei = preg_replace('/\D/', '', $imei);
        return [
            'tickets' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wbam_tickets WHERE imei=%s ORDER BY created_at DESC", $imei), ARRAY_A) ?: [],
            'units'   => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wbam_units WHERE imei=%s ORDER BY created_at DESC", $imei), ARRAY_A) ?: [],
        ];
    }

    public static function list(array $f = []): array {
        global $wpdb;
        $where = ['1=1']; $args = [];
        if (!empty($f['status'])) { $where[] = 'status=%s'; $args[] = $f['status']; }
        elseif (empty($f['all'])) { $where[] = "status NOT IN ('collected','cancelled')"; }
        if (!empty($f['branch_id'])) { $where[] = 'branch_id=%d'; $args[] = (int) $f['branch_id']; }
        if (!empty($f['q'])) {
            $like = '%' . $wpdb->esc_like($f['q']) . '%';
            $where[] = '(ticket_code LIKE %s OR customer_name LIKE %s OR phone LIKE %s OR imei LIKE %s OR device_model LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT * FROM {$wpdb->prefix}wbam_tickets WHERE " . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT 200';
        return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: [];
    }

    /** Parts cost + margin summary for one ticket. */
    public static function economics(int $id): array {
        global $wpdb;
        $t = self::get($id);
        $parts = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(unit_cost*qty),0) FROM {$wpdb->prefix}wbam_ticket_parts WHERE ticket_id=%d", $id));
        $paid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}wbam_order_lines WHERE ticket_id=%d", $id));
        return [
            'quoted' => (float) ($t['quote'] ?? 0),
            'paid'   => $paid,
            'parts'  => $parts,
            'margin' => $paid - $parts,
        ];
    }

    /* ---------------- public tracking page ---------------- */

    /** [wbam_track] — customer self-service status: ticket code + phone last 4. */
    public static function track_shortcode($atts = []): string {
        $code = sanitize_text_field($_GET['t'] ?? '');
        $last4 = preg_replace('/\D/', '', (string) ($_GET['p'] ?? ''));
        $out = '<div class="wbam-track"><form method="get">'
             . '<p><label>Ticket number<br><input name="t" value="' . esc_attr($code) . '" placeholder="T-0042" required></label></p>'
             . '<p><label>Last 4 digits of your phone number<br><input name="p" value="" maxlength="4" pattern="\d{4}" required></label></p>'
             . '<p><button type="submit">Check status</button></p></form>';
        if ($code && $last4) {
            $t = self::by_code($code);
            $ok = $t && substr(preg_replace('/\D/', '', $t['phone']), -4) === $last4;
            if (!$ok) {
                $out .= '<p><strong>No match found.</strong> Please check the ticket number and phone digits.</p>';
            } else {
                $labels = [
                    'booked' => 'Booked in', 'received' => 'Device received', 'diagnosed' => 'Diagnosed',
                    'awaiting_parts' => 'Waiting for parts', 'in_repair' => 'Repair in progress',
                    'ready' => 'Ready for collection 🎉', 'collected' => 'Collected', 'cancelled' => 'Cancelled',
                ];
                $out .= '<h3>' . esc_html($t['ticket_code']) . ' — ' . esc_html($t['device_model']) . '</h3>'
                      . '<p>Status: <strong>' . esc_html($labels[$t['status']] ?? $t['status']) . '</strong></p>'
                      . (!empty($t['repair_type']) ? '<p>Repair: ' . esc_html($t['repair_type']) . '</p>' : '')
                      . (!empty($t['due_date']) && !in_array($t['status'], ['collected', 'cancelled'], true) ? '<p>Estimated completion: ' . esc_html(mysql2date('j M Y', $t['due_date'])) . '</p>' : '')
                      . ((int) ($t['device_held'] ?? 1) === 0 && in_array($t['status'], ['booked', 'awaiting_parts'], true) ? '<p>We\'ll call you to bring your device in as soon as the parts arrive.</p>' : '')
                      . ($t['quote'] !== null ? '<p>Quoted: £' . number_format((float) $t['quote'], 2) . ($t['deposit'] > 0 ? ' (deposit £' . number_format((float) $t['deposit'], 2) . ' received)' : '') . '</p>' : '')
                      . '<p>Booked: ' . esc_html(mysql2date('j M Y', $t['created_at'])) . '</p>';
            }
        }
        return $out . '</div>';
    }
}
