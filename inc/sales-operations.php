<?php
/**
 * Second-generation sales operations for the independent CRM host.
 * New tables are additive: existing customers, quotes and CSV catalog survive.
 */
defined( 'ABSPATH' ) || exit;

function crmb_sales_ops_tables() {
    global $wpdb;
    return array(
        'calls' => $wpdb->prefix . 'crmb_sales_calls',
        'discounts' => $wpdb->prefix . 'crmb_sales_discounts',
    );
}
function crmb_sales_ops_ready() { return '1' === get_option( 'crmb_sales_ops_schema_v1' ); }
function crmb_sales_ops_install() {
    if ( crmb_sales_ops_ready() || ! crmb_sales_ready() ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $tables = crmb_sales_ops_tables();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$tables['calls']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) unsigned NOT NULL,
        opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
        owner_id bigint(20) unsigned NOT NULL,
        outcome varchar(24) NOT NULL,
        note text NOT NULL,
        next_followup_day date DEFAULT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY owner_date (owner_id,created_utc),
        KEY customer_id (customer_id),
        KEY opportunity_id (opportunity_id)
    ) $charset;" );
    dbDelta( "CREATE TABLE {$tables['discounts']} (
        quote_id bigint(20) unsigned NOT NULL,
        percent decimal(5,2) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        requested_by bigint(20) unsigned NOT NULL,
        reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
        reviewed_utc datetime DEFAULT NULL,
        PRIMARY KEY  (quote_id),
        KEY status (status)
    ) $charset;" );
    foreach ( $tables as $table ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { return; }
    }
    update_option( 'crmb_sales_ops_schema_v1', '1', false );
}
add_action( 'init', 'crmb_sales_ops_install', 13 );

