<?php
if (!defined('ABSPATH')) exit;

/**
 * Internal parts inventory (deliberately NOT Shopify products), vendors,
 * and purchase orders. Flow: tech opens a ticket → adds "parts needed"
 * (from stock, or to order) → PO per vendor → mark ordered (email vendor)
 * → receive → costs land on the ticket → repair margin is real.
 */
class WBAM_Parts {

    /* ---------------- vendors ---------------- */

    public static function save_vendor(array $in): int {
        global $wpdb;
        $row = [
            'name'  => sanitize_text_field($in['name'] ?? ''),
            'email' => sanitize_email($in['email'] ?? ''),
            'phone' => sanitize_text_field($in['phone'] ?? ''),
            'url'   => esc_url_raw($in['url'] ?? ''),
            'notes' => sanitize_textarea_field($in['notes'] ?? ''),
            'active'=> isset($in['active']) ? (int) !!$in['active'] : 1,
        ];
        if (!empty($in['id'])) {
            $wpdb->update("{$wpdb->prefix}wbam_vendors", $row, ['id' => (int) $in['id']]);
            return (int) $in['id'];
        }
        $wpdb->insert("{$wpdb->prefix}wbam_vendors", $row);
        return (int) $wpdb->insert_id;
    }

    public static function vendors(bool $active_only = true): array {
        global $wpdb;
        $w = $active_only ? 'WHERE active=1' : '';
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wbam_vendors $w ORDER BY name", ARRAY_A) ?: [];
    }

    /* ---------------- parts catalog + stock ---------------- */

    public static function save_part(array $in): int {
        global $wpdb;
        $row = [
            'sku'   => sanitize_text_field($in['sku'] ?? ''),
            'name'  => sanitize_text_field($in['name'] ?? ''),
            'compat'=> sanitize_text_field($in['compat'] ?? ''),
            'default_vendor_id' => (int) ($in['default_vendor_id'] ?? 0),
            'min_qty' => (int) ($in['min_qty'] ?? 0),
            'notes' => sanitize_textarea_field($in['notes'] ?? ''),
        ];
        if (!empty($in['id'])) {
            $wpdb->update("{$wpdb->prefix}wbam_parts", $row, ['id' => (int) $in['id']]);
            return (int) $in['id'];
        }
        $wpdb->insert("{$wpdb->prefix}wbam_parts", $row);
        return (int) $wpdb->insert_id;
    }

