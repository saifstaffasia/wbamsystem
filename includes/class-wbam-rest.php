<?php
if (!defined('ABSPATH')) exit;

/**
 * REST routes:
 *   POST /wbam/v1/webhook          — Shopify webhooks (HMAC-verified, public)
 *   POST /wbam/v1/booking          — storefront repair booking (CORS-limited, public)
 *   GET  /wbam/v1/models           — intake autocomplete (staff)
 *   POST /wbam/v1/intake           — create unit (staff)
 *   GET  /wbam/v1/report           — report payload (managers)
 *   POST /wbam/v1/report/refresh   — pull today from Shopify (managers)
 *   POST /wbam/v1/unit/{id}/(sold|return|writeoff|transfer) (staff/managers)
 */
class WBAM_Rest {

    public static function routes(): void {
        register_rest_route('wbam/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => ['WBAM_Webhooks', 'handle'],
            'permission_callback' => '__return_true', // HMAC inside
        ]);

        register_rest_route('wbam/v1', '/booking', [
            'methods' => 'POST',
            'callback' => [self::class, 'booking'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('wbam/v1', '/models', [
            'methods' => 'GET',
            'callback' => fn(WP_REST_Request $r) => rest_ensure_response(WBAM_Catalog::search_models((string) $r['term'])),
            'permission_callback' => fn() => current_user_can('wbam_use'),
        ]);

        register_rest_route('wbam/v1', '/intake', [
            'methods' => 'POST',
            'callback' => [self::class, 'intake'],
            'permission_callback' => fn() => current_user_can('wbam_use'),
        ]);

        register_rest_route('wbam/v1', '/report', [
            'methods' => 'GET',
            'callback' => fn(WP_REST_Request $r) => rest_ensure_response(
                WBAM_Reports::build((string) ($r['range'] ?: 'today'), (int) $r['branch'], (string) $r['from'], (string) $r['to'])
            ),
            'permission_callback' => fn() => current_user_can('wbam_reports'),
        ]);

        register_rest_route('wbam/v1', '/report/refresh', [
            'methods' => 'POST',
            'callback' => function () {
                try { return rest_ensure_response(['pulled' => WBAM_Sync::refresh_today()]); }
                catch (Throwable $e) { return new WP_Error('wbam_sync', $e->getMessage(), ['status' => 500]); }
            },
            'permission_callback' => fn() => current_user_can('wbam_reports'),
        ]);

        register_rest_route('wbam/v1', '/unit/(?P<id>\d+)/(?P<op>sold|return|writeoff|transfer)', [
            'methods' => 'POST',
            'callback' => [self::class, 'unit_op'],
            'permission_callback' => fn() => current_user_can('wbam_use'),
        ]);

        /* ---- POS UI extension endpoints (auth: Shopify session token) ---- */

        register_rest_route('wbam/v1', '/pos/booking', [
            'methods' => 'POST',
            'callback' => [self::class, 'pos_booking'],
            'permission_callback' => [self::class, 'pos_auth'],
        ]);
        register_rest_route('wbam/v1', '/pos/tickets', [
            'methods' => 'GET',
            'callback' => [self::class, 'pos_tickets'],
            'permission_callback' => [self::class, 'pos_auth'],
        ]);
        register_rest_route('wbam/v1', '/pos/line', [
            'methods' => 'POST',
            'callback' => [self::class, 'pos_line'],
            'permission_callback' => [self::class, 'pos_auth'],
        ]);
        register_rest_route('wbam/v1', '/pos/models', [
            'methods' => 'GET',
            'callback' => fn(WP_REST_Request $r) => rest_ensure_response(WBAM_Catalog::search_models((string) $r['term'])),
            'permission_callback' => [self::class, 'pos_auth'],
        ]);
        register_rest_route('wbam/v1', '/pos/intake', [
            'methods' => 'POST',
            'callback' => [self::class, 'pos_intake'],
            'permission_callback' => [self::class, 'pos_auth'],
        ]);
    }

    /* ================= POS extension auth + endpoints ================= */

    /**
     * POS UI extensions authenticate with a Shopify session token (JWT, HS256,
     * signed with the app's client secret). WP-logged-in Hub staff pass too.
     */
    public static function pos_auth(WP_REST_Request $req): bool {
        if (is_user_logged_in() && current_user_can('wbam_use')) return true;
        $auth = (string) ($req->get_header('authorization') ?? '');
        if (stripos($auth, 'bearer ') !== 0) return false;
        return (bool) self::verify_session_token(trim(substr($auth, 7)));
    }

    public static function verify_session_token(string $jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return false;
        [$h, $p, $sig] = $parts;
        $secret = WBAM_Shopify::i()->secret();
        if ($secret === '') return false;
        $calc = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($calc, $sig)) return false;
        $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time() - 10) return false;
        $shop = WBAM_Shopify::normalize_domain((string) WBAM_Settings::get('shop_domain'));
        $dest = (string) ($payload['dest'] ?? '');
        if ($dest !== '' && parse_url($dest, PHP_URL_HOST) !== $shop) return false;
        return $payload;
    }

