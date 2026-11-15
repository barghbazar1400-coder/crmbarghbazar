<?php
/** SQLite behavioral checks for per-user cursor chat. Run: php tests/chat.php */
define('ABSPATH', __DIR__);
if(!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"PDO SQLite required\n"); exit(2); }
$logged=true; $uid=7; $work_ready=true; $nonce_ok=true; $checks=0; $last_mutation=null;
function add_action($tag,$fn,$priority=10) {}
function add_filter($tag,$fn) {}
function is_user_logged_in() { global $logged; return $logged; }
function get_option($key) { global $work_ready; return $work_ready && $key==='crmb_work_schema_v1' ? '1' : false; }
function get_current_user_id() { global $uid; return $uid; }
function absint($number) { return abs((int)$number); }
function get_userdata($id) {
    return in_array((int)$id,array(7,8,9,10),true) ? (object)array('ID'=>(int)$id,'display_name'=>'کارمند '.$id) : false;
}
function get_users($args=array()) {
    return array_map('get_userdata',array(7,8,9,10));
}
function crmb_work_tables() { return array('messages'=>'wp_crmb_messages'); }
function crmb_work_date($v) { return (string)$v; }
function crmb_notice_ready() { return true; }
function crmb_notice_tables() { return array('notices'=>'wp_crmb_notifications'); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/','',(string)$v); }
function wp_unslash($v) { return $v; }
function nocache_headers() {}
function check_ajax_referer($action,$field) {
    global $nonce_ok;
    if(!$nonce_ok || $action!=='crmb_chat' || $field!=='nonce') throw new RuntimeException('bad_nonce');
}
function crmb_work_mutate($data) { global $last_mutation; $last_mutation=$data; return $data['body']===''?'invalid':'sent'; }
class CapturedChatResponse extends Exception {
    public $data; public $http;
    function __construct($data,$http){$this->data=$data;$this->http=$http;}
}
function wp_send_json_success($data) { throw new CapturedChatResponse($data,200); }
function wp_send_json_error($data,$http=400) { throw new CapturedChatResponse($data,$http); }
function verify($expected,$actual) {
    global $checks;
    ++$checks;
    if($expected!==$actual) throw new RuntimeException('Check '.$checks.' expected '.var_export($expected,true).' got '.var_export($actual,true));
}
class ChatTestDB {
    public $prefix='wp_'; public $last_error=''; public PDO $db;
    function __construct() {
        $this->db=new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_crmb_messages(
            id INTEGER PRIMARY KEY AUTOINCREMENT,sender_id INTEGER,recipient_id INTEGER,subject TEXT,
            body TEXT,created_utc TEXT,read_utc TEXT DEFAULT NULL
        );
        CREATE TABLE wp_crmb_notifications(
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,actor_id INTEGER,kind TEXT,read_utc TEXT DEFAULT NULL
        );");
    }
    function prepare($sql,...$args) {
        foreach($args as $arg) {
            $val=is_numeric($arg) && is_int($arg) ? (string)$arg : $this->db->quote((string)$arg);
            $sql=preg_replace_callback('/%[ds]/',fn($match)=>$match[0]==='%d'?(string)(int)$arg:$val,$sql,1);
        }
        return $sql;
    }
    function query($sql) {
        $this->last_error='';
        try { return $this->db->exec($sql); }
        catch(Throwable $err) {$this->last_error=$err->getMessage();return false;}
    }
    function get_row($sql) {
        $this->last_error='';
        try { $s=$this->db->query($sql); return $s->fetch(PDO::FETCH_OBJ)?:null; }
        catch(Throwable $err) {$this->last_error=$err->getMessage();return null;}
    }
    function get_results($sql) {
        $this->last_error='';
        try { return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
        catch(Throwable $err) {$this->last_error=$err->getMessage();return null;}
    }
}
$wpdb=new ChatTestDB();
require __DIR__.'/../inc/chat.php';
// 85 messages in chronological order, bidirectional between current user and peer.
$insert=$wpdb->db->prepare("INSERT INTO wp_crmb_messages(sender_id,recipient_id,subject,body,created_utc) VALUES(?,?,?,?,'2026-11-15 09:00:00')");
for($i=1;$i<=85;$i++) {
    $mine=$i%2===0;
    $insert->execute(array($mine?7:8,$mine?8:7,'پیام گفتگو','Message '.$i));
}
$insert->execute(array(9,10,'private','Top secret other thread'));
$wpdb->db->exec("INSERT INTO wp_crmb_notifications(user_id,actor_id,kind) VALUES(7,8,'message'),(8,7,'message'),(7,9,'message')");
verify(false,(bool)crmb_chat_peer(7));
verify(false,(bool)crmb_chat_peer(99));
verify(0,crmb_chat_peer_from_message(86));
verify(8,crmb_chat_peer_from_message(1));
verify(0,crmb_chat_peer_from_message(0));
$page=crmb_chat_page(8);
verify(true,$page['ready']);
verify(30,count($page['rows']));
verify(true,$page['has_more']);
verify(56,(int)$page['rows'][0]->id);
verify(85,(int)$page['rows'][29]->id);
verify(85,(int)$page['rows'][29]->id);
$older=crmb_chat_page(8,56);
verify(30,count($older['rows']));
verify(26,(int)$older['rows'][0]->id);
verify(55,(int)$older['rows'][29]->id);
verify(true,$older['has_more']);
$last=crmb_chat_page(8,26);
verify(25,count($last['rows']));
verify(1,(int)$last['rows'][0]->id);
verify(false,$last['has_more']);
verify(0,count(crmb_chat_page(8,1)['rows']));
verify(false,crmb_chat_page(8,1,2)['ready']);
$forward=crmb_chat_page(8,26,0,10);
verify(10,count($forward['rows']));
verify(16,(int)$forward['rows'][0]->id);
verify(25,(int)$forward['rows'][9]->id);
// Cursor-based newer messages, ascending.
$forward=crmb_chat_page(8,0,16,10);
verify(10,count($forward['rows']));
verify(17,(int)$forward['rows'][0]->id);
verify(true,$forward['has_more']);
verify(false,crmb_chat_page(9)['has_more']);
verify(0,count(crmb_chat_page(9)['rows']));
verify(false,crmb_chat_page(99)['ready']);
verify(30,count(crmb_chat_page(8,0,0,30)['rows']));
$contacts=crmb_chat_contacts();
verify(8,(int)$contacts[0]['id']);
verify(43,(int)$contacts[0]['unread']);
verify(true,count($contacts)>=3);
verify(true,crmb_chat_mark_read(8));
verify(0,(int)$wpdb->db->query("SELECT COUNT(*) FROM wp_crmb_messages WHERE recipient_id=7 AND sender_id=8 AND read_utc IS NULL")->fetchColumn());
verify(42,(int)$wpdb->db->query("SELECT COUNT(*) FROM wp_crmb_messages WHERE sender_id=7 AND recipient_id=8 AND read_utc IS NULL")->fetchColumn());
// Sent messages are unrelated to recipient read state.
verify(0,(int)$wpdb->db->query("SELECT COUNT(*) FROM wp_crmb_notifications WHERE user_id=7 AND actor_id=8 AND read_utc IS NULL")->fetchColumn());
verify(1,(int)$wpdb->db->query("SELECT COUNT(*) FROM wp_crmb_notifications WHERE user_id=8 AND actor_id=7 AND read_utc IS NULL")->fetchColumn());
$uid=8;
verify(7,crmb_chat_peer_from_message(2));
verify(false,crmb_chat_page(8)['ready']);
$uid=9;
verify(0,crmb_chat_peer_from_message(2));
verify(0,count(crmb_chat_page(7)['rows']));
$uid=7;

function ajax($mode,$extra=array()) {
    $_POST=array_merge(array('mode'=>$mode,'nonce'=>'mock','peer'=>'8'),$extra);
    try { crmb_chat_ajax(); } catch(CapturedChatResponse $e) { return $e; }
    throw new RuntimeException('No response');
}
$response=ajax('thread');
verify(200,$response->http);
verify(30,count($response->data['messages']));
verify('Message 56',$response->data['messages'][0]['text']);
verify(true,$response->data['has_more']);
verify(200,ajax('send',array('body'=>'new message','sender_id'=>'10','subject'=>'Injected')) ->http);
verify('send_message',$last_mutation['task']);
verify(false,'7' === $last_mutation['recipient_id']); // recipient must be current peer 8
verify('8',$last_mutation['recipient_id']);
verify('پیام گفتگو',$last_mutation['subject']);
verify(false,isset($last_mutation['sender_id']));
verify(400,ajax('send',array('peer'=>'7','body'=>'mine'))->http);
verify(400,ajax('thread',array('peer'=>'10','before'=>'23','after'=>'30'))->http);
verify(403,(function() use (&$logged) {$logged=false; $response=ajax('thread');$logged=true;return $response->http;})());
$nonce_ok=false;
try { ajax('thread');throw new RuntimeException('bad nonce permitted'); }
catch(RuntimeException $e) {verify('bad_nonce',$e->getMessage());}
$nonce_ok=true;
// The view never embeds all private message bodies in initial HTML.
$template=file_get_contents(__DIR__.'/../template-parts/messages.php');
verify(true,str_contains($template,'crmb-messenger'));
verify(true,str_contains($template,'crmb-chat-bubbles'));
verify(false,str_contains($template,'$message->body'));
$js=file_get_contents(__DIR__.'/../assets/js/crmb-chat.js');
verify(true,str_contains($js,'MAX_RENDERED = 240'));
verify(true,str_contains($js,'before: anchor'));
verify(true,str_contains($js,'after: anchor'));
echo "$checks private chat, cursor, read and paging checks passed\n";
