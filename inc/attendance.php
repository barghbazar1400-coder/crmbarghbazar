<?php
/**
 * Attendance records and month-end payroll summaries for CRM BarghBazar.
 * Raw daily rows remain private to administrators and expire after safe rollup.
 */
defined( 'ABSPATH' ) || exit;

function crmb_att_tables() {
    global $wpdb;
    return array(
        'days'    => $wpdb->prefix . 'crmb_attendance_days',
        'months'  => $wpdb->prefix . 'crmb_attendance_months',
    );
}

/** Create tables once, and retry only when installation hasn't succeeded. */
function crmb_att_install() {
    if ( '1' === get_option( 'crmb_att_schema_v1' ) ) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $tables  = crmb_att_tables();
    $charset = $wpdb->get_charset_collate();

    dbDelta( "CREATE TABLE {$tables['days']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        work_day date NOT NULL,
        checkin_utc datetime DEFAULT NULL,
        checkout_utc datetime DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'present',
        leave_minutes int(10) unsigned NOT NULL DEFAULT 0,
        expected_minutes int(10) unsigned NOT NULL DEFAULT 0,
        start_local varchar(5) NOT NULL DEFAULT '09:00',
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY employee_day (user_id,work_day),
        KEY work_day (work_day)
    ) $charset;" );

    dbDelta( "CREATE TABLE {$tables['months']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        employee_name varchar(255) NOT NULL,
        jalali_month char(7) NOT NULL,
        scheduled_minutes int(10) unsigned NOT NULL DEFAULT 0,
        worked_minutes int(10) unsigned NOT NULL DEFAULT 0,
        regular_minutes int(10) unsigned NOT NULL DEFAULT 0,
        overtime_minutes int(10) unsigned NOT NULL DEFAULT 0,
        delay_minutes int(10) unsigned NOT NULL DEFAULT 0,
        leave_days int(10) unsigned NOT NULL DEFAULT 0,
        leave_minutes int(10) unsigned NOT NULL DEFAULT 0,
        absence_days int(10) unsigned NOT NULL DEFAULT 0,
        missing_days int(10) unsigned NOT NULL DEFAULT 0,
        days_with_records int(10) unsigned NOT NULL DEFAULT 0,
        archived_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY employee_month (user_id,jalali_month),
        KEY jalali_month (jalali_month)
    ) $charset;" );

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables['days'] ) ) === $tables['days']
        && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables['months'] ) ) === $tables['months'] ) {
        update_option( 'crmb_att_schema_v1', '1', false );
    }
}
add_action( 'init', 'crmb_att_install', 4 );

/**
 * Persian calendar conversion using day-count arithmetic.
 * Integers are used throughout to avoid relying on optional intl modules.
 */
