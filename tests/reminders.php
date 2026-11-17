<?php
/** SQLite behavioral regression for opt-in daily CRM reminders. */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';

$test_options = array( 'crmb_reminder_last_day' => '' );
$scheduled = array(); $cleared = array();
function crmb_att_now_local() { return new DateTimeImmutable( '2026-10-30 08:20:00', crmb_att_timezone() ); }
function wp_next_scheduled( $name ) { return false; }
function wp_schedule_single_event( $time, $name ) {
    $GLOBALS['scheduled'][] = array( $name, $time ); return true;
}
function wp_clear_scheduled_hook( $name ) { $GLOBALS['cleared'][] = $name; return 1; }
function update_option( $key, $value, $autoload = false ) {
    $GLOBALS['test_options'][$key] = $value; return true;
}
function wp_validate_redirect( $url, $default = false ) {
    return str_starts_with( $url, '/main/' ) ? $url : $default;
}
function esc_url_raw( $url ) { return $url; }
function user_can( $user, $cap ) { return 9 === (int) $user && 'manage_options' === $cap; }
function get_users( $args = array() ) { return array(); }

$wpdb->db->exec( "CREATE TABLE wp_crmb_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,actor_id INTEGER,kind TEXT,
  title TEXT,summary TEXT,detail TEXT,href TEXT,source_key TEXT,
  created_utc TEXT,read_utc TEXT,push_state TEXT DEFAULT 'pending',
  attempts INTEGER DEFAULT 0,next_try_utc TEXT,UNIQUE(user_id,source_key)
);
CREATE TABLE wp_crmb_sales_customers (
  id INTEGER PRIMARY KEY,owner_id INTEGER,name TEXT
);
CREATE TABLE wp_crmb_sales_opportunities (
  id INTEGER PRIMARY KEY,customer_id INTEGER,owner_id INTEGER,followup_day TEXT,stage TEXT
);
CREATE TABLE wp_crmb_sales_calls (
  id INTEGER PRIMARY KEY,customer_id INTEGER,owner_id INTEGER,outcome TEXT,
  next_followup_day TEXT,created_utc TEXT
);" );
require __DIR__ . '/../inc/notifications.php';
require __DIR__ . '/../inc/sales.php';
require __DIR__ . '/../inc/sales-operations.php';
require __DIR__ . '/../inc/reminders.php';

// Default is opt-in, so releasing the code will not flood old inboxes.
check( 0, crmb_reminder_rules()['enabled'] );
check( 'disabled', crmb_reminder_run() );
check( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_notifications' ) );
check( true, crmb_reminder_overdue_milestone( 1 ) );
check( false, crmb_reminder_overdue_milestone( 2 ) );
check( true, crmb_reminder_overdue_milestone( 3 ) );
check( false, crmb_reminder_overdue_milestone( 5 ) );
check( true, crmb_reminder_overdue_milestone( 7 ) );
check( true, crmb_reminder_overdue_milestone( 14 ) );
check( false, crmb_reminder_overdue_milestone( 15 ) );

// Business timezone: run 09:00 in Tehran, not UTC or visitor time.
$tz = new DateTimeZone( 'Asia/Tehran' );
$next = crmb_reminder_due_moment( 9, new DateTimeImmutable( '2026-10-30 08:15:00', $tz ) );
check( '2026-10-30 09:00', ( new DateTimeImmutable( '@' . $next ) )
    ->setTimezone( $tz )->format( 'Y-m-d H:i' ) );
$next = crmb_reminder_due_moment( 9, new DateTimeImmutable( '2026-10-30 10:15:00', $tz ) );
check( '2026-10-31 09:00', ( new DateTimeImmutable( '@' . $next ) )
    ->setTimezone( $tz )->format( 'Y-m-d H:i' ) );

// Manager-only rule configuration with validation and configurable thresholds.
$uid=7; $admin=false;
$valid = array( 'enabled'=>'1','due'=>'1','overdue'=>'1','stalled'=>'1',
    'followups'=>'1', 'advance_days'=>'1','stale_hours'=>'48',
    'hour'=>'9','daily_cap'=>'12' );
check( 'denied', crmb_reminder_save_rules( $valid ) );
$uid=9; $admin=true;
check( 'invalid', crmb_reminder_save_rules( array_merge( $valid, array( 'hour'=>'23' ) ) ) );
check( 'invalid', crmb_reminder_save_rules( array_merge( $valid, array( 'daily_cap'=>'100' ) ) ) );
check( 'saved', crmb_reminder_save_rules( $valid ) );
check( 1, crmb_reminder_rules()['enabled'] );
check( 9, crmb_reminder_rules()['hour'] );
check( 1, count( $cleared ) );
check( 1, count( $scheduled ) );
check( 'crmb_reminder_daily', $scheduled[0][0] );
$uid=7; $admin=false;

// Independent task scenarios.
function add_reminder_task( $title, $status, $due, $owner, $creator = 9, $created = '2026-10-29 01:00:00' ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_tasks', array(
        'title'=>$title, 'description'=>'', 'status'=>$status,'due_day'=>$due,
        'assigned_to'=>$owner,'created_by'=>$creator,'priority'=>'normal',
        'created_utc'=>$created,'updated_utc'=>$created
    ) );
    return (int) $wpdb->insert_id;
}
$soon=add_reminder_task( 'فردا', 'todo', '2026-10-31', 7 );
$today=add_reminder_task( 'امروز', 'doing', '2026-10-30', 8 );
$late1=add_reminder_task( 'دیرکرد یک روزه', 'todo', '2026-10-29', 7 );
$late3=add_reminder_task( 'دیرکرد سه روزه', 'paused', '2026-10-27', 8 );
$late2=add_reminder_task( 'دیرکرد دو روزه', 'todo', '2026-10-28', 7 );
$review=add_reminder_task( 'منتظر بازبینی مدیر', 'review', '2026-10-29', 7 );
$done=add_reminder_task( 'تکمیل شده', 'done', '2026-10-27', 8 );
$stale=add_reminder_task( 'بدون گزارش اخیر', 'doing', null, 7, 9, '2026-10-23 01:00:00' );
$recent=add_reminder_task( 'گزارش تازه دارد', 'doing', null, 7, 9, '2026-10-23 01:00:00' );
$wpdb->insert( 'wp_crmb_task_events', array(
    'task_id'=>$recent,'actor_id'=>7,'event_type'=>'rich_note',
    'body'=>'گزارش کارمند','created_utc'=>'2026-10-30 04:00:00',
) );

