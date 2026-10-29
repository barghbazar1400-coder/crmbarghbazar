<?php
/**
 * Requests for paid leave and administrator decisions.
 *
 * Employee access: submit and see ONLY own request/decision.
 * Administrator access: review requests and view the full queue.
 *
 * @package CRMBarghBazar
 */
defined( 'ABSPATH' ) || exit;

function crmb_leave_table() {
    global $wpdb;
    return $wpdb->prefix . 'crmb_leave_requests';
}

function crmb_leave_install() {
    if ( '1' === get_option( 'crmb_leave_schema_v1' ) ) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = crmb_leave_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        leave_day date NOT NULL,
        time_from varchar(5) NOT NULL,
        time_to varchar(5) NOT NULL,
        minutes int(10) unsigned NOT NULL DEFAULT 0,
        reason text NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'pending',
        created_utc datetime NOT NULL,
        reviewed_by bigint(20) unsigned DEFAULT NULL,
        reviewed_utc datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY employee_day (user_id,leave_day),
        KEY review_queue (status,leave_day)
    ) $charset;" );
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $table === $exists ) {
        update_option( 'crmb_leave_schema_v1', '1', false );
    }
}
add_action( 'init', 'crmb_leave_install', 6 );

function crmb_leave_status_label( $status ) {
    return array(
        'pending' => 'در حال بررسی',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
    )[ $status ] ?? 'نامشخص';
}

function crmb_leave_redirect( $notice, $section ) {
    wp_safe_redirect(
        add_query_arg(
            array(
                'crm_section' => $section,
                'leave_notice' => $notice,
            ),
            home_url( '/main/' )
        )
    );
    exit;
}

/**
 * Accept one Persian calendar date (YYYY-MM-DD) and time interval.
 * Explicitly check a round trip to reject nonexistent Jalali dates.
 */
function crmb_leave_parse_day( $raw ) {
    if ( ! is_string( $raw ) ) {
        return false;
    }
    $latin = strtr( trim( $raw ), array(
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ) );
    if ( ! preg_match( '/^(1[2345][0-9]{2})-(0[1-9]|1[0-2])-([0-3][0-9])$/', $latin, $m ) ) {
        return false;
    }
    $day = crmb_att_to_gregorian( (int) $m[1], (int) $m[2], (int) $m[3] );
    return $day && crmb_att_jdate( $day ) === $latin ? $day : false;
}

function crmb_leave_submit() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'برای ثبت مرخصی ابتدا وارد شوید.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'crmb_leave_submit', 'crmb_leave_nonce' );
    crmb_leave_install();
    if ( '1' !== get_option( 'crmb_leave_schema_v1' ) ) {
        crmb_leave_redirect( 'error', 'clock' );
    }

    $raw_day = isset( $_POST['leave_day'] ) && is_string( $_POST['leave_day'] )
        ? sanitize_text_field( wp_unslash( $_POST['leave_day'] ) ) : '';
    $day = crmb_leave_parse_day( $raw_day );
    $start = isset( $_POST['time_from'] ) && is_string( $_POST['time_from'] )
        ? sanitize_text_field( wp_unslash( $_POST['time_from'] ) ) : '';
    $end = isset( $_POST['time_to'] ) && is_string( $_POST['time_to'] )
        ? sanitize_text_field( wp_unslash( $_POST['time_to'] ) ) : '';
    $reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] )
        ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';
    $reason = mb_substr( $reason, 0, 1000 );
    $start_minutes = crmb_att_is_time( $start ) ? (int) substr( $start, 0, 2 ) * 60 + (int) substr( $start, 3, 2 ) : -1;
    $end_minutes = crmb_att_is_time( $end ) ? (int) substr( $end, 0, 2 ) * 60 + (int) substr( $end, 3, 2 ) : -1;

    if ( ! $day || $start_minutes < 0 || $end_minutes <= $start_minutes
        || $day < wp_date( 'Y-m-d' )
        || $day > wp_date( 'Y-m-d', strtotime( '+1 year' ) )
        || crmb_att_month_summary( get_current_user_id(), substr( crmb_att_jdate( $day ), 0, 7 ) ) ) {
        crmb_leave_redirect( 'invalid', 'clock' );
    }

    global $wpdb;
    $table = crmb_leave_table();
    $duplicate = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND leave_day = %s
         AND status IN ('pending','approved') AND time_from < %s AND time_to > %s LIMIT 1",
        get_current_user_id(), $day, $end, $start
    ) );
    if ( $duplicate ) {
        crmb_leave_redirect( 'overlap', 'clock' );
    }
    $inserted = $wpdb->insert(
        $table,
        array(
            'user_id' => get_current_user_id(),
            'leave_day' => $day,
            'time_from' => $start,
            'time_to' => $end,
            'minutes' => $end_minutes - $start_minutes,
            'reason' => $reason,
            'status' => 'pending',
            'created_utc' => current_time( 'mysql', true ),
        ),
        array( '%d','%s','%s','%s','%d','%s','%s','%s' )
    );
    crmb_leave_redirect( $inserted ? 'submitted' : 'error', 'clock' );
}
add_action( 'admin_post_crmb_leave_submit', 'crmb_leave_submit' );

