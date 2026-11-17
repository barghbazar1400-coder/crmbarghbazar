<?php
/**
 * Scheduled, opt-in CRM reminders for tasks and sales follow-ups.
 * Reuses the private notification inbox/push queue and its UNIQUE
 * (user_id,source_key). No parallel messaging channel or new database table.
 */
defined( 'ABSPATH' ) || exit;

function crmb_reminder_rules() {
    $defaults = array(
        'enabled' => 0,
        'due' => 1, 'overdue' => 1, 'stalled' => 1, 'followups' => 1,
        'advance_days' => 1, 'stale_hours' => 48, 'hour' => 9, 'daily_cap' => 12,
    );
    $saved = get_option( 'crmb_reminder_rules_v1', array() );
    if ( ! is_array( $saved ) ) { return $defaults; }
    $rules = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
    foreach ( array( 'enabled', 'due', 'overdue', 'stalled', 'followups' ) as $key ) {
        $rules[$key] = (int) ( '1' === (string) $rules[$key] );
    }
    $rules['advance_days'] = max( 1, min( 3, (int) $rules['advance_days'] ) );
    $rules['stale_hours'] = in_array( (int) $rules['stale_hours'], array( 24, 48, 72 ), true )
        ? (int) $rules['stale_hours'] : 48;
    $rules['hour'] = max( 7, min( 18, (int) $rules['hour'] ) );
    $rules['daily_cap'] = max( 5, min( 30, (int) $rules['daily_cap'] ) );
    return $rules;
}

/** Rule milestones avoid sending an unread notification every single day. */
function crmb_reminder_overdue_milestone( $days ) {
    $days = (int) $days;
    return 1 === $days || 3 === $days || ( $days >= 7 && 0 === ( $days - 7 ) % 7 );
}

function crmb_reminder_due_moment( $hour, $now = null ) {
    $now = $now instanceof DateTimeImmutable ? $now : crmb_att_now_local();
    $candidate = $now->setTime( (int) $hour, 0, 0 );
    if ( $candidate <= $now ) { $candidate = $candidate->modify( '+1 day' ); }
    return $candidate->getTimestamp();
}
function crmb_reminder_schedule() {
    if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' )
        || ! crmb_reminder_rules()['enabled'] || ! crmb_notice_ready() ) { return; }
    if ( ! wp_next_scheduled( 'crmb_reminder_daily' ) ) {
        wp_schedule_single_event( crmb_reminder_due_moment( crmb_reminder_rules()['hour'] ),
            'crmb_reminder_daily' );
    }
}
add_action( 'init', 'crmb_reminder_schedule', 28 );

function crmb_reminder_save_rules( $data ) {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) { return 'denied'; }
    if ( ! is_array( $data ) ) { return 'invalid'; }
    $rules = crmb_reminder_rules();
    foreach ( array( 'enabled', 'due', 'overdue', 'stalled', 'followups' ) as $key ) {
        $rules[$key] = isset( $data[$key] ) && '1' === (string) $data[$key] ? 1 : 0;
    }
    $advance = isset( $data['advance_days'] ) && is_scalar( $data['advance_days'] )
        ? (int) $data['advance_days'] : 0;
    $stale = isset( $data['stale_hours'] ) && is_scalar( $data['stale_hours'] )
        ? (int) $data['stale_hours'] : 0;
    $hour = isset( $data['hour'] ) && is_scalar( $data['hour'] )
        ? (int) $data['hour'] : -1;
    $cap = isset( $data['daily_cap'] ) && is_scalar( $data['daily_cap'] )
        ? (int) $data['daily_cap'] : 0;
    if ( $advance < 1 || $advance > 3
        || ! in_array( $stale, array( 24, 48, 72 ), true )
        || $hour < 7 || $hour > 18 || $cap < 5 || $cap > 30 ) { return 'invalid'; }
    $rules['advance_days'] = $advance;
    $rules['stale_hours'] = $stale;
    $rules['hour'] = $hour;
    $rules['daily_cap'] = $cap;
    if ( ! update_option( 'crmb_reminder_rules_v1', $rules, false ) &&
        crmb_reminder_rules() !== $rules ) { return 'error'; }
    // Changes to the configured hour must not leave the previous alarm active.
    if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
        wp_clear_scheduled_hook( 'crmb_reminder_daily' );
    }
    crmb_reminder_schedule();
    return 'saved';
}
function crmb_reminder_rules_action() {
    if ( ! is_user_logged_in() ) { wp_die( 'ورود لازم است.', '', array( 'response' => 403 ) ); }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'درخواست نامعتبر.', '', array( 'response' => 405 ) ); }
    check_admin_referer( 'crmb_reminder_rules', 'crmb_reminder_nonce' );
    $result = crmb_reminder_save_rules( wp_unslash( $_POST ) );
    wp_safe_redirect( add_query_arg(
        array( 'crm_section' => 'notifications', 'reminder_result' => $result ),
        home_url( '/main/' )
    ) );
    exit;
}
add_action( 'admin_post_crmb_reminder_rules', 'crmb_reminder_rules_action' );

