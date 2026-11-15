<?php
namespace KMPanel\Connector;

if (!defined('ABSPATH')) { exit; }

final class Management
{
    public static function allowed($request)
    {
        $auth = Auth::authorize($request);
        if (is_wp_error($auth)) { return $auth; }
        if (is_multisite()) { return new \WP_Error('kmpanel_multisite', 'Remote management for multisite requires a separate network permission model and is disabled.', array('status' => 403)); }
        if (!get_option('kmpanel_remote_management', false)) {
            return new \WP_Error('kmpanel_disabled', 'Remote management is disabled by this site administrator.', array('status' => 403));
        }
        return true;
    }

    public static function update($request)
    {
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) { return new \WP_Error('kmpanel_file_mods', 'File modifications are disabled.', array('status' => 403)); }
        $type = $request->get_param('type');
        $slug = (string) $request->get_param('slug');
        if (!in_array($type, array('plugin', 'theme', 'core'), true)) { return new \WP_Error('kmpanel_type', 'Supported types: plugin, theme, core.', array('status' => 400)); }
        $lock = 'kmpanel_update_lock';
        if (!add_option($lock, time(), '', false)) { return new \WP_Error('kmpanel_busy', 'Another update is running. If a previous PHP process crashed, clear kmpanel_update_lock after verifying it stopped.', array('status' => 409)); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        // Remote operations never prompt for FTP credentials or collect them.
        if (get_filesystem_method(array(), WP_CONTENT_DIR) !== 'direct') { delete_option($lock); return new \WP_Error('kmpanel_filesystem', 'Direct filesystem access is required.', array('status' => 409)); }
        $skin = new \Automatic_Upgrader_Skin();
        $result = null;
        $version = null;
        ob_start();
        try {
            if ($type === 'plugin') {
                $plugins = get_plugins();
                if (!isset($plugins[$slug])) { return new \WP_Error('kmpanel_plugin', 'Installed plugin not found.', array('status' => 404)); }
                wp_update_plugins();
                $upgrader = new \Plugin_Upgrader($skin);
                $result = $upgrader->upgrade($slug);
                wp_clean_plugins_cache(true);
                $plugins = get_plugins();
                $version = isset($plugins[$slug]) ? $plugins[$slug]['Version'] : null;
            } elseif ($type === 'theme') {
                $theme = wp_get_theme($slug);
                if (!$theme->exists()) { return new \WP_Error('kmpanel_theme', 'Installed theme not found.', array('status' => 404)); }
                wp_update_themes();
                $upgrader = new \Theme_Upgrader($skin);
                $result = $upgrader->upgrade($slug);
                wp_clean_themes_cache(true);
                $version = wp_get_theme($slug)->get('Version');
            } else {
                wp_version_check();
                $available = get_core_updates();
                $target = null;
                foreach (is_array($available) ? $available : array() as $candidate) {
                    if ($candidate->response === 'upgrade') { $target = $candidate; break; }
                }
                if (!$target) { return new \WP_REST_Response(array('success' => true, 'changed' => false, 'type' => $type, 'version' => get_bloginfo('version'))); }
                $upgrader = new \Core_Upgrader($skin);
                $result = $upgrader->upgrade($target);
                $version = is_string($result) ? $result : $target->current;
            }
        } catch (\Throwable $e) {
            return new \WP_Error('kmpanel_update_error', 'Update failed. Inspect the local WordPress error log.', array('status' => 500));
        } finally {
            ob_end_clean();
            delete_option($lock);
        }
        if (is_wp_error($result) || $result === false) { return new \WP_Error('kmpanel_update_failed', is_wp_error($result) ? wp_strip_all_tags($result->get_error_message()) : 'WordPress could not complete this update.', array('status' => 500)); }
        return new \WP_REST_Response(array('success' => true, 'changed' => $result !== null, 'type' => $type, 'slug' => $slug, 'version' => $version));
    }

    public static function issueLogin()
    {
        $user_id = (int) get_option('kmpanel_login_user', 0);
        if (!$user_id || !user_can($user_id, 'manage_options')) { return new \WP_Error('kmpanel_login_disabled', 'Select a local administrator to enable panel login.', array('status' => 403)); }
        $token = bin2hex(random_bytes(32));
        add_option('kmpanel_login_' . hash('sha256', $token), array('user_id' => $user_id, 'expires' => time() + 60, 'secret_hash' => hash('sha256', (string) get_option('kmpanel_secret', ''))), '', false);
        $response = new \WP_REST_Response(array('url' => add_query_arg(array('action' => 'kmpanel_login', 'token' => $token), admin_url('admin-post.php')), 'expires_at' => gmdate('c', time() + 60)));
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function consumeLogin()
    {
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        if (!is_ssl()) { wp_die('HTTPS is required.', '', array('response' => 403)); }
        $token = isset($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { wp_die('Invalid login link.', '', array('response' => 403)); }
        $key = 'kmpanel_login_' . hash('sha256', $token);
        $data = get_option($key);
        // DELETE on the unique option row makes consumption atomic, even across workers.
        global $wpdb;
        $consumed = $wpdb->delete($wpdb->options, array('option_name' => $key));
        wp_cache_delete($key, 'options');
        if (!$consumed || !is_array($data) || $data['expires'] < time() || !get_option('kmpanel_secret') || !isset($data['secret_hash']) || !hash_equals($data['secret_hash'], hash('sha256', (string) get_option('kmpanel_secret', ''))) || !get_option('kmpanel_remote_management', false) || (int) get_option('kmpanel_login_user') !== (int) $data['user_id'] || !user_can($data['user_id'], 'manage_options')) {
            wp_die('This login link is expired, revoked, or already used.', '', array('response' => 403));
        }
        $user = get_user_by('id', $data['user_id']);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, true);
        do_action('wp_login', $user->user_login, $user);
        wp_safe_redirect(admin_url());
        exit;
    }
}
