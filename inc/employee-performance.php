<?php
/**
 * Read-only, role-scoped staff performance report from existing CRM tables.
 * No ranking, scoring, or estimations: statuses, event counts and tracked time
 * stay separate because different tasks have different difficulty.
 */
defined( 'ABSPATH' ) || exit;

function crmb_performance_periods() {
    return array( 'today' => 'امروز', 'week' => '۷ روز اخیر', 'month' => '۳۰ روز اخیر' );
}
function crmb_performance_bounds( $period = 'week' ) {
    $days = array( 'today' => 1, 'week' => 7, 'month' => 30 );
    $period = isset( $days[$period] ) ? $period : 'week';
    $today = new DateTimeImmutable( crmb_att_today() . ' 00:00:00', crmb_att_timezone() );
    $start = $today->modify( '-' . ( $days[$period] - 1 ) . ' days' );
    $end = $today->modify( '+1 day' );
    $utc = new DateTimeZone( 'UTC' );
    return array(
        'period' => $period,
        'first_day' => $start->format( 'Y-m-d' ),
        'last_day' => $today->format( 'Y-m-d' ),
        'start_utc' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
        'end_utc' => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
    );
}
function crmb_performance_zero( $uid ) {
    return array(
        'user_id' => (int) $uid,
        'total' => 0, 'open' => 0, 'review' => 0, 'overdue' => 0,
        'created' => 0, 'completed' => 0,
        'tracked_seconds' => 0, 'timed_completed' => 0,
        'reports' => 0, 'reported_tasks' => 0, 'corrections' => 0,
    );
}
function crmb_performance_data( $period = 'week', $focus_user = 0 ) {
    if ( ! function_exists( 'crmb_leadership_can_operate' )
        || ! crmb_leadership_can_operate() ) { return null; }
    $bounds = crmb_performance_bounds( $period );
    $out = array( 'ready' => false, 'bounds' => $bounds, 'rows' => array(),
        'selected' => 0, 'details' => array() );
    if ( '1' !== get_option( 'crmb_work_schema_v1' )
        || ! function_exists( 'crmb_progress_ready' ) || ! crmb_progress_ready() ) {
        return $out;
    }
    global $wpdb;
    $tasks = crmb_work_tables()['tasks'];
    $events = crmb_progress_tables()['events'];
    $not_deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
        ? ' WHERE t.deleted_utc IS NULL' : '';
    $deleted_condition = $not_deleted ? ' AND t.deleted_utc IS NULL' : '';
    $start = $bounds['start_utc'];
    $end = $bounds['end_utc'];

    // Snapshot counters (open, review, overdue) are intentionally distinct
    // from activity within a selected historical period.
    $tasks_sql = $wpdb->prepare(
        "SELECT t.assigned_to AS user_id,COUNT(*) AS total,
            SUM(CASE WHEN t.status IN ('todo','doing','paused') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN t.status='review' THEN 1 ELSE 0 END) AS review_count,
            SUM(CASE WHEN t.status IN ('todo','doing','paused','review')
                       AND t.due_day IS NOT NULL AND t.due_day < %s THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN t.created_utc >= %s AND t.created_utc < %s THEN 1 ELSE 0 END) AS created_count,
            SUM(CASE WHEN t.status='done' AND t.completed_utc >= %s AND t.completed_utc < %s
                     THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN t.status='done' AND t.completed_utc >= %s AND t.completed_utc < %s
                       AND t.tracked_seconds > 0 THEN t.tracked_seconds ELSE 0 END) AS tracked_seconds,
            SUM(CASE WHEN t.status='done' AND t.completed_utc >= %s AND t.completed_utc < %s
                       AND t.tracked_seconds > 0 THEN 1 ELSE 0 END) AS timed_completed
          FROM {$tasks} t {$not_deleted} GROUP BY t.assigned_to",
        crmb_att_today(), $start, $end, $start, $end, $start, $end, $start, $end
    );
    $task_rows = $wpdb->get_results( $tasks_sql );
    if ( $wpdb->last_error || ! is_array( $task_rows ) ) { return $out; }

    // Attribute progress reports to the ACTOR who authored them, not the
    // current task assignee; a task might have been reassigned afterward.
    $report_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.actor_id AS user_id,COUNT(*) AS reports,
                 COUNT(DISTINCT e.task_id) AS reported_tasks
           FROM {$events} e
          WHERE e.event_type IN ('note','blocker','rich_note','rich_blocker')
            AND e.created_utc >= %s AND e.created_utc < %s
          GROUP BY e.actor_id",
        $start, $end
    ) );
    if ( $wpdb->last_error || ! is_array( $report_rows ) ) { return $out; }

    // Old rejection events have no dedicated assignee-at-decision column.
    // Count them against the CURRENT task owner and label that limitation.
    $correction_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.assigned_to AS user_id,COUNT(*) AS corrections
           FROM {$events} e INNER JOIN {$tasks} t ON t.id=e.task_id
          WHERE e.event_type='review_rejected'
            AND e.created_utc >= %s AND e.created_utc < %s{$deleted_condition}
          GROUP BY t.assigned_to", $start, $end
    ) );
    if ( $wpdb->last_error || ! is_array( $correction_rows ) ) { return $out; }

    $by_user = array();
    foreach ( $task_rows as $row ) {
        $id = (int) $row->user_id;
        if ( $id < 1 ) { continue; }
        $by_user[$id] = crmb_performance_zero( $id );
        foreach ( array(
            'total' => 'total', 'open_count' => 'open',
            'review_count' => 'review', 'overdue_count' => 'overdue',
            'created_count' => 'created', 'completed_count' => 'completed',
            'tracked_seconds' => 'tracked_seconds', 'timed_completed' => 'timed_completed',
        ) as $src => $dst ) { $by_user[$id][$dst] = (int) $row->$src; }
    }
    foreach ( $report_rows as $row ) {
        $id = (int) $row->user_id;
        if ( $id < 1 ) { continue; }
        if ( ! isset( $by_user[$id] ) ) { $by_user[$id] = crmb_performance_zero( $id ); }
        $by_user[$id]['reports'] = (int) $row->reports;
        $by_user[$id]['reported_tasks'] = (int) $row->reported_tasks;
    }
    foreach ( $correction_rows as $row ) {
        $id = (int) $row->user_id;
        if ( $id < 1 ) { continue; }
        if ( ! isset( $by_user[$id] ) ) { $by_user[$id] = crmb_performance_zero( $id ); }
        $by_user[$id]['corrections'] = (int) $row->corrections;
    }
    foreach ( $by_user as $id => &$row ) {
        $person = get_userdata( $id );
        $row['name'] = $person && trim( (string) $person->display_name )
            ? $person->display_name : 'کارمند #' . $id;
        $row['avg_seconds'] = $row['timed_completed']
            ? (int) round( $row['tracked_seconds'] / $row['timed_completed'] ) : null;
    }
    unset( $row );
    uasort( $by_user, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
    $out['ready'] = true;
    $out['rows'] = array_values( $by_user );
    $focus_user = (int) $focus_user;
    if ( $focus_user < 1 || ! isset( $by_user[$focus_user] ) ) { return $out; }

    $out['selected'] = $focus_user;
    // Two small, strictly task-scoped drilldowns; no private message, HR or
    // customer data is ever queried in the performance module.
    $overdue = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.id,t.title,t.status,t.due_day,t.completed_utc
           FROM {$tasks} t WHERE t.assigned_to=%d
             AND t.status IN ('todo','doing','paused','review')
             AND t.due_day IS NOT NULL AND t.due_day < %s{$deleted_condition}
          ORDER BY t.due_day ASC,t.id DESC LIMIT 12",
        $focus_user, crmb_att_today()
    ) );
    if ( $wpdb->last_error || ! is_array( $overdue ) ) {
        $out['ready'] = false; return $out;
    }
    $done = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.id,t.title,t.status,t.due_day,t.completed_utc
           FROM {$tasks} t WHERE t.assigned_to=%d AND t.status='done'
             AND t.completed_utc >= %s AND t.completed_utc < %s{$deleted_condition}
          ORDER BY t.completed_utc DESC,t.id DESC LIMIT 12",
        $focus_user, $start, $end
    ) );
    if ( $wpdb->last_error || ! is_array( $done ) ) {
        $out['ready'] = false; return $out;
    }
    $out['details'] = array( 'overdue' => $overdue, 'completed' => $done );
    return $out;
}