/** Single-recipient budget for a scheduled pass, not global deletion of inbox data. */
function crmb_reminder_send( $uid, $key, $title, $summary, $href, &$budget, $daily_cap ) {
    $uid = (int) $uid;
    if ( ! $uid || ( $budget[$uid] ?? 0 ) >= $daily_cap ) { return false; }
    $ok = crmb_notice_add( $uid, 'task', $title, $summary, $href, $key );
    if ( $ok ) { $budget[$uid] = ( $budget[$uid] ?? 0 ) + 1; }
    return $ok;
}
/**
 * Processes at most 500 rows per category per day. All filters are on
 * indexable task date/status columns and private event access stays unchanged.
 * Failure stops the pass from recording a successful day.
 */
function crmb_reminder_run( $day = null, $now_utc = null, $override = null ) {
    $rules = is_array( $override ) ? $override : crmb_reminder_rules();
    if ( empty( $rules['enabled'] ) || ! crmb_notice_ready()
        || '1' !== get_option( 'crmb_work_schema_v1' ) ) { return 'disabled'; }
    $day = $day ?: crmb_att_today();
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) { return 'invalid'; }
    if ( get_option( 'crmb_reminder_last_day', '' ) === $day ) { return 'already'; }
    $now_utc = $now_utc ?: gmdate( 'Y-m-d H:i:s' );
    $now_timestamp = strtotime( $now_utc . ' UTC' );
    if ( ! $now_timestamp ) { return 'invalid'; }
    global $wpdb;
    $tasks = crmb_work_tables()['tasks'];
    $deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
        ? ' AND t.deleted_utc IS NULL' : '';
    $budget = array();
    $cap = max( 5, min( 30, (int) $rules['daily_cap'] ) );
    $today_stamp = strtotime( $day . ' UTC' );
    $future = gmdate( 'Y-m-d', $today_stamp + (int) $rules['advance_days'] * 86400 );
    $send_task = static function ( $row, $phase, $label, $recipient ) use ( &$budget, $cap ) {
        $task_id = (int) $row->id;
        $recipient = (int) $recipient;
        $date = (string) $row->due_day;
        $title = trim( (string) $row->title );
        $title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 80, 'UTF-8' ) : $title;
        return crmb_reminder_send(
            $recipient, 'reminder:task:' . $task_id . ':' . $date . ':' . $phase,
            $label . ': «' . $title . '»',
            'وظیفه #' . $task_id . ' · موعد ' . crmb_att_jdate( $date ),
            crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ), $budget, $cap
        );
    };
    if ( ! empty( $rules['due'] ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id,t.title,t.assigned_to,t.due_day
               FROM {$tasks} t WHERE t.status IN ('todo','doing','paused')
                 AND t.due_day BETWEEN %s AND %s{$deleted}
               ORDER BY t.due_day ASC,t.id DESC LIMIT 500", $day, $future
        ) );
        if ( $wpdb->last_error || ! is_array( $rows ) ) { return 'error'; }
        foreach ( $rows as $row ) {
            $phase = $row->due_day === $day ? 'today' : 'advance';
            $send_task( $row, $phase,
                'today' === $phase ? 'موعد انجام امروز' : 'یادآوری موعد نزدیک', $row->assigned_to );
        }
    }
    if ( ! empty( $rules['overdue'] ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id,t.title,t.assigned_to,t.created_by,t.status,t.due_day
               FROM {$tasks} t
              WHERE t.status IN ('todo','doing','paused','review')
                AND t.due_day < %s{$deleted}
              ORDER BY t.due_day DESC,t.id DESC LIMIT 500", $day
        ) );
        if ( $wpdb->last_error || ! is_array( $rows ) ) { return 'error'; }
        foreach ( $rows as $row ) {
            $days_late = (int) round( ( $today_stamp - strtotime( $row->due_day . ' UTC' ) ) / 86400 );
            if ( ! crmb_reminder_overdue_milestone( $days_late ) ) { continue; }
            $phase = 'late-' . $days_late;
            // An employee waiting for manager review should not receive a
            // misleading overdue task alert; the manager receives it instead.
            if ( 'review' === $row->status ) {
                if ( function_exists( 'user_can' ) && user_can( (int) $row->created_by, 'manage_options' ) ) {
                    $send_task( $row, 'review-' . $phase,
                        'کار منتظر تأیید و عقب‌افتاده', $row->created_by );
                }
                continue;
            }
            $send_task( $row, $phase, 'وظیفه عقب‌افتاده', $row->assigned_to );
            if ( $days_late >= 3 && (int) $row->created_by !== (int) $row->assigned_to
                && function_exists( 'user_can' )
                && user_can( (int) $row->created_by, 'manage_options' ) ) {
                $send_task( $row, 'manager-' . $phase,
                    'پیگیری تأخیر کارمند', $row->created_by );
            }
        }
    }

    if ( ! empty( $rules['stalled'] ) && crmb_progress_ready() ) {
        $events = crmb_progress_tables()['events'];
        $cutoff = gmdate( 'Y-m-d H:i:s', $now_timestamp - (int) $rules['stale_hours'] * 3600 );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id,t.title,t.assigned_to,t.created_by,t.created_utc,
                (SELECT MAX(e.created_utc) FROM {$events} e
                  WHERE e.task_id=t.id AND e.actor_id=t.assigned_to
                    AND e.event_type IN ('note','blocker','rich_note','rich_blocker')
                ) AS last_progress_utc,
                (SELECT MAX(e.id) FROM {$events} e
                  WHERE e.task_id=t.id AND e.actor_id=t.assigned_to
                    AND e.event_type IN ('note','blocker','rich_note','rich_blocker')
                ) AS last_progress_id
               FROM {$tasks} t
              WHERE t.status IN ('doing','paused') AND t.created_utc < %s{$deleted}
                AND NOT EXISTS (
                    SELECT 1 FROM {$events} recent WHERE recent.task_id=t.id
                      AND recent.actor_id=t.assigned_to
                      AND recent.event_type IN ('note','blocker','rich_note','rich_blocker')
                      AND recent.created_utc >= %s
                )
              ORDER BY t.updated_utc DESC,t.id DESC LIMIT 500",
            $cutoff, $cutoff
        ) );
        if ( $wpdb->last_error || ! is_array( $rows ) ) { return 'error'; }
        foreach ( $rows as $row ) {
            $last = $row->last_progress_utc ?: $row->created_utc;
            $hours = (int) floor( ( $now_timestamp - strtotime( $last . ' UTC' ) ) / 3600 );
            if ( $hours < (int) $rules['stale_hours'] ) { continue; }
            // One alert per 72-hour period after crossing the threshold;
            // a new employee report changes the key and resets the clock.
            $phase = 1 + (int) floor( ( $hours - (int) $rules['stale_hours'] ) / 72 );
            $key = 'reminder:stale:' . (int) $row->id . ':' . (int) $row->assigned_to .
                ':' . (int) $row->last_progress_id . ':' . $phase;
            $label = 'گزارش پیشرفت جدید ثبت نشده';
            $text = 'وظیفه #' . (int) $row->id . ' · ' . $hours . ' ساعت بدون گزارش کارمند';
            $url = crmb_notice_url( 'tasks', array( 'task_id' => $row->id ) );
            crmb_reminder_send( (int) $row->assigned_to, $key . ':owner',
                $label, $text, $url, $budget, $cap );
            if ( (int) $row->created_by !== (int) $row->assigned_to
                && function_exists( 'user_can' ) && user_can( (int) $row->created_by, 'manage_options' ) ) {
                crmb_reminder_send( (int) $row->created_by, $key . ':manager',
                    'نیازمند پیگیری: کار بدون گزارش', $text, $url, $budget, $cap );
            }
        }
    }

    if ( ! empty( $rules['followups'] ) && function_exists( 'crmb_sales_ready' ) && crmb_sales_ready() ) {
        $sales = crmb_sales_tables();
        if ( function_exists( 'crmb_sales_ops_ready' ) && crmb_sales_ops_ready()
            && '1' === get_option( 'crmb_sales_calls_v2' ) ) {
            $calls = crmb_sales_ops_tables()['calls'];
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT x.id,x.customer_id,x.next_followup_day AS due_day,
                        c.name AS customer_name,c.owner_id
                   FROM {$calls} x INNER JOIN {$sales['customers']} c ON c.id=x.customer_id
                  WHERE x.outcome='followup' AND x.next_followup_day<=%s
                    AND x.id=(SELECT MAX(y.id) FROM {$calls} y WHERE y.customer_id=x.customer_id)
                  ORDER BY x.next_followup_day DESC,x.id DESC LIMIT 500", $day
            ) );
        } else {
            $opportunities = $sales['opportunities'];
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT o.id,o.customer_id,o.followup_day AS due_day,
                        c.name AS customer_name,c.owner_id
                   FROM {$opportunities} o INNER JOIN {$sales['customers']} c ON c.id=o.customer_id
                  WHERE o.followup_day<=%s AND o.stage IN ('lead','contacted','quoted')
                  ORDER BY o.followup_day DESC,o.id DESC LIMIT 500", $day
            ) );
        }
        if ( $wpdb->last_error || ! is_array( $rows ) ) { return 'error'; }
        foreach ( $rows as $row ) {
            $late = (int) round( ( $today_stamp - strtotime( $row->due_day . ' UTC' ) ) / 86400 );
            if ( $late && ! crmb_reminder_overdue_milestone( $late ) ) { continue; }
            $phase = $late ? 'late-' . $late : 'today';
            $key = 'reminder:sales:' . (int) $row->id . ':' . $row->due_day . ':' . $phase;
            $url = crmb_notice_url( 'sales', array(
                'sales_tab' => 'customers', 'customer' => (int) $row->customer_id
            ) ) . '#crmb-customer-file';
            crmb_reminder_send( (int) $row->owner_id, $key,
                $late ? 'پیگیری مشتری عقب‌افتاده' : 'پیگیری مشتری برای امروز',
                'مشتری: ' . (string) $row->customer_name . ' · موعد ' . crmb_att_jdate( $row->due_day ),
                $url, $budget, $cap );
        }
    }
    update_option( 'crmb_reminder_last_day', $day, false );
    return 'saved';
}
add_action( 'crmb_reminder_daily', function () {
    crmb_reminder_run();
    crmb_reminder_schedule();
} );