    public static function parts(string $q = ''): array {
        global $wpdb;
        $sql = "SELECT p.*,
                  (SELECT COALESCE(SUM(qty),0) FROM {$wpdb->prefix}wbam_part_stock s WHERE s.part_id=p.id) total_qty
                FROM {$wpdb->prefix}wbam_parts p WHERE p.active=1";
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $sql .= $wpdb->prepare(' AND (p.name LIKE %s OR p.sku LIKE %s OR p.compat LIKE %s)', $like, $like, $like);
        }
        return $wpdb->get_results($sql . ' ORDER BY p.name LIMIT 200', ARRAY_A) ?: [];
    }

    public static function stock_row(int $part_id, int $branch_id): array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_part_stock WHERE part_id=%d AND branch_id=%d", $part_id, $branch_id
        ), ARRAY_A);
        if (!$r) {
            $wpdb->insert("{$wpdb->prefix}wbam_part_stock", ['part_id' => $part_id, 'branch_id' => $branch_id, 'qty' => 0, 'avg_cost' => 0]);
            $r = ['id' => $wpdb->insert_id, 'part_id' => $part_id, 'branch_id' => $branch_id, 'qty' => 0, 'avg_cost' => 0];
        }
        return $r;
    }

    private static function stock_receive(int $part_id, int $branch_id, int $qty, float $unit_cost): void {
        global $wpdb;
        $s = self::stock_row($part_id, $branch_id);
        $new_qty = (int) $s['qty'] + $qty;
        $avg = $new_qty > 0
            ? (((int) $s['qty'] * (float) $s['avg_cost']) + ($qty * $unit_cost)) / $new_qty
            : $unit_cost;
        $wpdb->update("{$wpdb->prefix}wbam_part_stock",
            ['qty' => $new_qty, 'avg_cost' => round($avg, 2)], ['id' => $s['id']]);
    }

    /** Use a stocked part on a ticket (decrements stock at the ticket's branch). */
    public static function use_from_stock(int $ticket_id, int $part_id, int $qty = 1): void {
        global $wpdb;
        $t = WBAM_Tickets::get($ticket_id);
        if (!$t) throw new RuntimeException('Unknown ticket.');
        $s = self::stock_row($part_id, (int) $t['branch_id']);
        if ((int) $s['qty'] < $qty) throw new RuntimeException('Not enough stock at this branch.');
        $wpdb->update("{$wpdb->prefix}wbam_part_stock", ['qty' => (int) $s['qty'] - $qty], ['id' => $s['id']]);
        $part = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_parts WHERE id=%d", $part_id), ARRAY_A);
        $wpdb->insert("{$wpdb->prefix}wbam_ticket_parts", [
            'ticket_id' => $ticket_id, 'part_id' => $part_id, 'description' => $part['name'] ?? ('Part #' . $part_id),
            'qty' => $qty, 'unit_cost' => (float) $s['avg_cost'], 'source' => 'stock', 'created_at' => current_time('mysql'),
        ]);
        WBAM_Tickets::event($ticket_id, 'part_used', ($part['name'] ?? $part_id) . " ×$qty from stock");
    }

    /* ---------------- purchase orders ---------------- */

    /**
     * Create a PO. $lines: [{part_id?, ticket_id?, description, product_url?, qty, unit_cost}]
     * Tech workflow: from a ticket, "order parts" creates/append to a draft PO per vendor.
     */
    public static function create_po(int $vendor_id, int $branch_id, array $lines, string $notes = ''): int {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}wbam_purchase_orders", [
            'po_code' => '', 'vendor_id' => $vendor_id, 'branch_id' => $branch_id,
            'status' => 'draft', 'notes' => sanitize_textarea_field($notes),
            'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        $wpdb->update("{$wpdb->prefix}wbam_purchase_orders",
            ['po_code' => 'PO-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)], ['id' => $id]);
        foreach ($lines as $l) self::add_po_line($id, $l);
        return $id;
    }

    public static function add_po_line(int $po_id, array $l): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wbam_po_lines", [
            'po_id'       => $po_id,
            'part_id'     => !empty($l['part_id']) ? (int) $l['part_id'] : null,
            'ticket_id'   => !empty($l['ticket_id']) ? (int) $l['ticket_id'] : null,
            'description' => sanitize_text_field($l['description'] ?? ''),
            'product_url' => esc_url_raw($l['product_url'] ?? ''),
            'qty'         => max(1, (int) ($l['qty'] ?? 1)),
            'unit_cost'   => (float) ($l['unit_cost'] ?? 0),
        ]);
        if (!empty($l['ticket_id'])) {
            WBAM_Tickets::event((int) $l['ticket_id'], 'part_ordered', ($l['description'] ?? '') . ' (PO #' . $po_id . ')');
        }
    }

    public static function get_po(int $id): ?array {
        global $wpdb;
        $po = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_purchase_orders WHERE id=%d", $id), ARRAY_A);
        if (!$po) return null;
        $po['lines'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_po_lines WHERE po_id=%d", $id), ARRAY_A) ?: [];
        $po['vendor'] = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_vendors WHERE id=%d", (int) $po['vendor_id']), ARRAY_A);
        return $po;
    }

    public static function list_pos(array $f = []): array {
        global $wpdb;
        $where = ['1=1']; $args = [];
        if (!empty($f['status'])) { $where[] = 'p.status=%s'; $args[] = $f['status']; }
        $sql = "SELECT p.*, v.name vendor_name,
                  (SELECT COALESCE(SUM(qty*unit_cost),0) FROM {$wpdb->prefix}wbam_po_lines l WHERE l.po_id=p.id) subtotal
                FROM {$wpdb->prefix}wbam_purchase_orders p
                LEFT JOIN {$wpdb->prefix}wbam_vendors v ON v.id=p.vendor_id
                WHERE " . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 100';
        return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: [];
    }

    /**
     * Staff place the actual order on the supplier's website themselves.
     * This just records reality: save the prices paid per line, mark ordered.
     * (Direct supplier-site integration can slot in here later.)
     */
    public static function mark_ordered(int $po_id, array $costs = []): void {
        global $wpdb;
        $po = self::get_po($po_id);
        if (!$po || $po['status'] !== 'draft') throw new RuntimeException('PO not in draft.');
        foreach ($costs as $line_id => $cost) {
            if ($cost === '' || $cost === null) continue;
            $wpdb->update("{$wpdb->prefix}wbam_po_lines",
                ['unit_cost' => (float) $cost],
                ['id' => (int) $line_id, 'po_id' => $po_id]);
        }
        $wpdb->update("{$wpdb->prefix}wbam_purchase_orders",
            ['status' => 'ordered', 'ordered_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
            ['id' => $po_id]);
    }

    /**
     * Receive lines. $received: [po_line_id => qty]. Stocked parts go into
     * part_stock; ticket-bound lines go straight onto the ticket at cost.
     */
    public static function receive(int $po_id, array $received): void {
        global $wpdb;
        $po = self::get_po($po_id);
        if (!$po) throw new RuntimeException('Unknown PO.');
        foreach ($po['lines'] as $line) {
            $qty = (int) ($received[(int) $line['id']] ?? 0);
            if ($qty <= 0) continue;
            $qty = min($qty, (int) $line['qty'] - (int) $line['qty_received']);
            if ($qty <= 0) continue;
            $wpdb->update("{$wpdb->prefix}wbam_po_lines",
                ['qty_received' => (int) $line['qty_received'] + $qty], ['id' => $line['id']]);
            if (!empty($line['ticket_id'])) {
                $wpdb->insert("{$wpdb->prefix}wbam_ticket_parts", [
                    'ticket_id' => (int) $line['ticket_id'], 'part_id' => $line['part_id'] ?: null,
                    'po_line_id' => (int) $line['id'], 'description' => $line['description'],
                    'qty' => $qty, 'unit_cost' => (float) $line['unit_cost'], 'source' => 'po',
                    'created_at' => current_time('mysql'),
                ]);
                WBAM_Tickets::event((int) $line['ticket_id'], 'part_received', $line['description'] . " ×$qty");
            } elseif (!empty($line['part_id'])) {
                self::stock_receive((int) $line['part_id'], (int) $po['branch_id'], $qty, (float) $line['unit_cost']);
            }
        }
        // All received?
        $open = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wbam_po_lines WHERE po_id=%d AND qty_received < qty", $po_id));
        $wpdb->update("{$wpdb->prefix}wbam_purchase_orders", [
            'status' => $open ? 'partial' : 'received',
            'received_at' => $open ? null : current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $po_id]);
    }
}
