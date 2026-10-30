<?php
/**
 * Theme setup and WordPress-native sign in.
 *
 * @package CRMBarghBazar
 */

defined( 'ABSPATH' ) || exit;

function crmbarghbazar_theme_setup() {
    add_theme_support( 'title-tag' );
    add_theme_support(
        'custom-logo',
        array(
            'height'      => 180,
            'width'       => 180,
            'flex-height' => true,
            'flex-width'  => true,
        )
    );
}
add_action( 'after_setup_theme', 'crmbarghbazar_theme_setup' );

function crmbarghbazar_enqueue_styles() {
    $stylesheet_path = get_stylesheet_directory() . '/style.css';
    $version         = file_exists( $stylesheet_path ) ? (string) filemtime( $stylesheet_path ) : '1.0.0';

    wp_enqueue_style( 'crmbarghbazar-style', get_stylesheet_uri(), array(), $version );
}
add_action( 'wp_enqueue_scripts', 'crmbarghbazar_enqueue_styles' );

/**
 * Keep login processing within WordPress instead of creating a separate user table.
 */
function crmbarghbazar_process_login() {
    // Handle the site root as the login page even when WordPress front-page
    // settings do not mark the request as is_front_page().
    $request_uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
        ? wp_unslash( $_SERVER['REQUEST_URI'] )
        : '';
    $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
    $home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
    $is_home_root = is_string( $request_path )
        && is_string( $home_path )
        && untrailingslashit( $request_path ) === untrailingslashit( $home_path );

    if ( ! is_front_page() && ! $is_home_root ) {
        return;
    }

    // The login response depends on each visitor's authentication cookies.
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    nocache_headers();

    // Existing sessions go straight to the CRM without seeing the login form.
    if ( is_user_logged_in() ) {
        wp_safe_redirect( home_url( '/main/' ) );
        exit;
    }

    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['crm_login_submit'] ) ) {
        return;
    }

    $nonce = isset( $_POST['crm_login_nonce'] ) && is_string( $_POST['crm_login_nonce'] )
        ? sanitize_text_field( wp_unslash( $_POST['crm_login_nonce'] ) )
        : '';

    if ( ! wp_verify_nonce( $nonce, 'crmbarghbazar_login' ) ) {
        $GLOBALS['crmbarghbazar_login_error'] = 'درخواست معتبر نیست. صفحه را تازه‌سازی و دوباره تلاش کنید.';
        return;
    }

    $username = isset( $_POST['crm_username'] ) && is_string( $_POST['crm_username'] )
        ? trim( sanitize_text_field( wp_unslash( $_POST['crm_username'] ) ) )
        : '';
    $password = isset( $_POST['crm_password'] ) && is_string( $_POST['crm_password'] )
        ? (string) wp_unslash( $_POST['crm_password'] )
        : '';

    if ( '' === $username || '' === $password ) {
        $GLOBALS['crmbarghbazar_login_error'] = 'نام کاربری و رمز عبور را وارد کنید.';
        return;
    }

    $user = wp_signon(
        array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => isset( $_POST['crm_remember'] ) && '1' === $_POST['crm_remember'],
        ),
        is_ssl()
    );

    if ( is_wp_error( $user ) ) {
        // Do not disclose whether an account exists.
        $GLOBALS['crmbarghbazar_login_error'] = 'اطلاعات ورود صحیح نیست. دوباره تلاش کنید.';
        return;
    }

    wp_safe_redirect( home_url( '/main/' ) );
    exit;
}
add_action( 'template_redirect', 'crmbarghbazar_process_login', 1 );

/**
 * Detect the private /main/ route without requiring manual WordPress page setup.
 */
function crmbarghbazar_is_main_request() {
    if ( is_page( 'main' ) ) {
        return true;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
        ? wp_unslash( $_SERVER['REQUEST_URI'] )
        : '';

    $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
    $main_path    = wp_parse_url( home_url( '/main/' ), PHP_URL_PATH );

    return is_string( $request_path )
        && is_string( $main_path )
        && untrailingslashit( $request_path ) === untrailingslashit( $main_path );
}

/**
 * Serve the CRM page to logged-in users, even when there is no WordPress page
 * with the "main" slug. Keep visitors on the sign-in screen.
 */
function crmbarghbazar_serve_main() {
    if ( ! crmbarghbazar_is_main_request() ) {
        return;
    }

    nocache_headers();

    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    if ( is_page( 'main' ) ) {
        return; // WordPress uses page-main.php automatically.
    }

    status_header( 200 );
    include get_template_directory() . '/page-main.php';
    exit;
}
add_action( 'template_redirect', 'crmbarghbazar_serve_main', 0 );

// CRM role and user management (loaded on frontend and admin-post requests).
require_once get_template_directory() . '/inc/access-management.php';
require_once get_template_directory() . '/inc/attendance.php';
require_once get_template_directory() . '/inc/leave-requests.php';
require_once get_template_directory() . '/inc/departments.php';