/** Admin-only review, with a conditional SQL update to avoid double decisions. */
function crmb_leave_review() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'دسترسی به کارتابل مجاز نیست.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'crmb_leave_review', 'crmb_leave_nonce' );
    crmb_leave_install();
    global $wpdb;
    $table = crmb_leave_table();
    $id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
    $decision = isset( $_POST['decision'] ) && is_string( $_POST['decision'] )
        ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

    if ( ! $id || ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
        crmb_leave_redirect( 'invalid', 'attendance' );
    }
    $request = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d", $id
    ) );
    if ( ! $request || 'pending' !== $request->status
        || crmb_att_month_summary( (int) $request->user_id, substr( crmb_att_jdate( $request->leave_day ), 0, 7 ) ) ) {
        crmb_leave_redirect( 'closed', 'attendance' );
    }

    $written = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = %s, reviewed_by = %d, reviewed_utc = %s
         WHERE id = %d AND status = 'pending'",
        $decision, get_current_user_id(), current_time( 'mysql', true ), $id
    ) );
    crmb_leave_redirect( 1 === $written ? 'reviewed' : 'error', 'attendance' );
}
add_action( 'admin_post_crmb_leave_review', 'crmb_leave_review' );

/** Approved hours affect manager totals; requests are never exposed publicly. */
function crmb_leave_approved_day_map( $user_id, $month ) {
    global $wpdb;
    $bounds = crmb_att_month_bounds( $month );
    if ( ! $bounds || '1' !== get_option( 'crmb_leave_schema_v1' ) ) {
        return array();
    }
    $table = crmb_leave_table();
    $requests = $wpdb->get_results( $wpdb->prepare(
        "SELECT leave_day, minutes FROM {$table}
         WHERE user_id = %d AND status = 'approved' AND leave_day BETWEEN %s AND %s",
        $user_id, $bounds[0], $bounds[1]
    ) );
    $totals = array();
    foreach ( $requests as $request ) {
        $date = $request->leave_day;
        $totals[ $date ] = ( $totals[ $date ] ?? 0 ) + (int) $request->minutes;
    }
    return $totals;
}

/** Prevent archiving a month until its pending applications are resolved. */
function crmb_leave_month_has_pending( $user_id, $bounds ) {
    global $wpdb;
    if ( '1' !== get_option( 'crmb_leave_schema_v1' ) ) {
        return false;
    }
    $table = crmb_leave_table();
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND status = 'pending'
         AND leave_day BETWEEN %s AND %s LIMIT 1",
        $user_id, $bounds[0], $bounds[1]
    ) );
}

/** Only called *inside* an existing archive transaction. */
function crmb_leave_delete_archived_requests( $user_id, $bounds ) {
    global $wpdb;
    if ( '1' !== get_option( 'crmb_leave_schema_v1' ) ) {
        return true;
    }
    $table = crmb_leave_table();
    return false !== $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE user_id = %d AND leave_day BETWEEN %s AND %s
         AND status IN ('approved','rejected')",
        $user_id, $bounds[0], $bounds[1]
    ) );
}
