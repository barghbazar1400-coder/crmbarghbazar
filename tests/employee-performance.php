<?php
/** Real SQLite aggregation and role-scoped rendering for employee performance. */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';
$can_performance = true;
function crmb_leadership_can_operate() { global $can_performance; return $can_performance; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
require __DIR__ . '/../inc/employee-performance.php';

check( 'week', crmb_performance_bounds( 'not-a-period' )['period'] );
check( '2026-10-24', crmb_performance_bounds( 'week' )['first_day'] );
check( '2026-10-30', crmb_performance_bounds( 'week' )['last_day'] );
check( '2026-10-01', crmb_performance_bounds( 'month' )['first_day'] );
check( '2026-10-29 20:30:00', crmb_performance_bounds( 'today' )['start_utc'] );
check( '2026-10-30 20:30:00', crmb_performance_bounds( 'today' )['end_utc'] );

function performance_task( $uid, $title, $status, $created, $due = null, $completed = null, $seconds = 0 ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_tasks', array(
        'title' => $title, 'description' => 'private',
        'assigned_to' => $uid, 'created_by' => 9,
        'priority' => 'normal', 'status' => $status, 'due_day' => $due,
        'created_utc' => $created, 'updated_utc' => $created,
        'completed_utc' => $completed, 'tracked_seconds' => $seconds
    ) );
    return (int) $wpdb->insert_id;
}
function performance_event( $task_id, $actor, $type, $date ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_task_events', array(
        'task_id' => $task_id, 'actor_id' => $actor, 'event_type' => $type,
        'body' => '', 'created_utc' => $date,
    ) );
}
$old = '2026-09-20 10:00:00';
$late7 = performance_task( 7, 'کاری <script>نباید دیده شود</script>', 'todo', $old, '2026-10-29' );
$doing7 = performance_task( 7, 'فعال', 'doing', '2026-10-30 08:00:00', '2026-11-01' );
$done7 = performance_task( 7, 'تکمیل امروز', 'done', $old, '2026-10-30', '2026-10-30 08:00:00', 3600 );
$week7 = performance_task( 7, 'تکمیل هفته', 'done', $old, '2026-10-28', '2026-10-25 07:00:00', 7200 );
$future7 = performance_task( 7, 'تکمیل فردا', 'done', $old, null, '2026-10-31 08:00:00', 1800 );
$review8 = performance_task( 8, 'نیازمند تأیید', 'review', $old, '2026-10-29' );
$done8 = performance_task( 8, 'تکمیل بدون تایمر', 'done', $old, '2026-10-30', '2026-10-30 08:00:00', 0 );
$old8 = performance_task( 8, 'تکمیل در ماه', 'done', $old, null, '2026-10-15 08:00:00', 1200 );
$cancel8 = performance_task( 8, 'لغوشده', 'cancelled', $old, '2026-10-20' );
performance_event( $late7, 7, 'rich_note', '2026-10-30 06:00:00' );
performance_event( $doing7, 7, 'note', '2026-10-27 06:00:00' );
performance_event( $doing7, 8, 'note', '2026-10-30 07:00:00' ); // historic actor, not current assignee
performance_event( $late7, 9, 'review_rejected', '2026-10-30 06:05:00' );
performance_event( $review8, 9, 'review_rejected', '2026-10-27 06:05:00' );
performance_event( $doing7, 9, 'manager_note', '2026-10-30 07:00:00' ); // not employee progress
performance_event( $done7, 7, 'note', '2026-10-23 08:00:00' ); // outside week

$result = crmb_performance_data( 'today' );
check( true, $result['ready'] );
check( 2, count( $result['rows'] ) );
$by = array_column( $result['rows'], null, 'user_id' );
check( 2, $by[7]['open'] );
check( 0, $by[7]['review'] );
check( 1, $by[7]['overdue'] );
check( 1, $by[7]['created'] );
check( 1, $by[7]['completed'] );
check( 3600, $by[7]['avg_seconds'] );
check( 1, $by[7]['reports'] );
check( 1, $by[7]['reported_tasks'] );
check( 1, $by[7]['corrections'] );
check( 1, $by[8]['review'] );
check( 1, $by[8]['overdue'] ); // manager approval, not employee overdue action
check( 1, $by[8]['completed'] );
check( null, $by[8]['avg_seconds'] );
check( 1, $by[8]['reports'] ); // authored by 8 on task now assigned to 7
check( 0, $by[8]['corrections'] );

$week = crmb_performance_data( 'week', 7 );
check( true, $week['ready'] );
check( 7, $week['selected'] );
$by_week = array_column( $week['rows'], null, 'user_id' );
check( 2, $by_week[7]['completed'] );
check( 5400, $by_week[7]['avg_seconds'] );
check( 2, $by_week[7]['reports'] );
check( 2, $by_week[7]['reported_tasks'] );
check( 1, $by_week[7]['corrections'] );
check( 1, $by_week[8]['corrections'] );
check( 2, count( $week['details']['completed'] ) );
check( 1, count( $week['details']['overdue'] ) );
check( $late7, (int) $week['details']['overdue'][0]->id );
$month = crmb_performance_data( 'month' );
$by_month = array_column( $month['rows'], null, 'user_id' );
check( 2, $by_month[8]['completed'] ); // old completion in month, not week
check( 2, $by_month[7]['completed'] ); // future does not leak into period
check( 3, $by_month[7]['reports'] ); // Oct 23 report visible only in month

// An arbitrary user id does not create an unscoped detail query.
$missing = crmb_performance_data( 'week', 9999 );
check( 0, $missing['selected'] );
check( array(), $missing['details'] );

// HTML: contextual ranges, filters, safe links and escaped task titles.
$crm_current_section = 'operations';
$_GET = array( 'performance_period' => 'week', 'performance_user' => 7 );
ob_start();
include __DIR__ . '/../template-parts/employee-performance.php';
$html = ob_get_clean();
check( true, str_contains( $html, 'عملکرد و حجم کار کارکنان' ) );
check( true, str_contains( $html, 'درخواست اصلاح در بازه' ) );
check( true, str_contains( $html, 'میانگین زمان‌سنج تکمیل‌ها' ) );
check( true, str_contains( $html, 'performance_period=week' ) );
check( true, str_contains( $html, 'performance_user=7' ) );
check( true, str_contains( $html, 'task_id=' . $late7 ) );
check( false, str_contains( $html, '<script>نباید دیده شود</script>' ) );
check( true, str_contains( $html, '&lt;script&gt;' ) );
check( true, str_contains( $html, 'بدون امتیازدهی یا رتبه‌بندی' ) );
check( true, str_contains( $html, 'مسئول فعلی' ) || str_contains( $html, 'فعلی' ) );

// Non-operational employees get no metrics and render nothing.
$can_performance = false;
check( null, crmb_performance_data( 'today' ) );
ob_start();
include __DIR__ . '/../template-parts/employee-performance.php';
check( '', ob_get_clean() );
$can_performance = true;

// Fail closed: a missing event table should show unavailable, not fabricated zeros.
$wpdb->db->exec( 'DROP TABLE wp_crmb_task_events' );
$broken = crmb_performance_data( 'week' );
check( false, $broken['ready'] );
echo "$checks time-scoped employee performance, attribution, permission and UI checks passed\n";
