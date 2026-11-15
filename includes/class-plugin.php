<?php
namespace KMPanel\Connector;

if (!defined('ABSPATH')) { exit; }

final class Plugin
{
    public static function boot()
    {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_kmpanel_settings', array(__CLASS__, 'save'));
        add_action('kmpanel_cleanup', array(Auth::class, 'cleanup'));
        add_action('admin_post_kmpanel_login', array(Management::class, 'consumeLogin'));
        add_action('admin_post_nopriv_kmpanel_login', array(Management::class, 'consumeLogin'));
        Updater::boot();
    }

    public static function activate()
    {
        if (!get_option('kmpanel_secret')) { add_option('kmpanel_secret', bin2hex(random_bytes(32)), '', false); }
        add_option('kmpanel_auto_update', true, '', false);
        if (!wp_next_scheduled('kmpanel_cleanup')) { wp_schedule_event(time() + 300, 'hourly', 'kmpanel_cleanup'); }
    }

    public static function deactivate() { wp_clear_scheduled_hook('kmpanel_cleanup'); }

    public static function routes()
    {
        register_rest_route('kmpanel/v1', '/update', array('methods' => 'POST', 'callback' => array(Management::class, 'update'), 'permission_callback' => array(Management::class, 'allowed')));
        register_rest_route('kmpanel/v1', '/login', array('methods' => 'POST', 'callback' => array(Management::class, 'issueLogin'), 'permission_callback' => array(Management::class, 'allowed')));
        register_rest_route('kmpanel/v1', '/status', array('methods' => 'GET', 'callback' => array(Telemetry::class, 'status'), 'permission_callback' => array(Auth::class, 'authorize')));
    }

    public static function menu() { add_options_page('KMPanel Connector', 'KMPanel', 'manage_options', 'kmpanel-connector', array(__CLASS__, 'page')); }

