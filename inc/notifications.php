<?php
/** CRM event inbox, announcements, and permission-checked AJAX API. */
defined( 'ABSPATH' ) || exit;

function crmb_notice_tables() {
    global $wpdb;
    return array(
        'notices' => $wpdb->prefix . 'crmb_notifications',
        'devices' => $wpdb->prefix . 'crmb_push_devices',
    );
}
function crmb_notice_ready() {
    return '1' === get_option( 'crmb_notice_schema_v1' );
}
function crmb_notice_install() {
    if ( crmb_notice_ready() || '1' !== get_option( 'crmb_work_schema_v1' ) ) { return; }
    global $wpdb;
    $t = crmb_notice_tables();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$t['notices']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
        kind varchar(30) NOT NULL,
        title varchar(160) NOT NULL,
        summary varchar(255) NOT NULL DEFAULT '',
        detail text NOT NULL,
        href varchar(255) NOT NULL,
        source_key varchar(160) NOT NULL,
        created_utc datetime NOT NULL,
        read_utc datetime DEFAULT NULL,
        push_state varchar(12) NOT NULL DEFAULT 'pending',
        attempts tinyint unsigned NOT NULL DEFAULT 0,
        next_try_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_notice (user_id,source_key),
        KEY user_read (user_id,read_utc,id),
        KEY push_queue (push_state,next_try_utc,id)
    ) {$charset};" );
    dbDelta( "CREATE TABLE {$t['devices']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        endpoint_hash char(64) NOT NULL,
        endpoint text NOT NULL,
        p256dh varchar(150) NOT NULL,
        auth varchar(70) NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY endpoint_hash (endpoint_hash),
        KEY device_owner (user_id,id)
    ) {$charset};" );
    foreach ( $t as $table ) {
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { return; }
    }
    add_option( 'crmb_notice_schema_v1', '1', '', false );
}
add_action( 'init', 'crmb_notice_install', 14 );
add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    $items['notifications'] = array( 'label' => 'اعلان‌ها و اطلاعیه‌ها', 'always_visible' => true );
    return $items;
} );

/** Validate character limits correctly for Persian text even without mbstring. */
function crmb_notice_length( $value ) {
    return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value );
}
function crmb_notice_url( $section, $params = array() ) {
    return add_query_arg( array_merge( array( 'crm_section' => $section ), $params ), home_url( '/main/' ) );
}
function crmb_notice_add( $user_id, $kind, $title, $summary, $href, $source_key, $actor_id = 0, $detail = '' ) {
    if ( ! crmb_notice_ready() || ! $user_id || ! get_userdata( $user_id ) || ! $source_key ) { return false; }
    if ( ! in_array( $kind, array( 'message', 'task', 'manager', 'task_done', 'announcement' ), true ) ) { return false; }
    // Private message content NEVER enters push payloads; shorten inbox text as well.
    $title = sanitize_text_field( $title );
    $summary = sanitize_text_field( $summary );
    $detail = sanitize_textarea_field( $detail );
    $href = esc_url_raw( $href );
    if ( crmb_notice_length( $title ) > 160 || crmb_notice_length( $summary ) > 255 || strlen( $href ) > 255
        || strlen( $source_key ) > 160 || crmb_notice_length( $detail ) > 2000 || ! wp_validate_redirect( $href, false ) ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $now = gmdate( 'Y-m-d H:i:s' );
    $inserted = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$t['notices']} (user_id,actor_id,kind,title,summary,detail,href,source_key,created_utc,next_try_utc) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s)",
        (int) $user_id, (int) $actor_id, $kind, $title, $summary, $detail, $href, $source_key, $now, $now
    ) );
    if ( 1 === $inserted ) {
        // Queue at once, and best-effort spawn background WordPress cron after
        // this admin-post handler; regular WP-Cron would otherwise wait for
        // another page view before processing the event.
        if ( function_exists( 'crmb_push_public_key' ) && crmb_push_public_key() ) {
            if ( ! wp_next_scheduled( 'crmb_notice_push_immediate' ) ) {
                wp_schedule_single_event( time(), 'crmb_notice_push_immediate' );
            }
            if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
                spawn_cron( time() );
            }
        }
        return true;
    }
    return false;
}