function crmb_sales_ops_date( $value ) {
    if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; }
    $day = crmb_leave_parse_day( trim( $value ) );
    return false === $day ? false : $day;
}
function crmb_sales_ops_discount_percent( $value ) {
    if ( ! is_scalar( $value ) || ! preg_match( '/^(?:0|[1-9]|[1-3][0-9]|40)(?:\.[0-9]{1,2})?$/D', (string) $value ) ) { return null; }
    $number = (float) $value;
    return $number >= 0 && $number <= 40 ? $number : null;
}
function crmb_sales_ops_discount( $quote_id ) {
    if ( ! crmb_sales_ops_ready() || ! crmb_sales_quote( (int) $quote_id ) ) { return null; }
    global $wpdb; $t = crmb_sales_ops_tables();
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['discounts']} WHERE quote_id=%d", $quote_id ) );
}
function crmb_sales_ops_total( $subtotal, $discount ) {
    $percent = $discount && 'approved' === $discount->status ? (float) $discount->percent : 0;
    return max( 0, (int) round( (int) $subtotal * ( 100 - $percent ) / 100 ) );
}
function crmb_sales_ops_call( $data ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ops_ready() ) { return 'denied'; }
    $customer_id = crmb_sales_id( $data, 'customer_id' );
    $opportunity_id = crmb_sales_id( $data, 'opportunity_id' );
    $customer = crmb_sales_customer( $customer_id );
    $outcome = crmb_sales_clean( $data, 'outcome', 24 );
    $note = crmb_sales_clean( $data, 'note', 3000, true );
    $day = crmb_sales_ops_date( $data['next_followup_day'] ?? '' );
    $outcomes = array( 'answered', 'unanswered', 'interested', 'not_interested', 'followup' );
    if ( ! $customer || ! in_array( $outcome, $outcomes, true ) || '' === $note || false === $day ) { return 'invalid'; }
    global $wpdb; $base = crmb_sales_tables(); $t = crmb_sales_ops_tables();
    if ( $opportunity_id ) {
        $o = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$base['opportunities']} WHERE id=%d AND customer_id=%d" . crmb_sales_scope(),
            $opportunity_id, $customer_id
        ) );
        if ( ! $o || $wpdb->last_error ) { return 'denied'; }
    }
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $now = gmdate( 'Y-m-d H:i:s' );
    $ok = $wpdb->insert( $t['calls'], array(
        'customer_id' => $customer_id, 'opportunity_id' => $opportunity_id,
        'owner_id' => get_current_user_id(), 'outcome' => $outcome,
        'note' => $note, 'next_followup_day' => $day, 'created_utc' => $now,
    ) );
    if ( false !== $ok && $opportunity_id ) {
        $values = array( 'updated_utc' => $now );
        if ( null !== $day ) { $values['followup_day'] = $day; }
        if ( 'lead' === $o->stage && in_array( $outcome, array( 'answered', 'interested', 'followup' ), true ) ) {
            $values['stage'] = 'contacted';
        }
        $ok = $wpdb->update( $base['opportunities'], $values, array( 'id' => $opportunity_id, 'owner_id' => (int) $o->owner_id ) );
    }
    if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
    return 'saved';
}
function crmb_sales_ops_move( $data ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() ) { return 'denied'; }
    $id = crmb_sales_id( $data, 'opportunity_id' );
    $stage = crmb_sales_clean( $data, 'stage', 24 );
    if ( ! isset( crmb_sales_stages()[$stage] ) || ! $id ) { return 'invalid'; }
    global $wpdb; $t = crmb_sales_tables();
    $old = $wpdb->get_row( $wpdb->prepare(
        "SELECT id,owner_id,stage FROM {$t['opportunities']} WHERE id=%d" . crmb_sales_scope(), $id
    ) );
    if ( ! $old ) { return 'denied'; }
    if ( $old->stage === $stage ) { return 'saved'; }
    $result = $wpdb->update( $t['opportunities'],
        array( 'stage' => $stage, 'updated_utc' => gmdate( 'Y-m-d H:i:s' ) ),
        array( 'id' => $id, 'owner_id' => $old->owner_id, 'stage' => $old->stage )
    );
    return 1 === $result ? 'saved' : ( false === $result ? 'error' : 'changed' );
}
/** Move entire customer history; never give two employees conflicting ownership. */
function crmb_sales_ops_assign( $data ) {
    if ( ! crmb_sales_admin() || ! crmb_sales_ready() ) { return 'denied'; }
    $id = crmb_sales_id( $data, 'customer_id' );
    $owner = crmb_sales_id( $data, 'owner_id' );
    $user = $owner ? get_userdata( $owner ) : false;
    if ( ! $id || ! $user || ( ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'crm_menu_sales' ) ) ) {
        return 'invalid';
    }
    global $wpdb; $t = crmb_sales_tables();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $customer = $wpdb->get_row( $wpdb->prepare( "SELECT id,owner_id FROM {$t['customers']} WHERE id=%d FOR UPDATE", $id ) );
    if ( ! $customer || $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return 'invalid'; }
    $ok = $wpdb->update( $t['customers'], array( 'owner_id' => $owner, 'updated_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
    if ( false !== $ok ) { $ok = $wpdb->update( $t['opportunities'], array( 'owner_id' => $owner ), array( 'customer_id' => $id ) ); }
    if ( false !== $ok ) { $ok = $wpdb->update( $t['quotes'], array( 'owner_id' => $owner ), array( 'customer_id' => $id ) ); }
    if ( false !== $ok && crmb_sales_ops_ready() ) {
        $extra = crmb_sales_ops_tables();
        $ok = $wpdb->update( $extra['calls'], array( 'owner_id' => $owner ), array( 'customer_id' => $id ) );
    }
    if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
    return 'saved';
}
function crmb_sales_ops_review_discount( $data ) {
    if ( ! crmb_sales_admin() || ! crmb_sales_ops_ready() ) { return 'denied'; }
    $quote_id = crmb_sales_id( $data, 'quote_id' );
    $decision = crmb_sales_clean( $data, 'decision', 20 );
    if ( ! in_array( $decision, array( 'approved', 'rejected' ), true ) || ! crmb_sales_quote( $quote_id ) ) { return 'invalid'; }
    global $wpdb; $t = crmb_sales_ops_tables();
    $result = $wpdb->update( $t['discounts'], array(
        'status' => $decision, 'reviewed_by' => get_current_user_id(), 'reviewed_utc' => gmdate( 'Y-m-d H:i:s' ),
    ), array( 'quote_id' => $quote_id, 'status' => 'pending' ) );
    return 1 === $result ? 'saved' : ( false === $result ? 'error' : 'changed' );
}
function crmb_sales_ops_handle_post() {
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! crmb_sales_access() ) {
        wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'crmb_sales_ops', 'crmb_sales_ops_nonce' );
    $data = wp_unslash( $_POST );
    $action = crmb_sales_clean( $data, 'task', 40 );
    $result = 'invalid';
    if ( 'log_call' === $action ) { $result = crmb_sales_ops_call( $data ); }
    elseif ( 'move_opportunity' === $action ) { $result = crmb_sales_ops_move( $data ); }
    elseif ( 'assign_customer' === $action ) { $result = crmb_sales_ops_assign( $data ); }
    elseif ( 'discount_review' === $action ) { $result = crmb_sales_ops_review_discount( $data ); }
    $url = add_query_arg( array( 'crm_section' => 'sales', 'sales_notice' => $result ), home_url( '/main/' ) );
    if ( 'discount_review' === $action ) {
        $url = add_query_arg( array( 'sales_view' => 'quote', 'quote' => crmb_sales_id( $data, 'quote_id' ) ), $url );
    } else { $url .= '#crmb-sales-workbench'; }
    wp_safe_redirect( $url ); exit;
}
add_action( 'admin_post_crmb_sales_ops', 'crmb_sales_ops_handle_post' );
function crmb_sales_ops_form_fields( $task ) {
    echo '<input type="hidden" name="action" value="crmb_sales_ops"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
    wp_nonce_field( 'crmb_sales_ops', 'crmb_sales_ops_nonce' );
}
