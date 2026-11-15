<?php
/** wp --path=/path/to/site eval-file /secure/path/auto-update-smoke.php */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit(1); }
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$file = 'kmpanel-connector/kmpanel-connector.php';
if (!is_plugin_active($file)) { WP_CLI::error('Connector must be active.'); }
\KMPanel\Connector\Updater::clear();
delete_site_transient('update_plugins');
wp_update_plugins();
$updates = get_site_transient('update_plugins');
if (empty($updates->response[$file])) { WP_CLI::error('No connector update offer.'); }
$item = $updates->response[$file];
$updater = new WP_Automatic_Updater();
if ($updater->is_disabled() || !$updater->should_update('plugin', $item, WP_PLUGIN_DIR)) { WP_CLI::error('WordPress policy blocks automatic update.'); }
if (!WP_Upgrader::create_lock('auto_updater')) { WP_CLI::error('Another automatic updater holds the lock.'); }
$was_network_active = is_plugin_active_for_network($file);
$background = static function () { return true; };
add_filter('wp_doing_cron', $background, PHP_INT_MAX);
try {
    $updater->update('plugin', $item);
    $installed = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
    if ($installed['Version'] !== $item->new_version) { WP_CLI::error('Update failed: installed version does not match offer.'); }
    if (!is_plugin_active($file) || is_plugin_active_for_network($file) !== $was_network_active) { WP_CLI::error('Update changed plugin activation state.'); }
    WP_CLI::success('Connector active and updated to ' . $installed['Version'] . '; no other update targets invoked.');
} finally {
    remove_filter('wp_doing_cron', $background, PHP_INT_MAX);
    WP_Upgrader::release_lock('auto_updater');
}