add_action( 'crmb_message_sent', function ( $message_id, $recipient, $sender ) {
    crmb_notice_add( $recipient, 'message', 'پیام جدید داری', 'یک پیام داخلی جدید دریافت کرده‌اید.',
        crmb_notice_url( 'messages', array( 'message' => $message_id ) ), 'message:' . $message_id, $sender );
}, 10, 3 );
add_action( 'crmb_task_assigned', function ( $task_id, $recipient, $actor, $previous = 0 ) {
    if ( (int) $recipient === (int) $actor ) { return; }
    $event = $previous ? 'reassigned' : 'assigned';
    crmb_notice_add( $recipient, 'task', $previous ? 'وظیفه جدید به شما منتقل شد' : 'وظیفه جدید به شما واگذار شد',
        'وظایف خود را بررسی کنید.', crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ),
        'task:' . $task_id . ':' . $event . ':' . wp_generate_uuid4(), $actor );
}, 10, 4 );
add_action( 'crmb_manager_guidance', function ( $task_id, $recipient, $actor, $event_id ) {
    if ( (int) $actor === (int) $recipient ) { return; }
    crmb_notice_add( $recipient, 'manager', 'راهنمایی جدید مدیر', 'برای یکی از وظایف شما راهنمایی ثبت شده است.',
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ), 'guide:' . $event_id, $actor );
}, 10, 4 );
add_action( 'crmb_task_completed', function ( $task_id, $creator, $actor, $event_id ) {
    if ( (int) $creator === (int) $actor ) { return; }
    crmb_notice_add( $creator, 'task_done', 'وظیفه تکمیل شد', 'یکی از وظایف واگذارشده تکمیل شده است.',
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ), 'completed:' . $event_id, $actor );
}, 10, 4 );
/**
 * Compact, identifiable manager-review notice. Use the submitting actor
 * rather than the task's current assignee: old notifications must continue
 * naming the person who actually submitted them, even after reassignment.
 */
function crmb_notice_review_text( $task_id, $task_title, $actor_id, $resubmitted = false ) {
    $name = crmb_work_user_name( (int) $actor_id );
    $name = sanitize_text_field( $name );
    $title = sanitize_text_field( $task_title );
    if ( function_exists( 'mb_substr' ) ) {
        $name = mb_substr( $name, 0, 60, 'UTF-8' );
        $title = mb_substr( $title, 0, 90, 'UTF-8' );
    } else {
        // Unicode-safe fallback for older PHP installations without mbstring.
        preg_match( '/^.{0,60}/us', $name, $n );
        preg_match( '/^.{0,90}/us', $title, $t );
        $name = $n[0] ?? '';
        $title = $t[0] ?? '';
    }
    if ( '' === $title ) { $title = 'کار #' . (int) $task_id; }
    return array(
        'title' => ( $resubmitted ? 'ارسال مجدد برای تأیید: «' : 'در انتظار تأیید: «' ) . $title . '»',
        'summary' => 'ارسال‌کننده: ' . $name . ' | شماره کار: #' . (int) $task_id,
    );
}
function crmb_notice_review_task_text( $task_id, $actor_id, $resubmitted = false ) {
    global $wpdb;
    $tables = crmb_work_tables();
    $task = $wpdb->get_row( $wpdb->prepare(
        "SELECT id,title FROM {$tables['tasks']} WHERE id=%d",
        (int) $task_id
    ) );
    return crmb_notice_review_text( $task_id, $task ? $task->title : '', $actor_id, $resubmitted );
}
function crmb_notice_task_review_requested( $task_id, $manager, $actor, $event_id ) {
    if ( ! $manager || (int) $manager === (int) $actor ) { return; }
    $text = crmb_notice_review_task_text( $task_id, $actor );
    crmb_notice_add( $manager, 'task', $text['title'], $text['summary'],
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ),
        'task_review_request:' . $event_id, $actor );
}
add_action( 'crmb_task_review_requested', 'crmb_notice_task_review_requested', 10, 4 );

