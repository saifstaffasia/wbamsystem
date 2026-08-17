<?php
if (!defined('ABSPATH')) exit;

/**
 * WP-admin UI. Deliberately plain & fast: big touch targets, scan-first inputs.
 */
class WBAM_Admin {

    public static function menu(): void {
        add_menu_page('WBAM Hub', 'WBAM Hub', 'wbam_use', 'wbam', [self::class, 'page_dashboard'], 'dashicons-smartphone', 3);
        add_submenu_page('wbam', 'Dashboard', 'Dashboard', 'wbam_use', 'wbam', [self::class, 'page_dashboard']);
        add_submenu_page('wbam', 'Device intake', 'Device intake', 'wbam_use', 'wbam-intake', [self::class, 'page_intake']);
        add_submenu_page('wbam', 'Units', 'Units', 'wbam_use', 'wbam-units', [self::class, 'page_units']);
        add_submenu_page('wbam', 'Reconcile', 'Reconcile', 'wbam_use', 'wbam-reconcile', [self::class, 'page_reconcile']);
        add_submenu_page('wbam', 'Stocktake', 'Stocktake', 'wbam_manage', 'wbam-stocktake', [self::class, 'page_stocktake']);
        add_submenu_page('wbam', 'Repairs', 'Repairs', 'wbam_use', 'wbam-tickets', [self::class, 'page_tickets']);
        add_submenu_page('wbam', 'Parts & POs', 'Parts & POs', 'wbam_use', 'wbam-parts', [self::class, 'page_parts']);
        add_submenu_page('wbam', 'Reports', 'Reports', 'wbam_reports', 'wbam-reports', [self::class, 'page_reports']);
        add_submenu_page('wbam', 'Settings', 'Settings', 'wbam_settings', 'wbam-settings', [self::class, 'page_settings']);
    }

