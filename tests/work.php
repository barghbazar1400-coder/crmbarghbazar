<?php
/** Standalone ownership, validation and SQL-scoping regression checks. */
define('ABSPATH', __DIR__);
$logged = true; $admin = false; $ready = true; $checks = 0;
function add_action($key, $callback, $priority = 10) {}
function add_filter($key, $callback) {}
function is_user_logged_in() { global $logged; return $logged; }
function current_user_can($cap) { global $admin; return $admin; }
function get_option($key) { global $ready; return $ready ? '1' : false; }
function get_current_user_id() { return 7; }
function absint($s) { return abs((int)$s); }
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_textarea_field($s) { return strip_tags($s); }
function get_userdata($id) { return in_array($id, array(7,8,9), true) ? (object) array('display_name' => 'Employee') : false; }
function crmb_leave_parse_day($s) { return '1405-08-08' === $s ? '2026-10-30' : false; }
class WorkDB {
    public $prefix = 'wp_'; public $last_error = ''; public $queries = array(); public $writes = array();
    public $rows = array(); public $row; public $fail = false; public $affected = 1;
    function prepare($sql, ...$args) {
        foreach ($args as $arg) { $sql = preg_replace_callback('/%[ds]/', fn($m) => $m[0] === '%d' ? (string)(int)$arg : "'" . str_replace("'", "''", $arg) . "'", $sql, 1); }
        return $sql;
    }
    function get_row($sql) { $this->queries[] = $sql; return $this->row; }
    function insert($table, $data) { $this->writes[] = array($table, $data); return $this->fail ? false : 1; }
    function update($table, $data, $where) { $this->writes[] = array($table, $data, $where); return $this->fail ? false : $this->affected; }
    function query($sql) { $this->queries[] = $sql; return $this->fail ? false : $this->affected; }
    function get_var($sql) { $this->queries[] = $sql; $this->last_error = $this->fail ? 'failed' : ''; return $this->fail ? null : 2; }
    function get_results($sql) { $this->queries[] = $sql; $this->last_error = $this->fail ? 'failed' : ''; return $this->rows; }
}
$wpdb = new WorkDB();
require __DIR__ . '/../inc/work.php';
function check($expected, $actual) { global $checks; if ($expected !== $actual) { throw new RuntimeException('Check ' . ($checks + 1) . ' failed: ' . var_export($actual, true)); } $checks++; }
$create = array('task'=>'save_task','title'=>'Task','description'=>'Body','assigned_to'=>'8','priority'=>'high','due_day'=>'1405-08-08');
$logged = false; check('denied', crmb_work_mutate($create)); check(false, crmb_work_list('tasks')['ready']);
$logged = true; $ready = false; check('error', crmb_work_mutate($create)); check(null, crmb_work_unread_count());
$ready = true; check('denied', crmb_work_mutate($create)); check(0, count($wpdb->writes));
$admin = true; check('saved', crmb_work_mutate($create));
check(7, $wpdb->writes[0][1]['created_by']); check('todo', $wpdb->writes[0][1]['status']); check('2026-10-30', $wpdb->writes[0][1]['due_day']);
foreach (array('assigned_to'=>'99','priority'=>'root','due_day'=>'invalid','title'=>'') as $key=>$value) { check('invalid', crmb_work_mutate(array_replace($create, array($key=>$value)))); }
check('invalid', crmb_work_mutate($create + array('id'=>array())));
check('invalid', crmb_work_mutate(array_replace($create, array('task'=>'no_action'))));
$wpdb->row = (object) array('id'=>4,'assigned_to'=>8,'status'=>'todo');
check('saved', crmb_work_mutate($create + array('id'=>'4')));
check(false, isset($wpdb->writes[1][1]['status']));
$admin = false; $status = array('task'=>'task_status','id'=>'4','status'=>'doing');
check('denied', crmb_work_mutate($status));
$wpdb->row->assigned_to = 7; check('saved', crmb_work_mutate($status));
$write = end($wpdb->writes); check(array('id'=>4,'assigned_to'=>7,'status'=>'todo'), $write[2]);
check('denied', crmb_work_mutate(array_replace($status,array('status'=>'cancelled'))));
$wpdb->row->status='cancelled'; check('denied', crmb_work_mutate($status));
$admin=true; check('saved', crmb_work_mutate($status));
$wpdb->affected=0; check('changed', crmb_work_mutate($status)); $wpdb->affected=1;
$message = array('task'=>'send_message','recipient_id'=>'8','subject'=>'Hello','body'=>'Text','sender_id'=>'99');
$admin=false; check('sent', crmb_work_mutate($message)); $write=end($wpdb->writes); check(7,$write[1]['sender_id']); check(false,isset($write[1]['read_utc']));
check('invalid',crmb_work_mutate(array_replace($message,array('recipient_id'=>'7'))));
check('invalid',crmb_work_mutate(array_replace($message,array('recipient_id'=>'99'))));
check('invalid',crmb_work_mutate(array_replace($message,array('body'=>''))));
check('read',crmb_work_mutate(array('task'=>'read_message','id'=>'5','recipient_id'=>'99')));
check(true,str_contains(end($wpdb->queries),'recipient_id = 7 AND read_utc IS NULL'));
$wpdb->affected=0; check('changed',crmb_work_mutate(array('task'=>'read_message','id'=>'5'))); $wpdb->affected=1;
$_GET['employee']=99;
$wpdb->queries=array(); crmb_work_list('tasks',1,5,true,false,true);
check(true,str_contains($wpdb->queries[0],'assigned_to = 7')); check(true,str_contains($wpdb->queries[1],"status IN ('todo','doing')")); check(true,str_contains($wpdb->queries[1],'LIMIT 5 OFFSET 0'));
$admin=true; $wpdb->queries=array(); crmb_work_list('tasks',1,20,true); check(true,str_contains($wpdb->queries[0],'WHERE 1=1'));
$wpdb->queries=array(); crmb_work_list('messages',1,20,true,false,false,99); check(true,str_contains($wpdb->queries[0],'recipient_id = 7 AND id = 99'));
$wpdb->queries=array(); crmb_work_list('messages',2,20,true,true); check(true,str_contains($wpdb->queries[1],'sender_id = 7')); check(true,str_contains($wpdb->queries[1],'OFFSET 20'));
$wpdb->fail=true; check(false,crmb_work_list('messages')['ready']); check(null,crmb_work_unread_count()); check('error',crmb_work_mutate($message));
$wpdb->fail=false; check(2,crmb_work_unread_count());