    /** Tile → new walk-in booking (optionally straight into a deposit line). */
    public static function pos_booking(WP_REST_Request $r) {
        try {
            $branch = null;
            if ((int) $r['location_id']) $branch = WBAM_Settings::branch_by_location((int) $r['location_id']);
            if (!$branch) { $bs = WBAM_Settings::branches(); $branch = $bs[0] ?? null; }
            if (!trim((string) $r['customer_name']) || !trim((string) $r['phone'])) {
                return new WP_Error('wbam_invalid', 'Name and phone are required', ['status' => 400]);
            }
            $t = WBAM_Tickets::create([
                'branch_id'     => (int) ($branch['id'] ?? 0),
                'customer_name' => (string) $r['customer_name'],
                'phone'         => (string) $r['phone'],
                'email'         => (string) $r['email'],
                'device_model'  => (string) $r['device_model'],
                'imei'          => (string) $r['imei'],
                'passcode'      => (string) $r['passcode'],
                'fault'         => (string) $r['fault'],
                'repair_type'   => (string) $r['repair_type'],
                'due_date'      => (string) $r['due_date'],
                'device_held'   => $r['device_held'] === null ? 1 : (int) !!$r['device_held'],
                'quote'         => (string) $r['quote'],
            ], 'pos');
            return rest_ensure_response(['ok' => true, 'id' => (int) $t['id'], 'ticket' => $t['ticket_code']]);
        } catch (Throwable $e) {
            return new WP_Error('wbam_pos', $e->getMessage(), ['status' => 400]);
        }
    }

    /** Tile → buy a device in: unit registry + pooled stock +1 + payout logged. */
    public static function pos_intake(WP_REST_Request $r) {
        try {
            $branch = null;
            if ((int) $r['location_id']) $branch = WBAM_Settings::branch_by_location((int) $r['location_id']);
            if (!$branch) { $bs = WBAM_Settings::branches(); $branch = $bs[0] ?? null; }
            $common = [
                'imei'           => (string) $r['imei'],
                'purchase_price' => (float) $r['purchase_price'],
                'branch_id'      => (int) ($branch['id'] ?? 0),
                'source'         => (string) $r['source'],
                'source_ref'     => (string) $r['source_ref'],
                'payout_method'  => (string) $r['payout_method'],
                'battery_health' => (string) $r['battery_health'],
            ];
            if ((int) $r['custom'] === 1) {
                $unit = WBAM_Units::intake_custom($common + [
                    'title'      => (string) $r['title'],
                    'grade'      => (string) $r['grade'],
                    'sell_price' => (float) $r['sell_price'],
                ]);
            } else {
                $unit = WBAM_Units::intake($common + [
                    'product_id'  => (int) $r['product_id'],
                    'model_title' => (string) $r['model_title'],
                    'selected'    => (array) $r['selected'],
                ]);
            }
            return rest_ensure_response([
                'ok'        => true,
                'unit_code' => $unit['unit_code'],
                'imei'      => $unit['imei'],
                'title'     => trim($unit['model_title'] . ' — ' . $unit['variant_title']),
            ]);
        } catch (Throwable $e) {
            return new WP_Error('wbam_pos', $e->getMessage(), ['status' => 400]);
        }
    }

    /** Tile → find open tickets (code digits, name, phone). */
    public static function pos_tickets(WP_REST_Request $r) {
        $rows = WBAM_Tickets::list(['q' => (string) $r['q']]);
        $out = [];
        foreach (array_slice($rows, 0, 10) as $t) {
            $eco = WBAM_Tickets::economics((int) $t['id']);
            $out[] = [
                'id'       => (int) $t['id'],
                'ticket'   => $t['ticket_code'],
                'customer' => $t['customer_name'],
                'phone'    => $t['phone'],
                'device'   => $t['device_model'],
                'status'   => $t['status'],
                'quote'    => $t['quote'] !== null ? (float) $t['quote'] : null,
                'paid'     => $eco['paid'],
                'due'      => $t['quote'] !== null ? max(0, round((float) $t['quote'] - $eco['paid'], 2)) : null,
            ];
        }
        return rest_ensure_response($out);
    }

