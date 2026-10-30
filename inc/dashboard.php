<?php
/** Personal dashboard: never accept a requested employee ID. */
defined( 'ABSPATH' ) || exit;

add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    return array( 'dashboard' => array( 'label' => 'پیش‌خوان', 'always_visible' => true ) ) + $items;
} );

function crmb_dashboard_data() {
    $data = array( 'day' => null, 'open' => null, 'requests' => array(), 'pending' => null, 'team_pending' => null, 'attendance_ready' => false, 'leave_ready' => false );
    if ( ! is_user_logged_in() ) { return $data; }
    global $wpdb;
    $uid = get_current_user_id();
    if ( '1' === get_option( 'crmb_att_schema_v1' ) ) {
        $tables = crmb_att_tables();
        $data['day'] = $wpdb->get_row( $wpdb->prepare(
            "SELECT work_day, checkin_utc, checkout_utc, status FROM {$tables['days']} WHERE user_id = %d AND work_day = %s LIMIT 1",
            $uid, crmb_att_today()
        ) );
        $day_ok = ! $wpdb->last_error;
        $data['open'] = $wpdb->get_row( $wpdb->prepare(
            "SELECT work_day, checkin_utc FROM {$tables['days']} WHERE user_id = %d AND status = 'present' AND checkin_utc IS NOT NULL AND checkout_utc IS NULL ORDER BY work_day DESC LIMIT 1",
            $uid
        ) );
        $data['attendance_ready'] = $day_ok && ! $wpdb->last_error;
    }
    if ( '1' === get_option( 'crmb_leave_schema_v1' ) ) {
        $table = crmb_leave_table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, leave_day, time_from, time_to, status, created_utc, reviewed_utc FROM {$table} WHERE user_id = %d ORDER BY COALESCE(reviewed_utc, created_utc) DESC, id DESC LIMIT 5",
            $uid
        ) );
        $rows_ok = ! $wpdb->last_error;
        $pending = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'pending'", $uid
        ) );
        $data['leave_ready'] = $rows_ok && ! $wpdb->last_error;
        if ( $data['leave_ready'] ) {
            $data['requests'] = $rows ?: array();
            $data['pending'] = (int) $pending;
        }
        if ( current_user_can( 'manage_options' ) ) {
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
            if ( ! $wpdb->last_error ) { $data['team_pending'] = (int) $total; }
        }
    }
    return $data;
}