// The last rejecting manager may differ from the task creator. The notice
// still identifies the employee and task instead of a generic resubmission.
function crmb_notice_task_review_resubmitted( $task_id, $reviewer, $actor, $event_id ) {
    if ( ! $reviewer || (int) $reviewer === (int) $actor ) { return; }
    $text = crmb_notice_review_task_text( $task_id, $actor, true );
    crmb_notice_add( $reviewer, 'task', $text['title'], $text['summary'],
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ),
        'task_review_resubmitted:' . $event_id, $actor );
}
add_action( 'crmb_task_review_resubmitted', 'crmb_notice_task_review_resubmitted', 10, 4 );
add_action( 'crmb_task_review_decided', function ( $task_id, $owner, $actor, $event_id, $approved ) {
    if ( (int) $owner === (int) $actor ) { return; }
    crmb_notice_add( $owner, 'task', $approved ? 'کار شما تأیید شد' : 'کار برای اصلاح برگشت',
        $approved ? 'مدیر انجام کار شما را تأیید نهایی کرد.' : 'دلیل بازگشت کار در تاریخچه همان وظیفه ثبت شده است.',
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ),
        'task_review_decision:' . $event_id, $actor );
}, 10, 5 );

add_action( 'crmb_task_status_updated', function ( $task_id, $owner, $actor, $event_id ) {
    if ( (int) $owner === (int) $actor ) { return; }
    crmb_notice_add( $owner, 'task', 'وضعیت وظیفه تغییر کرد', 'مدیر وضعیت یکی از کارهای شما را تغییر داده است.',
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ), 'task_status:' . $event_id, $actor );
}, 10, 4 );

/**
 * Strictly match the canonical private task URL; never use a substring such
 * as "%task_id=12%" that would accidentally match task #123.
 */
function crmb_notice_task_url( $task_id ) {
    return crmb_notice_url( 'tasks', array( 'task_id' => (int) $task_id ) );
}
/**
 * Viewing the actual task (even via the menu) acknowledges its BELL alerts.
 * It deliberately does not write to manager guidance/revision receipt tables:
 * those explicit "خواندم" confirmations retain their separate meaning.
 */
function crmb_notice_read_task_view( $task_id ) {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() || ! $task_id
        || ! function_exists( 'crmb_progress_task' )
        || ! crmb_progress_task( (int) $task_id ) ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables()['notices'];
    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE {$t} SET read_utc=%s,
          push_state=CASE WHEN push_state IN ('pending','retry') THEN 'skipped' ELSE push_state END
         WHERE user_id=%d AND href=%s AND kind IN ('task','manager','task_done')
           AND read_utc IS NULL",
        gmdate( 'Y-m-d H:i:s' ), get_current_user_id(),
        crmb_notice_task_url( $task_id )
    ) );
    return false !== $result;
}
/** Applied before the topbar bell is rendered, not after the task page. */
function crmb_notice_task_view_route() {
    if ( ! is_user_logged_in() || ! function_exists( 'is_page' )
        || ! is_page( 'main' ) || ! isset( $_GET['task_id'] )
        || ! is_scalar( $_GET['task_id'] ) ) { return; }
    $section = isset( $_GET['crm_section'] ) && is_string( $_GET['crm_section'] )
        ? sanitize_key( wp_unslash( $_GET['crm_section'] ) ) : '';
    if ( 'tasks' !== $section ) { return; }
    crmb_notice_read_task_view( absint( $_GET['task_id'] ) );
}
add_action( 'template_redirect', 'crmb_notice_task_view_route', 9 );

/**
 * Resolve old status alerts when the real task condition changes, rather
 * than making each recipient visit the notification popover.
 * Prefixes are fixed internal keys, not user-submitted SQL patterns.
 */
