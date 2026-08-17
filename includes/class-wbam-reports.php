<?php
if (!defined('ABSPATH')) exit;

/**
 * Reporting off the local warehouse — instant, no API calls at view time.
 * Ranges: today / yesterday / wtd / mtd / qtd / ytd / custom.
 */
class WBAM_Reports {

    public static function range_bounds(string $range, string $from = '', string $to = ''): array {
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $start = new DateTime('today', $tz);
        $end = (clone $now);
        switch ($range) {
            case 'today': break;
            case 'yesterday':
                $start->modify('-1 day');
                $end = (clone $start)->setTime(23, 59, 59);
                break;
            case 'wtd': $start->modify('monday this week'); break;
            case 'mtd': $start->modify('first day of this month'); break;
            case 'qtd':
                $q = (int) floor(((int) $now->format('n') - 1) / 3) * 3 + 1;
                $start = new DateTime($now->format('Y') . '-' . str_pad((string) $q, 2, '0', STR_PAD_LEFT) . '-01', $tz);
                break;
            case 'ytd': $start = new DateTime($now->format('Y') . '-01-01', $tz); break;
            case 'custom':
                $start = new DateTime($from ?: 'today', $tz);
                $end = new DateTime(($to ?: 'now') . ' 23:59:59', $tz);
                break;
        }
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** The full report payload for a range (+ optional branch/location filter). */
    public static function build(string $range, int $branch_id = 0, string $from = '', string $to = ''): array {
        global $wpdb;
        [$a, $b] = self::range_bounds($range, $from, $to);
        $p = $wpdb->prefix;

        $loc = 0;
        if ($branch_id && ($br = WBAM_Settings::branch($branch_id))) $loc = (int) $br['shopify_location_id'];
        $locSql = $loc ? $wpdb->prepare(' AND o.location_id=%d', $loc) : '';

        $base = "FROM {$p}wbam_orders o WHERE o.processed_at BETWEEN %s AND %s AND o.cancelled=0 AND o.test=0" . $locSql;

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) orders_n, COALESCE(SUM(o.total),0) gross, COALESCE(SUM(o.refunded),0) refunded,
                    COALESCE(SUM(o.tax),0) tax, COALESCE(SUM(o.discounts),0) discounts
             $base", $a, $b), ARRAY_A);

        $lineagg = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(l.total),0) line_rev,
                    COALESCE(SUM(CASE WHEN l.cogs IS NOT NULL THEN l.cogs*l.qty END),0) cogs,
                    SUM(CASE WHEN l.cogs IS NULL AND l.kind='product' THEN 1 ELSE 0 END) untracked
             FROM {$p}wbam_order_lines l JOIN {$p}wbam_orders o ON o.order_id=l.order_id
             WHERE o.processed_at BETWEEN %s AND %s AND o.cancelled=0 AND o.test=0" . $locSql, $a, $b), ARRAY_A);

        $tenders = $wpdb->get_results($wpdb->prepare(
            "SELECT t.gateway, COALESCE(SUM(t.amount),0) amount
             FROM {$p}wbam_tenders t JOIN {$p}wbam_orders o ON o.order_id=t.order_id
             WHERE o.processed_at BETWEEN %s AND %s AND o.cancelled=0 AND o.test=0" . $locSql .
            ' GROUP BY t.gateway ORDER BY amount DESC', $a, $b), ARRAY_A);
        $buckets = [];
        foreach ($tenders as $t) {
            $bk = WBAM_Settings::tender_bucket($t['gateway']);
            $buckets[$bk] = ($buckets[$bk] ?? 0) + (float) $t['amount'];
        }
        arsort($buckets);

