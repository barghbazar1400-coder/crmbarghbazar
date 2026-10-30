<?php
/** Task timer/history and private daily reports. */
defined( 'ABSPATH' ) || exit;
function crmb_progress_tables() {
    global $wpdb;
    return array( 'tasks' => $wpdb->prefix . 'crmb_tasks', 'locks' => $wpdb->prefix . 'crmb_work_locks', 'events' => $wpdb->prefix . 'crmb_task_events', 'reports' => $wpdb->prefix . 'crmb_daily_reports', 'images' => $wpdb->prefix . 'crmb_report_images' );
}
function crmb_progress_install() {
    if ( '1' === get_option( 'crmb_progress_schema_v1' ) || '1' !== get_option( 'crmb_work_schema_v1' ) ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = crmb_progress_tables(); $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$t['tasks']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(180) NOT NULL,
        description text NOT NULL,
        assigned_to bigint(20) unsigned NOT NULL,
        created_by bigint(20) unsigned NOT NULL,
        priority varchar(12) NOT NULL DEFAULT 'normal',
        due_day date DEFAULT NULL,
        status varchar(16) NOT NULL DEFAULT 'todo',
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        first_started_utc datetime DEFAULT NULL,
        timer_started_utc datetime DEFAULT NULL,
        completed_utc datetime DEFAULT NULL,
        tracked_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY employee_status (assigned_to,status,due_day),
        KEY updated_utc (updated_utc)
    ) ENGINE=InnoDB $charset;" );
    dbDelta( "CREATE TABLE {$t['events']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        task_id bigint(20) unsigned NOT NULL,
        actor_id bigint(20) unsigned NOT NULL,
        event_type varchar(20) NOT NULL,
        body text NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY task_history (task_id,id)
    ) ENGINE=InnoDB $charset;" );
    dbDelta( "CREATE TABLE {$t['reports']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        report_day date NOT NULL,
        body text NOT NULL,
        blockers text NOT NULL,
        next_plan text NOT NULL,
        status varchar(12) NOT NULL DEFAULT 'draft',
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        submitted_utc datetime DEFAULT NULL,
        reviewed_utc datetime DEFAULT NULL,
        reviewed_by bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY employee_day (user_id,report_day),
        KEY review_queue (status,report_day)
    ) ENGINE=InnoDB $charset;" );
    dbDelta( "CREATE TABLE {$t['images']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        report_id bigint(20) unsigned NOT NULL,
        mime varchar(32) NOT NULL,
        image_data longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY report_images (report_id)
    ) ENGINE=InnoDB $charset;" );
    dbDelta( "CREATE TABLE {$t['locks']} (
        user_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (user_id)
    ) ENGINE=InnoDB $charset;" );
    foreach ( $t as $table ) {
        $info = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( ! $info ) { return; }
        if ( 'innodb' !== strtolower( $info->Engine ) ) {
            if ( false === $wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ) ) { return; }
        }
    }
    // Verify new columns too: a failed ALTER must never enable timer writes.
    foreach ( array( 'first_started_utc', 'timer_started_utc', 'completed_utc', 'tracked_seconds' ) as $column ) {
        if ( $column !== $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$t['tasks']} LIKE %s", $column ) ) ) { return; }
    }
    update_option( 'crmb_progress_schema_v1', '1', false );
}
add_action( 'init', 'crmb_progress_install', 9 );
add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    $items['reports'] = array( 'label' => 'گزارش روزانه', 'always_visible' => true ); return $items;
} );
function crmb_progress_ready() { return '1' === get_option( 'crmb_progress_schema_v1' ); }
function crmb_progress_task( $id, $lock = false ) {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() || ! $id ) { return null; }
    global $wpdb; $t = crmb_progress_tables();
    $where = current_user_can( 'manage_options' ) ? '' : $wpdb->prepare( ' AND assigned_to = %d', get_current_user_id() );
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['tasks']} WHERE id = %d{$where}" . ( $lock ? ' FOR UPDATE' : '' ), $id ) );
}
function crmb_progress_event( $id, $type, $body = '' ) {
    global $wpdb; $t = crmb_progress_tables();
    return false !== $wpdb->insert( $t['events'], array( 'task_id' => $id, 'actor_id' => get_current_user_id(), 'event_type' => $type, 'body' => $body, 'created_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
}
function crmb_progress_fail( $code = 'error' ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $code; }
function crmb_progress_commit() { global $wpdb; return false === $wpdb->query( 'COMMIT' ) ? crmb_progress_fail() : 'saved'; }
/** Serialize starts per employee without modifying WordPress core tables. */
function crmb_progress_transition( $id, $status ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( ! crmb_progress_ready() ) { return 'error'; }
    if ( ! $id || ! isset( crmb_work_statuses()[ $status ] ) ) { return 'invalid'; }
    global $wpdb; $t = crmb_progress_tables(); $uid = get_current_user_id();
    if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['locks']} (user_id) VALUES (%d)", $uid ) ) ) { return 'error'; }
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$t['locks']} WHERE user_id = %d FOR UPDATE", $uid ) ) ) { return crmb_progress_fail(); }
    $task = crmb_progress_task( $id, true );
    if ( ! $task ) { return crmb_progress_fail( $wpdb->last_error ? 'error' : 'denied' ); }
    $admin = current_user_can( 'manage_options' ); $owner = (int) $task->assigned_to === $uid;
    if ( ! $admin && ( ! $owner || 'cancelled' === $task->status || 'cancelled' === $status ) ) { return crmb_progress_fail( 'denied' ); }
    if ( 'doing' === $status && ! $owner ) { return crmb_progress_fail( 'denied' ); }
    if ( $task->status === $status && ( 'doing' !== $status || $task->timer_started_utc ) ) { return crmb_progress_fail( 'changed' ); }
    $now = gmdate( 'Y-m-d H:i:s' );
    $values = array( 'status' => $status, 'updated_utc' => $now );
    if ( $task->timer_started_utc ) {
        $elapsed = max( 0, time() - strtotime( $task->timer_started_utc . ' UTC' ) );
        $values['tracked_seconds'] = (int) $task->tracked_seconds + $elapsed;
        $values['timer_started_utc'] = null;
    }
    if ( 'doing' === $status ) {
        $other = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['tasks']} WHERE assigned_to = %d AND timer_started_utc IS NOT NULL AND id <> %d LIMIT 1 FOR UPDATE", $uid, $id ) );
        if ( $wpdb->last_error ) { return crmb_progress_fail(); }
        if ( $other ) { return crmb_progress_fail( 'running' ); }
        $values['timer_started_utc'] = $now;
        $values['first_started_utc'] = $task->first_started_utc ?: $now;
        $values['completed_utc'] = null;
    }
    if ( 'done' === $status ) { $values['completed_utc'] = $now; }
    if ( in_array( $status, array( 'todo', 'paused', 'cancelled' ), true ) ) { $values['completed_utc'] = null; }
    if ( false === $wpdb->update( $t['tasks'], $values, array( 'id' => $id ) ) || ! crmb_progress_event( $id, $status ) ) { return crmb_progress_fail(); }
    return crmb_progress_commit();
}
/** Editing is serialized with timer writes; reassigning a running task is refused. */
function crmb_progress_edit_task( $id, $values ) {
    if ( ! current_user_can( 'manage_options' ) || ! crmb_progress_ready() ) { return 'denied'; }
    global $wpdb; $t = crmb_progress_tables();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $task = crmb_progress_task( $id, true );
    if ( ! $task ) { return crmb_progress_fail( 'invalid' ); }
    if ( $task->timer_started_utc && (int) $task->assigned_to !== (int) $values['assigned_to'] ) { return crmb_progress_fail( 'running' ); }
    if ( false === $wpdb->update( $t['tasks'], $values, array( 'id' => $id ) ) || ! crmb_progress_event( $id, 'edited', 'اطلاعات وظیفه به‌روزرسانی شد.' ) ) { return crmb_progress_fail(); }
    return crmb_progress_commit();
}
function crmb_progress_note( $id, $type, $body ) {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() ) { return 'denied'; }
    if ( ! in_array( $type, array( 'note', 'blocker' ), true ) || '' === $body || strlen( $body ) > 10000 ) { return 'invalid'; }
    global $wpdb;
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    if ( ! crmb_progress_task( $id, true ) ) { return crmb_progress_fail( 'denied' ); }
    if ( ! crmb_progress_event( $id, $type, $body ) ) { return crmb_progress_fail(); }
    return crmb_progress_commit();
}
function crmb_progress_seconds( $task ) {
    return (int) $task->tracked_seconds + ( $task->timer_started_utc ? max( 0, time() - strtotime( $task->timer_started_utc . ' UTC' ) ) : 0 );
}
function crmb_progress_duration( $seconds ) { return sprintf( '%02d:%02d:%02d', floor( $seconds / 3600 ), floor( $seconds / 60 ) % 60, $seconds % 60 ); }
function crmb_progress_event_labels() { return array( 'doing' => 'شروع / ادامه کار', 'paused' => 'توقف موقت', 'todo' => 'بازگشت به انجام‌نشده', 'done' => 'پایان کار', 'cancelled' => 'لغو توسط مدیر', 'note' => 'گزارش پیشرفت', 'blocker' => 'مانع / مشکل', 'edited' => 'ویرایش توسط مدیر' ); }
