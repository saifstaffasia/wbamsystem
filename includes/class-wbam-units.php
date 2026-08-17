<?php
if (!defined('ABSPATH')) exit;

/**
 * The unit registry — one row per physical phone. The Hub's core.
 */
class WBAM_Units {

    /* ---------------- intake ---------------- */

    /**
     * $in: imei, product_id, selected (option=>value map), grade, purchase_price,
     *      branch_id, source (buyback|tradein|supplier), source_ref, payout_method,
     *      battery_health, notes, price_override (optional selling price for new variants)
     * Returns the unit row (with label URL).
     */
    public static function intake(array $in): array {
        global $wpdb;
        $imei = preg_replace('/\D/', '', (string) ($in['imei'] ?? ''));
        if (strlen($imei) < 14 || strlen($imei) > 16) {
            throw new InvalidArgumentException('IMEI must be 14–16 digits.');
        }
        $dupe = $wpdb->get_var($wpdb->prepare(
            "SELECT unit_code FROM {$wpdb->prefix}wbam_units WHERE imei=%s AND status IN ('in_stock','reserved')", $imei
        ));
        if ($dupe) throw new RuntimeException("IMEI already in stock as $dupe.");

        $branch = WBAM_Settings::branch((int) $in['branch_id']);
        if (!$branch || !$branch['shopify_location_id']) {
            throw new RuntimeException('Branch is not linked to a Shopify location (Settings → Branches).');
        }

        $selected = (array) ($in['selected'] ?? []);
        $variant  = WBAM_Catalog::ensure_variant(
            (int) $in['product_id'], $selected,
            isset($in['price_override']) && $in['price_override'] !== '' ? (float) $in['price_override'] : null
        );
        $barcode  = WBAM_Catalog::ensure_pool_barcode((int) $in['product_id'], $variant);

        $now = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}wbam_units", [
            'unit_code'         => '',
            'imei'              => $imei,
            'product_id'        => (int) $in['product_id'],
            'variant_id'        => $variant['variant_id'],
            'inventory_item_id' => $variant['inventory_item_id'],
            'model_title'       => sanitize_text_field($in['model_title'] ?? ''),
            'variant_title'     => $variant['title'],
            'sku'               => $variant['sku'],
            'pool_barcode'      => $barcode,
            'grade'             => sanitize_text_field($selected['Condition'] ?? ($in['grade'] ?? '')),
            'branch_id'         => (int) $in['branch_id'],
            'status'            => 'in_stock',
            'purchase_price'    => (float) ($in['purchase_price'] ?? 0),
            'source'            => in_array($in['source'] ?? '', ['buyback', 'tradein', 'supplier'], true) ? $in['source'] : 'buyback',
            'source_ref'        => sanitize_text_field($in['source_ref'] ?? ''),
            'payout_method'     => sanitize_text_field($in['payout_method'] ?? ''),
            'battery_health'    => isset($in['battery_health']) && $in['battery_health'] !== '' ? (int) $in['battery_health'] : null,
            'checkmend_ref'     => sanitize_text_field($in['checkmend_ref'] ?? ''),
            'notes'             => sanitize_textarea_field($in['notes'] ?? ''),
            'created_by'        => get_current_user_id(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $id   = (int) $wpdb->insert_id;
        $code = 'U' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        $wpdb->update("{$wpdb->prefix}wbam_units", ['unit_code' => $code], ['id' => $id]);

        self::event($id, 'intake', sprintf('%s @ %s, £%.2f, %s',
            $variant['title'], $branch['name'], (float) $in['purchase_price'], $in['source'] ?? 'buyback'));

        // Money out (buyback/trade-in paid to the seller) → payouts ledger for cash reconciliation.
        if (in_array($in['source'] ?? '', ['buyback', 'tradein'], true) && (float) ($in['purchase_price'] ?? 0) > 0) {
            $wpdb->insert("{$wpdb->prefix}wbam_payouts", [
                'branch_id' => (int) $in['branch_id'],
                'unit_id'   => $id,
                'amount'    => (float) $in['purchase_price'],
                'method'    => sanitize_text_field($in['payout_method'] ?: 'cash'),
                'reference' => sanitize_text_field($in['source_ref'] ?? ''),
                'user_id'   => get_current_user_id(),
                'created_at'=> $now,
            ]);
        }

        // Shopify side: +1 pooled stock, refresh rolling-average cost.
        WBAM_Catalog::adjust_inventory($variant['inventory_item_id'], (int) $branch['shopify_location_id'], +1, 'received');
        try {
            WBAM_Catalog::refresh_variant_cost($variant['variant_id'], $variant['inventory_item_id']);
        } catch (Throwable $e) {
            WBAM_Sync::queue('refresh_cost', ['variant_id' => $variant['variant_id'], 'inventory_item_id' => $variant['inventory_item_id']], $e->getMessage());
        }

        return self::get($id);
    }

