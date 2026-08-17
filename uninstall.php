<?php
// Deliberately conservative: deactivating/uninstalling the plugin KEEPS all Hub
// data (units, tickets, orders warehouse). The IMEI registry is business-critical;
// drop the wbam_* tables manually if you ever truly want them gone.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
delete_option('wbam_settings');
delete_option('wbam_db_ver');
