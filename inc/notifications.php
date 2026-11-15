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
    if ( strlen( $title ) > 160 || strlen( $summary ) > 255 || strlen( $href ) > 255
        || strlen( $source_key ) > 160 || strlen( $detail ) > 2000 || ! wp_validate_redirect( $href, false ) ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $now = gmdate( 'Y-m-d H:i:s' );
    $inserted = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$t['notices']} (user_id,actor_id,kind,title,summary,detail,href,source_key,created_utc,next_try_utc) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s)",
        (int) $user_id, (int) $actor_id, $kind, $title, $summary, $detail, $href, $source_key, $now, $now
    ) );
    if ( 1 === $inserted ) {
        if ( ! wp_next_scheduled( 'crmb_notice_push_immediate' ) ) { wp_schedule_single_event( time() + 10, 'crmb_notice_push_immediate' ); }
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
add_action( 'crmb_task_status_updated', function ( $task_id, $owner, $actor, $event_id ) {
    if ( (int) $owner === (int) $actor ) { return; }
    crmb_notice_add( $owner, 'task', 'وضعیت وظیفه تغییر کرد', 'مدیر وضعیت یکی از کارهای شما را تغییر داده است.',
        crmb_notice_url( 'tasks', array( 'task_id' => $task_id ) ), 'task_status:' . $event_id, $actor );
}, 10, 4 );

function crmb_notice_count() {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() ) { return 0; }
    global $wpdb;
    $t = crmb_notice_tables();
    $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['notices']} WHERE user_id=%d AND read_utc IS NULL", get_current_user_id() ) );
    return $wpdb->last_error ? 0 : (int) $count;
}
function crmb_notice_list( $limit = 30 ) {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() ) { return array(); }
    global $wpdb;
    $t = crmb_notice_tables();
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id,kind,title,summary,detail,href,created_utc,read_utc FROM {$t['notices']} WHERE user_id=%d ORDER BY id DESC LIMIT %d",
        get_current_user_id(), max( 1, min( 50, (int) $limit ) )
    ) ) ?: array();
}
function crmb_notice_read( $id = 0, $all = false ) {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() || ( ! $all && ! $id ) ) { return false; }
    global $wpdb;
    $t = crmb_notice_tables();
    $query = $all
        ? $wpdb->prepare( "UPDATE {$t['notices']} SET read_utc=%s WHERE user_id=%d AND read_utc IS NULL", gmdate( 'Y-m-d H:i:s' ), get_current_user_id() )
        : $wpdb->prepare( "UPDATE {$t['notices']} SET read_utc=%s WHERE id=%d AND user_id=%d AND read_utc IS NULL",
            gmdate( 'Y-m-d H:i:s' ), $id, get_current_user_id() );
    return false !== $wpdb->query( $query );
}
function crmb_notice_announce( $title, $body, $scope, $target ) {
    if ( ! current_user_can( 'manage_options' ) || ! crmb_notice_ready() ) { return 'denied'; }
    $title = trim( sanitize_text_field( $title ) );
    $body = trim( sanitize_textarea_field( $body ) );
    if ( '' === $title || '' === $body || strlen( $title ) > 160 || strlen( $body ) > 2000 ) { return 'invalid'; }
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
        foreach ( crmb_notice_list( 8 ) as $item ) {
            $items[] = array( 'id' => (int) $item->id, 'kind' => $item->kind,
                'title' => $item->title, 'href' => $item->href, 'read' => (bool) $item->read_utc );
        }
        wp_send_json_success( array( 'count' => crmb_notice_count(), 'items' => $items ) );
    }
    if ( 'read' === $mode ) {
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! crmb_notice_read( $id ) ) { wp_send_json_error( array( 'message' => 'invalid' ), 400 ); }
        wp_send_json_success( array( 'count' => crmb_notice_count() ) );
    }
    wp_send_json_error( array( 'message' => 'invalid' ), 400 );
}
add_action( 'wp_ajax_crmb_notice', 'crmb_notice_ajax' );