function crmb_notice_resolve_task( $task_id, $prefixes, $user_id = 0 ) {
    if ( ! crmb_notice_ready() || ! $task_id || ! is_array( $prefixes )
        || ! $prefixes ) { return false; }
    $allowed = array(
        'task_review_request:', 'task_review_resubmitted:',
        'task_review_decision:', 'task_status:', 'task:', 'guide:', 'completed:',
        'reminder:task:', 'reminder:stale:',
    );
    $where_prefixes = array();
    $args = array( gmdate( 'Y-m-d H:i:s' ), crmb_notice_task_url( $task_id ) );
    foreach ( array_unique( $prefixes ) as $prefix ) {
        if ( ! in_array( $prefix, $allowed, true ) ) { return false; }
        $where_prefixes[] = 'source_key LIKE %s';
        $args[] = $prefix . '%';
    }
    $scope = '';
    if ( $user_id ) {
        $scope = ' AND user_id=%d';
        $args[] = (int) $user_id;
    }
    global $wpdb;
    $notices = crmb_notice_tables()['notices'];
    $query = $wpdb->prepare(
        "UPDATE {$notices} SET read_utc=%s,
         push_state=CASE WHEN push_state IN ('pending','retry') THEN 'skipped' ELSE push_state END
         WHERE href=%s AND read_utc IS NULL
           AND (" . implode( ' OR ', $where_prefixes ) . ")" . $scope,
        ...$args
    );
    return false !== $wpdb->query( $query );
}
// Clear a manager's pending review request when they decide, without
// acknowledging the employee's newly-created decision notification.
add_action( 'crmb_task_review_decided', function ( $task_id ) {
    crmb_notice_resolve_task( $task_id, array(
        'task_review_request:', 'task_review_resubmitted:',
        'reminder:task:', 'reminder:stale:',
    ) );
}, 5, 1 );
// A resubmission resolves the employee's previous correction notification,
// not the new manager review request being created.
add_action( 'crmb_task_review_requested', function ( $task_id, $manager, $actor ) {
    // Once the employee has submitted the corrected task, any older
    // assignment, correction or manager-status alert for that employee
    // is informational history rather than an outstanding action.
    crmb_notice_resolve_task( $task_id, array(
        'task_review_decision:', 'task:', 'task_status:', 'guide:',
        'reminder:task:', 'reminder:stale:',
    ), (int) $actor );
}, 5, 3 );
// Manager's status update supersedes a previous manager status notice.
add_action( 'crmb_task_status_updated', function ( $task_id ) {
    crmb_notice_resolve_task( $task_id, array( 'task_status:' ) );
    // Cancelling or archiving a task invalidates old action-required
    // reminders for it, including outstanding review requests.
    global $wpdb;
    $table = crmb_work_tables()['tasks'];
    $current = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM {$table} WHERE id=%d", (int) $task_id
    ) );
    if ( in_array( $current, array( 'cancelled', 'done' ), true ) ) {
        crmb_notice_resolve_task( $task_id, array(
            'task:', 'task_review_request:', 'task_review_resubmitted:',
            'task_review_decision:', 'guide:', 'completed:',
            'reminder:task:', 'reminder:stale:',
        ) );
    }
}, 5, 1 );
// On reassignment, the former assignee cannot see that task anymore.
// Do not keep a pointless unread bell notification for that former owner.
add_action( 'crmb_task_assigned', function ( $task_id, $recipient, $actor, $previous = 0 ) {
    if ( $previous && (int) $previous !== (int) $recipient ) {
        crmb_notice_resolve_task( $task_id, array(
            'task:', 'task_status:', 'task_review_decision:', 'guide:',
            'reminder:task:', 'reminder:stale:',
        ), (int) $previous );
    }
}, 5, 4 );

/**
 * One bounded pass for legacy review alerts created before this change.
 * A request only remains current while its own review event is the latest
 * submission and the task is STILL waiting for a manager decision.
 * Per-request memoization avoids repeating this query for bell count/list.
 */
