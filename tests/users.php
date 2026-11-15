<?php
require __DIR__.'/run.php';
require dirname(__DIR__).'/includes/class-users.php';
function is_ssl() { return true; }
function get_user_by($field, $id) { global $userStore; return $userStore[$id] ?? false; }
function user_can($user, $cap) { return true; }
function delete_option($key) { global $options; unset($options[$key]); }
function get_users($args) { return array(1); }
function wp_roles() { return (object) array('roles' => array('administrator' => array('name' => 'Administrator'))); }
function translate_user_role($role) { return $role; }
class WP_REST_Response { public $data; function __construct($data) { $this->data=$data; } function header($a,$b) {} }
class WP_User_Query { function __construct($args) {} function get_results() { global $userStore; return $userStore; } function get_total() { return 2; } }
class UserRequest extends TestRequest { public $params=array(); function get_param($key) { return $this->params[$key] ?? null; } }
$userStore = array(1 => (object) array('ID'=>1,'user_login'=>'admin','display_name'=>'Admin','user_email'=>'a@example.com','user_pass'=>'secret-hash','roles'=>array('administrator')), 2 => (object) array('ID'=>2,'roles'=>array('subscriber'),'user_login'=>'reader','display_name'=>'Reader','user_email'=>'r@example.com','user_pass'=>'hash2'));
$r=new UserRequest(array());
$r->params=array('operation'=>'delete','user_id'=>1,'reassign'=>2);
check(\KMPanel\Connector\Users::handle($r)->code==='kmpanel_users', 'last administrator deletion rejected');
$r->params['reassign']=1;
check(\KMPanel\Connector\Users::handle($r)->code==='kmpanel_users', 'self reassignment rejected');
$r->params=array('operation'=>'password','user_id'=>1,'password'=>'tiny');
check(\KMPanel\Connector\Users::handle($r)->code==='kmpanel_users', 'weak password rejected');
$r->params=array('operation'=>'list');
$result=\KMPanel\Connector\Users::handle($r);
check(!str_contains(json_encode($result->data),'secret-hash'), 'list does not disclose password hashes');
$options['kmpanel_user_management']=true;
$data=array('user_id'=>1,'managed'=>true,'password_hash'=>hash('sha256','secret-hash'));
check(\KMPanel\Connector\Management::loginStillAllowed($data), 'managed user login allowed');
$options['kmpanel_user_management']=false;
check(!\KMPanel\Connector\Management::loginStillAllowed($data), 'revocation invalidates issued links');
$options['kmpanel_user_management']=true;$userStore[1]->user_pass='changed';
check(!\KMPanel\Connector\Management::loginStillAllowed($data), 'password reset invalidates issued links');
echo "PASS: user management checks\n";

function get_role($role) { return $role === 'subscriber'; }
function validate_username($login) { return $login === 'new-user'; }
function is_email($email) { return $email === 'new@example.com'; }
function wp_insert_user($data) { global $inserted; $inserted=$data; return 3; }
function wp_set_password($password,$id) { global $changedPassword; $changedPassword=array($id,$password); }
class WP_Session_Tokens { public static $destroyed=false; static function get_instance($id) { return new self; } function destroy_all() { self::$destroyed=true; } }
$r->params=array('operation'=>'create','login'=>'new-user','email'=>'new@example.com','password'=>'long-test-password','role'=>'subscriber');
check(\KMPanel\Connector\Users::handle($r)->data['user_id']===3, 'user creation returns new ID');
check($inserted['role']==='subscriber', 'creation respects chosen role');
$r->params=array('operation'=>'password','user_id'=>1,'password'=>'long-new-password');
check(\KMPanel\Connector\Users::handle($r)->data['success'], 'administrator password reset supported');
check(WP_Session_Tokens::$destroyed, 'password reset revokes sessions');
$nonce=bin2hex(random_bytes(16));$timestamp=(string)time();$route='/kmpanel/v1/users';
$headers=array('x-km-timestamp'=>$timestamp,'x-km-nonce'=>$nonce,'x-km-signature'=>\KMPanel\Connector\Auth::signature($secret,$timestamp,$nonce,'POST',$route));
$options['kmpanel_user_management']=false;
check(\KMPanel\Connector\Users::allowed(new TestRequest($headers,'',$route,'POST'))->code==='kmpanel_users_disabled', 'signed request still requires site opt-in');
echo "PASS: creation, password and opt-in checks\n";
