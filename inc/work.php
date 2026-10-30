<?php
/** Private tasks and internal messages. All mutations use authenticated POSTs. */
defined( 'ABSPATH' ) || exit;

function crmb_work_tables() {
    global $wpdb;
    return array( 'tasks' => $wpdb->prefix . 'crmb_tasks', 'messages' => $wpdb->prefix . 'crmb_messages' );
}
function crmb_work_install() {
    if ( '1' === get_option( 'crmb_work_schema_v1' ) ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = crmb_work_tables(); $charset = $wpdb->get_charset_collate();
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
        PRIMARY KEY  (id),
        KEY employee_status (assigned_to,status,due_day),
        KEY updated_utc (updated_utc)
    ) $charset;" );
    dbDelta( "CREATE TABLE {$t['messages']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        sender_id bigint(20) unsigned NOT NULL,
        recipient_id bigint(20) unsigned NOT NULL,
        subject varchar(180) NOT NULL,
        body text NOT NULL,
        created_utc datetime NOT NULL,
        read_utc datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY inbox (recipient_id,read_utc,id),
        KEY sent (sender_id,id)
    ) $charset;" );
    foreach ( $t as $table ) {
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { return; }
    }
    update_option( 'crmb_work_schema_v1', '1', false );
}
add_action( 'init', 'crmb_work_install', 8 );
add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    $items['tasks'] = array( 'label' => 'وظایف', 'always_visible' => true );
    $items['messages'] = array( 'label' => 'پیام‌ها', 'always_visible' => true );
    return $items;
} );
function crmb_work_text( $data, $key, $multiline = false ) {
    if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) { return ''; }
    return trim( $multiline ? sanitize_textarea_field( $data[ $key ] ) : sanitize_text_field( $data[ $key ] ) );
}
function crmb_work_id( $data, $key ) {
    $value = $data[ $key ] ?? '';
    return is_scalar( $value ) && preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ? absint( $value ) : 0;
}
function crmb_work_statuses() { return array( 'todo' => 'انجام‌نشده', 'doing' => 'در حال انجام', 'paused' => 'توقف موقت', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده' ); }
function crmb_work_priorities() { return array( 'normal' => 'عادی', 'high' => 'مهم', 'urgent' => 'فوری' ); }
function crmb_work_date( $utc ) {
    if ( ! $utc ) { return '—'; }
    $date = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( crmb_att_timezone() );
    return crmb_att_jdate( $date->format( 'Y-m-d' ) ) . '، ' . $date->format( 'H:i' );
}
function crmb_work_user_name( $id ) {
    $user = get_userdata( $id );
    return $user ? $user->display_name : 'کاربر حذف‌شده';
}
/** Enforce ownership here as well as in the UI. Never trust a submitted user ID. */
function crmb_work_mutate( $data ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( '1' !== get_option( 'crmb_work_schema_v1' ) ) { return 'error'; }
    global $wpdb;
    $t = crmb_work_tables(); $uid = get_current_user_id();
    $task = crmb_work_text( $data, 'task' ); $id = crmb_work_id( $data, 'id' );
    $now = gmdate( 'Y-m-d H:i:s' );
    if ( 'save_task' === $task ) {
        if ( ! current_user_can( 'manage_options' ) ) { return 'denied'; }
        $title = crmb_work_text( $data, 'title' ); $body = crmb_work_text( $data, 'description', true );
        $assigned = crmb_work_id( $data, 'assigned_to' ); $priority = crmb_work_text( $data, 'priority' );
        $raw_due = crmb_work_text( $data, 'due_day' );
        $due = '' === $raw_due ? null : crmb_leave_parse_day( $raw_due );
        if ( '' === $title || strlen( $title ) > 180 || strlen( $body ) > 10000 || ! get_userdata( $assigned ) || ! isset( crmb_work_priorities()[ $priority ] ) || false === $due ) { return 'invalid'; }
        $row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$t['tasks']} WHERE id = %d", $id ) ) : null;
        if ( isset( $data['id'] ) && ( ! $id || ! $row ) ) { return 'invalid'; }
        $values = array( 'title' => $title, 'description' => $body, 'assigned_to' => $assigned, 'priority' => $priority, 'due_day' => $due, 'updated_utc' => $now );
        if ( $id && function_exists( 'crmb_progress_edit_task' ) ) { return crmb_progress_edit_task( $id, $values ); }
        if ( $id ) { $result = $wpdb->update( $t['tasks'], $values, array( 'id' => $id ) ); }
        else {
            $values += array( 'created_by' => $uid, 'status' => 'todo', 'created_utc' => $now );
            $result = $wpdb->insert( $t['tasks'], $values );
        }
        return false === $result ? 'error' : 'saved';
    }
    if ( 'task_status' === $task ) {
        $status = crmb_work_text( $data, 'status' );
        if ( function_exists( 'crmb_progress_transition' ) ) { return crmb_progress_transition( $id, $status ); }
        if ( ! $id || ! isset( crmb_work_statuses()[ $status ] ) ) { return 'invalid'; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT assigned_to,status FROM {$t['tasks']} WHERE id = %d", $id ) );
        if ( ! $row ) { return $wpdb->last_error ? 'error' : 'invalid'; }
        $admin = current_user_can( 'manage_options' );
        if ( ! $admin && ( (int) $row->assigned_to !== $uid || 'cancelled' === $status || 'cancelled' === $row->status ) ) { return 'denied'; }
        // Include ownership and original status in the write to resist concurrent reassignment.
        $where = array( 'id' => $id, 'assigned_to' => (int) $row->assigned_to, 'status' => $row->status );
        $result = $wpdb->update( $t['tasks'], array( 'status' => $status, 'updated_utc' => $now ), $where );
        return false === $result ? 'error' : ( 0 === $result && $status !== $row->status ? 'changed' : 'saved' );
    }
    if ( 'send_message' === $task ) {
        $recipient = crmb_work_id( $data, 'recipient_id' );
        $subject = crmb_work_text( $data, 'subject' ); $body = crmb_work_text( $data, 'body', true );
        if ( ! get_userdata( $recipient ) || $recipient === $uid || '' === $subject || strlen( $subject ) > 180 || '' === $body || strlen( $body ) > 10000 ) { return 'invalid'; }
        $result = $wpdb->insert( $t['messages'], array( 'sender_id' => $uid, 'recipient_id' => $recipient, 'subject' => $subject, 'body' => $body, 'created_utc' => $now ) );
        return false === $result ? 'error' : 'sent';
    }
    if ( 'read_message' === $task && $id ) {
        // A manager cannot mark another employee's messages as read.
        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$t['messages']} SET read_utc = %s WHERE id = %d AND recipient_id = %d AND read_utc IS NULL", $now, $id, $uid ) );
        return false === $result ? 'error' : ( 1 === $result ? 'read' : 'changed' );
    }
    return 'invalid';
}
function crmb_work_handle_post() {
    if ( ! is_user_logged_in() ) { wp_die( 'ابتدا وارد شوید.', '', array( 'response' => 403 ) ); }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'روش درخواست مجاز نیست.', '', array( 'response' => 405 ) ); }
    check_admin_referer( 'crmb_work', 'crmb_work_nonce' );
    $data = wp_unslash( $_POST );
    $notice = crmb_work_mutate( $data );
    $task = crmb_work_text( $data, 'task' );
    $section = in_array( $task, array( 'send_message', 'read_message' ), true ) ? 'messages' : 'tasks';
    $args = array( 'crm_section' => $section, 'work_notice' => $notice );
    if ( 'task_status' === $task ) { $args['task_id'] = crmb_work_id( $data, 'id' ); }
    wp_safe_redirect( add_query_arg( $args, home_url( '/main/' ) ) ); exit;
}
add_action( 'admin_post_crmb_work', 'crmb_work_handle_post' );