function crmb_notice_reconcile_old_reviews() {
    static $checked = false;
    if ( $checked || ! is_user_logged_in() || ! crmb_notice_ready()
        || ! function_exists( 'crmb_progress_ready' ) || ! crmb_progress_ready() ) { return; }
    $checked = true;
    global $wpdb;
    $n = crmb_notice_tables()['notices'];
    $pending = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,source_key FROM {$n}
          WHERE user_id=%d AND read_utc IS NULL
            AND (source_key LIKE 'task_review_request:%%'
                 OR source_key LIKE 'task_review_resubmitted:%%')
          ORDER BY id DESC LIMIT 150", get_current_user_id()
    ) );
    if ( $wpdb->last_error || ! $pending ) { return; }
    $ids = array();
    $notices = array();
    foreach ( $pending as $notice ) {
        if ( preg_match( '/^task_review_(?:request|resubmitted):([1-9][0-9]*)$/D',
            $notice->source_key, $match ) ) {
            $event_id = (int) $match[1];
            $ids[$event_id] = $event_id;
            $notices[$event_id][] = (int) $notice->id;
        }
    }
    if ( ! $ids ) { return; }
    $events = crmb_progress_tables()['events'];
    $tasks = crmb_work_tables()['tasks'];
    $marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.id,t.status,
          (SELECT MAX(x.id) FROM {$events} x
             WHERE x.task_id=e.task_id AND x.event_type='review') AS latest_review
           FROM {$events} e LEFT JOIN {$tasks} t ON t.id=e.task_id
          WHERE e.id IN ({$marks}) AND e.event_type='review'",
        ...array_values( $ids )
    ) );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { return; }
    $active = array();
    foreach ( $rows as $row ) {
        if ( 'review' === $row->status && (int) $row->latest_review === (int) $row->id ) {
            $active[(int) $row->id] = true;
        }
    }
    $close = array();
    foreach ( $notices as $event_id => $notice_ids ) {
        if ( ! isset( $active[$event_id] ) ) {
            $close = array_merge( $close, $notice_ids );
        }
    }
    if ( ! $close ) { return; }
    $marks = implode( ',', array_fill( 0, count( $close ), '%d' ) );
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$n} SET read_utc=%s,
          push_state=CASE WHEN push_state IN ('pending','retry') THEN 'skipped' ELSE push_state END
           WHERE user_id=%d AND read_utc IS NULL AND id IN ({$marks})",
        gmdate( 'Y-m-d H:i:s' ), get_current_user_id(), ...$close
    ) );
}
function crmb_notice_count() {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() ) { return 0; }
    crmb_notice_reconcile_old_reviews();
    global $wpdb;
    $t = crmb_notice_tables();
    $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['notices']} WHERE user_id=%d AND read_utc IS NULL", get_current_user_id() ) );
    return $wpdb->last_error ? 0 : (int) $count;
}
/**
 * Upgrade old, generic review notifications *on read*, without rewriting
 * history, re-sending push, or marking them read. One batched event lookup
 * covers the visible page, not one SQL query per notification.
 */
