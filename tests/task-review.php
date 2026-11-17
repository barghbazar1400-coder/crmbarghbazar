<?php
/**
 * SQLite test: employee submission, manager approval/rejection, revisions,
 * permissions, immutable history and transaction rollback.
 */
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
$event = $wpdb->get_row( "SELECT * FROM wp_crmb_task_events WHERE task_id={$id} AND event_type='review_rejected' LIMIT 1" );
check( true, (bool) $event );
check( 8, (int) $event->actor_id );
check( false, str_contains( $event->body, '<script>' ) );
check( 'changed', crmb_task_review_decide( $id, 'reject', 'Already returned' ) );
$admin = false; $uid = 7;
check( 'submitted', crmb_progress_transition( $id, 'done' ) );
check( 'review', crmb_progress_task( $id )->status );
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
$logged = false;
check( 'denied', crmb_task_review_decide( $id, 'approve' ) );

echo "$checks review workflow behavior and security checks passed\n";
