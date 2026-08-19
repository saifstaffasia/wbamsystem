<?php
if (!defined('ABSPATH')) exit;

/**
 * Order warehouse — every order lands here (webhook first, pull as sweep).
 * Reports read ONLY from these tables, so they are instant.
 */
class WBAM_Warehouse {

    /** Upsert from a REST order payload (webhook body or /orders.json item). */
    public static function upsert_order(array $o, bool $fetch_transactions = true): void {
        global $wpdb;
        $order_id = (int) ($o['id'] ?? 0);
        if (!$order_id) return;

        $user_id = (int) ($o['user_id'] ?? 0);
        WBAM_Settings::touch_staff($user_id);
        WBAM_Settings::auto_label_staff($user_id, $order_id);

        $customer = '';
        if (!empty($o['customer'])) {
            $customer = trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''));
        }

        $wpdb->replace("{$wpdb->prefix}wbam_orders", [
            'order_id'         => $order_id,
            'name'             => (string) ($o['name'] ?? ''),
            'processed_at'     => self::dt($o['processed_at'] ?? $o['created_at'] ?? null),
            'source_name'      => (string) ($o['source_name'] ?? ''),
            'location_id'      => (int) ($o['location_id'] ?? 0),
            'user_id'          => $user_id,
            'customer'         => $customer,
            'currency'         => (string) ($o['currency'] ?? 'GBP'),
            'subtotal'         => (float) ($o['subtotal_price'] ?? 0),
            'discounts'        => (float) ($o['total_discounts'] ?? 0),
            'tax'              => (float) ($o['total_tax'] ?? 0),
            'total'            => (float) ($o['total_price'] ?? 0),
            'refunded'         => self::refunded_total($o),
            'financial_status' => (string) ($o['financial_status'] ?? ''),
            'cancelled'        => !empty($o['cancelled_at']) ? 1 : 0,
            'test'             => !empty($o['test']) ? 1 : 0,
            'synced_at'        => current_time('mysql'),
        ]);

        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $kind = 'product';
            $ticket_id = null;
            $li_title = (string) ($li['title'] ?? '');
            // Trade-in markers (£0 lines from the POS tile) tie the traded-in unit to this sale.
            if (stripos($li_title, 'trade-in') === 0 && preg_match('/\b(U\d{4,})\b/', $li_title, $tm)) {
                $kind = 'tradein_marker';
                try { WBAM_Units::link_tradein($tm[1], $order_id, (string) ($o['name'] ?? '')); } catch (Throwable $e) {}
            }
            // Repair lines (from the POS tile / Hub drafts) carry "Repair … T-0042" in the title.
            elseif (stripos($li_title, 'repair') !== false && preg_match('/\bT[-\s]?0*(\d{1,6})\b/i', $li_title, $m)) {
                $ticket_id = (int) $m[1];
                $kind = stripos($li_title, 'deposit') !== false ? 'repair_deposit' : 'repair_balance';
            }
            $line_total = (float) ($li['price'] ?? 0) * (int) ($li['quantity'] ?? 1);
            foreach ((array) ($li['discount_allocations'] ?? []) as $da) {
                $line_total -= (float) ($da['amount'] ?? 0);
            }
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, unit_id, cogs FROM {$wpdb->prefix}wbam_order_lines WHERE order_id=%d AND line_id=%d",
                $order_id, (int) ($li['id'] ?? 0)
            ), ARRAY_A);
            $row = [
                'order_id'   => $order_id,
                'line_id'    => (int) ($li['id'] ?? 0),
                'product_id' => (int) ($li['product_id'] ?? 0),
                'variant_id' => (int) ($li['variant_id'] ?? 0),
                'sku'        => (string) ($li['sku'] ?? ''),
                'title'      => mb_substr((string) ($li['title'] ?? '') . (empty($li['variant_title']) ? '' : ' — ' . $li['variant_title']), 0, 255),
                'qty'        => (int) ($li['quantity'] ?? 1),
                'price'      => (float) ($li['price'] ?? 0),
                'total'      => $line_total,
                'kind'       => $kind,
                'ticket_id'  => $ticket_id,
            ];
            if ($existing) {
                $wpdb->update("{$wpdb->prefix}wbam_order_lines", $row, ['id' => $existing['id']]);
            } else {
                $wpdb->insert("{$wpdb->prefix}wbam_order_lines", $row);
            }
            if ($ticket_id) {
                WBAM_Tickets::attach_payment($ticket_id, $order_id, $kind, $line_total);
            }
        }

        // Trade-in markers can also arrive as cart attributes (more reliable than the £0 line).
        foreach ((array) ($o['note_attributes'] ?? []) as $na) {
            $k = (string) ($na['name'] ?? '');
            $v = (string) ($na['value'] ?? '');
            if (stripos($k, 'trade-in') === 0 && preg_match('/\b(U\d{4,})\b/', $k . ' ' . $v, $tm)) {
                try { WBAM_Units::link_tradein($tm[1], $order_id, (string) ($o['name'] ?? '')); } catch (Throwable $e) {}
            }
        }

        if ($fetch_transactions) {
            try { self::pull_transactions($order_id); }
            catch (Throwable $e) { WBAM_Sync::queue('pull_transactions', ['order_id' => $order_id], $e->getMessage()); }
        }
    }

    /** Tender rows (cash / card / Trade In / …) from the transactions endpoint. */
    public static function pull_transactions(int $order_id): void {
        global $wpdb;
        [$data] = WBAM_Shopify::i()->rest('GET', "/orders/{$order_id}/transactions.json");
        foreach ((array) ($data['transactions'] ?? []) as $t) {
            if (($t['status'] ?? '') !== 'success') continue;
            if (!in_array(($t['kind'] ?? ''), ['sale', 'capture', 'refund'], true)) continue;
            $amount = (float) ($t['amount'] ?? 0);
            if (($t['kind'] ?? '') === 'refund') $amount = -$amount;
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}wbam_tenders (order_id, txn_id, gateway, kind, amount, processed_at)
                 VALUES (%d,%d,%s,%s,%f,%s)
                 ON DUPLICATE KEY UPDATE gateway=VALUES(gateway), kind=VALUES(kind), amount=VALUES(amount), processed_at=VALUES(processed_at)",
                $order_id, (int) $t['id'], (string) ($t['gateway'] ?? ''), (string) $t['kind'],
                $amount, self::dt($t['processed_at'] ?? null)
            ));
        }
    }

    private static function refunded_total(array $o): float {
        $sum = 0.0;
        foreach ((array) ($o['refunds'] ?? []) as $r) {
            foreach ((array) ($r['transactions'] ?? []) as $t) {
                if (($t['kind'] ?? '') === 'refund' && ($t['status'] ?? '') === 'success') {
                    $sum += (float) ($t['amount'] ?? 0);
                }
            }
        }
        return $sum;
    }

    /** Pull a date range via REST (sweep / backfill / "Refresh" button). Returns count. */
    public static function pull_range(string $from_iso, ?string $to_iso = null): int {
        $count = 0;
        $path = '/orders.json?status=any&limit=100&processed_at_min=' . rawurlencode($from_iso)
              . ($to_iso ? '&processed_at_max=' . rawurlencode($to_iso) : '');
        while ($path) {
            [$data, $headers] = WBAM_Shopify::i()->rest('GET', $path);
            foreach ((array) ($data['orders'] ?? []) as $o) {
                self::upsert_order($o);
                WBAM_Units::sell_reconcile((int) $o['id']);
                $count++;
            }
            $next = WBAM_Shopify::next_page_info($headers);
            $path = $next ? "/orders.json?limit=100&page_info={$next}" : null;
        }
        WBAM_Settings::state_set('last_pull', gmdate('c'));
        return $count;
    }

    /** Fill accessory COGS from Shopify unit costs where we have none. */
    public static function backfill_costs(int $days = 2): int {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT l.variant_id FROM {$wpdb->prefix}wbam_order_lines l
             JOIN {$wpdb->prefix}wbam_orders o ON o.order_id=l.order_id
             WHERE l.cogs IS NULL AND l.unit_id IS NULL AND l.variant_id>0 AND l.kind='product'
               AND o.processed_at > DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT 500", $days
        ), ARRAY_A);
        if (!$rows) return 0;
        $costs = WBAM_Catalog::fetch_costs(array_column($rows, 'variant_id'));
        $n = 0;
        foreach ($costs as $variant_id => $cost) {
            if ($cost === null) continue;
            $n += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}wbam_order_lines SET cogs=%f
                 WHERE variant_id=%d AND cogs IS NULL AND unit_id IS NULL AND kind='product'",
                $cost, $variant_id
            ));
        }
        return $n;
    }

    private static function dt(?string $iso): ?string {
        if (!$iso) return null;
        try {
            $d = new DateTime($iso);
            $d->setTimezone(wp_timezone());
            return $d->format('Y-m-d H:i:s');
        } catch (Throwable $e) { return null; }
    }
}