    public static function assets($hook): void {
        if (strpos((string) ($_GET['page'] ?? ''), 'wbam') !== 0) return;
        wp_enqueue_style('wbam-hub', WBAM_URL . 'assets/css/hub.css', [], WBAM_VER);
        wp_enqueue_script('wbam-app', WBAM_URL . 'assets/js/app.js', [], WBAM_VER, true);
        wp_localize_script('wbam-app', 'WBAM', [
            'rest'  => rest_url('wbam/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
        if (($_GET['page'] ?? '') === 'wbam-intake') {
            wp_enqueue_script('wbam-intake', WBAM_URL . 'assets/js/intake.js', ['wbam-app'], WBAM_VER, true);
        }
    }

    private static function h(string $title): void {
        echo '<div class="wrap wbam-admin"><h1>' . esc_html($title) . '</h1>';
    }

    private const RTYPES = ['Diagnosis', 'Screen Change', 'Battery Change', 'Backglass Change', 'Other Repair'];

    private static function rtype_select(string $name, string $selected): string {
        $out = "<select name=\"$name\"><option value=\"\">—</option>";
        foreach (self::RTYPES as $r) {
            $out .= '<option value="' . esc_attr($r) . '"' . selected($selected, $r, false) . '>' . esc_html($r) . '</option>';
        }
        return $out . '</select>';
    }

    private static function branch_select(string $name = 'branch_id', int $selected = 0, bool $all = false): string {
        $out = "<select name=\"$name\">";
        if ($all) $out .= '<option value="0">All branches</option>';
        foreach (WBAM_Settings::branches() as $b) {
            $out .= '<option value="' . (int) $b['id'] . '"' . selected($selected, (int) $b['id'], false) . '>' . esc_html($b['name']) . '</option>';
        }
        return $out . '</select>';
    }

    /* ================= Dashboard ================= */

    public static function page_dashboard(): void {
        self::h('WBAM Hub');
        if (!WBAM_Shopify::i()->is_configured()) {
            echo '<div class="notice notice-warning"><p>Connect Shopify first: <a href="' . esc_url(admin_url('admin.php?page=wbam-settings')) . '">Settings</a>.</p></div></div>';
            return;
        }
        if (current_user_can('wbam_reports')) {
            $r = WBAM_Reports::build('today');
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wbam-reports')) . '">Full reports</a> ';
            echo '<button class="button wbam-refresh" data-rest="' . esc_url(rest_url('wbam/v1/report/refresh')) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">↻ Refresh from Shopify</button></p>';
            echo WBAM_Reports::html($r); // Today, all branches
        }
        // Pending reconciliations poke.
        $pending = WBAM_Units::pending_reconcile();
        if ($pending) {
            echo '<div class="notice notice-warning"><p><b>' . count($pending) . '</b> sold line(s) need a unit confirmed — <a href="' . esc_url(admin_url('admin.php?page=wbam-reconcile')) . '">Reconcile now</a>.</p></div>';
        }
        echo '</div>';
    }

    /* ================= Intake ================= */

    public static function page_intake(): void {
        self::h('Device intake');
        ?>
        <p class="description">Scan the IMEI (or type it), pick the model &amp; condition, enter what you paid. Saving adds stock to Shopify, records the payout, and prints the shelf label.</p>
        <form id="wbam-intake-form" class="wbam-card" onsubmit="return false;">
          <div class="wbam-grid">
            <label>IMEI <input id="wi-imei" inputmode="numeric" autocomplete="off" autofocus placeholder="scan here…" required></label>
            <label>Branch <?php echo self::branch_select('branch_id'); ?></label>
            <label>Model <input id="wi-model" placeholder="start typing… e.g. iPhone 12" autocomplete="off">
              <input type="hidden" id="wi-product-id"><input type="hidden" id="wi-model-title">
              <div id="wi-model-list" class="wbam-list"></div>
            </label>
            <span id="wi-options"></span>
            <label>Purchase price (£) <input id="wi-price" type="number" step="0.01" min="0" required></label>
            <label>Source
              <select id="wi-source">
                <option value="buyback">Buy-in (walk-in)</option>
                <option value="tradein">Trade-in (against a sale)</option>
                <option value="supplier">Supplier</option>
              </select>
            </label>
            <label>Paid by
              <select id="wi-payout">
                <option value="cash">Cash</option>
                <option value="bank">Bank transfer</option>
                <option value="store_credit">Store credit / trade-in value</option>
                <option value="">—</option>
              </select>
            </label>
            <label>Seller / ref <input id="wi-ref" placeholder="seller name, PO#, order#…"></label>
            <label>Battery % <input id="wi-battery" type="number" min="0" max="100"></label>
            <label>Stolen-check ref <input id="wi-checkmend" placeholder="CheckMEND ref (optional)"></label>
            <label style="grid-column:1/-1">Notes <input id="wi-notes"></label>
          </div>
          <p>
            <button class="button button-primary button-hero" id="wi-save">Save + print label</button>
            <span id="wi-status" class="wbam-status"></span>
          </p>
        </form>
        <div id="wi-done"></div>
        <?php
        echo '</div>';
    }

    /* ================= Units ================= */

    public static function page_units(): void {
        // Row actions.
        if (!empty($_POST['wbam_unit_action']) && check_admin_referer('wbam_units')) {
            $id = (int) $_POST['unit_id'];
            try {
                switch ($_POST['wbam_unit_action']) {
                    case 'return':   WBAM_Units::return_to_stock($id, sanitize_text_field($_POST['reason'] ?? '')); break;
                    case 'writeoff': current_user_can('wbam_manage') && WBAM_Units::write_off($id, sanitize_text_field($_POST['reason'] ?? '')); break;
                    case 'transfer': WBAM_Units::transfer($id, (int) $_POST['to_branch']); break;
                }
                echo '<div class="notice notice-success"><p>Done.</p></div>';
            } catch (Throwable $e) {
                echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        self::h('Units');
        $q = sanitize_text_field($_GET['q'] ?? '');
        $status = sanitize_key($_GET['status'] ?? 'in_stock');
        $branch = (int) ($_GET['branch_id'] ?? 0);
        ?>
        <form method="get" class="wbam-filters">
          <input type="hidden" name="page" value="wbam-units">
          <input name="q" value="<?php echo esc_attr($q); ?>" placeholder="scan / IMEI / code / model…">
          <select name="status">
            <?php foreach (['in_stock' => 'In stock', 'sold' => 'Sold', 'written_off' => 'Written off', '' => 'Any'] as $k => $l) {
                echo '<option value="' . esc_attr($k) . '"' . selected($status, $k, false) . ">$l</option>";
            } ?>
          </select>
          <?php echo self::branch_select('branch_id', $branch, true); ?>
          <button class="button">Filter</button>
          <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'wbam-units', 'status' => 'in_stock', 'aging' => 1], admin_url('admin.php'))); ?>">Aging &gt;45d</a>
        </form>
        <?php
        $units = !empty($_GET['aging']) ? WBAM_Units::aging(45) : WBAM_Units::search(['q' => $q, 'status' => $status, 'branch_id' => $branch]);
        echo '<table class="widefat striped"><thead><tr><th>Unit</th><th>Device</th><th>IMEI</th><th>Branch</th><th>Status</th><th>Cost</th><th>Sold</th><th>In stock since</th><th></th></tr></thead><tbody>';
        foreach ($units as $u) {
            $b = WBAM_Settings::branch((int) $u['branch_id']);
            echo '<tr><td><b>' . esc_html($u['unit_code']) . '</b></td>'
               . '<td>' . esc_html($u['model_title'] . ' — ' . $u['variant_title']) . '</td>'
               . '<td>…' . esc_html(substr($u['imei'], -8)) . '</td>'
               . '<td>' . esc_html($b['name'] ?? '?') . '</td>'
               . '<td>' . esc_html($u['status']) . '</td>'
               . '<td>£' . number_format((float) $u['purchase_price'], 2) . '</td>'
               . '<td>' . ($u['status'] === 'sold' ? esc_html($u['order_name']) . ' £' . number_format((float) $u['sale_price'], 2) : '—') . '</td>'
               . '<td>' . esc_html(mysql2date('j M', $u['created_at'])) . '</td>'
               . '<td class="wbam-actions"><a class="button button-small" target="_blank" href="' . esc_url(WBAM_Labels::url((int) $u['id'])) . '">Label</a> ';
            echo '<form method="post" style="display:inline">';
            wp_nonce_field('wbam_units');
            echo '<input type="hidden" name="unit_id" value="' . (int) $u['id'] . '">';
            if ($u['status'] === 'sold') {
                echo '<button class="button button-small" name="wbam_unit_action" value="return" onclick="return confirm(\'Return to stock?\')">Return</button> ';
            }
            if ($u['status'] === 'in_stock') {
                echo self::branch_select('to_branch', 0, false);
                echo '<button class="button button-small" name="wbam_unit_action" value="transfer">Move</button> ';
                if (current_user_can('wbam_manage')) {
                    echo '<button class="button button-small" name="wbam_unit_action" value="writeoff" onclick="return confirm(\'Write off this unit?\')">Write off</button>';
                }
            }
            echo '</form></td></tr>';
        }
        if (!$units) echo '<tr><td colspan="9">Nothing found.</td></tr>';
        echo '</tbody></table></div>';
    }

    /* ================= Reconcile ================= */

    public static function page_reconcile(): void {
        global $wpdb;
        if (!empty($_POST['wbam_attach']) && check_admin_referer('wbam_reconcile')) {
            $unit = WBAM_Units::by_code(sanitize_text_field($_POST['unit_scan']));
            $line = $wpdb->get_row($wpdb->prepare(
                "SELECT l.*, o.name FROM {$wpdb->prefix}wbam_order_lines l JOIN {$wpdb->prefix}wbam_orders o ON o.order_id=l.order_id WHERE l.id=%d",
                (int) $_POST['line_row']), ARRAY_A);
            if ($unit && $line && $unit['status'] === 'in_stock' && (int) $unit['variant_id'] === (int) $line['variant_id']) {
                WBAM_Units::mark_sold((int) $unit['id'], (int) $line['order_id'], $line['name'], (float) $line['price'], (int) $line['id']);
                echo '<div class="notice notice-success"><p>' . esc_html($unit['unit_code']) . ' attached to ' . esc_html($line['name']) . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>No match — check the scan is the right unit (same model/variant) and still in stock.</p></div>';
            }
        }
        self::h('Reconcile sold units');
        echo '<p class="description">These sold lines match a serialized model but more than one unit was in stock — scan the label of the actual phone that left.</p>';
        $rows = WBAM_Units::pending_reconcile();
        echo '<table class="widefat striped"><thead><tr><th>Order</th><th>When</th><th>Item</th><th>Candidates</th><th>Scan unit</th></tr></thead><tbody>';
        global $wpdb;
        foreach ($rows as $r) {
            $cands = $wpdb->get_results($wpdb->prepare(
                "SELECT unit_code, imei FROM {$wpdb->prefix}wbam_units WHERE variant_id=%d AND status='in_stock'", (int) $r['variant_id']), ARRAY_A);
            $list = implode(', ', array_map(fn($c) => $c['unit_code'] . ' (…' . substr($c['imei'], -4) . ')', $cands));
            echo '<tr><td>' . esc_html($r['order_name']) . '</td><td>' . esc_html(mysql2date('j M H:i', $r['processed_at'])) . '</td>'
               . '<td>' . esc_html($r['title']) . '</td><td>' . esc_html($list ?: '—') . '</td><td>';
            echo '<form method="post" class="wbam-inline">';
            wp_nonce_field('wbam_reconcile');
            echo '<input type="hidden" name="line_row" value="' . (int) $r['id'] . '">'
               . '<input name="unit_scan" placeholder="scan unit code / IMEI" autocomplete="off">'
               . '<button class="button" name="wbam_attach" value="1">Attach</button></form></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">All clear 🎉</td></tr>';
        echo '</tbody></table></div>';
    }

    /* ================= Stocktake ================= */

    public static function page_stocktake(): void {
        self::h('Stocktake');
        $branch = (int) ($_POST['branch_id'] ?? 0);
        $scans_raw = (string) ($_POST['scans'] ?? '');
        echo '<p class="description">Pick the branch, then scan every phone on the shelf into the box (one per line — unit codes or IMEIs both work). Nothing changes until you apply.</p>';
        echo '<form method="post">';
        wp_nonce_field('wbam_stocktake');
        echo '<p>' . self::branch_select('branch_id', $branch) . '</p>';
        echo '<p><textarea name="scans" rows="12" class="large-text code" placeholder="U00042&#10;356789104563217&#10;U00051">' . esc_textarea($scans_raw) . '</textarea></p>';
        echo '<p><button class="button" name="do" value="diff">Compare</button> ';
        echo '<button class="button button-primary" name="do" value="apply" onclick="return confirm(\'Write off missing units and push counts to Shopify?\')">Apply: write off missing + push counts</button></p></form>';

        if ($branch && $scans_raw && check_admin_referer('wbam_stocktake')) {
            $scans = preg_split('/[\r\n,;]+/', $scans_raw) ?: [];
            $diff = WBAM_Units::stocktake_diff($branch, $scans);
            if (($_POST['do'] ?? '') === 'apply') {
                foreach ($diff['missing'] as $m) WBAM_Units::write_off((int) $m['id'], 'stocktake: not found');
                $n = WBAM_Units::stocktake_push($branch);
                echo '<div class="notice notice-success"><p>Applied: ' . count($diff['missing']) . ' written off, counts pushed for ' . $n . ' variant(s).</p></div>';
                $diff = WBAM_Units::stocktake_diff($branch, $scans);
            }
            echo '<h2>Result</h2><ul>';
            echo '<li>✅ Matched: <b>' . count($diff['matched']) . '</b></li>';
            echo '<li>⚠️ In DB but not scanned (missing): <b>' . count($diff['missing']) . '</b>';
            if ($diff['missing']) {
                echo '<ul>';
                foreach ($diff['missing'] as $m) echo '<li>' . esc_html($m['unit_code'] . ' — ' . $m['model_title'] . ' ' . $m['variant_title'] . ' (…' . substr($m['imei'], -4) . ')') . '</li>';
                echo '</ul>';
            }
            echo '</li>';
            echo '<li>❓ Scanned but unknown (intake them): <b>' . count($diff['unknown']) . '</b> ' . esc_html(implode(', ', array_slice($diff['unknown'], 0, 20))) . '</li>';
            echo '</ul>';
        }
        echo '</div>';
    }

    /* ================= Tickets ================= */

    public static function page_tickets(): void {
        $tid = (int) ($_GET['ticket'] ?? 0);
        if ($tid) { self::ticket_detail($tid); return; }

        if (!empty($_POST['wbam_new_ticket']) && check_admin_referer('wbam_ticket_new')) {
            $t = WBAM_Tickets::create([
                'branch_id' => (int) $_POST['branch_id'],
                'customer_name' => $_POST['customer_name'] ?? '', 'phone' => $_POST['phone'] ?? '',
                'email' => $_POST['email'] ?? '', 'device_model' => $_POST['device_model'] ?? '',
                'imei' => $_POST['imei'] ?? '', 'passcode' => $_POST['passcode'] ?? '',
                'fault' => $_POST['fault'] ?? '', 'quote' => $_POST['quote'] ?? '',
                'repair_type' => $_POST['repair_type'] ?? '', 'due_date' => $_POST['due_date'] ?? '',
                'device_held' => isset($_POST['device_held']) ? (int) $_POST['device_held'] : 1,
            ], 'walkin');
            wp_safe_redirect(admin_url('admin.php?page=wbam-tickets&ticket=' . $t['id'])); exit;
        }

        self::h('Repairs');
        $q = sanitize_text_field($_GET['q'] ?? '');
        echo '<div class="wbam-cols"><div>';
        echo '<form method="get" class="wbam-filters"><input type="hidden" name="page" value="wbam-tickets">'
           . '<input name="q" value="' . esc_attr($q) . '" placeholder="ticket / name / phone / IMEI…"><button class="button">Search</button>'
           . '<label><input type="checkbox" name="all" value="1"' . checked(!empty($_GET['all']), true, false) . '> incl. closed</label></form>';
        $rows = WBAM_Tickets::list(['q' => $q, 'all' => !empty($_GET['all'])]);
        echo '<table class="widefat striped"><thead><tr><th>Ticket</th><th>Customer</th><th>Device</th><th>Status</th><th>Type · Due</th><th>Quote</th><th>Updated</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            $due = '';
            if (!empty($t['due_date'])) {
                $overdue = $t['due_date'] < current_time('Y-m-d') && !in_array($t['status'], ['ready', 'collected', 'cancelled'], true);
                $due = '<br><small' . ($overdue ? ' style="color:#b32d2e;font-weight:700"' : '') . '>due ' . esc_html(mysql2date('j M', $t['due_date'])) . ($overdue ? ' ⚠' : '') . '</small>';
            }
            $held = (int) ($t['device_held'] ?? 1) === 1 ? '' : ' <small title="Customer still has the device">📵</small>';
            echo '<tr><td><a href="' . esc_url(admin_url('admin.php?page=wbam-tickets&ticket=' . $t['id'])) . '"><b>' . esc_html($t['ticket_code']) . '</b></a></td>'
               . '<td>' . esc_html($t['customer_name']) . '<br><small>' . esc_html($t['phone']) . '</small></td>'
               . '<td>' . esc_html($t['device_model']) . $held . '</td><td><span class="wbam-st st-' . esc_attr($t['status']) . '">' . esc_html(str_replace('_', ' ', $t['status'])) . '</span></td>'
               . '<td>' . esc_html($t['repair_type'] ?: '—') . $due . '</td>'
               . '<td>' . ($t['quote'] !== null ? '£' . number_format((float) $t['quote'], 2) : '—') . '</td>'
               . '<td>' . esc_html(mysql2date('j M H:i', $t['updated_at'])) . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="7">No open tickets.</td></tr>';
        echo '</tbody></table></div><div class="wbam-card">';
        echo '<h2>New walk-in repair</h2><form method="post">';
        wp_nonce_field('wbam_ticket_new');
        echo '<div class="wbam-grid">'
           . '<label>Branch ' . self::branch_select('branch_id') . '</label>'
           . '<label>Customer <input name="customer_name" required></label>'
           . '<label>Phone <input name="phone"></label>'
           . '<label>Email <input name="email" type="email"></label>'
           . '<label>Device <input name="device_model" placeholder="iPhone 12, S22…" required></label>'
           . '<label>IMEI <input name="imei" inputmode="numeric"></label>'
           . '<label>Passcode <input name="passcode"></label>'
           . '<label>Quote £ <input name="quote" type="number" step="0.01"></label>'
           . '<label>Repair type ' . self::rtype_select('repair_type', '') . '</label>'
           . '<label>Est. completion <input name="due_date" type="date" value="' . esc_attr(date('Y-m-d', strtotime('+7 days', current_time('timestamp')))) . '"></label>'
           . '<label>Device left with us? <select name="device_held"><option value="1">Yes — device is here</option><option value="0">No — customer keeps it for now</option></select></label>'
           . '<label style="grid-column:1/-1">Fault <textarea name="fault" rows="3" required></textarea></label>'
           . '</div><p><button class="button button-primary" name="wbam_new_ticket" value="1">Create ticket</button></p></form>';
        echo '<p class="description">Walk-ins can also be booked straight from the <b>Repairs tile in POS</b> — including taking the deposit in the same basket.</p>';
        echo '</div></div></div>';
    }

    private static function ticket_detail(int $id): void {
        global $wpdb;
        // Actions.
        if (!empty($_POST['wbam_taction']) && check_admin_referer('wbam_ticket_' . $id)) {
            try {
                switch ($_POST['wbam_taction']) {
                    case 'status':
                        WBAM_Tickets::set_status($id, sanitize_key($_POST['status']), sanitize_text_field($_POST['note'] ?? ''));
                        break;
                    case 'save':
                        WBAM_Tickets::update_fields($id, [
                            'diagnosis' => $_POST['diagnosis'] ?? '', 'quote' => ($_POST['quote'] ?? '') !== '' ? (float) $_POST['quote'] : null,
                            'repair_type' => $_POST['repair_type'] ?? '', 'due_date' => $_POST['due_date'] ?? '',
                            'device_held' => isset($_POST['device_held']) ? (int) $_POST['device_held'] : 1,
                        ]);
                        break;
                    case 'part_stock':
                        WBAM_Parts::use_from_stock($id, (int) $_POST['part_id'], max(1, (int) $_POST['qty']));
                        break;
                    case 'part_order':
                        WBAM_Parts::create_po((int) $_POST['vendor_id'], (int) ($_POST['branch_id'] ?? 0), [[
                            'ticket_id' => $id, 'description' => $_POST['description'] ?? '',
                            'product_url' => $_POST['product_url'] ?? '', 'qty' => max(1, (int) $_POST['qty']),
                            'unit_cost' => (float) $_POST['unit_cost'],
                        ]]);
                        WBAM_Tickets::set_status($id, 'awaiting_parts', 'parts ordered');
                        break;
                    case 'draft':
                        $url = WBAM_Tickets::draft_payment($id, (float) $_POST['amount'], sanitize_key($_POST['which'] ?? 'deposit'));
                        echo '<div class="notice notice-success"><p>Payment link: <a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a> (also on the ticket log — send it to the customer).</p></div>';
                        break;
                }
            } catch (Throwable $e) {
                echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        $t = WBAM_Tickets::get($id);
        if (!$t) { echo '<div class="wrap"><p>Unknown ticket.</p></div>'; return; }
        $eco = WBAM_Tickets::economics($id);
        self::h($t['ticket_code'] . ' — ' . $t['device_model']);
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=wbam-tickets')) . '">&larr; all tickets</a></p>';
        echo '<div class="wbam-cols"><div class="wbam-card">';
        echo '<p><b>' . esc_html($t['customer_name']) . '</b> · ' . esc_html($t['phone']) . ' · ' . esc_html($t['email']) . '<br>'
           . 'IMEI ' . esc_html($t['imei'] ?: '—') . ' · booked ' . esc_html(mysql2date('j M Y H:i', $t['created_at'])) . ' · source ' . esc_html($t['source'])
           . ' · <b>' . ((int) ($t['device_held'] ?? 1) === 1 ? 'device with us' : 'customer has the device') . '</b></p>';
        echo '<p><b>Fault:</b> ' . nl2br(esc_html($t['fault'])) . '</p>';

        echo '<form method="post">'; wp_nonce_field('wbam_ticket_' . $id);
        echo '<p><label>Diagnosis<br><textarea name="diagnosis" rows="3" class="large-text">' . esc_textarea($t['diagnosis'] ?? '') . '</textarea></label></p>'
           . '<p><label>Quote £ <input name="quote" type="number" step="0.01" value="' . esc_attr($t['quote']) . '"></label> '
           . '<label>Type ' . self::rtype_select('repair_type', (string) ($t['repair_type'] ?? '')) . '</label> '
           . '<label>Est. completion <input name="due_date" type="date" value="' . esc_attr($t['due_date'] ?? '') . '"></label> '
           . '<label>Device <select name="device_held"><option value="1"' . selected((int) ($t['device_held'] ?? 1), 1, false) . '>with us</option><option value="0"' . selected((int) ($t['device_held'] ?? 1), 0, false) . '>customer has it</option></select></label> '
           . '<button class="button" name="wbam_taction" value="save">Save</button></p></form>';

        echo '<h3>Status</h3><form method="post" class="wbam-inline">'; wp_nonce_field('wbam_ticket_' . $id);
        echo '<select name="status">';
        foreach (WBAM_Tickets::STATUSES as $s) echo '<option value="' . $s . '"' . selected($t['status'], $s, false) . '>' . str_replace('_', ' ', $s) . '</option>';
        echo '</select> <input name="note" placeholder="note (goes in the customer message where relevant)">'
           . '<button class="button button-primary" name="wbam_taction" value="status">Update + notify</button></form>';

        echo '<h3>Money</h3><p>Quoted £' . number_format($eco['quoted'], 2) . ' · paid £' . number_format($eco['paid'], 2)
           . ' · parts £' . number_format($eco['parts'], 2) . ' · <b>margin £' . number_format($eco['margin'], 2) . '</b></p>';
        echo '<p class="description">In store: use the <b>Repairs tile in POS</b> (adds the deposit/balance to the cart — no typing), or create a draft below and open it in POS under <b>Draft orders</b>. The draft link also works for remote card payment.</p>';
        echo '<form method="post" class="wbam-inline">'; wp_nonce_field('wbam_ticket_' . $id);
        echo '<select name="which"><option value="deposit">deposit</option><option value="balance">balance</option></select>'
           . '<input name="amount" type="number" step="0.01" placeholder="£"><button class="button" name="wbam_taction" value="draft">Create draft / payment link</button></form>';

        echo '</div><div>';
        echo '<div class="wbam-card"><h3>Parts</h3>';
        $tp = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wbam_ticket_parts WHERE ticket_id=%d", $id), ARRAY_A);
        if ($tp) {
            echo '<ul>';
            foreach ($tp as $p) echo '<li>' . esc_html($p['description']) . ' ×' . (int) $p['qty'] . ' — £' . number_format((float) $p['unit_cost'], 2) . ' <small>(' . esc_html($p['source']) . ')</small></li>';
            echo '</ul>';
        }
        echo '<h4>Use from stock</h4><form method="post" class="wbam-inline">'; wp_nonce_field('wbam_ticket_' . $id);
        echo '<select name="part_id">';
        foreach (WBAM_Parts::parts() as $p) echo '<option value="' . (int) $p['id'] . '">' . esc_html($p['name'] . ' (' . $p['total_qty'] . ' in stock)') . '</option>';
        echo '</select><input name="qty" type="number" value="1" min="1" style="width:4em">'
           . '<button class="button" name="wbam_taction" value="part_stock">Use</button></form>';
        echo '<h4>Order from vendor</h4><form method="post">'; wp_nonce_field('wbam_ticket_' . $id);
        echo '<input type="hidden" name="branch_id" value="' . (int) $t['branch_id'] . '">';
        echo '<p><select name="vendor_id">';
        foreach (WBAM_Parts::vendors() as $v) echo '<option value="' . (int) $v['id'] . '">' . esc_html($v['name']) . '</option>';
        echo '</select></p>'
           . '<p><input name="description" placeholder="part description" class="regular-text" required></p>'
           . '<p><input name="product_url" placeholder="vendor product URL (optional)" class="regular-text"></p>'
           . '<p><input name="qty" type="number" value="1" min="1" style="width:4em"> × £<input name="unit_cost" type="number" step="0.01" style="width:6em"> '
           . '<button class="button" name="wbam_taction" value="part_order">Create PO</button></p></form>';
        echo '<p class="description">This creates a draft PO. Order it on the supplier\'s website, then in <a href="' . esc_url(admin_url('admin.php?page=wbam-parts')) . '">Parts &amp; POs</a> enter the price paid and hit <b>Mark ordered</b>. Receiving books the cost to this ticket.</p></div>';

        if ($t['imei']) {
            $h = WBAM_Tickets::history($t['imei']);
            if (count($h['tickets']) > 1 || $h['units']) {
                echo '<div class="wbam-card"><h3>This IMEI before</h3><ul>';
                foreach ($h['tickets'] as $ht) if ((int) $ht['id'] !== $id) echo '<li>' . esc_html($ht['ticket_code'] . ' · ' . $ht['status'] . ' · ' . mysql2date('j M Y', $ht['created_at'])) . '</li>';
                foreach ($h['units'] as $hu) echo '<li>Stock unit ' . esc_html($hu['unit_code'] . ' · ' . $hu['status']) . '</li>';
                echo '</ul></div>';
            }
        }

        echo '<div class="wbam-card"><h3>Log</h3><ul class="wbam-log">';
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_ticket_events WHERE ticket_id=%d ORDER BY id DESC LIMIT 50", $id), ARRAY_A);
        foreach ($events as $e) {
            echo '<li><small>' . esc_html(mysql2date('j M H:i', $e['created_at'])) . '</small> <b>' . esc_html($e['event']) . '</b> ' . esc_html($e['detail']) . '</li>';
        }
        echo '</ul></div></div></div></div>';
    }

    /* ================= Parts & POs ================= */

    public static function page_parts(): void {
        if (!empty($_POST['wbam_paction']) && check_admin_referer('wbam_parts')) {
            try {
                switch ($_POST['wbam_paction']) {
                    case 'vendor': WBAM_Parts::save_vendor($_POST); break;
                    case 'part':   WBAM_Parts::save_part($_POST); break;
                    case 'po_ordered': WBAM_Parts::mark_ordered((int) $_POST['po_id'], (array) ($_POST['cost'] ?? [])); break;
                    case 'po_receive':
                        WBAM_Parts::receive((int) $_POST['po_id'], array_map('intval', (array) ($_POST['recv'] ?? [])));
                        break;
                    case 'restock_po':
                        WBAM_Parts::create_po((int) $_POST['vendor_id'], (int) $_POST['branch_id'], [[
                            'part_id' => (int) $_POST['part_id'], 'description' => sanitize_text_field($_POST['description']),
                            'qty' => max(1, (int) $_POST['qty']), 'unit_cost' => (float) $_POST['unit_cost'],
                        ]]);
                        break;
                }
                echo '<div class="notice notice-success"><p>Saved.</p></div>';
            } catch (Throwable $e) {
                echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        self::h('Parts & POs');
        echo '<div class="wbam-cols"><div>';

        echo '<h2>Purchase orders</h2>';
        $pos = WBAM_Parts::list_pos();
        echo '<table class="widefat striped"><thead><tr><th>PO</th><th>Vendor</th><th>Status</th><th>Value</th><th>Lines</th><th></th></tr></thead><tbody>';
        foreach ($pos as $po) {
            $detail = WBAM_Parts::get_po((int) $po['id']);
            echo '<tr><td><b>' . esc_html($po['po_code']) . '</b><br><small>' . esc_html(mysql2date('j M', $po['created_at'])) . '</small></td>'
               . '<td>' . esc_html($po['vendor_name'] ?? '—') . '</td><td>' . esc_html($po['status']) . '</td>'
               . '<td>£' . number_format((float) $po['subtotal'], 2) . '</td><td>';
            foreach ($detail['lines'] as $l) {
                echo esc_html($l['description']) . ' ×' . (int) $l['qty'];
                if ((int) $l['qty_received'] > 0) echo ' <small>(' . (int) $l['qty_received'] . ' recvd)</small>';
                if (!empty($l['ticket_id'])) echo ' <small>→ T-' . str_pad((string) $l['ticket_id'], 4, '0', STR_PAD_LEFT) . '</small>';
                echo '<br>';
            }
            echo '</td><td>';
            echo '<form method="post">'; wp_nonce_field('wbam_parts');
            echo '<input type="hidden" name="po_id" value="' . (int) $po['id'] . '">';
            if ($po['status'] === 'draft') {
                if (!empty($detail['vendor']['url'])) {
                    echo '<a class="button button-small" target="_blank" href="' . esc_url($detail['vendor']['url']) . '">Open supplier site ↗</a><br>';
                }
                foreach ($detail['lines'] as $l) {
                    echo '<label>' . esc_html(mb_substr($l['description'], 0, 16)) . ' £<input type="number" step="0.01" name="cost[' . (int) $l['id'] . ']" value="' . esc_attr($l['unit_cost']) . '" style="width:5em"></label>';
                    if (!empty($l['product_url'])) echo ' <a target="_blank" href="' . esc_url($l['product_url']) . '">link↗</a>';
                    echo '<br>';
                }
                echo '<button class="button button-primary button-small" name="wbam_paction" value="po_ordered" onclick="return confirm(\'Ordered on the supplier site? Prices entered are saved as the cost.\')">Mark ordered + save prices</button>';
            } elseif (in_array($po['status'], ['ordered', 'partial'], true)) {
                foreach ($detail['lines'] as $l) {
                    $left = (int) $l['qty'] - (int) $l['qty_received'];
                    if ($left > 0) echo '<label>' . esc_html(mb_substr($l['description'], 0, 18)) . ' <input type="number" name="recv[' . (int) $l['id'] . ']" value="' . $left . '" min="0" max="' . $left . '" style="width:3.5em"></label><br>';
                }
                echo '<button class="button button-small" name="wbam_paction" value="po_receive">Receive</button>';
            }
            echo '</form></td></tr>';
        }
        if (!$pos) echo '<tr><td colspan="6">No POs yet.</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Parts in stock</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Part</th><th>Fits</th><th>Qty (all branches)</th><th>Avg cost</th></tr></thead><tbody>';
        global $wpdb;
        foreach (WBAM_Parts::parts() as $p) {
            $avg = $wpdb->get_var($wpdb->prepare("SELECT AVG(avg_cost) FROM {$wpdb->prefix}wbam_part_stock WHERE part_id=%d AND qty>0", (int) $p['id']));
            echo '<tr><td>' . esc_html($p['name']) . '</td><td>' . esc_html($p['compat']) . '</td>'
               . '<td>' . (int) $p['total_qty'] . '</td><td>' . ($avg ? '£' . number_format((float) $avg, 2) : '—') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '</div><div>';
        echo '<div class="wbam-card"><h3>New vendor</h3><form method="post">'; wp_nonce_field('wbam_parts');
        echo '<p><input name="name" placeholder="Vendor name" required class="regular-text"></p>'
           . '<p><input name="email" type="email" placeholder="orders@vendor.com" class="regular-text"></p>'
           . '<p><input name="url" placeholder="https://…" class="regular-text"></p>'
           . '<p><button class="button" name="wbam_paction" value="vendor">Add vendor</button></p></form></div>';

        echo '<div class="wbam-card"><h3>New part (for stocked cheap parts)</h3><form method="post">'; wp_nonce_field('wbam_parts');
        echo '<p><input name="name" placeholder="e.g. iPhone 11 screen (aftermarket)" required class="regular-text"></p>'
           . '<p><input name="compat" placeholder="fits: iPhone 11" class="regular-text"></p>'
           . '<p>Min qty <input name="min_qty" type="number" value="0" style="width:4em"></p>'
           . '<p><button class="button" name="wbam_paction" value="part">Add part</button></p></form></div>';

        echo '<div class="wbam-card"><h3>Restock PO</h3><form method="post">'; wp_nonce_field('wbam_parts');
        echo '<p><select name="part_id">';
        foreach (WBAM_Parts::parts() as $p) echo '<option value="' . (int) $p['id'] . '">' . esc_html($p['name']) . '</option>';
        echo '</select></p><p><select name="vendor_id">';
        foreach (WBAM_Parts::vendors() as $v) echo '<option value="' . (int) $v['id'] . '">' . esc_html($v['name']) . '</option>';
        echo '</select> ' . self::branch_select('branch_id') . '</p>'
           . '<p><input type="hidden" name="description" value="Restock"><input name="qty" type="number" value="5" min="1" style="width:4em"> × £<input name="unit_cost" type="number" step="0.01" style="width:6em"></p>'
           . '<p><button class="button" name="wbam_paction" value="restock_po">Create draft PO</button></p></form></div>';
        echo '</div></div></div>';
    }

    /* ================= Reports ================= */

    public static function page_reports(): void {
        self::h('Reports');
        $range = sanitize_key($_GET['range'] ?? 'today');
        $branch = (int) ($_GET['branch'] ?? 0);
        $from = sanitize_text_field($_GET['from'] ?? '');
        $to = sanitize_text_field($_GET['to'] ?? '');
        echo '<form method="get" class="wbam-filters"><input type="hidden" name="page" value="wbam-reports">';
        echo '<select name="range">';
        foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'wtd' => 'Week to date', 'mtd' => 'Month to date', 'qtd' => 'Quarter to date', 'ytd' => 'Year to date', 'custom' => 'Custom'] as $k => $l) {
            echo '<option value="' . $k . '"' . selected($range, $k, false) . ">$l</option>";
        }
        echo '</select> ' . self::branch_select('branch', $branch, true)
           . ' <input type="date" name="from" value="' . esc_attr($from) . '"> <input type="date" name="to" value="' . esc_attr($to) . '">'
           . ' <button class="button">Run</button> '
           . '<button type="button" class="button wbam-refresh" data-rest="' . esc_url(rest_url('wbam/v1/report/refresh')) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">↻ Refresh from Shopify</button></form>';
        echo WBAM_Reports::html(WBAM_Reports::build($range, $branch, $from, $to));
        echo '<p class="description">Managers can see this same report on the shop-floor tablet at <code>' . esc_html(home_url('/report/')) . '</code> (page with the <code>[wbam_report]</code> shortcode).</p>';
        echo '</div>';
    }

    /* ================= Settings ================= */

    public static function page_settings(): void {
        if (!empty($_POST['wbam_saction']) && check_admin_referer('wbam_settings')) {
            try {
                switch ($_POST['wbam_saction']) {
                    case 'save':
                        $map = json_decode(stripslashes((string) ($_POST['tender_map'] ?? '')), true);
                        WBAM_Settings::update([
                            'shop_domain'   => sanitize_text_field($_POST['shop_domain']),
                            'client_id'     => trim((string) $_POST['client_id']),
                            'client_secret' => trim((string) $_POST['client_secret']) ?: WBAM_Settings::get('client_secret'),
                            'api_version'   => sanitize_text_field($_POST['api_version']),
                            'tender_map'    => is_array($map) ? $map : WBAM_Settings::get('tender_map'),
                            'email_from'    => sanitize_email($_POST['email_from']),
                            'business_phone'=> sanitize_text_field($_POST['business_phone']),
                            'booking_origin'=> esc_url_raw($_POST['booking_origin']),
                            'sms_provider'  => sanitize_key($_POST['sms_provider']),
                            'twilio_sid'    => trim((string) $_POST['twilio_sid']),
                            'twilio_token'  => trim((string) $_POST['twilio_token']) ?: WBAM_Settings::get('twilio_token'),
                            'twilio_from'   => sanitize_text_field($_POST['twilio_from']),
                            'label_w_mm'    => (int) $_POST['label_w_mm'],
                            'label_h_mm'    => (int) $_POST['label_h_mm'],
                        ]);
                        echo '<div class="notice notice-success"><p>Saved.</p></div>';
                        break;
                    case 'test':
                        [$ok, $msg] = WBAM_Shopify::i()->test_connection();
                        echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . '"><p>' . esc_html($msg) . '</p></div>';
                        break;
                    case 'branches':
                        $n = WBAM_Settings::sync_branches();
                        echo '<div class="notice notice-success"><p>' . count($n) . ' location(s) synced.</p></div>';
                        break;
                    case 'webhooks':
                        $r = WBAM_Webhooks::register();
                        echo '<div class="notice notice-success"><p>Webhooks — added: ' . esc_html(implode(', ', $r['registered']) ?: 'none') . '; already present: ' . esc_html(implode(', ', $r['already']) ?: 'none') . '.</p></div>';
                        break;
                    case 'backfill':
                        $n = WBAM_Sync::backfill(59);
                        echo '<div class="notice notice-success"><p>Backfilled ' . (int) $n . ' orders (last 59 days).</p></div>';
                        break;
                    case 'staff':
                        global $wpdb;
                        foreach ((array) ($_POST['staff'] ?? []) as $uid => $label) {
                            $wpdb->update("{$wpdb->prefix}wbam_staff_map", ['label' => sanitize_text_field($label)], ['user_id' => (int) $uid]);
                        }
                        echo '<div class="notice notice-success"><p>Staff labels saved.</p></div>';
                        break;
                }
            } catch (Throwable $e) {
                echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        $s = WBAM_Settings::all();
        self::h('Hub settings');
        echo '<form method="post">'; wp_nonce_field('wbam_settings');
        echo '<table class="form-table">';
        echo '<tr><th>Shop domain</th><td><input name="shop_domain" value="' . esc_attr($s['shop_domain']) . '" class="regular-text"> <code>*.myshopify.com</code></td></tr>';
        echo '<tr><th>Client ID</th><td><input name="client_id" value="' . esc_attr($s['client_id']) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Client secret</th><td><input name="client_secret" type="password" placeholder="' . ($s['client_secret'] ? '•••••• (saved)' : '') . '" class="regular-text"></td></tr>';
        echo '<tr><th>API version</th><td><input name="api_version" value="' . esc_attr($s['api_version']) . '"></td></tr>';
        echo '<tr><th>Tender buckets</th><td><textarea name="tender_map" rows="6" class="large-text code">' . esc_textarea(wp_json_encode($s['tender_map'], JSON_PRETTY_PRINT)) . '</textarea><p class="description">gateway → report bucket. Your POS custom method arrives as gateway <code>Trade In</code>.</p></td></tr>';
        echo '<tr><th>Booking origin</th><td><input name="booking_origin" value="' . esc_attr($s['booking_origin']) . '" class="regular-text"> <span class="description">storefront allowed to POST bookings</span></td></tr>';
        echo '<tr><th>Email from</th><td><input name="email_from" value="' . esc_attr($s['email_from']) . '" class="regular-text"> Phone shown in messages: <input name="business_phone" value="' . esc_attr($s['business_phone']) . '"></td></tr>';
        echo '<tr><th>SMS</th><td><select name="sms_provider"><option value="">Off (email only)</option><option value="twilio"' . selected($s['sms_provider'], 'twilio', false) . '>Twilio</option></select> SID <input name="twilio_sid" value="' . esc_attr($s['twilio_sid']) . '"> Token <input name="twilio_token" type="password" placeholder="' . ($s['twilio_token'] ? '•••' : '') . '"> From <input name="twilio_from" value="' . esc_attr($s['twilio_from']) . '" placeholder="+44…"></td></tr>';
        echo '<tr><th>Label size</th><td><input name="label_w_mm" type="number" value="' . (int) $s['label_w_mm'] . '" style="width:4em"> × <input name="label_h_mm" type="number" value="' . (int) $s['label_h_mm'] . '" style="width:4em"> mm</td></tr>';
        echo '</table><p><button class="button button-primary" name="wbam_saction" value="save">Save</button> '
           . '<button class="button" name="wbam_saction" value="test">Test connection</button> '
           . '<button class="button" name="wbam_saction" value="branches">Sync locations → branches</button> '
           . '<button class="button" name="wbam_saction" value="webhooks">Register webhooks</button> '
           . '<button class="button" name="wbam_saction" value="backfill" onclick="return confirm(\'Pull the last 59 days of orders?\')">Backfill 59 days</button></p></form>';

        echo '<h2>Branches</h2><table class="widefat striped" style="max-width:560px"><thead><tr><th>Branch</th><th>Shopify location</th><th>Active</th></tr></thead><tbody>';
        foreach (WBAM_Settings::branches(false) as $b) {
            echo '<tr><td>' . esc_html($b['name']) . '</td><td>' . esc_html($b['shopify_location_id']) . '</td><td>' . ($b['active'] ? '✓' : '—') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Staff map</h2><p class="description">POS orders arrive with a numeric user id. Give each one a name — new ids appear here automatically after their first sale.</p>';
        global $wpdb;
        $staff = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wbam_staff_map ORDER BY user_id", ARRAY_A);
        echo '<form method="post">'; wp_nonce_field('wbam_settings');
        echo '<table class="widefat striped" style="max-width:560px"><thead><tr><th>User id</th><th>Name</th></tr></thead><tbody>';
        foreach ($staff as $st) {
            echo '<tr><td>' . (int) $st['user_id'] . '</td><td><input name="staff[' . (int) $st['user_id'] . ']" value="' . esc_attr($st['label']) . '"></td></tr>';
        }
        if (!$staff) echo '<tr><td colspan="2">None seen yet — appears after the first synced order.</td></tr>';
        echo '</tbody></table><p><button class="button" name="wbam_saction" value="staff">Save staff labels</button></p></form>';

        echo '<h2>Webhook endpoint</h2><p><code>' . esc_html(WBAM_Webhooks::address()) . '</code></p>';
        echo '<h2>Storefront booking snippet</h2><p class="description">Paste into the repair page (see <code>templates/booking-form.html</code> in the plugin folder).</p>';
        echo '</div>';
    }
}
