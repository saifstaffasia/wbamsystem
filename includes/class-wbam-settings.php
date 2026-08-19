<?php
if (!defined('ABSPATH')) exit;

/**
 * Settings storage + helpers. One custom app (created in the WBAM store's own
 * dev dashboard, client-credentials grant — same pattern as the Redeem apps).
 *
 * Required app scopes (put ALL of these in the REQUIRED scopes box, not Optional):
 *   read_products, write_products, read_inventory, write_inventory,
 *   read_orders, write_orders, read_customers, write_customers,
 *   read_locations, read_draft_orders, write_draft_orders, read_cash_tracking
 *
 * NOTE: read_users (per-staff attribution via Order.staffMember) is gated to
 * Advanced/Plus stores. The Hub instead records the `user_id` present on order
 * webhooks/REST payloads and labels it via the Staff map screen.
 */
class WBAM_Settings {

    public static function all(): array {
        $d = [
            'shop_domain'   => 'sa-we-buy-any-mobile.myshopify.com',
            'client_id'     => '',
            'client_secret' => '',
            'api_version'   => '2026-07',
            'tender_map'    => [
                'cash'             => 'Cash',
                'shopify_payments' => 'Card',
                'Trade In'         => 'Trade-in',
                'card'             => 'Card',
                'manual'           => 'Other',
                'gift_card'        => 'Gift card',
            ],
            'email_from'    => get_option('admin_email'),
            'sms_provider'  => '',          // '' = disabled, 'twilio'
            'twilio_sid'    => '',
            'twilio_token'  => '',
            'twilio_from'   => '',
            'booking_origin'=> 'https://webuyanymobile.com, https://www.webuyanymobile.com, https://sa-we-buy-any-mobile.myshopify.com',
            'label_w_mm'    => 40,
            'label_h_mm'    => 30,
            'business_name' => 'WeBuyAnyMobile',
            'business_phone'=> '',
            'vat_note'      => 'Margin scheme — used goods',
        ];
        $o = get_option('wbam_settings', []);
        return array_merge($d, is_array($o) ? $o : []);
    }

    public static function get(string $key, $fallback = null) {
        $all = self::all();
        return $all[$key] ?? $fallback;
    }

    public static function update(array $patch): void {
        update_option('wbam_settings', array_merge(self::all(), $patch), false);
    }

    /** Map a raw gateway string to a report bucket (Cash / Card / Trade-in / ...). */
    public static function tender_bucket(string $gateway): string {
        $map = self::get('tender_map', []);
        if (isset($map[$gateway])) return $map[$gateway];
        foreach ($map as $k => $v) {
            if (strcasecmp($k, $gateway) === 0) return $v;
        }
        return $gateway !== '' ? ucfirst($gateway) : 'Other';
    }

    /* ---------------- branches ---------------- */

    public static function branches(bool $active_only = true): array {
        global $wpdb;
        $where = $active_only ? 'WHERE active=1' : '';
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wbam_branches $where ORDER BY id", ARRAY_A) ?: [];
    }

    public static function branch_by_location(int $location_id): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_branches WHERE shopify_location_id=%d", $location_id
        ), ARRAY_A);
        return $r ?: null;
    }

    public static function branch(int $id): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_branches WHERE id=%d", $id), ARRAY_A);
        return $r ?: null;
    }

    /** Pull locations from Shopify and upsert as branches. */
    public static function sync_branches(): array {
        global $wpdb;
        $res = WBAM_Shopify::i()->graphql(
            'query { locations(first: 20) { nodes { id name isActive } } }'
        );
        $nodes = $res['data']['locations']['nodes'] ?? [];
        foreach ($nodes as $n) {
            $lid = (int) preg_replace('/\D/', '', $n['id']);
            $exists = self::branch_by_location($lid);
            if ($exists) {
                $wpdb->update("{$wpdb->prefix}wbam_branches",
                    ['name' => $n['name'], 'active' => $n['isActive'] ? 1 : 0],
                    ['id' => $exists['id']]);
            } else {
                $wpdb->insert("{$wpdb->prefix}wbam_branches",
                    ['name' => $n['name'], 'shopify_location_id' => $lid, 'active' => $n['isActive'] ? 1 : 0]);
            }
        }
        return self::branches(false);
    }

    /* ---------------- staff map ---------------- */

    public static function staff_label(int $user_id): string {
        global $wpdb;
        if (!$user_id) return 'Unattributed';
        $l = $wpdb->get_var($wpdb->prepare(
            "SELECT label FROM {$wpdb->prefix}wbam_staff_map WHERE user_id=%d", $user_id
        ));
        return $l !== null && $l !== '' ? $l : ('Staff #' . $user_id);
    }

    /** First time we see a user_id on an order, add a placeholder row for the manager to label. */
    public static function touch_staff(int $user_id): void {
        global $wpdb;
        if (!$user_id) return;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}wbam_staff_map (user_id, label, active) VALUES (%d, '', 1)",
            $user_id
        ));
    }

    /**
     * Fill an empty staff label from the order's timeline ("Jane Doe processed
     * this order on Shopify POS.") — works on every plan, no read_users scope.
     * Manual edits in Settings always win: only blank labels are filled.
     */
    public static function auto_label_staff(int $user_id, int $order_id): void {
        global $wpdb;
        if (!$user_id || !$order_id) return;
        $label = $wpdb->get_var($wpdb->prepare(
            "SELECT label FROM {$wpdb->prefix}wbam_staff_map WHERE user_id=%d", $user_id
        ));
        if ($label === null || $label !== '') return;
        try {
            [$data] = WBAM_Shopify::i()->rest('GET', "/orders/{$order_id}/events.json?limit=50");
            foreach ((array) ($data['events'] ?? []) as $ev) {
                $msg = wp_strip_all_tags((string) ($ev['message'] ?? ''));
                if (!preg_match('/^(.{2,60}?) (?:processed|placed) this order/i', $msg, $m)) continue;
                $name = trim($m[1]);
                if ($name === '' || strcasecmp($name, 'You') === 0 || stripos($name, 'Shopify') !== false) return;
                $wpdb->update("{$wpdb->prefix}wbam_staff_map",
                    ['label' => sanitize_text_field($name)], ['user_id' => $user_id]);
                return;
            }
        } catch (Throwable $e) {} // cosmetic — never block order ingest
    }

    /* ---------------- sync state ---------------- */

    public static function state_get(string $k, $fallback = null) {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare("SELECT v FROM {$wpdb->prefix}wbam_sync_state WHERE k=%s", $k));
        if ($v === null) return $fallback;
        $j = json_decode($v, true);
        return $j === null ? $v : $j;
    }

    public static function state_set(string $k, $v): void {
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}wbam_sync_state", [
            'k' => $k,
            'v' => is_scalar($v) ? (string) $v : wp_json_encode($v),
        ]);
    }
}
