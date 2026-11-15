<?php
/**
 * Private, cursor-paginated one-to-one messaging for CRM.
 *
 * This uses the existing crmb_messages records, keeping historical subjects
 * and preserving old links from the notification center.
 */
defined( 'ABSPATH' ) || exit;

function crmb_chat_ready() {
    return is_user_logged_in() && '1' === get_option( 'crmb_work_schema_v1' );
}

function crmb_chat_peer( $peer_id ) {
    $peer_id = absint( $peer_id );
    if ( ! crmb_chat_ready() || ! $peer_id || $peer_id === get_current_user_id() ) {
        return false;
    }
    return get_userdata( $peer_id );
}

/** Resolve old /main/?crm_section=messages&message=ID links without exposing another user's thread. */
function crmb_chat_peer_from_message( $message_id ) {
    if ( ! crmb_chat_ready() || ! $message_id ) { return 0; }
    global $wpdb;
    $table = crmb_work_tables()['messages'];
    $uid = get_current_user_id();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT sender_id,recipient_id FROM {$table} WHERE id=%d AND (sender_id=%d OR recipient_id=%d)",
        absint( $message_id ), $uid, $uid
    ) );
    if ( ! $row ) { return 0; }
    return (int) $row->sender_id === $uid ? (int) $row->recipient_id : (int) $row->sender_id;
}

/**
 * Return one independent conversation, at most 30 messages.
 * Older messages use id < before; newer messages use id > after.
 * This avoids increasingly expensive OFFSET on long histories.
 */
function crmb_chat_page( $peer_id, $before = 0, $after = 0, $limit = 30 ) {
    $empty = array( 'rows' => array(), 'has_more' => false, 'ready' => false );
    if ( ! crmb_chat_peer( $peer_id ) ) { return $empty; }
    global $wpdb;
    $table = crmb_work_tables()['messages'];
    $uid = get_current_user_id();
    $limit = max( 1, min( 30, (int) $limit ) );
    $before = absint( $before );
    $after = absint( $after );
    // The caller may use one cursor type per request, never both.
    if ( $before && $after ) { return $empty; }
    $where = $wpdb->prepare(
        '((sender_id=%d AND recipient_id=%d) OR (sender_id=%d AND recipient_id=%d))',
        $uid, $peer_id, $peer_id, $uid
    );
    if ( $before ) { $where .= $wpdb->prepare( ' AND id < %d', $before ); }
    if ( $after ) { $where .= $wpdb->prepare( ' AND id > %d', $after ); }
    $order = $after ? 'ASC' : 'DESC';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,sender_id,recipient_id,subject,body,created_utc,read_utc
         FROM {$table} WHERE {$where} ORDER BY id {$order} LIMIT %d",
        $limit + 1
    ) );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { return $empty; }
    $more = count( $rows ) > $limit;
    if ( $more ) { $rows = array_slice( $rows, 0, $limit ); }
    // Return chronological order for display. 'more' means older or newer
    // items remain on the server, depending on the cursor direction.
    if ( ! $after ) { $rows = array_reverse( $rows ); }
    return array( 'rows' => $rows, 'has_more' => $more, 'ready' => true );
}

/** Mark only the current recipient's messages from the selected peer as read. */
function crmb_chat_mark_read( $peer_id ) {
    if ( ! crmb_chat_peer( $peer_id ) ) { return false; }
    global $wpdb;
    $uid = get_current_user_id();
    $table = crmb_work_tables()['messages'];
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET read_utc=%s WHERE recipient_id=%d AND sender_id=%d AND read_utc IS NULL",
        gmdate( 'Y-m-d H:i:s' ), $uid, $peer_id
    ) );
    if ( false === $updated ) { return false; }
    if ( function_exists( 'crmb_notice_ready' ) && crmb_notice_ready() ) {
        $notice_table = crmb_notice_tables()['notices'];
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$notice_table} SET read_utc=%s WHERE user_id=%d AND actor_id=%d AND kind=%s AND read_utc IS NULL",
            gmdate( 'Y-m-d H:i:s' ), $uid, $peer_id, 'message'
        ) );
    }
    return true;
}

/**
 * Single aggregate for existing conversation summaries; no per-message query.
 * User roster is bounded and no other employee's private message is returned.
 */
