<?php
namespace KMPanel\Connector;

if (!defined('ABSPATH')) { exit; }

final class Users
{
    public static function allowed($request)
    {
        $auth = Auth::authorize($request);
        if (is_wp_error($auth)) { return $auth; }
        if (!is_ssl() || is_multisite() || !get_option('kmpanel_user_management', false)) {
            return new \WP_Error('kmpanel_users_disabled', 'Enable user management in KMPanel Connector on a single-site HTTPS installation.', array('status' => 403));
        }
        return true;
    }

    public static function handle($request)
    {
        $operation = $request->get_param('operation') ?: 'list';
        if ($operation === 'list') {
            $page = max(1, (int) $request->get_param('page'));
            $query = new \WP_User_Query(array('number' => 100, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC'));
            $users = array();
            foreach ($query->get_results() as $user) {
                $users[] = array('id' => $user->ID, 'login' => $user->user_login, 'name' => $user->display_name, 'email' => $user->user_email, 'roles' => array_values($user->roles));
            }
            $roles = array();
            foreach (wp_roles()->roles as $key => $role) { $roles[$key] = translate_user_role($role['name']); }
            return self::response(array('users' => $users, 'roles' => $roles, 'total' => (int) $query->get_total(), 'page' => $page));
        }
        if (!in_array($operation, array('create', 'password', 'delete', 'login'), true)) { return self::error('Unknown operation.'); }
        $id = (int) $request->get_param('user_id');
        $user = $id > 0 ? get_user_by('id', $id) : false;
        if ($operation !== 'create' && !$user) { return self::error('User not found.'); }
        if ($operation === 'login') { return Management::issueUserLogin($id); }
        // Serialize destructive operations so concurrent requests cannot remove the last administrator.
        if (!add_option('kmpanel_users_lock', time(), '', false)) { return self::error('Another user operation is running.', 409); }
        try {
            if ($operation === 'delete') {
                $recipient = (int) $request->get_param('reassign');
                if (!$recipient || $recipient === $id || !get_user_by('id', $recipient)) { return self::error('Choose another existing user to receive all content.'); }
                if (in_array('administrator', $user->roles, true) && count(get_users(array('role' => 'administrator', 'number' => 2, 'fields' => 'ID'))) < 2) { return self::error('Cannot delete the last administrator.', 409); }
                require_once ABSPATH . 'wp-admin/includes/user.php';
                if (!wp_delete_user($id, $recipient)) { return self::error('Deletion failed.', 500); }
            } else {
                $password = $request->get_param('password');
                if (!is_string($password) || strlen($password) < 12 || strlen($password) > 256) { return self::error('Password must contain 12–256 characters.'); }
                if ($operation === 'password') {
                    wp_set_password($password, $id);
                    \WP_Session_Tokens::get_instance($id)->destroy_all();
                } else {
                    $role = (string) $request->get_param('role');
                    $login = (string) $request->get_param('login');
                    $email = (string) $request->get_param('email');
                    if (!get_role($role) || !validate_username($login) || !is_email($email)) { return self::error('Invalid role, login or email.'); }
                    $id = wp_insert_user(array('user_login' => $login, 'user_email' => $email, 'user_pass' => $password, 'role' => $role));
                    if (is_wp_error($id)) { return self::error(wp_strip_all_tags($id->get_error_message())); }
                }
            }
            return self::response(array('success' => true, 'user_id' => $id));
        } finally { delete_option('kmpanel_users_lock'); }
    }

    private static function response($data)
    {
        $response = new \WP_REST_Response($data);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    private static function error($message, $status = 400)
    {
        return new \WP_Error('kmpanel_users', $message, array('status' => $status));
    }
}
