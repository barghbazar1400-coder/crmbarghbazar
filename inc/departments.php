<?php
/** Organizational units. WordPress terms store units; user meta stores membership. */
defined( 'ABSPATH' ) || exit;

add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    $items['departments'] = array( 'label' => 'منابع انسانی', 'admin_only' => true );
    return $items;
} );

add_action( 'init', function () {
    register_taxonomy( 'crmb_department', null, array(
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'rewrite' => false, 'query_var' => false,
        'capabilities' => array_fill_keys( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), 'manage_options' ),
    ) );
} );

function crmb_department( $id ) {
    $term = get_term( $id, 'crmb_department' );
    return $term && ! is_wp_error( $term ) ? $term : false;
}

function crmb_department_members( $id ) {
    return get_users( array( 'meta_key' => 'crmb_department_id', 'meta_value' => (string) $id, 'orderby' => 'display_name', 'order' => 'ASC' ) );
}

/** Return a status code so validation and persistence can be tested separately. */
function crmb_department_save( $data ) {
    $task = isset( $data['task'] ) && is_string( $data['task'] ) ? $data['task'] : '';
    $id = isset( $data['department_id'] ) && is_scalar( $data['department_id'] ) ? absint( $data['department_id'] ) : 0;
    $term = $id ? crmb_department( $id ) : false;
    if ( 'save' === $task ) {
        $name = isset( $data['name'] ) && is_string( $data['name'] ) ? trim( sanitize_text_field( $data['name'] ) ) : '';
        $manager = isset( $data['manager'] ) && is_scalar( $data['manager'] ) ? absint( $data['manager'] ) : 0;
        if ( '' === $name || strlen( $name ) > 180 || ( $id && ! $term ) || ( $manager && ! get_userdata( $manager ) ) ) {
            return 'invalid';
        }
        $duplicate = get_term_by( 'name', $name, 'crmb_department' );
        if ( $duplicate && (int) $duplicate->term_id !== $id ) { return 'duplicate'; }
        $result = $id ? wp_update_term( $id, 'crmb_department', array( 'name' => $name ) ) : wp_insert_term( $name, 'crmb_department' );
        if ( is_wp_error( $result ) ) { return 'error'; }
        $id = (int) $result['term_id'];
        $result = update_term_meta( $id, 'crmb_manager_id', $manager );
        return is_wp_error( $result ) ? 'error' : 'saved';
    }
    if ( 'assign' === $task ) {
        $user = isset( $data['employee'] ) && is_scalar( $data['employee'] ) ? absint( $data['employee'] ) : 0;
        if ( ! get_userdata( $user ) || ( $id && ! $term ) ) { return 'invalid'; }
        if ( (int) get_user_meta( $user, 'crmb_department_id', true ) === $id ) { return 'saved'; }
        $result = $id ? update_user_meta( $user, 'crmb_department_id', $id ) : delete_user_meta( $user, 'crmb_department_id' );
        return $result ? 'saved' : 'error';
    }
    if ( 'delete' === $task && $term ) {
        if ( crmb_department_members( $id ) || get_userdata( (int) get_term_meta( $id, 'crmb_manager_id', true ) ) ) { return 'in_use'; }
        $result = wp_delete_term( $id, 'crmb_department' );
        return $result && ! is_wp_error( $result ) ? 'deleted' : 'error';
    }
    return 'invalid';
}

add_action( 'admin_post_crmb_departments', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) );
    }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        wp_die( 'روش درخواست مجاز نیست.', '', array( 'response' => 405 ) );
    }
    check_admin_referer( 'crmb_departments', 'crmb_nonce' );
    $notice = crmb_department_save( wp_unslash( $_POST ) );
    wp_safe_redirect( add_query_arg( array( 'crm_section' => 'departments', 'department_notice' => $notice ), home_url( '/main/' ) ) );
    exit;
} );