function crmb_notice_review_legacy_labels( $items ) {
    if ( ! is_array( $items ) || ! $items || ! crmb_progress_ready() ) { return $items; }
    $legacy = array();
    foreach ( $items as $index => $item ) {
        if ( ! isset( $item->source_key, $item->title ) ) { continue; }
        $requested = 'کار در انتظار تأیید شماست' === $item->title
            && preg_match( '/^task_review_request:([1-9][0-9]*)$/D', $item->source_key, $match );
        $resubmitted = 'اصلاحات کار برای تأیید مجدد ارسال شد' === $item->title
            && preg_match( '/^task_review_resubmitted:([1-9][0-9]*)$/D', $item->source_key, $match );
        if ( $requested || $resubmitted ) {
            $legacy[(int) $match[1]][] = array( $index, (bool) $resubmitted );
        }
    }
    if ( ! $legacy ) { return $items; }
    global $wpdb;
    $events = crmb_progress_tables()['events'];
    $tasks = crmb_work_tables()['tasks'];
    $ids = array_keys( $legacy );
    $marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.id,e.task_id,e.actor_id,t.title
           FROM {$events} e INNER JOIN {$tasks} t ON t.id=e.task_id
          WHERE e.id IN ({$marks}) AND e.event_type='review'",
        ...$ids
    ) );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { return $items; }
    foreach ( $rows as $event ) {
        foreach ( $legacy[(int) $event->id] ?? array() as $match ) {
            $index = $match[0];
            // Source key is bound to the real review event and actor.
            $label = crmb_notice_review_text(
                (int) $event->task_id, $event->title, (int) $event->actor_id, $match[1]
            );
            $items[$index]->title = $label['title'];
            $items[$index]->summary = $label['summary'];
        }
    }
    return $items;
}
function crmb_notice_list( $limit = 30 ) {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() ) { return array(); }
    crmb_notice_reconcile_old_reviews();
    global $wpdb;
    $t = crmb_notice_tables();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,kind,title,summary,detail,href,created_utc,read_utc,source_key
           FROM {$t['notices']} WHERE user_id=%d ORDER BY id DESC LIMIT %d",
        get_current_user_id(), max( 1, min( 50, (int) $limit ) )
    ) );
    return crmb_notice_review_legacy_labels( $rows ?: array() );
}
function crmb_notice_read( $id = 0, $all = false ) {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() || ( ! $all && ! $id ) ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $query = $all
        ? $wpdb->prepare( "UPDATE {$t['notices']} SET read_utc=%s WHERE user_id=%d AND read_utc IS NULL", gmdate( 'Y-m-d H:i:s' ), get_current_user_id() )
        : $wpdb->prepare( "UPDATE {$t['notices']} SET read_utc=%s WHERE id=%d AND user_id=%d AND read_utc IS NULL",
            gmdate( 'Y-m-d H:i:s' ), $id, get_current_user_id() );
    $changed = $wpdb->query( $query );
    return false !== $changed && ( $all || $changed > 0 );
}
/** Keep a message's notification in sync with the receiver's explicit read action. */
function crmb_notice_read_message( $message_id, $user_id ) {
    if ( ! crmb_notice_ready() || ! $message_id || ! $user_id ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE {$t['notices']} SET read_utc=%s WHERE user_id=%d AND source_key=%s AND read_utc IS NULL",
        gmdate( 'Y-m-d H:i:s' ), $user_id, 'message:' . $message_id
    ) );
    return false !== $result;
}

/** An acknowledged manager note should no longer appear unread in the bell. */
function crmb_notice_read_guidance( $event_id, $user_id ) {
    if ( ! crmb_notice_ready() || ! $event_id || ! $user_id ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE {$t['notices']} SET read_utc=%s WHERE user_id=%d AND source_key=%s AND read_utc IS NULL",
        gmdate( 'Y-m-d H:i:s' ), $user_id, 'guide:' . $event_id
    ) );
    return false !== $result;
}

/** Explicit acknowledgement of a correction request also resolves its bell notice. */
function crmb_notice_read_revision( $event_id, $user_id ) {
    if ( ! crmb_notice_ready() || ! $event_id || ! $user_id ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE {$t['notices']} SET read_utc=%s
          WHERE user_id=%d AND source_key=%s AND read_utc IS NULL",
        gmdate( 'Y-m-d H:i:s' ), $user_id, 'task_review_decision:' . $event_id
    ) );
    return false !== $result;
}

