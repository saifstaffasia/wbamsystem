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

        // Current shelf price of one variant (for prefilling the intake's Selling price box).
        register_rest_route('wbam/v1', '/price', [
            'methods' => 'GET',
            'callback' => [self::class, 'variant_price'],
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

        // Resumable history backfill (managers). Call repeatedly until done:true.
        register_rest_route('wbam/v1', '/backfill', [
            'methods' => 'POST',
            'callback' => function (WP_REST_Request $r) {
                try { return rest_ensure_response(WBAM_Sync::backfill_step(min(59, max(1, (int) ($r['days'] ?: 59))))); }
                catch (Throwable $e) { return new WP_Error('wbam_sync', $e->getMessage(), ['status' => 500]); }
            },
            'permission_callback' => fn() => current_user_can('wbam_manage'),
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
        register_rest_route('wbam/v1', '/pos/price', [
            'methods' => 'GET',
            'callback' => [self::class, 'variant_price'],
            'permission_callback' => [self::class, 'pos_auth'],
        ]);
        register_rest_route('wbam/v1', '/pos/intake', [
            'methods' => 'POST',
            'callback' => [self::class, 'pos_intake'],
            'permission_callback' => [self::class, 'pos_auth'],
        ]);

        /* ---- front-end staff app (cookie + nonce auth) ---- */
        $staff = fn() => is_user_logged_in() && current_user_can('wbam_use');

        register_rest_route('wbam/v1', '/app/bootstrap', [
            'methods' => 'GET',
            'callback' => fn() => rest_ensure_response([
                'branches' => WBAM_Settings::branches(),
                'statuses' => WBAM_Tickets::STATUSES,
                'rtypes'   => ['Diagnosis', 'Screen Change', 'Battery Change', 'Backglass Change', 'Other Repair'],
                'grades'   => ['New', 'Used (A - Excellent)', 'Used (B - Very Good)', 'Used (C - Good)'],
                'parts'    => WBAM_Parts::parts(),
                'vendors'  => WBAM_Parts::vendors(),
            ]),
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/units', [
            'methods' => 'GET',
            'callback' => function (WP_REST_Request $r) {
                $rows = WBAM_Units::search(['q' => (string) $r['q'], 'status' => (string) $r['status'], 'branch_id' => (int) $r['branch']]);
                return rest_ensure_response(array_map(fn($u) => WBAM_Units::get((int) $u['id']), $rows));
            },
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/unit/(?P<id>\d+)/edit', [
            'methods' => 'POST',
            'callback' => function (WP_REST_Request $r) {
                try {
                    return rest_ensure_response(['ok' => true, 'unit' => WBAM_Units::update_unit((int) $r['id'], (array) $r->get_json_params())]);
                } catch (Throwable $e) { return new WP_Error('wbam_edit', $e->getMessage(), ['status' => 400]); }
            },
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/reconcile', [
            'methods' => 'GET',
            'callback' => function () {
                global $wpdb;
                $rows = WBAM_Units::pending_reconcile();
                foreach ($rows as &$row) {
                    $row['candidates'] = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, unit_code, imei FROM {$wpdb->prefix}wbam_units WHERE variant_id=%d AND status='in_stock'",
                        (int) $row['variant_id']), ARRAY_A) ?: [];
                }
                return rest_ensure_response($rows);
            },
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/reconcile/attach', [
            'methods' => 'POST',
            'callback' => function (WP_REST_Request $r) {
                global $wpdb;
                $unit = WBAM_Units::by_code((string) $r['unit_scan']);
                $line = $wpdb->get_row($wpdb->prepare(
                    "SELECT l.*, o.name FROM {$wpdb->prefix}wbam_order_lines l JOIN {$wpdb->prefix}wbam_orders o ON o.order_id=l.order_id WHERE l.id=%d",
                    (int) $r['line_row']), ARRAY_A);
                if (!$unit || !$line || $unit['status'] !== 'in_stock' || (int) $unit['variant_id'] !== (int) $line['variant_id']) {
                    return new WP_Error('wbam_rec', 'No match — check the scan (same model/variant, still in stock).', ['status' => 400]);
                }
                WBAM_Units::mark_sold((int) $unit['id'], (int) $line['order_id'], $line['name'], (float) $line['price'], (int) $line['id']);
                return rest_ensure_response(['ok' => true]);
            },
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/tickets', [
            'methods' => 'GET',
            'callback' => fn(WP_REST_Request $r) => rest_ensure_response(WBAM_Tickets::list(['q' => (string) $r['q'], 'all' => (bool) $r['all']])),
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/ticket/new', [
            'methods' => 'POST',
            'callback' => function (WP_REST_Request $r) {
                try {
                    $p = (array) $r->get_json_params();
                    return rest_ensure_response(['ok' => true, 'ticket' => WBAM_Tickets::create($p, 'walkin')]);
                } catch (Throwable $e) { return new WP_Error('wbam_tk', $e->getMessage(), ['status' => 400]); }
            },
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/ticket/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => function (WP_REST_Request $r) {
                global $wpdb;
                $id = (int) $r['id'];
                $t = WBAM_Tickets::get($id);
                if (!$t) return new WP_Error('wbam_tk', 'Unknown ticket', ['status' => 404]);
                $t['economics'] = WBAM_Tickets::economics($id);
                $t['parts'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_ticket_parts WHERE ticket_id=%d", $id), ARRAY_A) ?: [];
                $t['events'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_ticket_events WHERE ticket_id=%d ORDER BY id DESC LIMIT 40", $id), ARRAY_A) ?: [];
                return rest_ensure_response($t);
            },
            'permission_callback' => $staff,
        ]);
        register_rest_route('wbam/v1', '/app/ticket/(?P<id>\d+)/(?P<op>status|save|draft|part-stock|part-order)', [
            'methods' => 'POST',
            'callback' => [self::class, 'app_ticket_op'],
            'permission_callback' => $staff,
        ]);
    }

    public static function app_ticket_op(WP_REST_Request $r) {
        $id = (int) $r['id'];
        $p  = (array) $r->get_json_params();
        try {
            switch ($r['op']) {
                case 'status':
                    return rest_ensure_response(['ok' => true, 'ticket' => WBAM_Tickets::set_status($id, sanitize_key($p['status'] ?? ''), sanitize_text_field($p['note'] ?? ''))]);
                case 'save':
                    WBAM_Tickets::update_fields($id, [
                        'diagnosis'   => $p['diagnosis'] ?? '',
                        'quote'       => ($p['quote'] ?? '') !== '' ? (float) $p['quote'] : null,
                        'repair_type' => $p['repair_type'] ?? '',
                        'due_date'    => $p['due_date'] ?? '',
                        'device_held' => isset($p['device_held']) ? (int) !!$p['device_held'] : 1,
                    ]);
                    return rest_ensure_response(['ok' => true, 'ticket' => WBAM_Tickets::get($id)]);
                case 'draft':
                    $url = WBAM_Tickets::draft_payment($id, (float) ($p['amount'] ?? 0), ($p['which'] ?? 'deposit') === 'balance' ? 'balance' : 'deposit');
                    return rest_ensure_response(['ok' => true, 'url' => $url]);
                case 'part-stock':
                    WBAM_Parts::use_from_stock($id, (int) ($p['part_id'] ?? 0), max(1, (int) ($p['qty'] ?? 1)));
                    return rest_ensure_response(['ok' => true]);
                case 'part-order':
                    $t = WBAM_Tickets::get($id);
                    WBAM_Parts::create_po((int) ($p['vendor_id'] ?? 0), (int) ($t['branch_id'] ?? 0), [[
                        'ticket_id' => $id,
                        'description' => sanitize_text_field($p['description'] ?? ''),
                        'product_url' => esc_url_raw($p['product_url'] ?? ''),
                        'qty' => max(1, (int) ($p['qty'] ?? 1)),
                        'unit_cost' => (float) ($p['unit_cost'] ?? 0),
                    ]]);
                    WBAM_Tickets::set_status($id, 'awaiting_parts', 'parts ordered');
                    return rest_ensure_response(['ok' => true]);
            }
        } catch (Throwable $e) {
            return new WP_Error('wbam_tk', $e->getMessage(), ['status' => 400]);
        }
        return new WP_Error('wbam_tk', 'Bad op', ['status' => 400]);
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

    /** Current shelf price of the variant matching the picked options (null if it doesn't exist yet). */
    public static function variant_price(WP_REST_Request $r) {
        try {
            $selected = json_decode((string) $r['selected'], true);
            if (!is_array($selected)) $selected = [];
            return rest_ensure_response(WBAM_Catalog::peek_variant((int) $r['product_id'], $selected));
        } catch (Throwable $e) {
            return new WP_Error('wbam_price', $e->getMessage(), ['status' => 400]);
        }
    }

    /** Tile → buy a device in: unit registry + pooled stock +1 + payout logged. */
    public static function pos_intake(WP_REST_Request $r) {
        try {
            $branch = null;
            if ((int) $r['location_id']) $branch = WBAM_Settings::branch_by_location((int) $r['location_id']);
            if (!$branch) { $bs = WBAM_Settings::branches(); $branch = $bs[0] ?? null; }
            $unit = self::do_intake($r, (int) ($branch['id'] ?? 0));
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

    /** Shared intake dispatcher (staff app + POS tile): catalog or custom path. */
    private static function do_intake(WP_REST_Request $r, int $branch_id): array {
        $common = [
            'imei'           => (string) $r['imei'],
            'purchase_price' => (float) $r['purchase_price'],
            'branch_id'      => $branch_id,
            'source'         => (string) $r['source'],
            'source_ref'     => (string) $r['source_ref'],
            'payout_method'  => (string) $r['payout_method'],
            'battery_health' => (string) $r['battery_health'],
            'checkmend_ref'  => (string) $r['checkmend_ref'],
            'notes'          => (string) $r['notes'],
            'seller'         => (array) ($r['seller'] ?? []),
            'bank'           => (array) ($r['bank'] ?? []),
        ];
        if ((int) $r['custom'] === 1) {
            return WBAM_Units::intake_custom($common + [
                'title'      => (string) $r['title'],
                'grade'      => (string) $r['grade'],
                'sell_price' => (float) $r['sell_price'],
            ]);
        }
        return WBAM_Units::intake($common + [
            'product_id'     => (int) $r['product_id'],
            'model_title'    => (string) $r['model_title'],
            'selected'       => (array) $r['selected'],
            'price_override' => (string) $r['price_override'],
        ]);
    }

    public static function intake(WP_REST_Request $r) {
        try {
            $unit = self::do_intake($r, (int) $r['branch_id']);
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