function crmb_att_to_jalali( $gy, $gm, $gd ) {
    $gdays = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
    $gy -= 1600; $gm -= 1; $gd -= 1;
    $gno = 365 * $gy + intdiv( $gy + 3, 4 ) - intdiv( $gy + 99, 100 ) + intdiv( $gy + 399, 400 );
    for ( $i = 0; $i < $gm; $i++ ) { $gno += $gdays[ $i ]; }
    if ( $gm > 1 && ( 0 === $gy % 400 || ( 0 === $gy % 4 && 0 !== $gy % 100 ) ) ) { $gno++; }
    $jno = $gno + $gd - 79;
    $cycles = intdiv( $jno, 12053 );
    $jno %= 12053;
    $jy = 979 + 33 * $cycles + 4 * intdiv( $jno, 1461 );
    $jno %= 1461;
    if ( $jno >= 366 ) {
        $jy += intdiv( $jno - 1, 365 );
        $jno = ( $jno - 366 ) % 365;
    }
    if ( $jno < 186 ) { $jm = 1 + intdiv( $jno, 31 ); $jd = 1 + $jno % 31; }
    else { $jm = 7 + intdiv( $jno - 186, 30 ); $jd = 1 + ( $jno - 186 ) % 30; }
    return array( $jy, $jm, $jd );
}
function crmb_att_to_gregorian( $jy, $jm, $jd ) {
    if ( $jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) { return false; }
    $jy -= 979;
    $jno = 365 * $jy + 8 * intdiv( $jy, 33 ) + intdiv( ( $jy % 33 ) + 3, 4 );
    $jno += $jm <= 6 ? ( $jm - 1 ) * 31 : 186 + ( $jm - 7 ) * 30;
    $jno += $jd - 1;
    $gno = $jno + 79;
    $gy = 1600 + 400 * intdiv( $gno, 146097 );
    $gno %= 146097;
    $leap = true;
    if ( $gno >= 36525 ) {
        $gno--;
        $gy += 100 * intdiv( $gno, 36524 );
        $gno %= 36524;
        if ( $gno >= 365 ) { $gno++; } else { $leap = false; }
    }
    $gy += 4 * intdiv( $gno, 1461 );
    $gno %= 1461;
    if ( $gno >= 366 ) {
        $leap = false;
        $gno--;
        $gy += intdiv( $gno, 365 );
        $gno %= 365;
    }
    $days = array( 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
    $gm = 1;
    while ( $gm <= 12 && $gno >= $days[ $gm - 1 ] ) { $gno -= $days[ $gm - 1 ]; $gm++; }
    $gd = $gno + 1;
    return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
}
function crmb_att_jdate( $gregorian ) {
    $parts = explode( '-', substr( $gregorian, 0, 10 ) );
    if ( 3 !== count( $parts ) ) { return ''; }
    $j = crmb_att_to_jalali( (int) $parts[0], (int) $parts[1], (int) $parts[2] );
    return sprintf( '%04d-%02d-%02d', $j[0], $j[1], $j[2] );
}
function crmb_att_month_bounds( $month ) {
    if ( ! preg_match( '/^(1[2345][0-9]{2})-(0[1-9]|1[0-2])$/', $month ) ) { return false; }
    list( $y, $m ) = array_map( 'intval', explode( '-', $month ) );
    $first = crmb_att_to_gregorian( $y, $m, 1 );
    $next  = 12 === $m ? crmb_att_to_gregorian( $y + 1, 1, 1 ) : crmb_att_to_gregorian( $y, $m + 1, 1 );
    if ( ! $first || ! $next ) { return false; }
    return array( $first, gmdate( 'Y-m-d', strtotime( $next . ' -1 day' ) ), $next );
}
function crmb_att_current_month() {
    return substr( crmb_att_jdate( wp_date( 'Y-m-d' ) ), 0, 7 );
}
function crmb_att_month_name( $month ) {
    $names = array( '', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
    $n = explode( '-', $month );
    return isset( $n[1] ) ? ( $names[ (int) $n[1] ] ?? '' ) . ' ' . $n[0] : '';
}
function crmb_att_is_time( $value ) {
    return is_string( $value ) && (bool) preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value );
}
function crmb_att_enroll( $user_id, $date ) {
    $old = get_user_meta( $user_id, 'crmb_att_started_on', true );
    if ( ! is_string( $old ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $old ) || $date < $old ) {
        update_user_meta( $user_id, 'crmb_att_started_on', $date );
    }
}
function crmb_att_settings( $user_id ) {
    $saved = get_user_meta( $user_id, 'crmb_att_schedule', true );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    return array(
        'start'   => isset( $saved['start'] ) && crmb_att_is_time( $saved['start'] ) ? $saved['start'] : '09:00',
        'minutes' => isset( $saved['minutes'] ) ? min( 1440, max( 0, (int) $saved['minutes'] ) ) : 480,
        // Saturday through Thursday; Friday off by default.
        'weekdays'=> isset( $saved['weekdays'] ) && is_array( $saved['weekdays'] )
            ? array_values( array_intersect( range( 0, 6 ), array_map( 'intval', $saved['weekdays'] ) ) )
            : array( 0, 1, 2, 3, 4, 6 ),
    );
}
function crmb_att_expected( $user_id, $date ) {
    $s = crmb_att_settings( $user_id );
    $weekday = (int) ( new DateTimeImmutable( $date, wp_timezone() ) )->format( 'w' );
    return in_array( $weekday, $s['weekdays'], true ) ? $s['minutes'] : 0;
}
function crmb_att_local_to_utc( $date, $time ) {
    if ( ! crmb_att_is_time( $time ) ) { return false; }
    $dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
    return $dt ? $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : false;
}
function crmb_att_utc_to_local( $time ) {
    if ( ! $time ) { return ''; }
    return ( new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'H:i' );
}
function crmb_att_duration( $row ) {
    if ( empty( $row->checkin_utc ) || empty( $row->checkout_utc ) ) { return 0; }
    return max( 0, (int) floor( ( strtotime( $row->checkout_utc . ' UTC' ) - strtotime( $row->checkin_utc . ' UTC' ) ) / 60 ) );
}
function crmb_att_day_metrics( $row ) {
    $result = array( 'worked' => 0, 'regular' => 0, 'overtime' => 0, 'delay' => 0, 'leave' => 0, 'leave_days' => 0, 'absence' => 0 );
    if ( ! $row ) { return $result; }
    if ( 'absence' === $row->status ) { $result['absence'] = 1; return $result; }
    if ( 'paid_leave' === $row->status || 'unpaid_leave' === $row->status ) {
        $result['leave'] = $row->leave_minutes ?: $row->expected_minutes;
        $result['leave_days'] = 1;
        return $result;
    }
    if ( 'present' !== $row->status ) { return $result; }
    // Partial-day leave may coexist with clocked work; it is not counted as work.
    $result['leave'] = (int) $row->leave_minutes;
    $result['worked'] = crmb_att_duration( $row );
    if ( ! $row->checkout_utc ) { return $result; }
    $expected = (int) $row->expected_minutes;
    $result['regular'] = min( $expected, $result['worked'] );
    $result['overtime'] = max( 0, $result['worked'] - $expected );
    if ( $expected > 0 && $row->checkin_utc && crmb_att_is_time( $row->start_local ) ) {
        $scheduled = crmb_att_local_to_utc( $row->work_day, $row->start_local );
        if ( $scheduled ) {
            $result['delay'] = max( 0, (int) floor( ( strtotime( $row->checkin_utc . ' UTC' ) - strtotime( $scheduled . ' UTC' ) ) / 60 ) );
        }
    }
    return $result;
}
function crmb_att_format_minutes( $minutes ) {
    return sprintf( '%d:%02d', intdiv( max( 0, (int) $minutes ), 60 ), max( 0, (int) $minutes ) % 60 );
}
function crmb_att_rows( $user_id, $month ) {
    global $wpdb;
    $range = crmb_att_month_bounds( $month );
    if ( ! $range ) { return array(); }
    $t = crmb_att_tables();
    $found = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['days']} WHERE user_id = %d AND work_day BETWEEN %s AND %s ORDER BY work_day",
        $user_id, $range[0], $range[1]
    ) );
    $rows = array();
    foreach ( $found as $row ) { $rows[ $row->work_day ] = $row; }
    return $rows;
}
function crmb_att_month_metrics( $user_id, $month, $rows ) {
    $bounds = crmb_att_month_bounds( $month );
    $totals = array_fill_keys( array( 'scheduled_minutes', 'worked_minutes', 'regular_minutes', 'overtime_minutes', 'delay_minutes', 'leave_days', 'leave_minutes', 'absence_days', 'missing_days', 'days_with_records' ), 0 );
    if ( ! $bounds ) { return $totals; }
    $user = get_userdata( $user_id );
    $created = $user ? substr( (string) $user->user_registered, 0, 10 ) : $bounds[0];
    // No assumed unpaid shifts before this employee was first enrolled in CRM attendance.
    $enrolled = get_user_meta( $user_id, 'crmb_att_started_on', true );
    $created = is_string( $enrolled ) && preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $enrolled )
        ? max( $created, $enrolled ) : $created;
    $today = wp_date( 'Y-m-d' );
    foreach ( new DatePeriod( new DateTimeImmutable( $bounds[0] ), new DateInterval( 'P1D' ), new DateTimeImmutable( $bounds[2] ) ) as $dateobj ) {
        $date = $dateobj->format( 'Y-m-d' );
        if ( $date < $created ) { continue; }
        $row = $rows[ $date ] ?? null;
        $planned = $row ? (int) $row->expected_minutes : crmb_att_expected( $user_id, $date );
        if ( $row && 'holiday' === $row->status ) { $planned = 0; }
        $totals['scheduled_minutes'] += $planned;
        if ( $row ) {
            $totals['days_with_records']++;
            $m = crmb_att_day_metrics( $row );
            foreach ( array( 'worked' => 'worked_minutes', 'regular' => 'regular_minutes', 'overtime' => 'overtime_minutes', 'delay' => 'delay_minutes', 'leave' => 'leave_minutes', 'leave_days' => 'leave_days', 'absence' => 'absence_days' ) as $k => $v ) {
                $totals[ $v ] += $m[ $k ];
            }
        } elseif ( $planned > 0 && $date < $today ) {
            $totals['missing_days']++;
        }
    }
    return $totals;
}
function crmb_att_month_summary( $user_id, $month ) {
    global $wpdb;
    $t = crmb_att_tables();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['months']} WHERE user_id = %d AND jalali_month = %s", $user_id, $month
    ) );
}
function crmb_att_admin_only() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) );
    }
}
function crmb_att_return( $code, $employee = 0, $month = '' ) {
    $args = array( 'crm_section' => 'attendance', 'attendance_notice' => $code );
    if ( $employee ) { $args['employee'] = $employee; }
    if ( $month ) { $args['month'] = $month; }
    wp_safe_redirect( add_query_arg( $args, home_url( '/main/' ) ) );
    exit;
}