/** Fixed-scope lists; team tasks are explicitly administrator-only. */
function crmb_work_list( $kind, $page = 1, $limit = 20, $team = false, $sent = false, $active = false, $focus = 0 ) {
    $empty = array( 'rows' => array(), 'total' => 0, 'ready' => false );
    if ( ! is_user_logged_in() || '1' !== get_option( 'crmb_work_schema_v1' ) || ! in_array( $kind, array( 'tasks', 'messages' ), true ) ) { return $empty; }
    global $wpdb;
    $t = crmb_work_tables(); $uid = get_current_user_id();
    $limit = max( 1, min( 20, (int) $limit ) ); $page = max( 1, min( 100000, (int) $page ) );
    if ( 'tasks' === $kind ) {
        $where = $team && current_user_can( 'manage_options' ) ? '1=1' : $wpdb->prepare( 'assigned_to = %d', $uid );
        if ( $active ) { $where .= " AND status IN ('todo','doing','paused')"; }
        $order = "CASE WHEN status IN ('todo','doing','paused') THEN 0 ELSE 1 END, due_day IS NULL, due_day ASC, id DESC";
    } else {
        $where = $wpdb->prepare( $sent ? 'sender_id = %d' : 'recipient_id = %d', $uid );
        if ( $active ) { $where .= ' AND read_utc IS NULL'; }
        $order = 'id DESC';
    }
    if ( $focus ) { $where .= $wpdb->prepare( ' AND id = %d', absint( $focus ) ); }
    $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$t[$kind]} WHERE {$where}" );
    $count_ok = ! $wpdb->last_error;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t[$kind]} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d", $limit, ( $page - 1 ) * $limit ) );
    if ( ! $count_ok || $wpdb->last_error ) { return $empty; }
    return array( 'rows' => $rows ?: array(), 'total' => (int) $total, 'ready' => true );
}
function crmb_work_notice() {
    $code = isset( $_GET['work_notice'] ) && is_string( $_GET['work_notice'] ) ? sanitize_key( $_GET['work_notice'] ) : '';
    $notices = array( 'saved' => 'تغییرات ذخیره شد.', 'running' => 'ابتدا زمان‌سنج وظیفه در حال اجرا را متوقف کنید؛ برای انتقال مسئول هم زمان‌سنج باید متوقف باشد.', 'sent' => 'پیام ارسال شد.', 'read' => 'پیام خوانده‌شده ثبت شد.', 'invalid' => 'اطلاعات معتبر نیست؛ فیلدها را بررسی کنید.', 'denied' => 'اجازه این عملیات را ندارید.', 'error' => 'ذخیره انجام نشد؛ دوباره تلاش کنید.', 'changed' => 'وضعیت قبلاً تغییر کرده است؛ صفحه را تازه کنید.' );
    if ( isset( $notices[ $code ] ) ) { echo '<p role="status" class="crm-access-notice">' . esc_html( $notices[ $code ] ) . '</p>'; }
}
function crmb_work_unread_count() {
    if ( ! is_user_logged_in() || '1' !== get_option( 'crmb_work_schema_v1' ) ) { return null; }
    global $wpdb;
    $t = crmb_work_tables();
    $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['messages']} WHERE recipient_id = %d AND read_utc IS NULL", get_current_user_id() ) );
    return $wpdb->last_error ? null : (int) $count;
}
function crmb_work_form_fields( $task, $id = 0 ) {
    echo '<input type="hidden" name="action" value="crmb_work"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
    if ( $id ) { echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">'; }
    wp_nonce_field( 'crmb_work', 'crmb_work_nonce' );
}