    public static function save()
    {
        if (!current_user_can('manage_options')) { wp_die('Not authorized.', '', array('response' => 403)); }
        check_admin_referer('kmpanel_settings');
        $action = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'save';
        if ($action === 'rotate') {
            update_option('kmpanel_secret', bin2hex(random_bytes(32)), false);
        } elseif ($action === 'revoke') {
            delete_option('kmpanel_secret');
        } elseif ($action === 'check') {
            Updater::clear();
            delete_site_transient('update_plugins');
            wp_update_plugins();
        } else {
            $url = isset($_POST['panel_url']) ? untrailingslashit(esc_url_raw(wp_unslash($_POST['panel_url']), array('https'))) : '';
            if ($url && (!wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_QUERY) || wp_parse_url($url, PHP_URL_FRAGMENT) || wp_parse_url($url, PHP_URL_USER))) {
                wp_die('Use a public HTTPS URL without credentials, query, or fragment.');
            }
            update_option('kmpanel_remote_management', isset($_POST['remote_management']), false);
            $login_user = isset($_POST['login_user']) ? absint($_POST['login_user']) : 0;
            update_option('kmpanel_login_user', $login_user && user_can($login_user, 'manage_options') ? $login_user : 0, false);
            update_option('kmpanel_panel_url', $url, false);
            update_option('kmpanel_auto_update', isset($_POST['auto_update']), false);
            update_option('kmpanel_update_source', isset($_POST['update_source']) && $_POST['update_source'] === 'panel' ? 'panel' : 'github', false);
        }
        Updater::clear();
        wp_safe_redirect(add_query_arg('saved', '1', admin_url('options-general.php?page=kmpanel-connector')));
        exit;
    }

    public static function page()
    {
        if (!current_user_can('manage_options')) { return; }
        $secret = (string) get_option('kmpanel_secret', '');
        $status = get_option('kmpanel_update_status', array());
        ?>
        <div class="wrap"><h1>KMPanel Connector</h1>
        <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
        <p>Read-only WordPress inventory for KMPanel. Connect this endpoint and its unique secret in the site's KMPanel card.</p>
        <table class="form-table"><tr><th>Endpoint</th><td><code><?php echo esc_html(rest_url('kmpanel/v1/status')); ?></code></td></tr>
        <tr><th>Connection secret</th><td><input type="password" class="large-text" readonly autocomplete="off" value="<?php echo esc_attr($secret); ?>" id="kmpanel-secret"><button type="button" class="button" id="kmpanel-reveal">Show / hide</button><p class="description">Copy into KMPanel over a trusted channel. Rotating immediately invalidates the previous secret. Never use this secret for another site.</p></td></tr>
        <tr><th>Last panel contact</th><td><?php echo esc_html(get_option('kmpanel_last_contact', 'Not connected yet')); ?></td></tr></table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="kmpanel_settings"><?php wp_nonce_field('kmpanel_settings'); ?>
        <table class="form-table"><tr><th><label for="panel_url">KMPanel URL</label></th><td><input id="panel_url" type="url" name="panel_url" class="regular-text" placeholder="https://panel.example.com" value="<?php echo esc_attr(get_option('kmpanel_panel_url', '')); ?>"><p class="description">Required only for updates from a private GitHub repository through KMPanel.</p></td></tr>
        <tr><th>Update source</th><td><select name="update_source"><option value="github" <?php selected(get_option('kmpanel_update_source', 'github'), 'github'); ?>>GitHub public releases</option><option value="panel" <?php selected(get_option('kmpanel_update_source', 'github'), 'panel'); ?>>KMPanel proxy (private repository)</option></select></td></tr>
        <tr><th>Automatic updates</th><td><label><input type="checkbox" name="auto_update" value="1" <?php checked((bool) get_option('kmpanel_auto_update', true)); ?>>Automatically install verified releases of KMPanel Connector</label><p class="description">Other plugins are unaffected. WordPress automatic updates and its scheduler must be enabled.</p></td></tr>
        <tr><th>Remote management</th><td><label><input type="checkbox" name="remote_management" value="1" <?php checked((bool) get_option('kmpanel_remote_management', false)); ?>>Allow signed, explicit plugin/theme/core updates from KMPanel</label><p class="description">Disabled by default. This does not enable automatic updates of other plugins.</p></td></tr>
        <tr><th>One-time admin login</th><td><select name="login_user"><option value="0">Disabled</option><?php foreach (get_users(array('role' => 'administrator', 'fields' => array('ID', 'display_name'))) as $admin) : ?><option value="<?php echo esc_attr($admin->ID); ?>" <?php selected((int) get_option('kmpanel_login_user', 0), $admin->ID); ?>><?php echo esc_html($admin->display_name); ?></option><?php endforeach; ?></select><p class="description">Choose the administrator account for 60-second single-use links. Requires remote management and HTTPS.</p></td></tr></table>
        <?php if ($status) : ?><p>Update check: <?php echo esc_html($status['checked_at'] ?? ''); ?> — <?php echo esc_html(!empty($status['ok']) ? 'Latest release ' . $status['version'] : ($status['message'] ?? 'Unavailable')); ?></p><?php endif; ?>
        <p><button class="button button-primary" name="operation" value="save">Save settings</button> <button class="button" name="operation" value="check">Check for updates</button> <button class="button" name="operation" value="rotate" onclick="return confirm('Replace the connection secret? Update it in KMPanel afterwards.')">Rotate secret</button> <button class="button" name="operation" value="revoke" onclick="return confirm('Disconnect KMPanel? Signed requests will stop working.')">Revoke access</button></p></form>
        <p>Connector <?php echo esc_html(KMPANEL_CONNECTOR_VERSION); ?>. Inventory includes installed plugin and theme names/versions; it does not expose users, orders, credentials, or content.</p>
        </div><script>document.getElementById('kmpanel-reveal').addEventListener('click', function () { var input = document.getElementById('kmpanel-secret'); input.type = input.type === 'password' ? 'text' : 'password'; });</script>
        <?php
    }
}
