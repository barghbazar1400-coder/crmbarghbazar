<?php
/** Security and large-selection regression for private task progress photos. */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';
$uid = 7; $admin = false;
$wpdb->insert( 'wp_crmb_tasks', array(
    'title' => 'گزارش تصویری', 'description' => 'متن داخلی',
    'assigned_to' => 7, 'created_by' => 9, 'priority' => 'normal', 'status' => 'todo',
    'created_utc' => gmdate( 'Y-m-d H:i:s' ),
    'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
) );
$task_id = (int) $wpdb->insert_id;

// No 3-image validator: 35 no-file slots do not cause rejection.
$empty = array( 'images' => array( 'error' => array_fill( 0, 35, UPLOAD_ERR_NO_FILE ) ) );
check( array(), crmb_task_photo_files( $empty ) );
check( array(), crmb_task_photo_files( array() ) );
check( true, is_wp_error( crmb_task_photo_files( array(
    'images' => array( 'error' => array( UPLOAD_ERR_INI_SIZE ) )
) ) ) );

// A note is saved first and returns an immutable event identifier for per-photo
// HTTP requests. The same event owns all attachments.
$event_id = 0;
check( 'saved', crmb_task_rich_save( $task_id, 'rich_note', 'گزارش با عکس', array(), $event_id ) );
check( true, $event_id > 0 );
check( 7, (int) $wpdb->get_var( "SELECT actor_id FROM wp_crmb_task_events WHERE id={$event_id}" ) );
check( 'image_error', crmb_task_photo_append( $task_id, $event_id, array(
    'error' => UPLOAD_ERR_INI_SIZE
) ) );
check( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_task_note_images' ) );
check( 1, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_events WHERE id={$event_id}"
) );

// Other employees cannot attach or see a colleague's task note.
$uid = 8;
check( 'denied', crmb_task_photo_append( $task_id, $event_id, array() ) );
check( null, crmb_progress_task( $task_id ) );
// Even a manager who did not author the note cannot spoof its attachment.
$admin = true; $uid = 9;
check( 'denied', crmb_task_photo_append( $task_id, $event_id, array() ) );
$uid = 7; $admin = false;
check( 'denied', crmb_task_photo_append( $task_id, $event_id + 999, array() ) );
// If manager receives an approval request, employees cannot silently add
// images to previously submitted notes.
$wpdb->update( 'wp_crmb_tasks', array( 'status' => 'review' ), array( 'id' => $task_id ) );
check( 'locked', crmb_task_photo_append( $task_id, $event_id, array() ) );
$wpdb->update( 'wp_crmb_tasks', array( 'status' => 'todo' ), array( 'id' => $task_id ) );
$uid = 7; $logged = false;
check( 'denied', crmb_task_photo_append( $task_id, $event_id, array() ) );
$logged = true;

// Existing daily reports retain their own separate 3-file/1 MiB policy.
$daily_validator = file_get_contents( __DIR__ . '/../inc/daily-reports.php' );
check( true, str_contains( $daily_validator, "count( \$f['error'] ) > 3" ) );
check( true, str_contains( $daily_validator, '$size > 1048576' ) );
// New task-specific path intentionally has no such old limits.
$task_validator = file_get_contents( __DIR__ . '/../inc/task-photo-uploads.php' );
check( false, str_contains( $task_validator, 'MAX_FILES = 3' ) );
check( false, str_contains( $task_validator, '$size > 1048576' ) );
echo "$checks task-photo count, event identity, safe upload and authorization checks passed\n";
