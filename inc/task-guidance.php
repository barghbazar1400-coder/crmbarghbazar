<?php
/**
 * Per-employee explicit acknowledgement of manager guidance in task history.
 * Existing notes/events are immutable; a new table stores read receipts.
 */
defined( 'ABSPATH' ) || exit;

function crmb_task_guidance_table() {
    global $wpdb;
    return $wpdb->prefix . 'crmb_task_guidance_reads';
}

function crmb_task_guidance_ready() {
    return '1' === get_option( 'crmb_task_guidance_schema_v1' );
}

function crmb_task_guidance_install() {
    if ( crmb_task_guidance_ready() || ! crmb_progress_ready() ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = crmb_task_guidance_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        event_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        read_utc datetime NOT NULL,
        PRIMARY KEY  (event_id,user_id),
        KEY user_id (user_id,event_id)
    ) ENGINE=InnoDB {$charset};" );
    if ( $table === $wpdb->get_var( $wpdb->prepare(
        'SHOW TABLES LIKE %s', $wpdb->esc_like( $table )
    ) ) ) {
        update_option( 'crmb_task_guidance_schema_v1', '1', false );
    }
}
add_action( 'init', 'crmb_task_guidance_install', 13 );

/**
 * Count all unread guidance (not just the current history page).
 * Latest event ID is used to link to the proper history page.
 */
function crmb_task_guidance_unread( $task_id, $user_id ) {
    $empty = array( 'count' => 0, 'latest_id' => 0, 'error' => false );
    if ( ! crmb_task_guidance_ready() || ! $task_id || ! $user_id ) { return $empty; }
    global $wpdb;
    $events = crmb_progress_tables()['events'];
    $reads = crmb_task_guidance_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS unread_count, MAX(e.id) AS latest_id
           FROM {$events} e LEFT JOIN {$reads} r
             ON r.event_id=e.id AND r.user_id=%d
          WHERE e.task_id=%d AND e.event_type='manager_note' AND r.event_id IS NULL",
        $user_id, $task_id
    ) );
    if ( $wpdb->last_error || ! $row ) { $empty['error'] = true; return $empty; }
    return array(
        'count' => (int) $row->unread_count,
        'latest_id' => (int) $row->latest_id,
        'error' => false,
    );
}

/** Current history page's read states; returns event_id => read_utc. */
function crmb_task_guidance_reads( $events, $user_id ) {
    if ( ! crmb_task_guidance_ready() || ! $user_id || ! is_array( $events ) ) {
        return array();
    }
    $ids = array();
    foreach ( $events as $event ) {
        if ( 'manager_note' === $event->event_type ) { $ids[] = (int) $event->id; }
    }
    if ( ! $ids ) { return array(); }
    global $wpdb;
    $table = crmb_task_guidance_table();
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT event_id,read_utc FROM {$table} WHERE user_id=%d
          AND event_id IN ({$placeholders})",
        $user_id, ...$ids
    ) );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { return array(); }
    $read = array();
    foreach ( $rows as $row ) { $read[(int)$row->event_id] = $row->read_utc; }
    return $read;
}

/** Find the history page for an event; 20 entries per page, newest first. */
function crmb_task_guidance_history_page( $task_id, $event_id ) {
    if ( ! $task_id || ! $event_id ) { return 1; }
    global $wpdb;
    $table = crmb_progress_tables()['events'];
    $newer = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE task_id=%d AND id>%d",
        $task_id, $event_id
    ) );
    return $wpdb->last_error ? 1 : 1 + (int) floor( (int) $newer / 20 );
}

/**
 * Only the current task assignee can acknowledge a specific real manager note.
 * A manager viewing an employee's task cannot mark it read on their behalf.
 * The task row lock serializes acknowledgement with changes of assignee.
 */
function crmb_task_guidance_ack( $task_id, $event_id ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( ! crmb_task_guidance_ready() || ! crmb_progress_ready() ) { return 'error'; }
    if ( ! $task_id || ! $event_id ) { return 'invalid'; }
    global $wpdb;
    $tables = crmb_progress_tables();
    $uid = get_current_user_id();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $task = crmb_progress_task( $task_id, true );
    if ( ! $task || (int)$task->assigned_to !== $uid ) {
        return crmb_progress_fail( 'denied' );
    }
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$tables['events']}
          WHERE id=%d AND task_id=%d AND event_type='manager_note'",
        $event_id, $task_id
    ) );
    if ( $wpdb->last_error ) { return crmb_progress_fail(); }
    if ( ! $exists ) { return crmb_progress_fail( 'invalid' ); }
    $reads = crmb_task_guidance_table();
    $new = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$reads} (event_id,user_id,read_utc) VALUES (%d,%d,%s)",
        $event_id, $uid, gmdate( 'Y-m-d H:i:s' )
    ) );
    if ( false === $new ) { return crmb_progress_fail(); }
    if ( false === $wpdb->query( 'COMMIT' ) ) { return crmb_progress_fail(); }
    // Notification-inbox read state remains independent until the user presses
    // «خواندم». Seeing/opening a notice must not silently acknowledge guidance.
    if ( function_exists( 'crmb_notice_read_guidance' ) ) {
        crmb_notice_read_guidance( $event_id, $uid );
    }
    return 1 === $new ? 'read' : 'changed';
}