// Private customer records: latest call wins, old pending calls cancelled.
$wpdb->db->exec( "INSERT INTO wp_crmb_sales_customers VALUES
(21,8,'مشتری لغوشده'),(22,7,'مشتری امروز'),(23,8,'مشتری دیرکرد');
INSERT INTO wp_crmb_sales_calls VALUES
(1,21,8,'followup','2026-10-29','2026-10-26 12:00:00'),
(2,21,8,'answered',NULL,'2026-10-30 05:00:00'),
(3,22,7,'followup','2026-10-30','2026-10-29 05:00:00'),
(4,23,8,'followup','2026-10-27','2026-10-28 05:00:00');" );
$now = '2026-10-30 05:30:00';
check( 'saved', crmb_reminder_run( '2026-10-30', $now ) );
check( '2026-10-30', $test_options['crmb_reminder_last_day'] );
$rows = $wpdb->get_results( 'SELECT user_id,title,summary,source_key,href FROM wp_crmb_notifications ORDER BY id' );
$keys = array_column( array_map( static fn( $r ) => (array) $r, $rows ), 'source_key' );
$joined = implode( "\n", $keys );
check( true, str_contains( $joined, 'reminder:task:' . $soon . ':2026-10-31:advance' ) );
check( true, str_contains( $joined, 'reminder:task:' . $today . ':2026-10-30:today' ) );
check( true, str_contains( $joined, 'reminder:task:' . $late1 . ':2026-10-29:late-1' ) );
check( true, str_contains( $joined, 'reminder:task:' . $late3 . ':2026-10-27:late-3' ) );
check( true, str_contains( $joined, 'reminder:task:' . $late3 . ':2026-10-27:manager-late-3' ) );
check( true, str_contains( $joined, 'reminder:task:' . $review . ':2026-10-29:review-late-1' ) );
check( false, str_contains( $joined, 'reminder:task:' . $late2 . ':' ) );
check( false, str_contains( $joined, 'reminder:task:' . $done . ':' ) );
check( true, str_contains( $joined, 'reminder:stale:' . $stale . ':' ) );
check( false, str_contains( $joined, 'reminder:stale:' . $recent . ':' ) );
check( true, str_contains( $joined, 'reminder:sales:3:2026-10-30:today' ) );
check( true, str_contains( $joined, 'reminder:sales:4:2026-10-27:late-3' ) );
check( false, str_contains( $joined, 'reminder:sales:1:' ) );
check( true, str_contains( implode( "\n", array_column( array_map(
    static fn( $r ) => (array) $r, $rows ), 'href' ) ), 'customer=22' ) );
$review_receivers = $wpdb->get_results( "SELECT user_id FROM wp_crmb_notifications
    WHERE source_key LIKE 'reminder:task:{$review}:%'" );
check( 1, count( $review_receivers ) );
check( 9, (int) $review_receivers[0]->user_id ); // no nagging assignee while manager reviews
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_notifications' );
check( 'already', crmb_reminder_run( '2026-10-30', $now ) );
check( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_notifications' ) );

// Clearing a customer's pending follow-up or closing the task suppresses
// future reminders; changing dates creates a new key on next eligible phase.
$wpdb->update( 'wp_crmb_tasks', array( 'status'=>'done' ), array( 'id'=>$late3 ) );
$wpdb->insert( 'wp_crmb_task_events', array(
    'task_id'=>$stale,'actor_id'=>7,'event_type'=>'rich_note',
    'body'=>'گزارش ثبت شد','created_utc'=>'2026-10-30 05:45:00'
) );
$wpdb->db->exec( "INSERT INTO wp_crmb_sales_calls VALUES
(5,23,8,'answered',NULL,'2026-10-30 06:00:00');" );
$test_options['crmb_reminder_last_day']='';
check( 'saved', crmb_reminder_run( '2026-10-30', '2026-10-30 06:05:00' ) );
check( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_notifications' ) );

// No new notifications when disabled; next schedule is not registered.
$disabled = $valid;
$disabled['enabled']='0';
$uid=9; $admin=true;
check( 'saved', crmb_reminder_save_rules( $disabled ) );
check( 0, crmb_reminder_rules()['enabled'] );
$test_options['crmb_reminder_last_day']='';
check( 'disabled', crmb_reminder_run( '2026-10-31', '2026-10-31 05:30:00' ) );
check( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_notifications' ) );
echo "$checks scheduled CRM reminders, customer privacy, settings, milestones and deduplication checks passed\n";
