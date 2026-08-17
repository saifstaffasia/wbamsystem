<?php
if (!defined('ABSPATH')) exit;

/**
 * Sync orchestration: on-demand "Refresh" (the POS-side button), nightly sweep,
 * and a small retry queue so webhook hiccups never lose data.
 */
class WBAM_Sync {

    /** The "Refresh from Shopify" button — pulls today (store timezone). Fast. */
    public static function refresh_today(): int {
        $from = (new DateTime('today', wp_timezone()))->format('c');
        return WBAM_Warehouse::pull_range($from);
    }

    /** Nightly: sweep last 3 days (belt & braces vs missed webhooks), backfill costs, run queue. */
    public static function nightly(): void {
        try {
            $from = (new DateTime('-3 days', wp_timezone()))->format('c');
            WBAM_Warehouse::pull_range($from);
        } catch (Throwable $e) {
            self::queue('nightly_pull', [], $e->getMessage());
        }
        try { WBAM_Warehouse::backfill_costs(4); } catch (Throwable $e) {}
        self::run_queue();
        WBAM_Settings::state_set('last_nightly', gmdate('c'));
    }

    /** One-off history backfill (last N days ≤ 60 due to read_orders window). */
    public static function backfill(int $days = 59): int {
        $from = (new DateTime("-{$days} days", wp_timezone()))->format('c');
        $n = WBAM_Warehouse::pull_range($from);
        WBAM_Warehouse::backfill_costs($days + 1);
        return $n;
    }

    /* ---------------- retry queue ---------------- */

    public static function queue(string $task, array $payload, string $error = '', int $delay_min = 10): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wbam_queue", [
            'task'       => $task,
            'payload'    => wp_json_encode($payload),
            'attempts'   => 0,
            'last_error' => $error,
            'next_at'    => gmdate('Y-m-d H:i:s', time() + $delay_min * 60),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function run_queue(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wbam_queue WHERE next_at <= UTC_TIMESTAMP() AND attempts < 8 ORDER BY id LIMIT 25",
            ARRAY_A
        );
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true) ?: [];
            $ok = false;
            try {
                switch ($row['task']) {
                    case 'pull_transactions':
                        WBAM_Warehouse::pull_transactions((int) $payload['order_id']);
                        $ok = true; break;
                    case 'reupsert_order':
                        [$data] = WBAM_Shopify::i()->rest('GET', '/orders/' . (int) $payload['order_id'] . '.json');
                        if (!empty($data['order'])) WBAM_Warehouse::upsert_order($data['order']);
                        $ok = true; break;
                    case 'refresh_cost':
                        WBAM_Catalog::refresh_variant_cost((int) $payload['variant_id'], (int) $payload['inventory_item_id']);
                        $ok = true; break;
                    case 'webhook_replay':
                        WBAM_Warehouse::upsert_order((array) ($payload['payload'] ?? []));
                        WBAM_Units::sell_reconcile((int) ($payload['payload']['id'] ?? 0));
                        $ok = true; break;
                    case 'nightly_pull':
                        $from = (new DateTime('-3 days', wp_timezone()))->format('c');
                        WBAM_Warehouse::pull_range($from);
                        $ok = true; break;
                    case 'notify':
                        WBAM_Notify::run_task($payload);
                        $ok = true; break;
                    case 'publish_product':
                        WBAM_Catalog::publish_to_pos(WBAM_Shopify::gid('Product', (int) $payload['product_id']));
                        $ok = true; break;
                }
            } catch (Throwable $e) {
                $wpdb->update("{$wpdb->prefix}wbam_queue", [
                    'attempts'   => (int) $row['attempts'] + 1,
                    'last_error' => $e->getMessage(),
                    'next_at'    => gmdate('Y-m-d H:i:s', time() + min(120, 10 * (2 ** (int) $row['attempts'])) * 60),
                ], ['id' => $row['id']]);
                continue;
            }
            if ($ok) $wpdb->delete("{$wpdb->prefix}wbam_queue", ['id' => $row['id']]);
        }
    }
}
