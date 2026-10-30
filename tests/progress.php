<?php
/** SQLite-backed behavioral tests. Run with PHP PDO SQLite enabled. */
define('ABSPATH', __DIR__);
$uid=7; $admin=false; $logged=true; $ready=true; $checks=0;
function add_action($a,$b,$p=10) {}
function add_filter($a,$b) {}
function is_user_logged_in() { global $logged; return $logged; }
function current_user_can($cap) { global $admin; return $admin; }
function get_current_user_id() { global $uid; return $uid; }
function get_option($name) { global $ready; return $ready ? '1' : false; }
function absint($v) { return abs((int)$v); }
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_textarea_field($s) { return strip_tags($s); }
function get_userdata($id) { return $id>0 && $id<10 ? (object)array('display_name'=>'Employee') : false; }
function crmb_att_today() { return '2026-10-30'; }
function crmb_att_timezone() { return new DateTimeZone('Asia/Tehran'); }
function crmb_att_jdate($s) { return $s; }
function crmb_leave_parse_day($s) { return preg_match('/^2026-\d{2}-\d{2}$/D',$s) && checkdate((int)substr($s,5,2),(int)substr($s,8,2),2026) ? $s : false; }
function wp_kses($html,$allowed,$protocols) { global $allowed_html,$allowed_protocols; $allowed_html=$allowed; $allowed_protocols=$protocols; return strip_tags($html, '<p><strong><em><ul><ol><li><h3>'); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { function __construct($code) {} }
class ProgressDB {
    public $prefix='wp_'; public $last_error=''; public $insert_id=0; public $fail_table=''; public $sql=array(); public $db;
    function __construct() {
        $this->db=new PDO('sqlite::memory:'); $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_crmb_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,description TEXT,assigned_to INTEGER,created_by INTEGER,priority TEXT,due_day TEXT,status TEXT,created_utc TEXT,updated_utc TEXT,first_started_utc TEXT,timer_started_utc TEXT,completed_utc TEXT,tracked_seconds INTEGER DEFAULT 0);
        CREATE TABLE wp_crmb_work_locks(user_id INTEGER PRIMARY KEY);
        CREATE TABLE wp_crmb_task_events(id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,actor_id INTEGER,event_type TEXT,body TEXT,created_utc TEXT);
        CREATE TABLE wp_crmb_daily_reports(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,report_day TEXT,body TEXT,blockers TEXT,next_plan TEXT,status TEXT,created_utc TEXT,updated_utc TEXT,submitted_utc TEXT,reviewed_utc TEXT,reviewed_by INTEGER,UNIQUE(user_id,report_day));
        CREATE TABLE wp_crmb_report_images(id INTEGER PRIMARY KEY AUTOINCREMENT,report_id INTEGER,mime TEXT,image_data TEXT);");
    }
    function prepare($sql,...$args) { foreach($args as $arg) { $sql=preg_replace_callback('/%[ds]/',fn($m)=>$m[0]==='%d'?(string)(int)$arg:$this->db->quote($arg),$sql,1); } return $sql; }
    function run($sql) { $this->sql[]=$sql; $this->last_error=''; $sql=str_replace(array('START TRANSACTION','INSERT IGNORE'),array('BEGIN TRANSACTION','INSERT OR IGNORE'),$sql); $sql=preg_replace('/ FOR UPDATE$/','',$sql); try { return $this->db->query($sql); } catch(Throwable $e) { $this->last_error=$e->getMessage(); return false; } }
    function query($sql) { $s=$this->run($sql); return $s ? $s->rowCount() : false; }
    function get_row($sql) { $s=$this->run($sql); return $s ? ($s->fetch(PDO::FETCH_OBJ) ?: null) : null; }
    function get_var($sql) { $s=$this->run($sql); return $s ? $s->fetchColumn() : null; }
    function get_results($sql) { $s=$this->run($sql); return $s ? $s->fetchAll(PDO::FETCH_OBJ) : null; }
    function insert($table,$values) { if($table===$this->fail_table) { $this->last_error='injected write failure'; return false; } $keys=array_keys($values); $sql='INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_map(fn($v)=>$v===null?'NULL':$this->db->quote((string)$v),array_values($values))).')'; $r=$this->query($sql); $this->insert_id=(int)$this->db->lastInsertId(); return $r; }
    function update($table,$values,$where) { $set=implode(',',array_map(fn($k)=>$k.'='.($values[$k]===null?'NULL':$this->db->quote((string)$values[$k])),array_keys($values))); return $this->query('UPDATE '.$table.' SET '.$set.' WHERE '.$this->where($where)); }
    function where($where) { return implode(' AND ',array_map(fn($k)=>$k.'='.$this->db->quote((string)$where[$k]),array_keys($where))); }
    function delete($table,$where) { return $this->query('DELETE FROM '.$table.' WHERE '.$this->where($where)); }
}
$wpdb=new ProgressDB();
require __DIR__.'/../inc/work.php';
require __DIR__.'/../inc/progress.php';
require __DIR__.'/../inc/daily-reports.php';
function check($a,$b) { global $checks; if($a!==$b) throw new RuntimeException('Check '.($checks+1).' expected '.var_export($a,true).' got '.var_export($b,true)); $checks++; }
if (defined('CRMB_TEST_BOOTSTRAP_ONLY')) { return; }
$values=array('title'=>'Task','description'=>'Work','assigned_to'=>7,'created_by'=>1,'priority'=>'normal','status'=>'todo','created_utc'=>'2026-10-30 08:00:00','updated_utc'=>'2026-10-30 08:00:00');
$wpdb->insert('wp_crmb_tasks',$values); $id=$wpdb->insert_id;
$wpdb->insert('wp_crmb_tasks',$values); $other=$wpdb->insert_id;
$uid=8; check('denied',crmb_progress_transition($id,'doing')); check(null,crmb_progress_task($id));
$uid=7; check('saved',crmb_progress_transition($id,'doing')); $task=crmb_progress_task($id); check('doing',$task->status); check(true,(bool)$task->timer_started_utc); check(true,(bool)$task->first_started_utc);
check('running',crmb_progress_transition($other,'doing')); check('changed',crmb_progress_transition($id,'doing'));
$wpdb->update('wp_crmb_tasks',array('timer_started_utc'=>gmdate('Y-m-d H:i:s',time()-120)),array('id'=>$id));
check('saved',crmb_progress_transition($id,'paused')); $task=crmb_progress_task($id); check(null,$task->timer_started_utc); check(true,$task->tracked_seconds>=120 && $task->tracked_seconds<=121);
$seconds=(int)$task->tracked_seconds; check('00:02:00',crmb_progress_duration(120)); check($seconds,crmb_progress_seconds($task));
check('saved',crmb_progress_transition($other,'doing')); check('saved',crmb_progress_transition($other,'paused')); check('saved',crmb_progress_transition($id,'doing'));
$wpdb->fail_table='wp_crmb_task_events'; check('error',crmb_progress_transition($id,'done')); check('doing',crmb_progress_task($id)->status); check(true,(bool)crmb_progress_task($id)->timer_started_utc); $wpdb->fail_table='';
check('saved',crmb_progress_transition($id,'done')); check(true,(bool)crmb_progress_task($id)->completed_utc); check(null,crmb_progress_task($id)->timer_started_utc);
check('saved',crmb_progress_note($id,'blocker','Need feedback')); check('invalid',crmb_progress_note($id,'note',''));
$uid=8; check('denied',crmb_progress_note($id,'note','Foreign')); $admin=true; check(true,(bool)crmb_progress_task($id)); check('denied',crmb_progress_transition($id,'doing')); check('saved',crmb_progress_transition($id,'cancelled'));
$uid=7; $admin=false; check('denied',crmb_progress_transition($id,'doing')); $admin=true; check('saved',crmb_progress_transition($id,'todo')); $admin=false; check('saved',crmb_progress_transition($id,'doing'));
$admin=true; check('running',crmb_progress_edit_task($id,array('assigned_to'=>8))); check(7,(int)crmb_progress_task($id)->assigned_to); check('saved',crmb_progress_transition($id,'paused')); check('saved',crmb_progress_edit_task($id,array('assigned_to'=>8))); $admin=false; check(null,crmb_progress_task($id));
$events=$wpdb->get_results('SELECT * FROM wp_crmb_task_events ORDER BY id'); check(true,count($events)>5); check('doing',$events[0]->event_type); check(7,(int)$events[0]->actor_id);
$report=array('report_day'=>'2026-10-30','report_body'=>'<p>Daily result</p>','blockers'=>'Need help','next_plan'=>'Continue','mode'=>'draft');
check('invalid',crmb_report_save(array_replace($report,array('report_day'=>'2026-10-31')))); check('invalid',crmb_report_save(array_replace($report,array('report_day'=>'2026-09-01')))); check('invalid',crmb_report_save(array_replace($report,array('report_body'=>''))));
check('saved',crmb_report_save($report)); $rid=(int)$wpdb->get_var('SELECT id FROM wp_crmb_daily_reports WHERE user_id=7'); check('draft',crmb_report_get($rid)->status);
$uid=8; check(null,crmb_report_get($rid)); $admin=true; check(null,crmb_report_get($rid)); $uid=7; $admin=false;
check('saved',crmb_report_save(array_replace($report,array('report_body'=>'<h3>Updated</h3>','mode'=>'draft')))); check(1,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_daily_reports'));
$wpdb->insert('wp_crmb_report_images',array('report_id'=>$rid,'mime'=>'image/png','image_data'=>'abc')); $image_id=$wpdb->insert_id;
$uid=8; check('denied',crmb_report_remove_image($image_id)); $uid=7; check('saved',crmb_report_remove_image($image_id)); check(0,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_report_images'));
check('saved',crmb_report_save(array_replace($report,array('mode'=>'submit')))); check('locked',crmb_report_save($report)); check('denied',crmb_report_review($rid));
$uid=8; check(null,crmb_report_get($rid)); $admin=true; check(true,(bool)crmb_report_get($rid)); check('saved',crmb_report_review($rid)); check('changed',crmb_report_review($rid)); check('reviewed',crmb_report_get($rid)->status);
check(false,isset($allowed_html['img'])); check(false,isset($allowed_html['script'])); check(false,isset($allowed_html['iframe'])); check(array('http','https','mailto'),$allowed_protocols);
check(array(),crmb_report_uploads(array())); check(true,is_wp_error(crmb_report_uploads(array('images'=>array('error'=>array(UPLOAD_ERR_INI_SIZE))))));
check(true,is_wp_error(crmb_report_uploads(array('images'=>array('error'=>array(0,0,0,0))))));
check(true,is_wp_error(crmb_report_uploads(array('images'=>array('error'=>array(0),'tmp_name'=>array(__FILE__),'name'=>array('test.png'))))));
$admin=false; $logged=false; check('denied',crmb_progress_transition($other,'doing')); check('denied',crmb_report_save($report)); check(null,crmb_report_get($rid));
$logged=true; $ready=false; check('error',crmb_progress_transition($other,'doing')); check('error',crmb_report_save($report));

$ready=true; $uid=9; $admin=false;
$wpdb->fail_table='wp_crmb_daily_reports'; check('error',crmb_report_save($report)); check(0,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_daily_reports WHERE user_id=9')); $wpdb->fail_table='';
function esc_html($s) { return htmlspecialchars((string)$s); }
function esc_attr($s) { return htmlspecialchars((string)$s); }
function esc_url($s) { return htmlspecialchars((string)$s); }
function esc_textarea($s) { return htmlspecialchars((string)$s); }
function home_url($s) { return $s; }
function admin_url($s) { return $s; }
function add_query_arg($key,$value,$url='') { if(is_array($key)) return $value.'?'.http_build_query($key); return $url.'?'.$key.'='.$value; }
function get_transient($key) { return false; }
function wp_editor($body,$id,$settings) { echo '<textarea id="'.$id.'" name="'.$settings['textarea_name'].'">'.htmlspecialchars($body).'</textarea>'; }
function wp_nonce_field($action,$field) { echo '<input type="hidden" name="'.$field.'" value="test">'; }
function wpautop($s) { return $s; }
function wp_trim_words($s,$count) { return $s; }
$_GET=array();
ob_start(); include __DIR__.'/../template-parts/daily-reports.php'; $html=ob_get_clean();
check(true,str_contains($html,'name="report_body"')); check(true,str_contains($html,'multipart/form-data')); check(false,str_contains($html,'Daily result'));
$uid=7; ob_start(); include __DIR__.'/../template-parts/daily-reports.php'; $html=ob_get_clean();
check(false,str_contains($html,'name="report_body"')); check(true,str_contains($html,'Daily result'));
$uid=8; $_GET=array('report'=>$rid); ob_start(); include __DIR__.'/../template-parts/daily-reports.php'; $html=ob_get_clean();
check(false,str_contains($html,'Daily result')); check(true,str_contains($html,'اجازه')||str_contains($html,'مجوز'));
$admin=true; $_GET=array('scope'=>'team','report'=>$rid); ob_start(); include __DIR__.'/../template-parts/daily-reports.php'; $html=ob_get_clean(); check(true,str_contains($html,'Daily result'));
$admin=false; $uid=7; $_GET=array('task_id'=>$id); ob_start(); include __DIR__.'/../template-parts/task-progress.php'; $html=ob_get_clean(); check(false,str_contains($html,'Need feedback')); check(true,str_contains($html,'اجازه'));
$uid=8; $_GET=array('task_id'=>$id); ob_start(); include __DIR__.'/../template-parts/task-progress.php'; $html=ob_get_clean(); check(true,str_contains($html,'Need feedback')); check(true,str_contains($html,'زمان کار ثبت‌شده'));
$uid=7; $logged=false; ob_start(); include __DIR__.'/../template-parts/daily-reports.php'; check('',ob_get_clean());
$logged=true;
function wp_die($m,$title,$args) { throw new RuntimeException((string)$args['response']); }
function check_admin_referer($a,$field) { throw new RuntimeException('nonce_required'); }
$logged=false; try { crmb_progress_handle_post(); } catch (RuntimeException $e) { check('403',$e->getMessage()); }
$logged=true; $_SERVER['REQUEST_METHOD']='GET'; try { crmb_progress_handle_post(); } catch (RuntimeException $e) { check('405',$e->getMessage()); }
$_SERVER['REQUEST_METHOD']='POST'; try { crmb_progress_handle_post(); } catch (RuntimeException $e) { check('nonce_required',$e->getMessage()); }
echo "$checks progress/report database and rendering checks passed\n";
