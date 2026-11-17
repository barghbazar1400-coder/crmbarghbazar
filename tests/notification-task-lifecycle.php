<?php
/**
 * Integration regression: an inbox is about outstanding information, not
 * a permanent task-state to-do list. Direct task visits and task decisions
 * clear bell alerts, while explicit manager-note read receipts stay separate.
 */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
define( 'CRMB_CAPTURE_ACTIONS', true );
require __DIR__ . '/progress.php';
function do_action( $name, ...$args ) {
    $hooks = $GLOBALS['crmb_test_actions'][$name] ?? array();
    ksort( $hooks );
    foreach ( $hooks as $callbacks ) {
        foreach ( $callbacks as $tuple ) {
            call_user_func_array( $tuple[0], array_slice( $args, 0, $tuple[1] ) );
        }
    }
}
function wp_next_scheduled( $name ) { return false; }
function wp_schedule_single_event( $time, $name ) { return true; }
function wp_generate_uuid4() { static $n = 0; return 'notification-uuid-' . ++$n; }
function wp_validate_redirect( $url, $fallback = false ) {
    return str_starts_with( $url, '/main/' ) ? $url : $fallback;
}
function esc_url_raw( $url ) { return $url; }
function is_page( $name ) { return $name === ( $GLOBALS['page_slug'] ?? 'main' ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
$wpdb->db->exec( "CREATE TABLE wp_crmb_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,
    summary TEXT,detail TEXT,href TEXT,source_key TEXT,created_utc TEXT,read_utc TEXT,
    push_state TEXT DEFAULT 'pending',attempts INTEGER DEFAULT 0,next_try_utc TEXT,
    UNIQUE(user_id,source_key)
);
CREATE TABLE wp_crmb_push_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,endpoint_hash TEXT UNIQUE,
    endpoint TEXT,p256dh TEXT,auth TEXT,created_utc TEXT
);" );
require __DIR__ . '/../inc/notifications.php';
function insert_task( $title, $state = 'review', $owner = 7, $creator = 8 ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_tasks', array(
        'title' => $title, 'description' => '', 'assigned_to' => $owner,
        'created_by' => $creator, 'priority' => 'normal', 'status' => $state,
        'created_utc' => gmdate( 'Y-m-d H:i:s' ),
        'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
    ) );
    return (int) $wpdb->insert_id;
}
function review_event( $task_id, $type = 'review', $actor = 7 ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_task_events', array(
        'task_id' => $task_id, 'actor_id' => $actor,
        'event_type' => $type, 'body' => '',
        'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    ) );
    return (int) $wpdb->insert_id;
}
function notice_status( $key, $recipient ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT id,source_key,read_utc,push_state FROM wp_crmb_notifications
           WHERE user_id=%d AND source_key=%s", $recipient, $key
    ) );
}
function issue_review_notice( $task_id, $event_id ) {
    crmb_notice_task_review_requested( $task_id, 8, 7, $event_id );
    return 'task_review_request:' . $event_id;
}

// Legacy review alerts created before this fix: close one already decided
// and one obsolete earlier submission even though the task is in review again.
$active_task = insert_task( 'در انتظار تصمیم' );
$active_event = review_event( $active_task );
$active_key = issue_review_notice( $active_task, $active_event );
$closed_task = insert_task( 'تصمیم قدیمی', 'done' );
$closed_event = review_event( $closed_task );
$closed_key = issue_review_notice( $closed_task, $closed_event );
$again_task = insert_task( 'ارسال مجدد' );
$first_event = review_event( $again_task );
$obsolete_key = issue_review_notice( $again_task, $first_event );
$latest_event = review_event( $again_task );
$latest_key = issue_review_notice( $again_task, $latest_event );

$uid = 8; $admin = true;
check( 2, crmb_notice_count() ); // initial reconciliation of legacy state
check( null, notice_status( $active_key, 8 )->read_utc );
check( null, notice_status( $latest_key, 8 )->read_utc );
check( true, (bool) notice_status( $closed_key, 8 )->read_utc );
check( true, (bool) notice_status( $obsolete_key, 8 )->read_utc );
check( 'skipped', notice_status( $closed_key, 8 )->push_state );

// A direct /main/?crm_section=tasks&task_id=... visit, not a bell click,
// must clear its task alert before the topbar count is evaluated.
$_GET = array( 'crm_section' => 'tasks', 'task_id' => $active_task );
crmb_notice_task_view_route();
check( true, (bool) notice_status( $active_key, 8 )->read_utc );
check( 'skipped', notice_status( $active_key, 8 )->push_state );
check( 1, crmb_notice_count() );
$_GET['task_id'] = $again_task;
crmb_notice_task_view_route();
check( 0, crmb_notice_count() );
check( true, (bool) notice_status( $latest_key, 8 )->read_utc );