function crmb_chat_contacts( $limit = 120 ) {
    if ( ! crmb_chat_ready() ) { return array(); }
    global $wpdb;
    $table = crmb_work_tables()['messages'];
    $uid = get_current_user_id();
    $limit = max( 1, min( 150, (int) $limit ) );
    $summary = $wpdb->get_results( $wpdb->prepare(
        "SELECT CASE WHEN sender_id=%d THEN recipient_id ELSE sender_id END AS peer_id,
                MAX(id) AS last_id,
                SUM(CASE WHEN recipient_id=%d AND read_utc IS NULL THEN 1 ELSE 0 END) AS unread
         FROM {$table}
         WHERE sender_id=%d OR recipient_id=%d
         GROUP BY peer_id ORDER BY last_id DESC LIMIT %d",
        $uid, $uid, $uid, $uid, $limit
    ) );
    if ( $wpdb->last_error ) { return array(); }

    $records = array();
    foreach ( $summary ?: array() as $item ) {
        $id = (int) $item->peer_id;
        if ( $id <= 0 || $id === $uid ) { continue; }
        $user = get_userdata( $id );
        if ( ! $user ) { continue; }
        $records[ $id ] = array(
            'id' => $id,
            'name' => $user->display_name,
            'last_id' => (int) $item->last_id,
            'unread' => (int) $item->unread,
        );
    }

    // Include coworkers with no prior messages to allow starting conversations.
    $members = get_users( array( 'orderby' => 'display_name', 'number' => $limit, 'fields' => array( 'ID', 'display_name' ) ) );
    foreach ( $members as $user ) {
        $id = (int) $user->ID;
        if ( $id === $uid || isset( $records[ $id ] ) ) { continue; }
        $records[ $id ] = array(
            'id' => $id, 'name' => $user->display_name, 'last_id' => 0, 'unread' => 0,
        );
        if ( count( $records ) >= $limit ) { break; }
    }
    return array_values( $records );
}

function crmb_chat_public_message( $message ) {
    return array(
        'id' => (int) $message->id,
        'mine' => (int) $message->sender_id === get_current_user_id(),
        'subject' => (string) $message->subject,
        'text' => (string) $message->body,
        'time' => crmb_work_date( $message->created_utc ),
        'read' => ! empty( $message->read_utc ),
    );
}

/** All operations are login- and nonce-protected, scoped to the current user's messages. */
function crmb_chat_ajax() {
    if ( ! crmb_chat_ready() ) {
        wp_send_json_error( array( 'message' => 'برای استفاده از پیام‌ها وارد حساب شوید.' ), 403 );
    }
    check_ajax_referer( 'crmb_chat', 'nonce' );
    nocache_headers();
    $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] )
        ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
    if ( 'contacts' === $mode ) {
        wp_send_json_success( array( 'contacts' => crmb_chat_contacts() ) );
    }
    $peer = isset( $_POST['peer'] ) ? absint( $_POST['peer'] ) : 0;
    if ( ! crmb_chat_peer( $peer ) ) {
        wp_send_json_error( array( 'message' => 'همکار انتخاب‌شده معتبر نیست.' ), 400 );
    }
    if ( 'send' === $mode ) {
        $body = isset( $_POST['body'] ) && is_string( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '';
        // Reuse the existing validated message creation and notification hook.
        // Never take sender/subject from user-supplied data.
        $result = crmb_work_mutate( array(
            'task' => 'send_message',
            'recipient_id' => (string) $peer,
            'subject' => 'پیام گفتگو',
            'body' => $body,
        ) );
        if ( 'sent' !== $result ) {
            wp_send_json_error( array( 'message' => 'invalid' === $result ? 'پیام خالی یا بیش از حد طولانی است.' : 'ارسال پیام انجام نشد.' ), 400 );
        }
        wp_send_json_success( array( 'status' => 'sent' ) );
    }
    if ( 'read' === $mode ) {
        if ( ! crmb_chat_mark_read( $peer ) ) {
            wp_send_json_error( array( 'message' => 'ثبت خوانده‌شدن پیام‌ها انجام نشد.' ), 500 );
        }
        wp_send_json_success( array( 'status' => 'read' ) );
    }
    if ( 'thread' === $mode ) {
        $before = isset( $_POST['before'] ) ? absint( $_POST['before'] ) : 0;
        $after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
        $page = crmb_chat_page( $peer, $before, $after, 30 );
        if ( ! $page['ready'] ) {
            wp_send_json_error( array( 'message' => 'دریافت گفتگو انجام نشد.' ), 500 );
        }
        if ( ! $before && ! $after ) { crmb_chat_mark_read( $peer ); }
        if ( $after && ! empty( $page['rows'] ) ) {
            // Only a visible thread should invoke read; the client does not
            // poll thread messages while the document is hidden.
            crmb_chat_mark_read( $peer );
        }
        wp_send_json_success( array(
            'messages' => array_map( 'crmb_chat_public_message', $page['rows'] ),
            'has_more' => $page['has_more'],
            'direction' => $after ? 'newer' : 'older',
        ) );
    }
    wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 );
}
add_action( 'wp_ajax_crmb_chat', 'crmb_chat_ajax' );
