<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
$options = array('kmpanel_secret', 'kmpanel_auto_update', 'kmpanel_panel_url', 'kmpanel_update_source', 'kmpanel_last_contact', 'kmpanel_update_status', 'kmpanel_auto_update_status', 'kmpanel_remote_management', 'kmpanel_login_user', 'kmpanel_update_lock');
foreach ($options as $option) { delete_option($option); }
delete_transient('kmpanel_release');
wp_clear_scheduled_hook('kmpanel_cleanup');
wp_clear_scheduled_hook('kmpanel_connector_update');
global $wpdb;
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('kmpanel_nonce_') . '%'));

$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('kmpanel_login_') . '%'));
