<?php
namespace KMPanel\Connector;

if (!defined('ABSPATH')) { exit; }

final class Auth
{
    public static function signature($secret, $timestamp, $nonce, $method, $route, $body = '')
    {
        return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . strtoupper($method) . "\n" . $route . "\n" . hash('sha256', $body), $secret);
    }

    public static function verify($secret, $timestamp, $nonce, $signature, $method, $route, $body, $now = null)
    {
        if (!$secret || !preg_match('/^[0-9]{10}$/D', (string) $timestamp) || !preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', (string) $nonce) || !preg_match('/^[a-f0-9]{64}$/D', (string) $signature)) {
            return false;
        }
        if (abs(($now === null ? time() : $now) - (int) $timestamp) > 300) { return false; }
        return hash_equals(self::signature($secret, $timestamp, $nonce, $method, $route, $body), $signature);
    }

    public static function authorize($request)
    {
        $secret = (string) get_option('kmpanel_secret', '');
        $timestamp = $request->get_header('x-km-timestamp');
        $nonce = $request->get_header('x-km-nonce');
        if (!self::verify($secret, $timestamp, $nonce, $request->get_header('x-km-signature'), $request->get_method(), $request->get_route(), $request->get_body())) {
            return new \WP_Error('kmpanel_auth', 'Invalid or expired signature.', array('status' => 401));
        }
        // An option with a unique name provides atomic replay protection across PHP workers.
        $key = 'kmpanel_nonce_' . hash('sha256', $nonce);
        if (!add_option($key, time() + 610, '', false)) {
            return new \WP_Error('kmpanel_replay', 'This request has already been used.', array('status' => 409));
        }
        update_option('kmpanel_last_contact', gmdate('c'), false);
        return true;
    }

    public static function headers($method, $url, $body = '')
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(20));
        $route = wp_parse_url($url, PHP_URL_PATH) ?: '/';
        return array(
            'X-KM-Timestamp' => $timestamp,
            'X-KM-Nonce' => $nonce,
            'X-KM-Site-Url' => home_url(),
            'X-KM-Signature' => self::signature((string) get_option('kmpanel_secret', ''), $timestamp, $nonce, $method, $route, $body),
            'Accept' => 'application/json',
        );
    }

    public static function cleanup()
    {
        global $wpdb;
        $like = $wpdb->esc_like('kmpanel_nonce_') . '%';
        // Fixed option prefix, bounded cleanup, and no secrets in the query.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 5000", $like, time()));
        $keys = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like('kmpanel_login_') . '%'));
        foreach ($keys as $key) { $value = get_option($key); if (is_array($value) && isset($value['expires']) && $value['expires'] < time()) { delete_option($key); } }
    }
}
