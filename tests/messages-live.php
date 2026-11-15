<?php
/** Regression for no-refresh private inbox HTML, without WordPress or MySQL. */
define('ABSPATH', __DIR__);
$uid=7; $logged=true; $allowed_nonce=true; $checks=0;
function add_action($hook,$callback,$priority=10) {}
function add_filter($hook,$callback) {}
function is_user_logged_in() { global $logged; return $logged; }
function get_current_user_id() { global $uid; return $uid; }
function wp_unslash($v) { return $v; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value)); }
function nocache_headers() {}
function check_ajax_referer($action,$field) {
    global $allowed_nonce;
    if(!$allowed_nonce || $action!=='crmb_notice_ajax' || $field!=='nonce') throw new RuntimeException('bad_nonce');
}
function get_template_directory() { return dirname(__DIR__); }
function home_url($path) { return 'https://crmbarghbazar.com'.$path; }
function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
function admin_url($path) { return '/wp-admin/'.$path; }
function esc_html($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function crmb_work_user_name($id) { return 'کارمند '.(int)$id; }
function crmb_work_date($date) { return (string)$date; }
function crmb_work_form_fields($mode,$id=0) {
    echo '<input name="action" value="crmb_work"><input name="task" value="'.esc_attr($mode).'"><input name="id" value="'.(int)$id.'">';
}
function crmb_work_list($type,$page,$limit,$team,$sent,$active,$focus) {
    global $uid;
    if($type!=='messages'||$page!==1||$team||$sent||$active||$focus) throw new RuntimeException('leaked scope');
    if($uid===9) return array('ready'=>true,'rows'=>array(),'total'=>0);
    $row=(object)array(
        'id'=>$uid===7?31:42,'sender_id'=>$uid===7?8:7,'recipient_id'=>$uid,
        'subject'=>'موضوع خصوصی '.$uid, 'body'=>'پیام اختصاصی کارمند '.$uid,
        'read_utc'=>null,'created_utc'=>'2026-11-15 09:00:00'
    );
    return array('ready'=>true,'rows'=>array($row),'total'=>1);
}
class CapturedAjaxResponse extends Exception {
    public $payload;
    public $status;
    function __construct($payload,$status) {$this->payload=$payload;$this->status=$status;}
}
function wp_send_json_success($payload) { throw new CapturedAjaxResponse($payload,200); }
function wp_send_json_error($payload,$status=400) { throw new CapturedAjaxResponse($payload,$status); }
function test_check($expected,$actual) {
    global $checks;
    if($expected!==$actual) throw new RuntimeException('Check '.(++$checks).' failed: '.var_export($actual,true));
    ++$checks;
}
require __DIR__.'/../inc/notifications.php';
$_POST=array('mode'=>'messages_html','nonce'=>'test');
function html_response() {
    try { crmb_notice_ajax(); } catch(CapturedAjaxResponse $e) { return $e; }
    throw new RuntimeException('AJAX did not finish');
}
$response=html_response();
test_check(200,$response->status);
test_check(true,str_contains($response->payload['html'],'پیام اختصاصی کارمند 7'));
test_check(false,str_contains($response->payload['html'],'پیام اختصاصی کارمند 8'));
test_check(true,str_contains($response->payload['html'],'id="crmb-messages-live-list"'));
test_check(true,str_contains($response->payload['html'],'name="task" value="read_message"'));
$uid=8;
$response=html_response();
test_check(200,$response->status);
test_check(true,str_contains($response->payload['html'],'پیام اختصاصی کارمند 8'));
test_check(false,str_contains($response->payload['html'],'پیام اختصاصی کارمند 7'));
$uid=9;
$response=html_response();
test_check(200,$response->status);
test_check(true,str_contains($response->payload['html'],'هنوز پیامی'));
$allowed_nonce=false;
try { html_response(); throw new RuntimeException('nonce accepted'); }
catch(RuntimeException $e) { test_check('bad_nonce',$e->getMessage()); }
$allowed_nonce=true; $logged=false;
test_check(403,html_response()->status);
echo "$checks private AJAX inbox checks passed\n";