    /**
     * Tile → canonical cart-line title for a deposit/balance. The server spells
     * the title; the extension adds it as a custom sale. No staff typing, ever.
     */
    public static function pos_line(WP_REST_Request $r) {
        $t = WBAM_Tickets::get((int) $r['ticket_id']);
        if (!$t) return new WP_Error('wbam_pos', 'Unknown ticket', ['status' => 404]);
        $which = $r['which'] === 'balance' ? 'balance' : 'deposit';
        $amount = round((float) $r['amount'], 2);
        if ($amount <= 0) return new WP_Error('wbam_pos', 'Amount must be positive', ['status' => 400]);
        $title = sprintf('Repair %s — %s (%s)', $which, $t['ticket_code'], $t['device_model']);
        WBAM_Tickets::event((int) $t['id'], 'pos_line', "$which £" . number_format($amount, 2) . ' sent to POS cart');
        return rest_ensure_response(['ok' => true, 'title' => $title, 'price' => number_format($amount, 2, '.', ''), 'taxable' => false]);
    }

    /** Storefront booking form → ticket. Locked to the shop's origin + honeypot + throttle. */
    public static function booking(WP_REST_Request $r) {
        $origin = get_http_origin();
        $allowed = WBAM_Settings::get('booking_origin');
        if ($origin && $allowed && !str_starts_with($origin, rtrim($allowed, '/'))) {
            return new WP_Error('wbam_origin', 'Origin not allowed', ['status' => 403]);
        }
        if (!empty($r['website'])) { // honeypot
            return rest_ensure_response(['ok' => true]);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
        $key = 'wbam_bk_' . md5($ip);
        if ((int) get_transient($key) >= 5) {
            return new WP_Error('wbam_throttle', 'Too many requests', ['status' => 429]);
        }
        set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);

        if (!trim((string) $r['customer_name']) || (!trim((string) $r['phone']) && !trim((string) $r['email']))) {
            return new WP_Error('wbam_invalid', 'Name and phone or email are required', ['status' => 400]);
        }
        $branches = WBAM_Settings::branches();
        $t = WBAM_Tickets::create([
            'branch_id'     => (int) ($r['branch_id'] ?: ($branches[0]['id'] ?? 0)),
            'customer_name' => (string) $r['customer_name'],
            'phone'         => (string) $r['phone'],
            'email'         => (string) $r['email'],
            'device_model'  => (string) $r['device_model'],
            'imei'          => (string) $r['imei'],
            'fault'         => (string) $r['fault'],
            'quote'         => '',
        ], 'web');

        $res = rest_ensure_response(['ok' => true, 'ticket' => $t['ticket_code']]);
        if ($origin) {
            $res->header('Access-Control-Allow-Origin', esc_url_raw($origin));
        }
        return $res;
    }

    public static function intake(WP_REST_Request $r) {
        try {
            $unit = WBAM_Units::intake([
                'imei'           => (string) $r['imei'],
                'product_id'     => (int) $r['product_id'],
                'model_title'    => (string) $r['model_title'],
                'selected'       => (array) $r['selected'],
                'purchase_price' => (float) $r['purchase_price'],
                'branch_id'      => (int) $r['branch_id'],
                'source'         => (string) $r['source'],
                'source_ref'     => (string) $r['source_ref'],
                'payout_method'  => (string) $r['payout_method'],
                'battery_health' => (string) $r['battery_health'],
                'checkmend_ref'  => (string) $r['checkmend_ref'],
                'notes'          => (string) $r['notes'],
                'price_override' => (string) $r['price_override'],
            ]);
            return rest_ensure_response(['ok' => true, 'unit' => $unit]);
        } catch (Throwable $e) {
            return new WP_Error('wbam_intake', $e->getMessage(), ['status' => 400]);
        }
    }

    public static function unit_op(WP_REST_Request $r) {
        $id = (int) $r['id'];
        try {
            switch ($r['op']) {
                case 'sold': // manual reconcile: attach unit to an order line
                    WBAM_Units::mark_sold($id, (int) $r['order_id'], (string) $r['order_name'], (float) $r['sale_price'], (int) $r['line_row'] ?: null);
                    break;
                case 'return':
                    WBAM_Units::return_to_stock($id, (string) $r['reason']);
                    break;
                case 'writeoff':
                    if (!current_user_can('wbam_manage')) return new WP_Error('wbam_cap', 'Managers only', ['status' => 403]);
                    WBAM_Units::write_off($id, (string) $r['reason']);
                    break;
                case 'transfer':
                    WBAM_Units::transfer($id, (int) $r['to_branch']);
                    break;
            }
            return rest_ensure_response(['ok' => true, 'unit' => WBAM_Units::get($id)]);
        } catch (Throwable $e) {
            return new WP_Error('wbam_unit', $e->getMessage(), ['status' => 400]);
        }
    }
}