function home_url($s) { return $s; }
function admin_url($s) { return $s; }
function add_query_arg($key, $value, $url = '') { if (is_array($key)) { return $value . '?' . http_build_query($key); } return $url . '&' . $key . '=' . $value; }
function esc_html($s) { return htmlspecialchars((string)$s); }
function esc_attr($s) { return htmlspecialchars((string)$s); }
function esc_url($s) { return htmlspecialchars((string)$s); }
function esc_textarea($s) { return htmlspecialchars((string)$s); }
function sanitize_key($s) { return $s; }
function selected($a, $b) { if ((string)$a === (string)$b) echo 'selected'; }
function wp_nonce_field($a, $b) { echo '<input type="hidden" name="' . $b . '" value="test">'; }
function get_users($args) { return array((object)array('ID'=>8,'display_name'=>'Colleague')); }
function crmb_att_today() { return '2026-10-30'; }
function crmb_att_timezone() { return new DateTimeZone('Asia/Tehran'); }
function crmb_att_jdate($s) { return $s; }
$task_row = (object)array('id'=>3,'title'=>'<script>Task</script>','description'=>'<img src=x onerror=alert(1)>','assigned_to'=>7,'created_by'=>8,'priority'=>'high','due_day'=>'2026-10-29','status'=>'todo');
$wpdb->rows=array($task_row); $admin=false; $_GET=array();
ob_start(); include __DIR__ . '/../template-parts/tasks.php'; $html=ob_get_clean();
check(false,str_contains($html,'<script>')); check(true,str_contains($html,'&lt;script&gt;'));
check(false,str_contains($html,'واگذاری وظیفه جدید')); check(false,str_contains($html,'value="cancelled"'));
check(true,str_contains($html,'مهلت گذشته')); check(true,str_contains($html,'ثبت وضعیت'));
$admin=true; ob_start(); include __DIR__ . '/../template-parts/tasks.php'; $html=ob_get_clean();
check(true,str_contains($html,'واگذاری وظیفه جدید')); check(true,str_contains($html,'value="cancelled"'));
$wpdb->rows=array((object)array('id'=>5,'sender_id'=>8,'recipient_id'=>7,'subject'=>'<script>Message</script>','body'=>'<img src=x onerror=alert(1)>','created_utc'=>'2026-10-30 08:00:00','read_utc'=>null));
$admin=false; ob_start(); include __DIR__ . '/../template-parts/messages.php'; $html=ob_get_clean();
check(false,str_contains($html,'<script>')); check(false,str_contains($html,'<img src=')); check(true,str_contains($html,'خواندم'));
$_GET['box']='sent'; ob_start(); include __DIR__ . '/../template-parts/messages.php'; $html=ob_get_clean(); check(false,str_contains($html,'خواندم'));
$logged=false; ob_start(); include __DIR__ . '/../template-parts/messages.php'; check('',ob_get_clean());
ob_start(); include __DIR__ . '/../template-parts/tasks.php'; check('',ob_get_clean());
function wp_die($message, $title, $args) { throw new RuntimeException((string)$args['response']); }
function check_admin_referer($action, $field) { throw new RuntimeException('invalid_nonce'); }
$logged=false;
try { crmb_work_handle_post(); } catch (RuntimeException $e) { check('403',$e->getMessage()); }
$logged=true; $_SERVER['REQUEST_METHOD']='GET';
try { crmb_work_handle_post(); } catch (RuntimeException $e) { check('405',$e->getMessage()); }
$_SERVER['REQUEST_METHOD']='POST';
try { crmb_work_handle_post(); } catch (RuntimeException $e) { check('invalid_nonce',$e->getMessage()); }
echo "$checks work checks passed\n";