/** Safe clock buttons: employees can write their own punches but never read reports. */
function crmb_att_clock_action() {
    if ( ! is_user_logged_in() ) { wp_die( 'ابتدا وارد شوید.', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'crmb_att_clock', 'crmb_att_nonce' );
    crmb_att_install();
    global $wpdb;
    $t = crmb_att_tables();
    $uid = get_current_user_id();
    $action = isset( $_POST['clock_action'] ) && is_string( $_POST['clock_action'] )
        ? sanitize_key( wp_unslash( $_POST['clock_action'] ) ) : '';
    $today = wp_date( 'Y-m-d' );
    $month = substr( crmb_att_jdate( $today ), 0, 7 );
    if ( crmb_att_month_summary( $uid, $month ) ) { crmb_att_return( 'closed' ); }
    $now = gmdate( 'Y-m-d H:i:s' );
    if ( 'in' === $action ) {
        $open = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['days']} WHERE user_id = %d AND status = 'present'
             AND checkin_utc IS NOT NULL AND checkout_utc IS NULL LIMIT 1", $uid
        ) );
        if ( $open ) {
            wp_safe_redirect( add_query_arg( 'clock_notice', 'already', home_url( '/main/' ) ) );
            exit;
        }
        $schedule = crmb_att_settings( $uid );
        $created = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$t['days']} (user_id,work_day,checkin_utc,status,expected_minutes,start_local,created_utc,updated_utc)
             VALUES (%d,%s,%s,'present',%d,%s,%s,%s)",
            $uid, $today, $now, crmb_att_expected( $uid, $today ), $schedule['start'], $now, $now
        ) );
        if ( 1 === $created ) {
            crmb_att_enroll( $uid, $today );
        }
        $code = 1 === $created ? 'in' : 'already';
    } elseif ( 'out' === $action ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['days']} WHERE user_id = %d AND checkin_utc IS NOT NULL AND checkout_utc IS NULL
             AND status = 'present' ORDER BY work_day DESC LIMIT 1", $uid
        ) );
        if ( ! $row || ( strtotime( $now . ' UTC' ) - strtotime( $row->checkin_utc . ' UTC' ) ) > DAY_IN_SECONDS ) {
            $code = 'missing_in';
        } else {
            $done = $wpdb->query( $wpdb->prepare(
                "UPDATE {$t['days']} SET checkout_utc = %s, updated_utc = %s WHERE id = %d AND checkout_utc IS NULL",
                $now, $now, $row->id
            ) );
            $code = 1 === $done ? 'out' : 'already';
        }
    } else { $code = 'invalid'; }
    // No personal attendance figures are returned to the employee.
    wp_safe_redirect( add_query_arg( 'clock_notice', $code, home_url( '/main/' ) ) );
    exit;
}
add_action( 'admin_post_crmb_att_clock', 'crmb_att_clock_action' );