    /**
     * Custom-device intake: the device isn't in the catalog, so a one-off
     * Shopify product is created on the fly (label barcode = unit code).
     * Accepts IMEI or any serial (MacBooks etc.), so validation is looser.
     */
    public static function intake_custom(array $in): array {
        global $wpdb;
        $serial = strtoupper(preg_replace('/\s+/', '', (string) ($in['imei'] ?? '')));
        if (strlen($serial) < 5) throw new InvalidArgumentException('Enter the IMEI or serial number.');
        $dupe = $wpdb->get_var($wpdb->prepare(
            "SELECT unit_code FROM {$wpdb->prefix}wbam_units WHERE imei=%s AND status IN ('in_stock','reserved')", $serial
        ));
        if ($dupe) throw new RuntimeException("This IMEI/serial is already in stock as $dupe.");

        $branch = WBAM_Settings::branch((int) $in['branch_id']);
        if (!$branch || !$branch['shopify_location_id']) throw new RuntimeException('Branch is not linked to a Shopify location.');

        $title = sanitize_text_field(trim((string) ($in['title'] ?? '')));
        if ($title === '') throw new InvalidArgumentException('Enter the device name.');
        $grade = sanitize_text_field((string) ($in['grade'] ?? '')) ?: 'Used (B - Very Good)';
        $sell  = (float) ($in['sell_price'] ?? 0);
        if ($sell <= 0) throw new InvalidArgumentException('Enter the selling price.');
        $paid  = (float) ($in['purchase_price'] ?? 0);

        $now = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}wbam_units", [
            'unit_code'      => '',
            'imei'           => $serial,
            'model_title'    => $title,
            'variant_title'  => $grade,
            'grade'          => $grade,
            'branch_id'      => (int) $in['branch_id'],
            'status'         => 'in_stock',
            'purchase_price' => $paid,
            'source'         => in_array($in['source'] ?? '', ['buyback', 'tradein', 'supplier'], true) ? $in['source'] : 'buyback',
            'source_ref'     => sanitize_text_field($in['source_ref'] ?? ''),
            'payout_method'  => sanitize_text_field($in['payout_method'] ?? ''),
            'battery_health' => isset($in['battery_health']) && $in['battery_health'] !== '' ? (int) $in['battery_health'] : null,
            'notes'          => 'custom intake',
            'created_by'     => get_current_user_id(),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $id   = (int) $wpdb->insert_id;
        $code = 'U' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);

        $prod = WBAM_Catalog::create_custom_product($title, $grade, $sell, $paid, $code, (int) $branch['shopify_location_id']);
        $wpdb->update("{$wpdb->prefix}wbam_units", [
            'unit_code'         => $code,
            'product_id'        => $prod['product_id'],
            'variant_id'        => $prod['variant_id'],
            'inventory_item_id' => $prod['inventory_item_id'],
            'sku'               => $code,
            'pool_barcode'      => $code,
        ], ['id' => $id]);
        self::event($id, 'intake_custom', sprintf('%s (%s) @ %s, paid £%.2f, asking £%.2f', $title, $grade, $branch['name'], $paid, $sell));

        if (in_array($in['source'] ?? '', ['buyback', 'tradein'], true) && $paid > 0) {
            $wpdb->insert("{$wpdb->prefix}wbam_payouts", [
                'branch_id' => (int) $in['branch_id'],
                'unit_id'   => $id,
                'amount'    => $paid,
                'method'    => sanitize_text_field($in['payout_method'] ?: 'cash'),
                'reference' => sanitize_text_field($in['source_ref'] ?? ''),
                'user_id'   => get_current_user_id(),
                'created_at'=> $now,
            ]);
        }
        return self::get($id);
    }

    /** Tie a trade-in unit to the sale order it arrived with (via the £0 cart marker). */
    public static function link_tradein(string $unit_code, int $order_id, string $order_name): void {
        global $wpdb;
        $u = self::by_code($unit_code);
        if (!$u) return;
        $ref = trim($order_name) !== '' ? trim($order_name) : ('order #' . $order_id);
        if ($u['source_ref'] === $ref) return; // already linked
        $wpdb->update("{$wpdb->prefix}wbam_units",
            ['source_ref' => $ref, 'updated_at' => current_time('mysql')], ['id' => (int) $u['id']]);
        self::event((int) $u['id'], 'tradein_linked', "traded in against $ref");
    }

    /* ---------------- lifecycle ---------------- */

    public static function get(int $id): ?array {
        global $wpdb;
        $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_units WHERE id=%d", $id), ARRAY_A);
        if ($u) $u['label_url'] = WBAM_Labels::url($id);
        return $u ?: null;
    }

    public static function by_code(string $code): ?array {
        global $wpdb;
        $u = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_units WHERE unit_code=%s OR imei=%s", $code, preg_replace('/\D/', '', $code)
        ), ARRAY_A);
        return $u ?: null;
    }

    public static function event(int $unit_id, string $event, string $detail = ''): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wbam_unit_events", [
            'unit_id' => $unit_id, 'event' => $event, 'detail' => $detail,
            'user_id' => get_current_user_id(), 'created_at' => current_time('mysql'),
        ]);
    }

    public static function mark_sold(int $unit_id, int $order_id, string $order_name, float $sale_price, ?int $order_line_row = null): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}wbam_units", [
            'status' => 'sold', 'order_id' => $order_id, 'order_name' => $order_name,
            'sale_price' => $sale_price, 'sold_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ], ['id' => $unit_id]);
        if ($order_line_row) {
            $u = self::get($unit_id);
            $wpdb->update("{$wpdb->prefix}wbam_order_lines",
                ['unit_id' => $unit_id, 'cogs' => $u['purchase_price']],
                ['id' => $order_line_row]);
        }
        self::event($unit_id, 'sold', "$order_name @ £" . number_format($sale_price, 2));
        $u = self::get($unit_id);
        try { WBAM_Catalog::refresh_variant_cost((int) $u['variant_id'], (int) $u['inventory_item_id']); } catch (Throwable $e) {}

        // Stamp the sold unit's IMEI onto the order's Additional details —
        // replaces the manual note staff used to type on every phone sale.
        $attrs = ['Sold ' . $u['unit_code'] => trim($u['model_title'] . ' ' . $u['variant_title']) . ' — IMEI ' . $u['imei']];
        try {
            WBAM_Catalog::append_order_attributes($order_id, $attrs);
        } catch (Throwable $e) {
            WBAM_Sync::queue('order_attrs', ['order_id' => $order_id, 'attrs' => $attrs], $e->getMessage());
        }
    }

    /**
     * Called from the order webhook: try to attach exact units to used-phone lines.
     * One matching in-stock unit at that branch → auto-attach. Otherwise the line
     * appears in the Reconcile screen for a one-scan confirm.
     */
    public static function sell_reconcile(int $order_id): void {
        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_orders WHERE order_id=%d", $order_id), ARRAY_A);
        if (!$order) return;
        $branch = WBAM_Settings::branch_by_location((int) $order['location_id']);
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_order_lines WHERE order_id=%d AND unit_id IS NULL AND kind='product'", $order_id
        ), ARRAY_A);
        foreach ($lines as $line) {
            if (!$line['variant_id']) continue;
            $where = $branch ? $wpdb->prepare("AND branch_id=%d", $branch['id']) : '';
            $units = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wbam_units WHERE variant_id=%d AND status='in_stock' $where ORDER BY created_at ASC",
                $line['variant_id']
            ), ARRAY_A);
            if (!$units) continue; // not a serialized item (accessory/new) — nothing to do
            if (count($units) === 1 && (int) $line['qty'] === 1) {
                self::mark_sold((int) $units[0]['id'], $order_id, $order['name'], (float) $line['price'], (int) $line['id']);
            }
            // >1 candidates (or qty>1): leave for the Reconcile screen.
        }
    }

    public static function pending_reconcile(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT l.*, o.name AS order_name, o.processed_at, o.location_id
             FROM {$wpdb->prefix}wbam_order_lines l
             JOIN {$wpdb->prefix}wbam_orders o ON o.order_id=l.order_id
             WHERE l.unit_id IS NULL AND l.kind='product'
               AND l.variant_id IN (SELECT DISTINCT variant_id FROM {$wpdb->prefix}wbam_units WHERE status='in_stock')
             ORDER BY o.processed_at DESC LIMIT 100", ARRAY_A) ?: [];
    }

    public static function return_to_stock(int $unit_id, string $reason = ''): void {
        global $wpdb;
        $u = self::get($unit_id);
        if (!$u || $u['status'] !== 'sold') throw new RuntimeException('Unit is not sold.');
        $wpdb->update("{$wpdb->prefix}wbam_units",
            ['status' => 'in_stock', 'order_id' => null, 'order_name' => '', 'sale_price' => null, 'sold_at' => null, 'updated_at' => current_time('mysql')],
            ['id' => $unit_id]);
        $branch = WBAM_Settings::branch((int) $u['branch_id']);
        WBAM_Catalog::adjust_inventory((int) $u['inventory_item_id'], (int) $branch['shopify_location_id'], +1, 'restock');
        self::event($unit_id, 'returned', $reason);
    }

    public static function write_off(int $unit_id, string $reason): void {
        global $wpdb;
        $u = self::get($unit_id);
        if (!$u) throw new RuntimeException('Unknown unit.');
        $was_in_stock = $u['status'] === 'in_stock';
        $wpdb->update("{$wpdb->prefix}wbam_units",
            ['status' => 'written_off', 'updated_at' => current_time('mysql')], ['id' => $unit_id]);
        if ($was_in_stock) {
            $branch = WBAM_Settings::branch((int) $u['branch_id']);
            WBAM_Catalog::adjust_inventory((int) $u['inventory_item_id'], (int) $branch['shopify_location_id'], -1, 'damaged');
        }
        self::event($unit_id, 'write_off', $reason);
    }

    public static function transfer(int $unit_id, int $to_branch_id): void {
        global $wpdb;
        $u = self::get($unit_id);
        if (!$u || $u['status'] !== 'in_stock') throw new RuntimeException('Only in-stock units can transfer.');
        $from = WBAM_Settings::branch((int) $u['branch_id']);
        $to   = WBAM_Settings::branch($to_branch_id);
        if (!$to) throw new RuntimeException('Unknown destination branch.');
        WBAM_Catalog::adjust_inventory((int) $u['inventory_item_id'], (int) $from['shopify_location_id'], -1, 'correction');
        WBAM_Catalog::adjust_inventory((int) $u['inventory_item_id'], (int) $to['shopify_location_id'], +1, 'correction');
        $wpdb->update("{$wpdb->prefix}wbam_units",
            ['branch_id' => $to_branch_id, 'updated_at' => current_time('mysql')], ['id' => $unit_id]);
        self::event($unit_id, 'transfer', "{$from['name']} → {$to['name']}");
    }

    /* ---------------- stocktake ---------------- */

    /**
     * Compare scanned unit codes/IMEIs against DB in-stock at a branch.
     * Returns [matched, missing(units in DB not scanned), unknown(scans with no unit)].
     */
    public static function stocktake_diff(int $branch_id, array $scans): array {
        global $wpdb;
        $scans   = array_values(array_unique(array_filter(array_map('trim', $scans))));
        $in_db   = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_units WHERE branch_id=%d AND status='in_stock'", $branch_id
        ), ARRAY_A);
        $by_code = [];
        foreach ($in_db as $u) {
            $by_code[$u['unit_code']] = $u;
            $by_code[$u['imei']] = $u;
        }
        $matched = $unknown = [];
        $seen_ids = [];
        foreach ($scans as $s) {
            $key = preg_match('/^\d{14,16}$/', $s) ? $s : strtoupper($s);
            if (isset($by_code[$key])) {
                $u = $by_code[$key];
                if (!isset($seen_ids[$u['id']])) { $matched[] = $u; $seen_ids[$u['id']] = 1; }
            } else {
                $unknown[] = $s;
            }
        }
        $missing = array_values(array_filter($in_db, fn($u) => !isset($seen_ids[$u['id']])));
        return ['matched' => $matched, 'missing' => $missing, 'unknown' => $unknown];
    }

    /** After a stocktake, push absolute pooled counts for this branch's serialized variants to Shopify. */
    public static function stocktake_push(int $branch_id): int {
        global $wpdb;
        $branch = WBAM_Settings::branch($branch_id);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT variant_id, inventory_item_id, COUNT(*) AS n
             FROM {$wpdb->prefix}wbam_units WHERE branch_id=%d AND status='in_stock'
             GROUP BY variant_id, inventory_item_id", $branch_id
        ), ARRAY_A);
        $quantities = array_map(fn($r) => [
            'inventory_item_id' => (int) $r['inventory_item_id'],
            'location_id'       => (int) $branch['shopify_location_id'],
            'quantity'          => (int) $r['n'],
        ], $rows);
        foreach (array_chunk($quantities, 200) as $chunk) WBAM_Catalog::set_inventory($chunk);
        return count($quantities);
    }

    /* ---------------- queries ---------------- */

    public static function search(array $f): array {
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        if (!empty($f['status']))   { $where[] = 'status=%s';    $args[] = $f['status']; }
        if (!empty($f['branch_id'])){ $where[] = 'branch_id=%d'; $args[] = (int) $f['branch_id']; }
        if (!empty($f['q'])) {
            $where[] = '(imei LIKE %s OR unit_code LIKE %s OR model_title LIKE %s OR variant_title LIKE %s OR sku LIKE %s)';
            $like = '%' . $wpdb->esc_like($f['q']) . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT * FROM {$wpdb->prefix}wbam_units WHERE " . implode(' AND ', $where)
             . " ORDER BY created_at DESC LIMIT 200";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: [];
    }

    public static function aging(int $days = 45): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_units
             WHERE status='in_stock' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY created_at ASC LIMIT 200", $days), ARRAY_A) ?: [];
    }
}
