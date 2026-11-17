<?php
/**
 * PHP/PDO SQLite regression: per-recipient scope, deduplication, announcements,
 * read ownership and push-subscription validation. Does not send network push.
 * Execute: php tests/notifications.php
 */
define('CRMB_TEST_BOOTSTRAP_ONLY', true);
require __DIR__ . '/progress.php';
function get_users($args = array()) {
    return array((object) array('ID'=>7), (object) array('ID'=>8), (object) array('ID'=>9));
}
function crmb_department($id) { return (int)$id === 1 ? (object)array('term_id'=>1) : false; }
function crmb_department_members($id) { return array((object)array('ID'=>8), (object)array('ID'=>9)); }
function wp_generate_uuid4() { static $i=0; return 'test-uuid-'.(++$i); }
function wp_next_scheduled($name) { return false; }
function wp_schedule_single_event($time,$name) { return true; }
// The progress.php bootstrap already defines home_url() and add_query_arg().
function wp_validate_redirect($url,$default=false) {
    return str_starts_with($url, '/main/') ? $url : $default;
}
function esc_url_raw($url) { return $url; }
function wp_http_validate_url($url) { return 0 === strpos($url,'https://') ? $url : false; }
function wp_parse_url($url,$component) { return parse_url($url,$component); }

$wpdb->db->exec("CREATE TABLE wp_crmb_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,
    summary TEXT,detail TEXT,href TEXT,source_key TEXT,created_utc TEXT,read_utc TEXT,
    push_state TEXT DEFAULT 'pending',attempts INTEGER DEFAULT 0,next_try_utc TEXT,
    UNIQUE(user_id,source_key)
);
CREATE TABLE wp_crmb_push_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,endpoint_hash TEXT UNIQUE,
    endpoint TEXT,p256dh TEXT,auth TEXT,created_utc TEXT
);");
require __DIR__.'/../inc/notifications.php';
require __DIR__.'/../inc/notifications-push.php';

check(160, crmb_notice_length(str_repeat('آ',160)));
check(2000, crmb_notice_length(str_repeat('ت',2000)));
$target = crmb_notice_url('messages', array('message'=>1));
check(true, crmb_notice_add(8,'message','پیام جدید داری','متن عمومی',$target,'message:1',7));
check(false, crmb_notice_add(8,'message','پیام جدید داری','متن عمومی',$target,'message:1',7));
check(0, crmb_notice_count());
check(0, count(crmb_notice_list()));
$uid = 8;
check(1, crmb_notice_count());
check('message', crmb_notice_list()[0]->kind);
check('متن عمومی', crmb_notice_list()[0]->summary);
check(true, crmb_notice_read(1));
check(0, crmb_notice_count());
check(1, count(crmb_notice_list()));
$uid = 7;
check(false, crmb_notice_read(1));
check(false, crmb_notice_add(8,'message','bad','summary','https://evil.example.com','bad:1',7));
check(false, crmb_notice_add(8,'unknown','bad','summary',$target,'bad:2',7));
check(false, crmb_notice_add(111,'message','bad','summary',$target,'bad:3',7));

// Explicitly acknowledging a manager note clears only the matching user's bell.
$guidance_url = crmb_notice_url('tasks', array('task_id'=>5));
check(true, crmb_notice_add(8,'manager','راهنمایی مدیر','راهنمایی ثبت شد',$guidance_url,'guide:52',7));
$uid=8; check(1, crmb_notice_count());
check(true, crmb_notice_read_guidance(52,8));
check(0, crmb_notice_count());
$uid=7;
check(true, crmb_notice_add(8,'manager','راهنمایی مدیر','راهنمایی ثبت شد',$guidance_url,'guide:53',7));
check(true, crmb_notice_read_guidance(53,7));
$uid=8; check(1, crmb_notice_count());
check(true, crmb_notice_read_guidance(53,8));
check(0, crmb_notice_count());
$uid=7;

$admin = false;
check('denied', crmb_notice_announce('اطلاعیه','متن','all',0));
$admin = true;
check('saved', crmb_notice_announce('برنامه شرکت','متن اطلاعیه کامل','department',1));
$uid = 8; $admin = false;
$feed = crmb_notice_list();
check('announcement', $feed[0]->kind);
check('متن اطلاعیه کامل', $feed[0]->detail);
check(1, crmb_notice_count());
$uid = 7;
check(0, crmb_notice_count());
$admin = true;
check('invalid', crmb_notice_announce('title','body','department',999));
check('saved', crmb_notice_announce('اطلاعیه عمومی','متن همه کارکنان','all',0));
check(1, crmb_notice_count());
check(true, crmb_notice_read(0,true));
check(0, crmb_notice_count());

// The manager must immediately know which task and which employee sent it.
// The same contextual copy appears in both the bell and full notices page.
$wpdb->insert( 'wp_crmb_tasks', array(
    'title' => 'پیگیری تأمین کابل صنعتی', 'description' => '',
    'assigned_to' => 7, 'created_by' => 8, 'priority' => 'normal',
    'status' => 'review', 'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    'updated_utc' => gmdate( 'Y-m-d H:i:s' )
) );
$review_task_id = (int) $wpdb->insert_id;
function notice_review_event( $task, $actor ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_task_events', array(
        'task_id' => $task, 'actor_id' => $actor, 'event_type' => 'review',
        'body' => '', 'created_utc' => gmdate( 'Y-m-d H:i:s' )
    ) );
    return (int) $wpdb->insert_id;
}
$review_event_id = notice_review_event( $review_task_id, 7 );
crmb_notice_task_review_requested( $review_task_id, 8, 7, $review_event_id );
$uid = 8; $admin = false;
$feed = crmb_notice_list( 30 );
$review_notices = array_values( array_filter( $feed, static fn( $n ) =>
    'task_review_request:' . $GLOBALS['review_event_id'] === $n->source_key
) );
check( 1, count( $review_notices ) );
check( true, str_contains( $review_notices[0]->title, 'پیگیری تأمین کابل صنعتی' ) );
check( true, str_contains( $review_notices[0]->summary, 'ارسال‌کننده: Employee' ) );
check( true, str_contains( $review_notices[0]->summary, '#' . $review_task_id ) );
check( true, str_contains( $review_notices[0]->href, 'task_id=' . $review_task_id ) );
check( false, (bool) $review_notices[0]->read_utc );
$uid = 9;
check( 0, count( array_filter( crmb_notice_list( 30 ), static fn( $n ) =>
    'task_review_request:' . $GLOBALS['review_event_id'] === $n->source_key
) ) );