/** Administrator updates a user's schedule or any day in the current month. */
function crmb_att_admin_action() {
    crmb_att_admin_only();
    check_admin_referer( 'crmb_att_admin', 'crmb_att_nonce' );
    crmb_att_install();
    global $wpdb;
    $uid = isset( $_POST['employee'] ) ? absint( $_POST['employee'] ) : 0;
    $month = isset( $_POST['month'] ) && is_string( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
    $task = isset( $_POST['attendance_task'] ) && is_string( $_POST['attendance_task'] ) ? sanitize_key( wp_unslash( $_POST['attendance_task'] ) ) : '';
    if ( ! get_userdata( $uid ) || ! crmb_att_month_bounds( $month ) || $month > crmb_att_current_month() ) {
        crmb_att_return( 'invalid', $uid, $month );
    }
    // Past months may need an administrator to complete an open attendance row
    // before they can safely be archived. Only current-month schedules can change.
    if ( 'schedule' === $task && $month !== crmb_att_current_month() ) {
        crmb_att_return( 'closed', $uid, $month );
    }
    if ( crmb_att_month_summary( $uid, $month ) ) { crmb_att_return( 'closed', $uid, $month ); }
    if ( 'schedule' === $task ) {
        $start = isset( $_POST['start'] ) && is_string( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
        $minutes = isset( $_POST['minutes'] ) ? absint( $_POST['minutes'] ) : 0;
        $weekdays = isset( $_POST['weekdays'] ) && is_array( $_POST['weekdays'] )
            ? array_values( array_intersect( range( 0, 6 ), array_map( 'intval', wp_unslash( $_POST['weekdays'] ) ) ) ) : array();
        if ( ! crmb_att_is_time( $start ) || $minutes > 1440 ) { crmb_att_return( 'invalid', $uid, $month ); }
        update_user_meta( $uid, 'crmb_att_schedule', array( 'start' => $start, 'minutes' => $minutes, 'weekdays' => $weekdays ) );
        crmb_att_enroll( $uid, wp_date( 'Y-m-d' ) );
        crmb_att_return( 'saved', $uid, $month );
    }
    if ( 'day' !== $task ) { crmb_att_return( 'invalid', $uid, $month ); }
    $jdate = isset( $_POST['day'] ) && is_string( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : '';
    if ( ! preg_match( '/^(1[2345][0-9]{2})-(0[1-9]|1[0-2])-([0-3][0-9])$/', $jdate )
        || substr( $jdate, 0, 7 ) !== $month ) { crmb_att_return( 'invalid', $uid, $month ); }
    $j = array_map( 'intval', explode( '-', $jdate ) );
    $day = crmb_att_to_gregorian( $j[0], $j[1], $j[2] );
    $bounds = crmb_att_month_bounds( $month );
    if ( ! $day || $day < $bounds[0] || $day > $bounds[1] || crmb_att_jdate( $day ) !== $jdate
        || $day > wp_date( 'Y-m-d' ) ) { crmb_att_return( 'invalid', $uid, $month ); }

    $status = isset( $_POST['day_status'] ) && is_string( $_POST['day_status'] )
        ? sanitize_key( wp_unslash( $_POST['day_status'] ) ) : '';
    if ( ! in_array( $status, array( 'present', 'paid_leave', 'unpaid_leave', 'absence', 'holiday' ), true ) ) {
        crmb_att_return( 'invalid', $uid, $month );
    }
    $raw_in = isset( $_POST['checkin'] ) && is_string( $_POST['checkin'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['checkin'] ) ) ) : '';
    $raw_out = isset( $_POST['checkout'] ) && is_string( $_POST['checkout'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['checkout'] ) ) ) : '';
    $leave = isset( $_POST['leave_minutes'] ) ? absint( $_POST['leave_minutes'] ) : 0;
    $in = null; $out = null;
    if ( 'present' === $status ) {
        if ( ! crmb_att_is_time( $raw_in ) || ( '' !== $raw_out && ! crmb_att_is_time( $raw_out ) ) ) {
            crmb_att_return( 'invalid', $uid, $month );
        }
        $in = crmb_att_local_to_utc( $day, $raw_in );
        $out = '' !== $raw_out ? crmb_att_local_to_utc( $day, $raw_out ) : null;
        if ( $in && $out && $out <= $in ) {
            // A shift can finish after midnight.
            $out = crmb_att_local_to_utc( gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ), $raw_out );
        }
        if ( ! $in || ( '' !== $raw_out && ! $out )
            || ( $out && ( strtotime( $out . ' UTC' ) - strtotime( $in . ' UTC' ) ) > DAY_IN_SECONDS ) ) {
            crmb_att_return( 'invalid', $uid, $month );
        }
        $leave = min( 1440, $leave );
    } else {
        $leave = in_array( $status, array( 'paid_leave', 'unpaid_leave' ), true ) ? min( 1440, $leave ) : 0;
    }
    $t = crmb_att_tables();
    $schedule = crmb_att_settings( $uid );
    $now = gmdate( 'Y-m-d H:i:s' );
    $values = array(
        'user_id' => $uid, 'work_day' => $day, 'checkin_utc' => $in, 'checkout_utc' => $out,
        'status' => $status, 'leave_minutes' => $leave, 'expected_minutes' => crmb_att_expected( $uid, $day ),
        'start_local' => $schedule['start'], 'created_utc' => $now, 'updated_utc' => $now
    );
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$t['days']} WHERE user_id = %d AND work_day = %s", $uid, $day
    ) );
    if ( $existing ) {
        unset( $values['user_id'], $values['work_day'], $values['created_utc'] );
        $ok = $wpdb->update( $t['days'], $values, array( 'id' => (int) $existing ) );
    } else {
        $ok = $wpdb->insert( $t['days'], $values );
    }
    if ( false !== $ok ) {
        crmb_att_enroll( $uid, $day );
    }
    crmb_att_return( false === $ok ? 'invalid' : 'saved', $uid, $month );
}
add_action( 'admin_post_crmb_att_admin', 'crmb_att_admin_action' );

