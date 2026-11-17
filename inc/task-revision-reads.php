<?php
/**
 * Explicit per-employee read receipts for review_rejected events.
 * Reuses the existing event_id/user_id receipts table; no schema change.
 * Guidance events and rejection events retain distinct validation and UI.
 */
defined( 'ABSPATH' ) || exit;

function crmb_task_revision_ready() {
    return function_exists( 'crmb_task_guidance_ready' )
        && crmb_task_guidance_ready() && crmb_progress_ready();
}

/** Unread correction requests in the whole history, including old pages. */
function crmb_task_revision_unread( $task_id, $user_id ) {
    $empty = array( 'count' => 0, 'latest_id' => 0, 'latest_reason' => '', 'error' => false );
    if ( ! $task_id || ! $user_id || ! crmb_task_revision_ready() ) {
        $empty['error'] = true;
        return $empty;
    }
    global $wpdb;
    $events = crmb_progress_tables()['events'];
    $reads = crmb_task_guidance_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS unread_count, MAX(e.id) AS latest_id
           FROM {$events} e
           LEFT JOIN {$reads} r ON r.event_id=e.id AND r.user_id=%d
          WHERE e.task_id=%d AND e.event_type='review_rejected'
            AND r.event_id IS NULL",
        $user_id, $task_id
    ) );
    if ( $wpdb->last_error || ! $row ) { $empty['error'] = true; return $empty; }
    $latest_id = (int) $row->latest_id;
    $latest_reason = '';
    if ( $latest_id ) {
        $latest_reason = $wpdb->get_var( $wpdb->prepare(
            "SELECT body FROM {$events}
              WHERE id=%d AND task_id=%d AND event_type='review_rejected'",
            $latest_id, $task_id
        ) );
        if ( $wpdb->last_error ) { $empty['error'] = true; return $empty; }
    }
    return array(
        'count' => (int) $row->unread_count,
        'latest_id' => $latest_id,
        'latest_reason' => is_string( $latest_reason ) ? $latest_reason : '',
        'error' => false,
    );
}

/** Read states for rejection events on this history page only. */
function crmb_task_revision_reads( $events, $user_id ) {
    if ( ! crmb_task_revision_ready() || ! $user_id || ! is_array( $events ) ) {
        return array();
    }
    $ids = array();
    foreach ( $events as $event ) {
        if ( 'review_rejected' === $event->event_type ) {
            $ids[] = (int) $event->id;
        }
    }
    if ( ! $ids ) { return array(); }
    global $wpdb;
    $table = crmb_task_guidance_table();
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT event_id,read_utc FROM {$table}
          WHERE user_id=%d AND event_id IN ({$placeholders})",
        $user_id, ...$ids
    ) );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { return array(); }
    $read = array();
    foreach ( $rows as $row ) { $read[(int) $row->event_id] = $row->read_utc; }
    return $read;
}

/**
 * Only the current task assignee can confirm an actual correction event.
 * Transaction + locked task row protect against simultaneous reassignment.
 */
function crmb_task_revision_ack( $task_id, $event_id ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( ! crmb_task_revision_ready() ) { return 'error'; }
    if ( ! $task_id || ! $event_id ) { return 'invalid'; }
    global $wpdb;
    $uid = get_current_user_id();
    $tables = crmb_progress_tables();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $task = crmb_progress_task( $task_id, true );
    if ( ! $task || (int) $task->assigned_to !== $uid ) {
        return crmb_progress_fail( 'denied' );
    }
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$tables['events']}
           WHERE id=%d AND task_id=%d AND event_type='review_rejected'",
        $event_id, $task_id
    ) );
    if ( $wpdb->last_error ) { return crmb_progress_fail(); }
    if ( ! $exists ) { return crmb_progress_fail( 'invalid' ); }

    $reads = crmb_task_guidance_table();
    $new = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$reads} (event_id,user_id,read_utc)
          VALUES (%d,%d,%s)",
        $event_id, $uid, gmdate( 'Y-m-d H:i:s' )
    ) );
    if ( false === $new ) { return crmb_progress_fail(); }
    if ( false === $wpdb->query( 'COMMIT' ) ) { return crmb_progress_fail(); }
    if ( function_exists( 'crmb_notice_read_revision' ) ) {
        crmb_notice_read_revision( $event_id, $uid );
    }
    return 1 === $new ? 'read_revision' : 'changed';
}