        $staff = $wpdb->get_results($wpdb->prepare(
            "SELECT o.user_id, COUNT(DISTINCT o.order_id) orders_n, COALESCE(SUM(o.total),0) sales
             $base GROUP BY o.user_id ORDER BY sales DESC", $a, $b), ARRAY_A);
        $staff_gp = $wpdb->get_results($wpdb->prepare(
            "SELECT o.user_id,
                    COALESCE(SUM(l.total),0) rev,
                    COALESCE(SUM(CASE WHEN l.cogs IS NOT NULL THEN l.cogs*l.qty END),0) cogs
             FROM {$p}wbam_order_lines l JOIN {$p}wbam_orders o ON o.order_id=l.order_id
             WHERE o.processed_at BETWEEN %s AND %s AND o.cancelled=0 AND o.test=0" . $locSql .
            ' GROUP BY o.user_id', $a, $b), ARRAY_A);
        $gp_by_user = [];
        foreach ($staff_gp as $r) $gp_by_user[(int) $r['user_id']] = (float) $r['rev'] - (float) $r['cogs'];
        foreach ($staff as &$s) {
            $s['label'] = WBAM_Settings::staff_label((int) $s['user_id']);
            $s['gp']    = round($gp_by_user[(int) $s['user_id']] ?? 0, 2);
        }
        unset($s);

        $branches = $wpdb->get_results($wpdb->prepare(
            "SELECT o.location_id, COUNT(*) orders_n, COALESCE(SUM(o.total),0) sales
             $base GROUP BY o.location_id ORDER BY sales DESC", $a, $b), ARRAY_A);
        foreach ($branches as &$brw) {
            $bb = WBAM_Settings::branch_by_location((int) $brw['location_id']);
            $brw['label'] = $bb ? $bb['name'] : ('Location ' . $brw['location_id']);
        }
        unset($brw);

        $top = $wpdb->get_results($wpdb->prepare(
            "SELECT l.title, SUM(l.qty) qty, COALESCE(SUM(l.total),0) rev
             FROM {$p}wbam_order_lines l JOIN {$p}wbam_orders o ON o.order_id=l.order_id
             WHERE o.processed_at BETWEEN %s AND %s AND o.cancelled=0 AND o.test=0 AND l.kind='product'" . $locSql .
            ' GROUP BY l.title ORDER BY rev DESC LIMIT 10', $a, $b), ARRAY_A);

        $brSql = $branch_id ? $wpdb->prepare(' AND branch_id=%d', $branch_id) : '';
        $buyback = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) n, COALESCE(SUM(amount),0) spend,
                    COALESCE(SUM(CASE WHEN method='cash' THEN amount END),0) cash_spend
             FROM {$p}wbam_payouts WHERE created_at BETWEEN %s AND %s" . $brSql, $a, $b), ARRAY_A);

        $repairs = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.kind IN ('repair_deposit','repair_balance') THEN l.total END),0) revenue
             FROM {$p}wbam_order_lines l JOIN {$p}wbam_orders o ON o.order_id=l.order_id
             WHERE o.processed_at BETWEEN %s AND %s AND o.cancelled=0 AND o.test=0" . $locSql, $a, $b), ARRAY_A);
        $repair_parts = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tp.unit_cost*tp.qty),0) FROM {$p}wbam_ticket_parts tp
             JOIN {$p}wbam_tickets t ON t.id=tp.ticket_id
             WHERE tp.created_at BETWEEN %s AND %s" . ($branch_id ? $wpdb->prepare(' AND t.branch_id=%d', $branch_id) : ''), $a, $b));
        $intake_n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}wbam_units WHERE created_at BETWEEN %s AND %s" . $brSql, $a, $b));

        $gross = (float) $totals['gross'];
        $refunded = (float) $totals['refunded'];
        $cogs = (float) $lineagg['cogs'];
        $rev  = (float) $lineagg['line_rev'];
        $gp   = $rev - $cogs;

        return [
            'range'   => $range, 'from' => $a, 'to' => $b, 'branch_id' => $branch_id,
            'totals'  => [
                'orders'    => (int) $totals['orders_n'],
                'gross'     => round($gross, 2),
                'refunded'  => round($refunded, 2),
                'net'       => round($gross - $refunded, 2),
                'tax'       => round((float) $totals['tax'], 2),
                'discounts' => round((float) $totals['discounts'], 2),
                'cogs'      => round($cogs, 2),
                'gp'        => round($gp, 2),
                'gp_pct'    => $rev > 0 ? round($gp / $rev * 100, 1) : null,
                'untracked_cost_lines' => (int) $lineagg['untracked'],
            ],
            'tenders' => $buckets,
            'staff'   => $staff,
            'branches'=> $branches,
            'top'     => $top,
            'buyback' => [
                'count' => (int) ($buyback['n'] ?? 0),
                'spend' => round((float) ($buyback['spend'] ?? 0), 2),
                'cash'  => round((float) ($buyback['cash_spend'] ?? 0), 2),
                'intake_units' => $intake_n,
            ],
            'repairs' => [
                'revenue'    => round((float) ($repairs['revenue'] ?? 0), 2),
                'parts_cost' => round($repair_parts, 2),
            ],
            'generated_at' => current_time('mysql'),
        ];
    }

    /* ---------------- rendering ---------------- */

    public static function html(array $r): string {
        $money = fn($v) => '£' . number_format((float) $v, 2);
        $t = $r['totals'];
        ob_start(); ?>
        <div class="wbam-report">
          <div class="wbam-tiles">
            <div class="tile"><span>Sales</span><b><?php echo $money($t['gross']); ?></b><small><?php echo (int) $t['orders']; ?> orders</small></div>
            <div class="tile"><span>Refunds</span><b><?php echo $money($t['refunded']); ?></b><small>Net <?php echo $money($t['net']); ?></small></div>
            <div class="tile"><span>Gross profit</span><b><?php echo $money($t['gp']); ?></b><small><?php echo $t['gp_pct'] !== null ? $t['gp_pct'] . '%' : '—'; ?><?php echo $t['untracked_cost_lines'] ? ' · ' . (int) $t['untracked_cost_lines'] . ' lines w/o cost' : ''; ?></small></div>
            <div class="tile"><span>Buy-ins</span><b><?php echo $money($r['buyback']['spend']); ?></b><small><?php echo (int) $r['buyback']['intake_units']; ?> device(s) · cash <?php echo $money($r['buyback']['cash']); ?></small></div>
            <div class="tile"><span>Repairs revenue</span><b><?php echo $money($r['repairs']['revenue']); ?></b><small>parts <?php echo $money($r['repairs']['parts_cost']); ?></small></div>
          </div>

          <div class="wbam-cols">
            <div>
              <h3>Payments</h3>
              <table class="wbam-t"><tbody>
                <?php foreach ($r['tenders'] as $bucket => $amount): ?>
                  <tr><td><?php echo esc_html($bucket); ?></td><td class="r"><?php echo $money($amount); ?></td></tr>
                <?php endforeach; if (!$r['tenders']) echo '<tr><td colspan="2">No payments in range.</td></tr>'; ?>
              </tbody></table>

              <h3>Staff</h3>
              <table class="wbam-t"><thead><tr><th>Staff</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">GP</th></tr></thead><tbody>
                <?php foreach ($r['staff'] as $s): ?>
                  <tr><td><?php echo esc_html($s['label']); ?></td><td class="r"><?php echo (int) $s['orders_n']; ?></td>
                      <td class="r"><?php echo $money($s['sales']); ?></td><td class="r"><?php echo $money($s['gp']); ?></td></tr>
                <?php endforeach; if (!$r['staff']) echo '<tr><td colspan="4">Nothing yet.</td></tr>'; ?>
              </tbody></table>
            </div>
            <div>
              <?php if (count($r['branches']) > 1): ?>
              <h3>Branches</h3>
              <table class="wbam-t"><tbody>
                <?php foreach ($r['branches'] as $brw): ?>
                  <tr><td><?php echo esc_html($brw['label']); ?></td><td class="r"><?php echo (int) $brw['orders_n']; ?> orders</td><td class="r"><?php echo $money($brw['sales']); ?></td></tr>
                <?php endforeach; ?>
              </tbody></table>
              <?php endif; ?>

              <h3>Top sellers</h3>
              <table class="wbam-t"><tbody>
                <?php foreach ($r['top'] as $row): ?>
                  <tr><td><?php echo esc_html(mb_substr($row['title'], 0, 48)); ?></td><td class="r">×<?php echo (int) $row['qty']; ?></td><td class="r"><?php echo $money($row['rev']); ?></td></tr>
                <?php endforeach; if (!$r['top']) echo '<tr><td colspan="3">Nothing yet.</td></tr>'; ?>
              </tbody></table>
            </div>
          </div>
          <p class="wbam-foot">Generated <?php echo esc_html($r['generated_at']); ?> · figures include VAT where prices do · GP = line revenue − recorded costs (operational, not a VAT margin-scheme calculation).</p>
        </div>
        <?php return (string) ob_get_clean();
    }

    /** [wbam_report] — the POS-side page. Login + wbam_reports capability required. */
    public static function shortcode($atts = []): string {
        if (!is_user_logged_in() || !current_user_can('wbam_reports')) {
            return '<p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to view the store report.</p>';
        }
        $range  = sanitize_key($_GET['range'] ?? 'today');
        $branch = (int) ($_GET['branch'] ?? 0);
        $r = self::build($range ?: 'today', $branch);
        $nonce = wp_create_nonce('wp_rest');
        $tabs = '';
        foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'wtd' => 'Week', 'mtd' => 'Month', 'qtd' => 'Quarter', 'ytd' => 'Year'] as $k => $label) {
            $url = esc_url(add_query_arg(['range' => $k, 'branch' => $branch]));
            $cls = $k === $range ? 'on' : '';
            $tabs .= "<a class=\"$cls\" href=\"$url\">$label</a>";
        }
        $sel = '<select onchange="location=\'' . esc_js(add_query_arg(['range' => $range, 'branch' => ''])) . '\'+this.value">'
             . '<option value="0">All branches</option>';
        foreach (WBAM_Settings::branches() as $b) {
            $sel .= '<option value="' . (int) $b['id'] . '"' . selected($branch, (int) $b['id'], false) . '>' . esc_html($b['name']) . '</option>';
        }
        $sel .= '</select>';
        $css = '<link rel="stylesheet" href="' . esc_url(WBAM_URL . 'assets/css/hub.css?v=' . WBAM_VER) . '">';
        $btn = '<button class="wbam-refresh" data-nonce="' . esc_attr($nonce) . '" data-rest="' . esc_url(rest_url('wbam/v1/report/refresh')) . '">↻ Refresh from Shopify</button>';
        $js  = '<script>document.addEventListener("click",function(e){var b=e.target.closest(".wbam-refresh");if(!b)return;b.disabled=true;b.textContent="Refreshing…";fetch(b.dataset.rest,{method:"POST",headers:{"X-WP-Nonce":b.dataset.nonce}}).then(function(){location.reload();}).catch(function(){b.textContent="Failed — try again";b.disabled=false;});});</script>';
        return $css . '<div class="wbam-wrap"><div class="wbam-bar"><nav class="wbam-tabs">' . $tabs . '</nav>' . $sel . $btn . '</div>' . self::html($r) . '</div>' . $js;
    }
}
