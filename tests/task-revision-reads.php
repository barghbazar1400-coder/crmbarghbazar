<?php
/**
 * Regression: explicit read receipts for each manager correction request.
 * Uses the real SQLite progress/task-events implementation.
 */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';

$base = array(
    'title' => 'کار نیازمند اصلاح', 'description' => '',
    'assigned_to' => 7, 'created_by' => 1, 'priority' => 'normal',
    'status' => 'todo', 'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
);
$wpdb->insert( 'wp_crmb_tasks', $base );
$task_id = (int) $wpdb->insert_id;
$wpdb->insert( 'wp_crmb_tasks', $base );
$other_task_id = (int) $wpdb->insert_id;
function revision_event( $task, $type, $body = 'لطفاً گزارش را اصلاح کنید.' ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_task_events', array(
        'task_id' => $task, 'actor_id' => 1, 'event_type' => $type,
        'body' => $body, 'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    ) );
    return (int) $wpdb->insert_id;
}
$first = revision_event( $task_id, 'review_rejected', 'جزئیات مشتری را اصلاح کن.' );
$guidance = revision_event( $task_id, 'manager_note', 'راهنمایی جداگانه' );
$second = revision_event( $task_id, 'review_rejected', 'تصویر گزارش را اضافه کن.' );
$foreign = revision_event( $other_task_id, 'review_rejected' );
$other = revision_event( $task_id, 'note' );

check( true, crmb_task_revision_ready() );
check( 2, crmb_task_revision_unread( $task_id, 7 )['count'] );
check( $second, crmb_task_revision_unread( $task_id, 7 )['latest_id'] );
check( 'تصویر گزارش را اضافه کن.', crmb_task_revision_unread( $task_id, 7 )['latest_reason'] );
check( 1, crmb_task_guidance_unread( $task_id, 7 )['count'] );
check( array(), crmb_task_revision_reads(
    $wpdb->get_results( "SELECT * FROM wp_crmb_task_events WHERE task_id={$task_id}" ), 7
) );
check( 'invalid', crmb_task_revision_ack( 0, $first ) );
check( 'invalid', crmb_task_revision_ack( $task_id, 0 ) );
check( 'invalid', crmb_task_revision_ack( $task_id, $guidance ) );
check( 'invalid', crmb_task_revision_ack( $task_id, $other ) );
check( 'invalid', crmb_task_revision_ack( $task_id, $foreign ) );

$uid = 8; $admin = false;
check( 'denied', crmb_task_revision_ack( $task_id, $first ) );
$uid = 9; $admin = true;
check( 'denied', crmb_task_revision_ack( $task_id, $first ) );
$uid = 7; $admin = false;
check( 'read_revision', crmb_task_revision_ack( $task_id, $first ) );
check( 'changed', crmb_task_revision_ack( $task_id, $first ) );
check( 1, crmb_task_revision_unread( $task_id, 7 )['count'] );
check( $second, crmb_task_revision_unread( $task_id, 7 )['latest_id'] );
check( 1, crmb_task_guidance_unread( $task_id, 7 )['count'] );
$reads = crmb_task_revision_reads(
    $wpdb->get_results( "SELECT * FROM wp_crmb_task_events WHERE task_id={$task_id}" ), 7
);
check( true, isset( $reads[$first] ) && ! isset( $reads[$second] ) );
check( 'read_revision', crmb_task_revision_ack( $task_id, $second ) );
check( 0, crmb_task_revision_unread( $task_id, 7 )['count'] );
check( 1, crmb_task_guidance_unread( $task_id, 7 )['count'] );

// Every later rejection is independent and surfaces as unread again.
$third = revision_event( $task_id, 'review_rejected', 'توضیح نهایی را کامل کن.' );
check( 1, crmb_task_revision_unread( $task_id, 7 )['count'] );
check( $third, crmb_task_revision_unread( $task_id, 7 )['latest_id'] );
check( 'read_revision', crmb_task_revision_ack( $task_id, $third ) );

// New assignee has their own read state, old assignee loses write access.
$wpdb->update( 'wp_crmb_tasks', array( 'assigned_to' => 8 ), array( 'id' => $task_id ) );
$uid = 7; $admin = true;
check( 'denied', crmb_task_revision_ack( $task_id, $third ) );
$uid = 8; $admin = false;
check( 3, crmb_task_revision_unread( $task_id, 8 )['count'] );
check( 'read_revision', crmb_task_revision_ack( $task_id, $third ) );
check( 2, crmb_task_revision_unread( $task_id, 8 )['count'] );
check( 0, crmb_task_revision_unread( $task_id, 7 )['count'] );

// Cross-page link works when a request falls outside latest 20 events.
for ( $i=0; $i<22; $i++ ) { revision_event( $task_id, 'rich_note' ); }
check( 2, crmb_task_guidance_history_page( $task_id, $third ) );
check( 1, crmb_task_guidance_history_page( $task_id, $other + 1000 ) );

// No event/guidance deleted or silently acknowledged.
check( 27, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_events WHERE task_id={$task_id}"
) );
check( 4, (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM wp_crmb_task_guidance_reads'
) );
check( 1, crmb_task_guidance_unread( $task_id, 7 )['count'] );
$logged = false;
check( 'denied', crmb_task_revision_ack( $task_id, $third ) );
$logged = true; $ready = false;
check( 'error', crmb_task_revision_ack( $task_id, $third ) );
check( true, crmb_task_revision_unread( $task_id, 8 )['error'] );
echo "$checks manager revision-acknowledgement, isolation, reassignment and timeline checks passed\n";