function crmb_notice_announce( $title, $body, $scope, $target ) {
    if ( ! current_user_can( 'manage_options' ) || ! crmb_notice_ready() ) { return 'denied'; }
    $title = trim( sanitize_text_field( $title ) );
    $body = trim( sanitize_textarea_field( $body ) );
    if ( '' === $title || '' === $body || crmb_notice_length( $title ) > 160 || crmb_notice_length( $body ) > 2000 ) { return 'invalid'; }
    if ( ! in_array( $scope, array( 'all', 'user', 'department' ), true ) ) { return 'invalid'; }
    if ( 'user' === $scope && ! get_userdata( $target ) ) { return 'invalid'; }
    if ( 'department' === $scope && ! crmb_department( $target ) ) { return 'invalid'; }
    if ( 'user' === $scope ) { $users = array( (object) array( 'ID' => $target ) ); }
    elseif ( 'department' === $scope ) { $users = crmb_department_members( $target ); }
    else { $users = get_users( array( 'fields' => array( 'ID' ) ) ); }
    if ( count( $users ) > 500 ) { return 'invalid'; }
    // Store full text separately; notification previews and desktop push stay generic.
    $key = 'announcement:' . wp_generate_uuid4();
    $url = crmb_notice_url( 'notifications' );
    $success = 0;
    foreach ( $users as $user ) {
        if ( crmb_notice_add( (int) $user->ID, 'announcement', $title, 'اطلاعیه جدید مدیریت منتشر شد.', $url, $key, get_current_user_id(), $body ) ) { ++$success; }
    }
    return $success ? 'saved' : 'empty';
}
function crmb_notice_action() {
    if ( ! is_user_logged_in() ) { wp_die( 'ورود لازم است.', '', array( 'response' => 403 ) ); }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'روش نامعتبر.', '', array( 'response' => 405 ) ); }
    check_admin_referer( 'crmb_notices', 'crmb_notice_nonce' );
    $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
    if ( 'announcement' === $mode ) {
        $result = crmb_notice_announce(
            isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
            isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '',
            isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : '',
            isset( $_POST[ 'department' === ( $_POST['scope'] ?? '' ) ? 'target_department' : 'target_user' ] )
                ? absint( $_POST[ 'department' === ( $_POST['scope'] ?? '' ) ? 'target_department' : 'target_user' ] ) : 0
        );
    } elseif ( 'read' === $mode || 'read_all' === $mode ) {
        $result = crmb_notice_read( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, 'read_all' === $mode ) ? 'read' : 'error';
    } else { $result = 'invalid'; }
    wp_safe_redirect( add_query_arg( array( 'crm_section' => 'notifications', 'notice_result' => $result ), home_url( '/main/' ) ) );
    exit;
}
add_action( 'admin_post_crmb_notices', 'crmb_notice_action' );
function crmb_notice_ajax() {
    if ( ! is_user_logged_in() ) { wp_send_json_error( array( 'message' => 'unauthorized' ), 403 ); }
    check_ajax_referer( 'crmb_notice_ajax', 'nonce' );
    nocache_headers();
    $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'poll';
    if ( 'poll' === $mode ) {
        $items = array();
        foreach ( crmb_notice_list( 20 ) as $item ) {
            $items[] = array( 'id' => (int) $item->id, 'kind' => $item->kind,
                'title' => $item->title, 'summary' => $item->summary,
                'created' => crmb_work_date( $item->created_utc ),
                'href' => $item->href, 'read' => (bool) $item->read_utc );
        }
        wp_send_json_success( array( 'count' => crmb_notice_count(), 'items' => $items ) );
    }
    if ( 'messages_html' === $mode ) {
        // Re-render only the recipient's private inbox. The compose form is
        // outside this fragment, so incoming messages never erase a draft.
        if ( ! function_exists( 'crmb_work_list' ) ) {
            wp_send_json_error( array( 'message' => 'unavailable' ), 503 );
        }
        $sent = false;
        $page = 1;
        $messages = crmb_work_list( 'messages', $page, 20, false, false, false, 0 );
        if ( ! $messages['ready'] ) {
            wp_send_json_error( array( 'message' => 'unavailable' ), 503 );
        }
        $base = add_query_arg( array( 'crm_section' => 'messages', 'box' => 'inbox' ), home_url( '/main/' ) );
        ob_start();
        include get_template_directory() . '/template-parts/messages-list.php';
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html, 'total' => (int) $messages['total'] ) );
    }
    if ( 'read' === $mode ) {
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! crmb_notice_read( $id ) ) { wp_send_json_error( array( 'message' => 'invalid' ), 400 ); }
        wp_send_json_success( array( 'count' => crmb_notice_count() ) );
    }
    wp_send_json_error( array( 'message' => 'invalid' ), 400 );
}
add_action( 'wp_ajax_crmb_notice', 'crmb_notice_ajax' );
