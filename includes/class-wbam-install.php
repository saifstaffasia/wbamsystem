<?php
if (!defined('ABSPATH')) exit;

class WBAM_Install {

    const DB_VER = '4';

    public static function activate(): void {
        self::tables();
        self::roles();
        self::cron();
        update_option('wbam_db_ver', self::DB_VER);
    }

    public static function maybe_upgrade(): void {
        if (get_option('wbam_db_ver') !== self::DB_VER) {
            self::tables();
            self::upgrade_barcodes();
            update_option('wbam_db_ver', self::DB_VER);
        }
        if (get_option('wbam_ver_seen') !== WBAM_VER) {
            self::upgrade_booking_origins();
            update_option('wbam_ver_seen', WBAM_VER, false);
        }
    }

    /**
     * v0.5.0: booking_origin became a comma-separated allow-list (storefront +
     * myshopify preview domain). If an older single-value setting was saved,
     * widen it once so theme previews can book against the Hub.
     */
    private static function upgrade_booking_origins(): void {
        $saved = get_option('wbam_settings', []);
        if (!is_array($saved) || empty($saved['booking_origin'])) return; // no saved value — new default applies
        $cur = (string) $saved['booking_origin'];
        if (str_contains($cur, 'myshopify.com')) return; // already widened
        foreach (['https://www.webuyanymobile.com', 'https://sa-we-buy-any-mobile.myshopify.com'] as $a) {
            if (!str_contains($cur, str_replace('https://', '', $a))) $cur .= ', ' . $a;
        }
        WBAM_Settings::update(['booking_origin' => $cur]);
    }

