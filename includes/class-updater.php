<?php
namespace KMPanel\Connector;

if (!defined('ABSPATH')) { exit; }

final class Updater
{
    const REPOSITORY = 'kelyanmedia/KMPanelConnector';
    const CACHE = 'kmpanel_release';

    public static function boot()
    {
        add_filter('update_plugins_github.com', array(__CLASS__, 'update'), 10, 4);
        add_filter('auto_update_plugin', array(__CLASS__, 'autoUpdate'), 10, 2);
        add_filter('upgrader_pre_download', array(__CLASS__, 'download'), 10, 4);
        add_filter('plugins_api', array(__CLASS__, 'info'), 10, 3);
        add_action('upgrader_process_complete', array(__CLASS__, 'clear'), 10, 0);
    }

    public static function clear() { delete_transient(self::CACHE); }

    public static function autoUpdate($update, $item)
    {
        if (isset($item->plugin) && $item->plugin === plugin_basename(KMPANEL_CONNECTOR_FILE)) {
            return (bool) get_option('kmpanel_auto_update', true);
        }
        return $update;
    }

    public static function update($update, $data, $file, $locales)
    {
        if ($file !== plugin_basename(KMPANEL_CONNECTOR_FILE)) { return $update; }
        $release = self::latest();
        if (!$release) { return false; }
        return array('id' => 'https://github.com/' . self::REPOSITORY, 'slug' => 'kmpanel-connector', 'version' => $release['version'], 'url' => 'https://github.com/' . self::REPOSITORY, 'package' => $release['download_url'], 'requires' => $release['requires'], 'requires_php' => $release['requires_php'], 'tested' => $release['tested'], 'autoupdate' => (bool) get_option('kmpanel_auto_update', true));
    }

    public static function latest()
    {
        $cached = get_transient(self::CACHE);
        if (is_array($cached)) { return empty($cached['version']) ? null : $cached; }
        $panel = rtrim((string) get_option('kmpanel_panel_url', ''), '/');
        $proxy = get_option('kmpanel_update_source', 'github') === 'panel';
        if ($proxy) {
            if (!$panel || !get_option('kmpanel_secret') || strpos($panel, 'https://') !== 0) { return null; }
            $url = $panel . '/api/connector/releases/latest';
            $response = wp_safe_remote_get($url, array('timeout' => 15, 'redirection' => 0, 'headers' => Auth::headers('GET', $url), 'limit_response_size' => 256 * 1024));
            $release = self::json($response);
        } else {
            $response = wp_safe_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', array('timeout' => 15, 'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'KMPanel-Connector/' . KMPANEL_CONNECTOR_VERSION), 'limit_response_size' => 256 * 1024));
            $data = self::json($response);
            $release = null;
            if ($data && empty($data['draft']) && empty($data['prerelease'])) {
                $zip = null;
                $checksum_url = null;
                foreach (isset($data['assets']) ? $data['assets'] : array() as $asset) {
                    if ($asset['name'] === 'kmpanel-connector.zip') { $zip = $asset; }
                    if ($asset['name'] === 'kmpanel-connector.zip.sha256') { $checksum_url = $asset['browser_download_url']; }
                }
                if ($zip && self::githubPackage($zip['browser_download_url'])) {
                    $digest = isset($zip['digest']) && strpos($zip['digest'], 'sha256:') === 0 ? substr($zip['digest'], 7) : '';
                    if (!$digest && $checksum_url && self::githubPackage($checksum_url)) {
                        $checksum = wp_safe_remote_get($checksum_url, array('timeout' => 15, 'limit_response_size' => 1024));
                        if (!is_wp_error($checksum) && wp_remote_retrieve_response_code($checksum) === 200) { $digest = substr(trim(wp_remote_retrieve_body($checksum)), 0, 64); }
                    }
                    $release = array('version' => ltrim($data['tag_name'], 'v'), 'download_url' => $zip['browser_download_url'], 'sha256' => $digest, 'requires' => '6.0', 'requires_php' => '7.4', 'tested' => '');
                }
            }
        }
        if (!is_array($release) || empty($release['version']) || !preg_match('/^\d+\.\d+\.\d+$/D', $release['version']) || empty($release['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $release['sha256']) || empty($release['download_url']) || ($proxy ? !self::panelPackage($release['download_url'], $panel) : !self::githubPackage($release['download_url']))) {
            set_transient(self::CACHE, array('unavailable' => true), 5 * MINUTE_IN_SECONDS);
            update_option('kmpanel_update_status', array('checked_at' => gmdate('c'), 'ok' => false, 'message' => 'Release unavailable. Check update source and panel/repository access.'), false);
            return null;
        }
        $release = array_merge(array('requires' => '6.0', 'requires_php' => '7.4', 'tested' => ''), $release);
        $release['proxy'] = $proxy;
        set_transient(self::CACHE, $release, HOUR_IN_SECONDS);
        update_option('kmpanel_update_status', array('checked_at' => gmdate('c'), 'ok' => true, 'version' => $release['version']), false);
        return $release;
    }

    private static function json($response)
    {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return null; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : null;
    }

    public static function githubPackage($url)
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'], $parts['path']) && $parts['scheme'] === 'https' && $parts['host'] === 'github.com' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && strpos($parts['path'], '/' . self::REPOSITORY . '/releases/download/') === 0;
    }

    public static function panelPackage($url, $panel)
    {
        return (bool) preg_match('~^' . preg_quote(rtrim($panel, '/') . '/api/connector/releases/download/', '~') . '[0-9]+\.[0-9]+\.[0-9]+$~D', $url);
    }

    public static function download($reply, $package, $upgrader, $extra)
    {
        if ($reply !== false) { return $reply; }
        $is_ours = isset($extra['plugin']) && $extra['plugin'] === plugin_basename(KMPANEL_CONNECTOR_FILE);
        $panel = rtrim((string) get_option('kmpanel_panel_url', ''), '/');
        if (!$is_ours && !self::githubPackage($package) && !($panel && self::panelPackage($package, $panel))) { return $reply; }
        $release = self::latest();
        if (!$release || $package !== $release['download_url']) { return new \WP_Error('kmpanel_release', 'The verified release manifest is unavailable or changed. Check for updates again.'); }
        if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $file = wp_tempnam('kmpanel-connector.zip');
        if (!$file) { return new \WP_Error('kmpanel_temp', 'Could not create a temporary update file.'); }
        $args = array('timeout' => 120, 'stream' => true, 'filename' => $file, 'limit_response_size' => 20 * 1024 * 1024, 'redirection' => $release['proxy'] ? 0 : 5);
        if ($release['proxy']) { $args['headers'] = Auth::headers('GET', $package); }
        $response = wp_safe_remote_get($package, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || !hash_equals($release['sha256'], (string) hash_file('sha256', $file))) {
            @unlink($file);
            return new \WP_Error('kmpanel_integrity', 'Update download failed integrity verification. The installed plugin was not changed.');
        }
        return $file;
    }

    public static function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'kmpanel-connector') { return $result; }
        $release = self::latest();
        return (object) array('name' => 'KMPanel Connector', 'slug' => 'kmpanel-connector', 'version' => $release ? $release['version'] : KMPANEL_CONNECTOR_VERSION, 'author' => 'KelyanMedia', 'homepage' => 'https://github.com/' . self::REPOSITORY, 'requires' => '6.0', 'requires_php' => '7.4', 'download_link' => $release ? $release['download_url'] : '', 'sections' => array('description' => 'Secure, read-only WordPress inventory for KMPanel. No backups or customer data collection. Updates are SHA-256 verified.'));
    }
}