// A task view never clears a coworker's messages or other people's alerts.
check( true, crmb_notice_add( 8, 'message', 'پیام تازه', 'پیام همکار',
    crmb_notice_url( 'messages' ), 'message:92', 7 ) );
$uid = 9; $admin = false;
check( true, crmb_notice_add( 9, 'task', 'اعلان بی‌دسترسی', 'تست',
    crmb_notice_task_url( $active_task ), 'task:fake:9', 7 ) );
check( false, crmb_notice_read_task_view( $active_task ) );
check( null, notice_status( 'task:fake:9', 9 )->read_utc );
$uid = 8; $admin = true;
check( true, crmb_notice_read_task_view( $active_task ) );
check( null, notice_status( 'message:92', 8 )->read_utc );

// Live manager decisions supersede the review request and notify only the
// employee. On resubmission, the previous correction notice becomes read.
$live_task = insert_task( 'گردش واقعی تأیید' );
$live_event = review_event( $live_task );
do_action( 'crmb_task_review_requested', $live_task, 8, 7, $live_event );
$live_key = 'task_review_request:' . $live_event;
check( null, notice_status( $live_key, 8 )->read_utc );
check( 'rejected', crmb_task_review_decide( $live_task, 'reject', 'لینک‌ها نیاز به اصلاح دارند' ) );
check( true, (bool) notice_status( $live_key, 8 )->read_utc );
$decision_id = (int) $wpdb->get_var( "SELECT id FROM wp_crmb_task_events
    WHERE task_id={$live_task} AND event_type='review_rejected' ORDER BY id DESC LIMIT 1" );
$decision_key = 'task_review_decision:' . $decision_id;
check( null, notice_status( $decision_key, 7 )->read_utc );
$uid = 7; $admin = false;
check( 'submitted', crmb_progress_transition( $live_task, 'done' ) );
check( true, (bool) notice_status( $decision_key, 7 )->read_utc );
$next_event_id = (int) $wpdb->get_var( "SELECT id FROM wp_crmb_task_events
    WHERE task_id={$live_task} AND event_type='review' ORDER BY id DESC LIMIT 1" );
check( null, notice_status( 'task_review_request:' . $next_event_id, 8 )->read_utc );
$uid = 8; $admin = true;
check( 'approved', crmb_task_review_decide( $live_task, 'approve' ) );
check( true, (bool) notice_status( 'task_review_request:' . $next_event_id, 8 )->read_utc );
$approved_id = (int) $wpdb->get_var( "SELECT id FROM wp_crmb_task_events
    WHERE task_id={$live_task} AND event_type='review_approved' ORDER BY id DESC LIMIT 1" );
check( null, notice_status( 'task_review_decision:' . $approved_id, 7 )->read_utc );
// Employee sees the approval in their inbox, then visits the archived task.
$uid = 7; $admin = false;
check( true, crmb_notice_read_task_view( $live_task ) );
check( true, (bool) notice_status( 'task_review_decision:' . $approved_id, 7 )->read_utc );

// Manager guidance remains formally UNACKNOWLEDGED even if its bell notice
// disappears after the employee reads the relevant task in the menu.
$guide_task = insert_task( 'راهنمایی جدید', 'todo' );
$guide_event = review_event( $guide_task, 'manager_note', 8 );
check( true, crmb_notice_add( 7, 'manager', 'راهنمایی مدیر', 'متن راهنمایی',
    crmb_notice_task_url( $guide_task ), 'guide:' . $guide_event, 8 ) );
check( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_guidance_reads
    WHERE event_id={$guide_event}" ) );
$_GET = array( 'crm_section' => 'tasks', 'task_id' => $guide_task );
crmb_notice_task_view_route();
check( true, (bool) notice_status( 'guide:' . $guide_event, 7 )->read_utc );
check( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_guidance_reads
    WHERE event_id={$guide_event}" ) );

// Normal direct navigation only, not a random request containing task_id.
$extra_task = insert_task( 'مسیر اشتباه', 'todo' );
check( true, crmb_notice_add( 7, 'task', 'وظیفه تازه', 'داده',
    crmb_notice_task_url( $extra_task ), 'task:' . $extra_task . ':assigned:test', 8 ) );
$_GET = array( 'crm_section'=>'notifications', 'task_id'=>$extra_task );
crmb_notice_task_view_route();
check( null, notice_status( 'task:' . $extra_task . ':assigned:test', 7 )->read_utc );
$_GET['crm_section'] = 'tasks';
$GLOBALS['page_slug'] = 'other';
crmb_notice_task_view_route();
check( null, notice_status( 'task:' . $extra_task . ':assigned:test', 7 )->read_utc );
$GLOBALS['page_slug'] = 'main';
crmb_notice_task_view_route();
check( true, (bool) notice_status( 'task:' . $extra_task . ':assigned:test', 7 )->read_utc );
echo "$checks task view, manager review lifecycle, deduplication, authorization and read-receipt checks passed\n";