    /**
     * v4: swap SKU-string pool barcodes for scanner-friendly in-store EAN-13s.
     * Writes the new number to the Shopify variant and to every unit of that
     * variant. Capped + fault-tolerant; anything missed self-heals on the next
     * intake of that variant (ensure_pool_barcode upgrades on sight).
     */
    private static function upgrade_barcodes(): void {
        global $wpdb;
        $variants = $wpdb->get_results(
            "SELECT product_id, variant_id, MIN(pool_barcode) pool_barcode
             FROM {$wpdb->prefix}wbam_units
             WHERE status='in_stock' AND variant_id>0
             GROUP BY product_id, variant_id LIMIT 25", ARRAY_A) ?: [];
        foreach ($variants as $v) {
            if (WBAM_Catalog::is_store_ean((string) $v['pool_barcode'])) continue;
            try {
                $ean = WBAM_Catalog::ensure_pool_barcode((int) $v['product_id'], [
                    'variant_id' => (int) $v['variant_id'],
                    'barcode'    => '',
                    'sku'        => '',
                ]);
                $wpdb->update("{$wpdb->prefix}wbam_units",
                    ['pool_barcode' => $ean], ['variant_id' => (int) $v['variant_id']]);
            } catch (Throwable $e) {} // next intake of this variant fixes it
        }
    }

    private static function cron(): void {
        if (!wp_next_scheduled('wbam_nightly_sync')) {
            // 03:15 store-local; WP cron needs traffic — see readme for a real crontab entry.
            wp_schedule_event(strtotime('tomorrow 03:15'), 'daily', 'wbam_nightly_sync');
        }
        if (!wp_next_scheduled('wbam_retry_queue')) {
            wp_schedule_event(time() + 300, 'hourly', 'wbam_retry_queue');
        }
    }

    private static function roles(): void {
        // Managers: everything except plugin settings. Staff: day-to-day usage.
        $staff = [
            'read' => true,
            'wbam_use' => true,          // intake, labels, tickets, parts
        ];
        $manager = $staff + [
            'wbam_reports' => true,      // sales & profit reports
            'wbam_manage' => true,       // stocktake, write-offs, staff map, payouts
        ];
        add_role('wbam_staff', 'WBAM Staff', $staff);
        add_role('wbam_manager', 'WBAM Manager', $manager);
        foreach (['administrator'] as $r) {
            if ($role = get_role($r)) {
                foreach (['wbam_use', 'wbam_reports', 'wbam_manage', 'wbam_settings'] as $cap) $role->add_cap($cap);
            }
        }
    }

    private static function tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = [];

        $sql[] = "CREATE TABLE {$p}wbam_branches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(128) NOT NULL,
            shopify_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY location (shopify_location_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_units (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_code VARCHAR(32) NOT NULL DEFAULT '',
            imei VARCHAR(32) NOT NULL DEFAULT '',
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            inventory_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            model_title VARCHAR(255) NOT NULL DEFAULT '',
            variant_title VARCHAR(255) NOT NULL DEFAULT '',
            sku VARCHAR(128) NOT NULL DEFAULT '',
            pool_barcode VARCHAR(64) NOT NULL DEFAULT '',
            grade VARCHAR(64) NOT NULL DEFAULT '',
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'in_stock',
            purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(10,2) NULL,
            order_id BIGINT UNSIGNED NULL,
            order_name VARCHAR(32) NOT NULL DEFAULT '',
            sold_at DATETIME NULL,
            source VARCHAR(24) NOT NULL DEFAULT 'buyback',
            source_ref VARCHAR(255) NOT NULL DEFAULT '',
            payout_method VARCHAR(16) NOT NULL DEFAULT '',
            battery_health TINYINT NULL,
            checkmend_ref VARCHAR(64) NOT NULL DEFAULT '',
            seller_name VARCHAR(128) NOT NULL DEFAULT '',
            seller_json LONGTEXT NULL,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unit_code (unit_code),
            KEY imei (imei),
            KEY status_branch (status,branch_id),
            KEY variant (variant_id),
            KEY order_id (order_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_unit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(32) NOT NULL,
            detail TEXT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY unit_id (unit_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_orders (
            order_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(32) NOT NULL DEFAULT '',
            processed_at DATETIME NULL,
            source_name VARCHAR(32) NOT NULL DEFAULT '',
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer VARCHAR(255) NOT NULL DEFAULT '',
            currency CHAR(3) NOT NULL DEFAULT 'GBP',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discounts DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            refunded DECIMAL(12,2) NOT NULL DEFAULT 0,
            financial_status VARCHAR(32) NOT NULL DEFAULT '',
            cancelled TINYINT NOT NULL DEFAULT 0,
            test TINYINT NOT NULL DEFAULT 0,
            synced_at DATETIME NULL,
            PRIMARY KEY  (order_id),
            KEY processed_at (processed_at),
            KEY location_id (location_id),
            KEY user_id (user_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_order_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(128) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            qty INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_id BIGINT UNSIGNED NULL,
            ticket_id BIGINT UNSIGNED NULL,
            cogs DECIMAL(10,2) NULL,
            kind VARCHAR(24) NOT NULL DEFAULT 'product',
            PRIMARY KEY  (id),
            UNIQUE KEY order_line (order_id,line_id),
            KEY variant (variant_id),
            KEY unit_id (unit_id),
            KEY kind (kind)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_tenders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            txn_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gateway VARCHAR(64) NOT NULL DEFAULT '',
            kind VARCHAR(24) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            processed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY txn (order_id,txn_id),
            KEY gateway (gateway),
            KEY processed_at (processed_at)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_payouts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            unit_id BIGINT UNSIGNED NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            method VARCHAR(16) NOT NULL DEFAULT 'cash',
            details LONGTEXT NULL,
            reference VARCHAR(255) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY branch_created (branch_id,created_at)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_staff_map (
            user_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(128) NOT NULL DEFAULT '',
            active TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY  (user_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_code VARCHAR(16) NOT NULL DEFAULT '',
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_name VARCHAR(128) NOT NULL DEFAULT '',
            phone VARCHAR(32) NOT NULL DEFAULT '',
            email VARCHAR(128) NOT NULL DEFAULT '',
            device_model VARCHAR(128) NOT NULL DEFAULT '',
            imei VARCHAR(32) NOT NULL DEFAULT '',
            passcode VARCHAR(64) NOT NULL DEFAULT '',
            fault TEXT NULL,
            diagnosis TEXT NULL,
            repair_type VARCHAR(32) NOT NULL DEFAULT '',
            due_date DATE NULL,
            device_held TINYINT NOT NULL DEFAULT 1,
            quote DECIMAL(10,2) NULL,
            deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
            deposit_order_id BIGINT UNSIGNED NULL,
            balance_order_id BIGINT UNSIGNED NULL,
            draft_order_id BIGINT UNSIGNED NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'booked',
            assigned_user BIGINT UNSIGNED NOT NULL DEFAULT 0,
            warranty_days INT NOT NULL DEFAULT 90,
            source VARCHAR(16) NOT NULL DEFAULT 'walkin',
            collected_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY status (status),
            KEY imei (imei),
            KEY branch_created (branch_id,created_at)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_ticket_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(32) NOT NULL,
            detail TEXT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_vendors (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(128) NOT NULL,
            email VARCHAR(128) NOT NULL DEFAULT '',
            phone VARCHAR(32) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            notes TEXT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_parts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(64) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL,
            compat VARCHAR(255) NOT NULL DEFAULT '',
            default_vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            min_qty INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY sku (sku)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_part_stock (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            part_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            avg_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY part_branch (part_id,branch_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_purchase_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_code VARCHAR(16) NOT NULL DEFAULT '',
            vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            shipping DECIMAL(10,2) NOT NULL DEFAULT 0,
            ordered_at DATETIME NULL,
            received_at DATETIME NULL,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY po_code (po_code),
            KEY status (status),
            KEY vendor_id (vendor_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_po_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id BIGINT UNSIGNED NOT NULL,
            part_id BIGINT UNSIGNED NULL,
            ticket_id BIGINT UNSIGNED NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            product_url TEXT NULL,
            qty INT NOT NULL DEFAULT 1,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            qty_received INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY po_id (po_id),
            KEY ticket_id (ticket_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_ticket_parts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            part_id BIGINT UNSIGNED NULL,
            po_line_id BIGINT UNSIGNED NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            qty INT NOT NULL DEFAULT 1,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            source VARCHAR(16) NOT NULL DEFAULT 'stock',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_sync_state (
            k VARCHAR(64) NOT NULL,
            v LONGTEXT NULL,
            PRIMARY KEY  (k)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}wbam_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task VARCHAR(32) NOT NULL,
            payload LONGTEXT NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            next_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY next_at (next_at)
        ) $c;";

        foreach ($sql as $q) dbDelta($q);
    }
}