/**
 * Archive old Persian months. Transaction and row-count verification prevent
 * removing raw data unless the corresponding summary was safely committed.
 */
function crmb_att_archive_month( $uid, $month ) {
    global $wpdb;
    $t = crmb_att_tables();
    $bounds = crmb_att_month_bounds( $month );
    if ( ! $bounds || $month >= crmb_att_current_month() ) { return false; }
    $wpdb->query( 'START TRANSACTION' );
    $all = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['days']} WHERE user_id = %d AND work_day BETWEEN %s AND %s FOR UPDATE",
        $uid, $bounds[0], $bounds[1]
    ) );
    if ( ! $all || crmb_att_month_summary( $uid, $month ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
    foreach ( $all as $row ) {
        if ( 'present' === $row->status && ( ! $row->checkin_utc || ! $row->checkout_utc ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }
    $rows = array();
    foreach ( $all as $row ) { $rows[ $row->work_day ] = $row; }
    $metrics = crmb_att_month_metrics( $uid, $month, $rows );
    $user = get_userdata( $uid );
    $name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
    $name = $name ?: ( $user ? $user->display_name : 'کاربر ' . $uid );
    $data = array_merge(
        array( 'user_id' => $uid, 'employee_name' => $name, 'jalali_month' => $month ),
        $metrics,
        array( 'archived_utc' => gmdate( 'Y-m-d H:i:s' ) )
    );
    if ( 1 !== $wpdb->insert( $t['months'], $data ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
    $written = crmb_att_month_summary( $uid, $month );
    if ( ! $written || (int) $written->days_with_records !== count( $all ) ) {
        $wpdb->query( 'ROLLBACK' ); return false;
    }
    $deleted = $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$t['days']} WHERE user_id = %d AND work_day BETWEEN %s AND %s",
        $uid, $bounds[0], $bounds[1]
    ) );
    if ( (int) $deleted !== count( $all ) ) {
        $wpdb->query( 'ROLLBACK' );
        return false;
    }
    // wpdb can return 0 for a successful COMMIT statement; only false is failure.
    return false !== $wpdb->query( 'COMMIT' );
}
function crmb_att_rollup() {
    if ( '1' !== get_option( 'crmb_att_schema_v1' ) ) { crmb_att_install(); }
    global $wpdb;
    $t = crmb_att_tables();
    $today_month = crmb_att_current_month();
    $limits = crmb_att_month_bounds( $today_month );
    if ( ! $limits ) { return; }

    $older = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT user_id, work_day FROM {$t['days']} WHERE work_day < %s ORDER BY work_day LIMIT 2000",
        $limits[0]
    ) );
    $keys = array();
    foreach ( $older as $r ) {
        $month = substr( crmb_att_jdate( $r->work_day ), 0, 7 );
        if ( $month < $today_month ) { $keys[ $r->user_id . ':' . $month ] = array( (int) $r->user_id, $month ); }
    }
    foreach ( $keys as $entry ) { crmb_att_archive_month( $entry[0], $entry[1] ); }
}
add_action( 'crmb_att_rollup', 'crmb_att_rollup' );
function crmb_att_schedule_rollup() {
    if ( ! wp_next_scheduled( 'crmb_att_rollup' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'crmb_att_rollup' );
    }
}
add_action( 'init', 'crmb_att_schedule_rollup', 10 );

/**
 * Fallback for installations where WP-Cron is disabled or delayed.
 * Run at most once per hour on normal traffic; archives only CLOSED old months.
 */
function crmb_att_rollup_on_visit() {
    $key = 'crmb_att_visit_rollup_' . str_replace( '-', '_', crmb_att_current_month() );
    if ( get_transient( $key ) ) { return; }
    set_transient( $key, 1, HOUR_IN_SECONDS );
    crmb_att_rollup();
}
add_action( 'init', 'crmb_att_rollup_on_visit', 30 );