// Re-submission includes the same identifiable task and submitter.
$uid = 7;
$again_event_id = notice_review_event( $review_task_id, 7 );
crmb_notice_task_review_resubmitted( $review_task_id, 8, 7, $again_event_id );
$uid = 8;
$again = array_values( array_filter( crmb_notice_list( 30 ), static fn( $n ) =>
    'task_review_resubmitted:' . $GLOBALS['again_event_id'] === $n->source_key
) );
check( 1, count( $again ) );
check( true, str_contains( $again[0]->title, 'پیگیری تأمین کابل صنعتی' ) );
check( true, str_contains( $again[0]->summary, 'ارسال‌کننده: Employee' ) );

// Existing generic entries already in the database get meaningful labels
// without re-inserting, marking read, or emitting another push.
$legacy_event_id = notice_review_event( $review_task_id, 7 );
$legacy_key = 'task_review_request:' . $legacy_event_id;
$old_href = crmb_notice_url( 'tasks', array( 'task_id' => $review_task_id ) );
check( true, crmb_notice_add( 8, 'task', 'کار در انتظار تأیید شماست',
    'مسئول کار درخواست تأیید نهایی فرستاده است.', $old_href, $legacy_key, 7 ) );
$legacy_again_id = notice_review_event( $review_task_id, 7 );
$legacy_again_key = 'task_review_resubmitted:' . $legacy_again_id;
check( true, crmb_notice_add( 8, 'task', 'اصلاحات کار برای تأیید مجدد ارسال شد',
    'کارمند پس از اصلاح، وظیفه را دوباره برای بازبینی شما فرستاده است.',
    $old_href, $legacy_again_key, 7 ) );
$raw_before = $wpdb->get_row( $wpdb->prepare(
    "SELECT title,read_utc FROM wp_crmb_notifications WHERE user_id=%d AND source_key=%s",
    8, $legacy_key
) );
check( 'کار در انتظار تأیید شماست', $raw_before->title );
check( null, $raw_before->read_utc );
$legacy_feed = crmb_notice_list( 30 );
$legacy = array_values( array_filter( $legacy_feed, static fn( $n ) =>
    $n->source_key === $GLOBALS['legacy_key']
) );
check( 1, count( $legacy ) );
check( true, str_contains( $legacy[0]->title, 'پیگیری تأمین کابل صنعتی' ) );
check( true, str_contains( $legacy[0]->summary, 'ارسال‌کننده: Employee' ) );
$legacy_again = array_values( array_filter( $legacy_feed, static fn( $n ) =>
    $n->source_key === $GLOBALS['legacy_again_key']
) );
check( 1, count( $legacy_again ) );
check( true, str_contains( $legacy_again[0]->title, 'پیگیری تأمین کابل صنعتی' ) );
$raw_after = $wpdb->get_row( $wpdb->prepare(
    "SELECT title,read_utc FROM wp_crmb_notifications WHERE user_id=%d AND source_key=%s",
    8, $legacy_key
) );
check( 'کار در انتظار تأیید شماست', $raw_after->title );
check( null, $raw_after->read_utc );
check( false, crmb_notice_add( 8, 'task', 'Duplicate', 'No', $old_href, $legacy_key, 7 ) );

// Reading an unrelated notification still respects recipient ownership.
$uid = 7;
check( false, crmb_notice_read( (int) $legacy[0]->id ) );
$uid = 8;
check( true, crmb_notice_read( (int) $legacy[0]->id ) );
check( true, (bool) $wpdb->get_var( $wpdb->prepare(
    "SELECT read_utc FROM wp_crmb_notifications WHERE id=%d", (int) $legacy[0]->id
) ) );

check(true, crmb_push_safe_endpoint('https://fcm.googleapis.com/fcm/send/demo'));
check(true, crmb_push_safe_endpoint('https://updates.push.services.mozilla.com/wpush/v1/demo'));
check(false, crmb_push_safe_endpoint('http://fcm.googleapis.com/fcm/send/demo'));
check(false, crmb_push_safe_endpoint('https://127.0.0.1/private'));
check(false, crmb_push_safe_endpoint('https://fcm.googleapis.com.example.com/fcm/send/demo'));
check(false, crmb_push_safe_endpoint('https://fake.example.net/anything'));
$p256dh = rtrim(strtr(base64_encode(str_repeat('x',65)),'+/','-_'),'=');
$auth = rtrim(strtr(base64_encode(str_repeat('y',16)),'+/','-_'),'=');
check(true, crmb_push_valid_key($p256dh,65));
check(true, crmb_push_valid_key($auth,16));
check(false, crmb_push_valid_key('invalid!',65));
check(false, crmb_push_valid_key($auth,65));
echo "$checks notification, ownership and endpoint regression checks passed\n";
