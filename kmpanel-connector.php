<?php
/**
 * Plugin Name: KMPanel Connector
 * Plugin URI: https://github.com/kelyanmedia/KMPanelConnector
 * Description: Secure technical inventory and health reporting for KMPanel, with automatic GitHub release updates.
 * Version: 1.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: KelyanMedia
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/kelyanmedia/KMPanelConnector
 * Text Domain: kmpanel-connector
 */

if (!defined('ABSPATH')) { exit; }
define('KMPANEL_CONNECTOR_VERSION', '1.0.2');
define('KMPANEL_CONNECTOR_FILE', __FILE__);
define('KMPANEL_CONNECTOR_DIR', __DIR__);
require_once __DIR__ . '/includes/class-auth.php';
require_once __DIR__ . '/includes/class-telemetry.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-management.php';
require_once __DIR__ . '/includes/class-plugin.php';
\KMPanel\Connector\Plugin::boot();
register_activation_hook(__FILE__, array('KMPanel\\Connector\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('KMPanel\\Connector\\Plugin', 'deactivate'));
