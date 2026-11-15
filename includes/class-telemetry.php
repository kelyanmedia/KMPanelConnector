<?php
namespace KMPanel\Connector;

if (!defined('ABSPATH')) { exit; }

final class Telemetry
{
    public static function status()
    {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates = get_site_transient('update_themes');
        $core_updates = get_site_transient('update_core');
        $plugins = array();
        foreach (get_plugins() as $file => $plugin) {
            $update = isset($plugin_updates->response[$file]) ? $plugin_updates->response[$file] : null;
            $plugins[] = array('file' => $file, 'name' => wp_strip_all_tags($plugin['Name']), 'version' => $plugin['Version'], 'active' => is_plugin_active($file) || is_plugin_active_for_network($file), 'update_version' => $update ? $update->new_version : null);
        }
        $themes = array();
        foreach (wp_get_themes() as $slug => $theme) {
            $update = isset($theme_updates->response[$slug]) ? $theme_updates->response[$slug] : null;
            $themes[] = array('slug' => $slug, 'name' => wp_strip_all_tags($theme->get('Name')), 'version' => $theme->get('Version'), 'active' => $slug === get_stylesheet() || $slug === get_template(), 'update_version' => $update ? $update['new_version'] : null);
        }
        $core_count = 0;
        foreach (isset($core_updates->updates) ? $core_updates->updates : array() as $update) {
            if (isset($update->response) && $update->response === 'upgrade') { $core_count = 1; break; }
        }
        $result = array(
            'site_url' => home_url(), 'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION,
            'plugin_version' => KMPANEL_CONNECTOR_VERSION,
            'updates' => array('core' => $core_count, 'plugins' => count(isset($plugin_updates->response) ? $plugin_updates->response : array()), 'themes' => count(isset($theme_updates->response) ? $theme_updates->response : array())),
            'plugins' => $plugins, 'themes' => $themes,
            'health' => array('user_management_enabled' => (bool) get_option('kmpanel_user_management', false), 'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG, 'php_memory_limit' => ini_get('memory_limit'), 'ssl' => strpos(home_url(), 'https://') === 0, 'multisite' => is_multisite(), 'remote_management_enabled' => (bool) get_option('kmpanel_remote_management', false), 'admin_login_enabled' => (bool) get_option('kmpanel_remote_management', false) && (bool) get_option('kmpanel_login_user', 0)),
            'update_checks' => array('plugins_at' => isset($plugin_updates->last_checked) ? gmdate('c', $plugin_updates->last_checked) : null, 'themes_at' => isset($theme_updates->last_checked) ? gmdate('c', $theme_updates->last_checked) : null, 'core_at' => isset($core_updates->last_checked) ? gmdate('c', $core_updates->last_checked) : null),
            'connector_updates' => array('automatic_enabled' => (bool) get_option('kmpanel_auto_update', true), 'source' => get_option('kmpanel_update_source', 'github'), 'last_check' => get_option('kmpanel_update_status', null), 'last_install' => get_option('kmpanel_auto_update_status', null), 'next_check_at' => wp_next_scheduled('kmpanel_connector_update') ? gmdate('c', wp_next_scheduled('kmpanel_connector_update')) : null),
            'collected_at' => gmdate('c'),
        );
        $response = new \WP_REST_Response($result);
        $response->header('Cache-Control', 'no-store, private, max-age=0');
        $response->header('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
