<?php
/**
 * SQLite regression for CEO/COO task drilldown filters.
 * Tests real SQL output, counts, ownership, status and pagination.
 */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';

$now = gmdate( 'Y-m-d H:i:s' );
$old = gmdate( 'Y-m-d H:i:s', time() - 4 * 86400 );
function queue_task( $name, $status, $due, $owner = 7, $created = null, $timer = null ) {
    global $wpdb, $now;
    $wpdb->insert( 'wp_crmb_tasks', array(
        'title' => $name, 'description' => '', 'assigned_to' => $owner,
        'created_by' => 1, 'priority' => 'normal', 'due_day' => $due,
        'status' => $status, 'created_utc' => $created ?: $now,
        'updated_utc' => $now, 'timer_started_utc' => $timer
    ) );
    return (int) $wpdb->insert_id;
}
$overdue = queue_task( 'Overdue', 'todo', '2026-10-29' );
$review = queue_task( 'Overdue review', 'review', '2026-10-28', 8 );
$today = queue_task( 'Due today', 'todo', '2026-10-30' );
$completed = queue_task( 'Completed', 'done', '2026-10-28' );
$stalled = queue_task( 'Stalled', 'paused', '2026-11-01', 7, $old );
$recent = queue_task( 'Recent progress', 'doing', '2026-11-01', 7, $old, $now );
$other = queue_task( 'Other employee', 'doing', '2026-11-01', 8, $old );

$wpdb->insert( 'wp_crmb_task_events', array(
    'task_id' => $recent, 'actor_id' => 7, 'event_type' => 'rich_note',
    'body' => 'Progress today', 'created_utc' => $now
) );
// The activity queue uses the business timezone and selected business day.
$wpdb->insert( 'wp_crmb_task_events', array(
    'task_id' => $today, 'actor_id' => 7, 'event_type' => 'doing',
    'body' => '', 'created_utc' => '2026-10-30 09:30:00'
) );
$wpdb->insert( 'wp_crmb_task_events', array(
    'task_id' => $review, 'actor_id' => 8, 'event_type' => 'rich_note',
    'body' => '', 'created_utc' => '2026-10-30 10:00:00'
) );
$admin=true;
function check_queue( $queue, $view, $expected_ids, $assignee = 0 ) {
    $list = crmb_work_list( 'tasks', 1, 20, true, false, false, 0, $view, $assignee, $queue );
    check( true, $list['ready'] );
    $got = array_map( static fn( $item ) => (int) $item->id, $list['rows'] );
    sort( $got ); sort( $expected_ids );
    check( $expected_ids, $got );
    check( count( $expected_ids ), $list['total'] );
    $totals = crmb_work_task_counts( true, $assignee, $queue );
    check( true, $totals['ready'] );
    check( count( $expected_ids ), 'all' === $view ? $totals['all'] : $totals[$view] );
}
check_queue( 'overdue', 'open', array( $overdue, $review ) );
check_queue( 'overdue', 'open', array( $overdue ), 7 );
check_queue( 'overdue', 'review', array( $review ), 8 );
check_queue( 'stalled', 'open', array( $stalled, $other ) );
check_queue( 'stalled', 'open', array( $stalled ), 7 );
check_queue( 'timers', 'open', array( $recent ) );
check_queue( 'activity', 'all', array( $today, $review ) );
check_queue( 'activity', 'all', array( $today ), 7 );
// The second page MUST stay in the same server-side queue.
$page1 = crmb_work_list( 'tasks', 1, 1, true, false, false, 0, 'open', 0, 'overdue' );
$page2 = crmb_work_list( 'tasks', 2, 1, true, false, false, 0, 'open', 0, 'overdue' );
check( 2, $page1['total'] );
check( 2, $page2['total'] );
check( true, (int) $page1['rows'][0]->id !== (int) $page2['rows'][0]->id );
// Non-manager cannot inject queue= or assigned_to= to read other staff tasks.
$admin=false; $uid=7;
$private = crmb_work_list( 'tasks', 1, 20, true, false, false, 0, 'all', 8, 'overdue' );
check( true, $private['ready'] );
check( false, in_array( $review, array_map( static fn( $r ) => (int) $r->id, $private['rows'] ), true ) );
$mine = crmb_work_task_counts( true, 8, 'overdue' );
check( true, $mine['ready'] );
check( 5, $mine['all'] ); // Five assigned to employee 7; no manager queue applied.
// Unknown/unavailable queues fail closed for manager instead of showing all.
$admin=true;
check( false, crmb_work_list( 'tasks', 1, 20, true, false, false, 0, 'all', 0, 'bad_queue' )['ready'] );
check( false, crmb_work_task_counts( true, 0, 'bad_queue' )['ready'] );
echo "$checks manager control queue SQL, counts, access and pagination checks passed\n";
