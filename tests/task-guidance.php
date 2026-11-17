<?php
/**
 * SQLite behavior tests for independent manager-guidance read receipts.
 * Uses the same task/events schema as the production progress tests.
 */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';

$guidance_id = 0;
$values = array(
    'title' => 'کار آزمایشی', 'description' => 'توضیح',
    'assigned_to' => 7, 'created_by' => 1, 'priority' => 'normal',
    'due_day' => '2026-11-17', 'status' => 'todo',
    'created_utc' => '2026-11-17 06:00:00', 'updated_utc' => '2026-11-17 06:00:00',
);
$wpdb->insert( 'wp_crmb_tasks', $values );
$task_id = (int) $wpdb->insert_id;
$second_task = $task_id + 1;
$wpdb->insert( 'wp_crmb_tasks', $values );
$second_task = (int) $wpdb->insert_id;

function guidance_event( $task_id, $type = 'manager_note' ) {
    global $wpdb;
    $wpdb->insert( 'wp_crmb_task_events', array(
        'task_id' => $task_id,
        'actor_id' => 1,
        'event_type' => $type,
        'body' => '<p>راهنمایی آزمایشی</p>',
        'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    ) );
    return (int) $wpdb->insert_id;
}

$first = guidance_event( $task_id );
$second = guidance_event( $task_id );
$ordinary = guidance_event( $task_id, 'rich_note' );
$foreign = guidance_event( $second_task );

check( 2, crmb_task_guidance_unread( $task_id, 7 )['count'] );
check( $second, crmb_task_guidance_unread( $task_id, 7 )['latest_id'] );
check( array(), crmb_task_guidance_reads( $wpdb->get_results(
    "SELECT * FROM wp_crmb_task_events WHERE task_id={$task_id}"
), 7 ) );
check( 'invalid', crmb_task_guidance_ack( 0, $first ) );
check( 'invalid', crmb_task_guidance_ack( $task_id, 0 ) );
check( 'invalid', crmb_task_guidance_ack( $task_id, $ordinary ) );
check( 'invalid', crmb_task_guidance_ack( $task_id, $foreign ) );

$uid = 8; $admin = false;
check( 'denied', crmb_task_guidance_ack( $task_id, $first ) );
$admin = true; $uid = 9;
check( 'denied', crmb_task_guidance_ack( $task_id, $first ) );
$admin = false; $uid = 7;
check( 'read', crmb_task_guidance_ack( $task_id, $first ) );
check( 1, crmb_task_guidance_unread( $task_id, 7 )['count'] );
check( $second, crmb_task_guidance_unread( $task_id, 7 )['latest_id'] );
check( 'changed', crmb_task_guidance_ack( $task_id, $first ) );
check( 1, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_guidance_reads WHERE user_id=7"
) );
check( 'read', crmb_task_guidance_ack( $task_id, $second ) );
check( 0, crmb_task_guidance_unread( $task_id, 7 )['count'] );
$events = $wpdb->get_results( "SELECT * FROM wp_crmb_task_events WHERE task_id={$task_id}" );
$read = crmb_task_guidance_reads( $events, 7 );
check( 2, count( $read ) );
check( true, isset( $read[$first] ) && isset( $read[$second] ) );
check( 2, crmb_task_guidance_unread( $task_id, 8 )['count'] ); // receipts are personal.

// New guidance appears independently of prior acknowledgements.
$third = guidance_event( $task_id );
check( 1, crmb_task_guidance_unread( $task_id, 7 )['count'] );
check( $third, crmb_task_guidance_unread( $task_id, 7 )['latest_id'] );
check( 'read', crmb_task_guidance_ack( $task_id, $third ) );
check( 0, crmb_task_guidance_unread( $task_id, 7 )['count'] );

// An old task's guidance remains visible for the newly assigned worker.
$wpdb->update( 'wp_crmb_tasks', array( 'assigned_to' => 8 ), array( 'id' => $task_id ) );
$uid = 7; $admin = true;
check( 'denied', crmb_task_guidance_ack( $task_id, $first ) );
$uid = 8; $admin = false;
check( 3, crmb_task_guidance_unread( $task_id, 8 )['count'] );
check( 'read', crmb_task_guidance_ack( $task_id, $third ) );
check( 2, crmb_task_guidance_unread( $task_id, 8 )['count'] );
check( 0, crmb_task_guidance_unread( $task_id, 7 )['count'] );

// History navigation must work for unread guidance older than the first 20 events.
for ( $i = 0; $i < 22; $i++ ) { guidance_event( $task_id, 'rich_note' ); }
check( 2, crmb_task_guidance_history_page( $task_id, $third ) );
check( 1, crmb_task_guidance_history_page( $task_id, $ordinary + 22 + 1000 ) );

// No destructive update of manager guidance or general event rows.
check( 27, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_events WHERE task_id={$task_id}"
) );
check( 4, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_task_guidance_reads' ) );

$logged = false;
check( 'denied', crmb_task_guidance_ack( $task_id, $first ) );
$logged = true; $ready = false;
check( 'error', crmb_task_guidance_ack( $task_id, $first ) );

echo "$checks independent manager guidance acknowledgements, permissions and history-navigation checks passed\n";
