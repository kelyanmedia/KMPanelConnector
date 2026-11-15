<?php
/** Pure PHP contract tests. Deliberately no WordPress runtime or production credentials. */
define('ABSPATH', __DIR__ . '/');
define('KMPANEL_CONNECTOR_FILE', dirname(__DIR__) . '/kmpanel-connector.php');
define('KMPANEL_CONNECTOR_VERSION', '1.0.0');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$options = array();
$transients = array();
function get_option($key, $default = false) { global $options; return array_key_exists($key, $options) ? $options[$key] : $default; }
function add_option($key, $value, $deprecated = '', $autoload = null) { global $options; if (array_key_exists($key, $options)) { return false; } $options[$key] = $value; return true; }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; return true; }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; }
function delete_transient($key) { global $transients; unset($transients[$key]); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function plugin_basename($file) { return 'kmpanel-connector/kmpanel-connector.php'; }
function home_url() { return 'https://example.com'; }
function is_multisite() { return false; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { public $code; public $data; function __construct($code, $message, $data = null) { $this->code = $code; $this->data = $data; } }
class TestRequest {
    private $headers; private $body; private $route; private $method;
    function __construct($headers, $body = '', $route = '/kmpanel/v1/status', $method = 'GET') { $this->headers = $headers; $this->body = $body; $this->route = $route; $this->method = $method; }
    function get_header($name) { return $this->headers[$name] ?? ''; }
    function get_body() { return $this->body; }
    function get_route() { return $this->route; }
    function get_method() { return $this->method; }
}
require dirname(__DIR__) . '/includes/class-auth.php';
require dirname(__DIR__) . '/includes/class-updater.php';
require dirname(__DIR__) . '/includes/class-management.php';
use KMPanel\Connector\Auth;
use KMPanel\Connector\Updater;
use KMPanel\Connector\Management;
$count = 0;
function check($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . "\n"); exit(1); } }
$secret = str_repeat('a', 64); $ts = '1800000000'; $nonce = str_repeat('b', 32); $route = '/kmpanel/v1/status';
$signature = Auth::signature($secret, $ts, $nonce, 'GET', $route);
check(Auth::verify($secret, $ts, $nonce, $signature, 'GET', $route, '', (int) $ts), 'valid signature accepted');
check(!Auth::verify($secret, $ts, $nonce, $signature, 'GET', $route, '', (int) $ts + 301), 'expired signature rejected');
check(!Auth::verify($secret, $ts, $nonce, $signature, 'GET', $route, '', (int) $ts - 301), 'future signature rejected');
check(!Auth::verify('', $ts, $nonce, $signature, 'GET', $route, '', (int) $ts), 'revoked secret rejected');
check(!Auth::verify($secret, $ts, 'short', $signature, 'GET', $route, '', (int) $ts), 'short nonce rejected');
check(!Auth::verify($secret, $ts, $nonce, $signature, 'POST', $route, '', (int) $ts), 'method tampering rejected');
check(!Auth::verify($secret, $ts, $nonce, $signature, 'GET', '/kmpanel/v1/login', '', (int) $ts), 'route tampering rejected');
check(!Auth::verify($secret, $ts, $nonce, $signature, 'GET', $route, '{}', (int) $ts), 'body tampering rejected');
$headers = Auth::headers('POST', 'https://panel.test/api/connector/releases/latest', '{}');
check(isset($headers['X-KM-Site-Url']), 'site locator included');
$options['kmpanel_secret'] = $secret;
$headers = array('x-km-timestamp' => (string) time(), 'x-km-nonce' => $nonce);
$headers['x-km-signature'] = Auth::signature($secret, $headers['x-km-timestamp'], $nonce, 'GET', $route);
$request = new TestRequest($headers);
check(Auth::authorize($request) === true, 'first signed request accepted');
check(Auth::authorize($request)->code === 'kmpanel_replay', 'second identical request rejected');
check(get_option('kmpanel_last_contact') !== false, 'last contact recorded');
check(Updater::githubPackage('https://github.com/kelyanmedia/KMPanelConnector/releases/download/v1.0.0/kmpanel-connector.zip'), 'own public release allowed');
check(!Updater::githubPackage('https://github.com/evil/repo/releases/download/v1.0.0/plugin.zip'), 'other repository rejected');
check(!Updater::githubPackage('https://github.com.evil.test/kelyanmedia/KMPanelConnector/releases/download/v1.0.0/x.zip'), 'lookalike host rejected');
check(!Updater::githubPackage('https://user@github.com/kelyanmedia/KMPanelConnector/releases/download/v1.0.0/x.zip'), 'URL credential rejected');
check(Updater::panelPackage('https://panel.test/api/connector/releases/download/1.0.0', 'https://panel.test'), 'pinned proxy URL accepted');
check(!Updater::panelPackage('https://panel.test/api/connector/releases/download/1.0.0?x=y', 'https://panel.test'), 'unsigned query rejected');
check(!Updater::panelPackage('https://evil.test/api/connector/releases/download/1.0.0', 'https://panel.test'), 'different proxy origin rejected');
check(Updater::autoUpdate(null, (object) array('plugin' => 'kmpanel-connector/kmpanel-connector.php')) === true, 'own automatic updates enabled');
check(Updater::autoUpdate(null, (object) array('plugin' => 'other/other.php')) === null, 'other plugins unchanged');
$options['kmpanel_auto_update'] = false;
check(Updater::autoUpdate(true, (object) array('plugin' => 'kmpanel-connector/kmpanel-connector.php')) === false, 'local update opt-out honored');
$headers['x-km-nonce'] = str_repeat('c', 32);
$headers['x-km-signature'] = Auth::signature($secret, $headers['x-km-timestamp'], $headers['x-km-nonce'], 'POST', '/kmpanel/v1/update');
check(Management::allowed(new TestRequest($headers, '', '/kmpanel/v1/update', 'POST'))->code === 'kmpanel_disabled', 'remote updates disabled by default');
echo "PASS: $count security and contract checks\n";
