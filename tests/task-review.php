<?php
/**
 * SQLite test: employee submission, manager approval/rejection, revisions,
 * permissions, immutable history and transaction rollback.
 */
// Capture commit-success hooks without invoking real notification handlers.
$review_actions = array();
function do_action( $name, ...$args ) {
    $GLOBALS['review_actions'][] = array( $name, $args );
}
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';

$task = array(
    'title' => 'Test review workflow', 'description' => 'body',
    'assigned_to' => 7, 'created_by' => 1, 'priority' => 'normal',
    'status' => 'todo', 'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
);
$wpdb->insert( 'wp_crmb_tasks', $task );
$id = (int) $wpdb->insert_id;
check( true, isset( crmb_work_statuses()['review'] ) );
check( 'invalid', crmb_progress_transition( $id, 'review' ) );
$uid = 8;
check( 'denied', crmb_progress_transition( $id, 'done' ) );
$uid = 7;
check( 'submitted', crmb_progress_transition( $id, 'done' ) );
check( 'review', crmb_progress_task( $id )->status );
check( null, crmb_progress_task( $id )->completed_utc );
check( 'denied', crmb_progress_transition( $id, 'todo' ) );
check( 'denied', crmb_progress_transition( $id, 'done' ) );
check( 'denied', crmb_task_review_decide( $id, 'approve', '' ) );
$uid = 8;
check( 'denied', crmb_task_review_decide( $id, 'reject', 'Fix this issue' ) );
$admin = true;
check( 'invalid', crmb_task_review_decide( $id, 'reject', '' ) );
check( 'invalid', crmb_task_review_decide( $id, 'reject', 'a' ) );
check( 'denied', crmb_progress_transition( $id, 'done' ) );
$wpdb->fail_table = 'wp_crmb_task_events';
check( 'error', crmb_task_review_decide( $id, 'reject', 'Need additional evidence' ) );
check( 'review', crmb_progress_task( $id )->status );
$wpdb->fail_table = '';
check( 'rejected', crmb_task_review_decide( $id, 'reject', '<script>bad</script>Fix and resend' ) );
check( 'todo', crmb_progress_task( $id )->status );
check( null, crmb_progress_task( $id )->completed_utc );
// The manager's real team review list is empty after rejection.
$empty_review_state = crmb_work_review_state();
check( 0, $empty_review_state['count'] );
check( 0, crmb_work_list( 'tasks', 1, 20, true, false, false, 0, 'review' )['total'] );
$event = $wpdb->get_row( "SELECT * FROM wp_crmb_task_events WHERE task_id={$id} AND event_type='review_rejected' LIMIT 1" );
check( true, (bool) $event );
check( 8, (int) $event->actor_id );
check( false, str_contains( $event->body, '<script>' ) );
check( 'changed', crmb_task_review_decide( $id, 'reject', 'Already returned' ) );
$admin = false; $uid = 7;
check( 'submitted', crmb_progress_transition( $id, 'done' ) );
check( 'review', crmb_progress_task( $id )->status );
// Regression for the reported bug: a *second* employee submission must
// reappear in the manager's SQL list, filtered list and approval count.
$uid = 8; $admin = true;
$resubmitted_state = crmb_work_review_state();
check( 1, $resubmitted_state['count'] );
check( true, $resubmitted_state['version'] > $empty_review_state['version'] );
$manager_review_list = crmb_work_list( 'tasks', 1, 20, true, false, false, 0, 'review' );
check( 1, $manager_review_list['total'] );
check( $id, (int) $manager_review_list['rows'][0]->id );
check( 1, crmb_work_task_counts( true )['review'] );
check( 1, crmb_work_review_state( 7 )['count'] );
check( 0, crmb_work_review_state( 8 )['count'] );
// Review sent to this manager also appears under their PERSONAL "My work"
// approval filter, even though the employee remains the task assignee.
$my_review = crmb_work_list( 'tasks', 1, 20, false, false, false, 0, 'review' );
check( 1, $my_review['total'] );
check( $id, (int) $my_review['rows'][0]->id );
check( 1, crmb_work_task_counts( false )['review'] );
check( 1, crmb_work_review_state( 0, false )['count'] );
// The manager who rejected the task must get a new resubmission signal,
// even if a different user originally created the task.
$review_resubmit_hooks = array_values( array_filter( $review_actions, static function ( $event ) {
    return 'crmb_task_review_resubmitted' === $event[0];
} ) );
check( 1, count( $review_resubmit_hooks ) );
check( $id, (int) $review_resubmit_hooks[0][1][0] );
check( 8, (int) $review_resubmit_hooks[0][1][1] );
$uid = 7; $admin = false;
check( null, crmb_work_review_state() ); // Team review state isn't public.
$uid = 9; $admin = true;
check( 0, crmb_work_list( 'tasks', 1, 20, false, false, false, 0, 'review' )['total'] );
check( 0, crmb_work_task_counts( false )['review'] );
check( 0, crmb_work_review_state( 0, false )['count'] );
$uid = 9; $admin = false;
check( 'denied', crmb_task_review_decide( $id, 'approve' ) );
$admin = true;
check( 'approved', crmb_task_review_decide( $id, 'approve', 'Excellent work' ) );
check( 'done', crmb_progress_task( $id )->status );
check( true, (bool) crmb_progress_task( $id )->completed_utc );
check( 'changed', crmb_task_review_decide( $id, 'approve' ) );
$uid = 7; $admin = false;
check( 'denied', crmb_progress_transition( $id, 'doing' ) );
check( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_events WHERE task_id={$id} AND event_type='review'" ) );
check( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_events WHERE task_id={$id} AND event_type='review_rejected'" ) );
check( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_events WHERE task_id={$id} AND event_type='review_approved'" ) );
// Regression: task assignee with operations/manager permission must still
// submit work for review, never complete it via the generic status endpoint.
$logged = true; $admin = true; $uid = 8;
$wpdb->insert( 'wp_crmb_tasks', array(
    'title' => 'Manager-owned task also requires review',
    'description' => '', 'assigned_to' => 8, 'created_by' => 1,
    'priority' => 'normal', 'status' => 'todo',
    'created_utc' => gmdate( 'Y-m-d H:i:s' ), 'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
) );
$own_task_id = (int) $wpdb->insert_id;
check( 'submitted', crmb_progress_transition( $own_task_id, 'done' ) );
check( 'review', crmb_progress_task( $own_task_id )->status );
check( null, crmb_progress_task( $own_task_id )->completed_utc );
check( 1, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_tasks WHERE id={$own_task_id} AND status='review'"
) );
check( 'denied', crmb_progress_transition( $own_task_id, 'done' ) );
check( 'approved', crmb_task_review_decide( $own_task_id, 'approve' ) );
check( 'done', crmb_progress_task( $own_task_id )->status );
check( 'denied', crmb_progress_transition( $own_task_id, 'done' ) );

// A manager looking at another employee's open task cannot bypass review.
$wpdb->insert( 'wp_crmb_tasks', array(
    'title' => 'Team member task', 'description' => '', 'assigned_to' => 7,
    'created_by' => 1, 'priority' => 'normal', 'status' => 'todo',
    'created_utc' => gmdate( 'Y-m-d H:i:s' ), 'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
) );
$team_task_id = (int) $wpdb->insert_id;
check( 'denied', crmb_progress_transition( $team_task_id, 'done' ) );
check( 'todo', crmb_progress_task( $team_task_id )->status );

// First submission goes into the personal approval inbox of the manager
// who created the assignment, not every other manager's inbox.
$wpdb->insert( 'wp_crmb_tasks', array(
    'title' => 'Approval routed to manager eight',
    'description' => '', 'assigned_to' => 7, 'created_by' => 8,
    'priority' => 'normal', 'status' => 'todo',
    'created_utc' => gmdate( 'Y-m-d H:i:s' ), 'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
) );
$routed_id = (int) $wpdb->insert_id;
$uid = 7; $admin = false;
check( 'submitted', crmb_progress_transition( $routed_id, 'done' ) );
$uid = 8; $admin = true;
$personal = crmb_work_list( 'tasks', 1, 20, false, false, false, 0, 'review' );
check( 1, $personal['total'] );
check( $routed_id, (int) $personal['rows'][0]->id );
check( 1, crmb_work_task_counts( false )['review'] );
$uid = 9; $admin = true;
check( 0, crmb_work_task_counts( false )['review'] );
$uid = 8; $admin = true;
check( 'rejected', crmb_task_review_decide( $routed_id, 'reject', 'Please fix it' ) );
$uid = 7; $admin = false;
check( 'submitted', crmb_progress_transition( $routed_id, 'done' ) );
$uid = 8; $admin = true;
check( 1, crmb_work_task_counts( false )['review'] );
check( $routed_id, (int) crmb_work_list( 'tasks', 1, 20, false, false, false, 0, 'review' )['rows'][0]->id );
check( 'approved', crmb_task_review_decide( $routed_id, 'approve' ) );
check( 0, crmb_work_task_counts( false )['review'] );
$logged = false;
check( 'denied', crmb_task_review_decide( $id, 'approve' ) );

echo "$checks review workflow behavior and security checks passed\n";
