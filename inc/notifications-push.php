<?php
/** Standards-based Web Push for CRM (requires optional Composer library). */
defined( 'ABSPATH' ) || exit;

function crmb_push_library() {
    if ( class_exists( '\Minishlink\WebPush\WebPush' ) ) { return true; }
    $autoload = get_template_directory() . '/vendor/autoload.php';
    if ( is_readable( $autoload ) ) { require_once $autoload; }
    return class_exists( '\Minishlink\WebPush\WebPush' );
}
function crmb_push_keys() {
    $keys = get_option( 'crmb_push_vapid', array() );
    return is_array( $keys ) && ! empty( $keys['publicKey'] ) && ! empty( $keys['privateKey'] ) ? $keys : array();
}
function crmb_push_public_key() {
    $keys = crmb_push_keys();
    // Avoid loading Composer (and querying the filesystem) for every message
    // or when the server has not yet created its VAPID keys.
    if ( empty( $keys['publicKey'] ) ) { return ''; }
    return crmb_push_library() ? $keys['publicKey'] : '';
}
function crmb_push_setup_action() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی ندارید.', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'crmb_push_setup', 'crmb_push_setup_nonce' );
    $result = 'unavailable';
    if ( crmb_push_keys() ) { $result = 'already'; }
    elseif ( crmb_push_library() ) {
        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            $result = add_option( 'crmb_push_vapid', $keys, '', false ) ? 'enabled' : 'error';
            if ( 'enabled' === $result && crmb_notice_ready() ) {
                // Never replay old notifications once transport is activated.
                global $wpdb;
                $t = crmb_notice_tables();
                $wpdb->query( "UPDATE {$t['notices']} SET push_state='skipped' WHERE push_state IN ('pending','retry')" );
            }
        } catch ( \Throwable $e ) { $result = 'error'; }
    }
    wp_safe_redirect( add_query_arg( array( 'crm_section' => 'notifications', 'push_setup' => $result ), home_url( '/main/' ) ) );
    exit;
}
add_action( 'admin_post_crmb_push_setup', 'crmb_push_setup_action' );

/** Only accept known browser push services; never send server-side HTTP to arbitrary endpoints. */
function crmb_push_safe_endpoint( $endpoint ) {
    if ( ! is_string( $endpoint ) || strlen( $endpoint ) > 2048 || 0 !== strpos( $endpoint, 'https://' )
        || ! wp_http_validate_url( $endpoint ) ) { return false; }
    $host = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_HOST ) );
    if ( in_array( $host, array( 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com' ), true ) ) {
        return true;
    }
    foreach ( array( '.push.apple.com', '.notify.windows.com', '.wns.windows.com' ) as $suffix ) {
        if ( str_ends_with( $host, $suffix ) ) { return true; }
    }
    return false;
}
function crmb_push_valid_key( $value, $bytes ) {
    if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) { return false; }
    $decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
    return false !== $decoded && strlen( $decoded ) === $bytes;
}
function crmb_push_subscription_action() {
    if ( ! is_user_logged_in() || ! crmb_notice_ready() ) { wp_send_json_error( array( 'message' => 'denied' ), 403 ); }
    check_ajax_referer( 'crmb_notice_ajax', 'nonce' );
    nocache_headers();
    $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
    $value = isset( $_POST['subscription'] ) && is_string( $_POST['subscription'] )
        ? json_decode( wp_unslash( $_POST['subscription'] ), true ) : array();
    if ( ! is_array( $value ) || empty( $value['endpoint'] ) || ! is_string( $value['endpoint'] ) ) {
        wp_send_json_error( array( 'message' => 'invalid' ), 400 );
    }
    $endpoint = $value['endpoint'];
    if ( ! crmb_push_safe_endpoint( $endpoint ) ) { wp_send_json_error( array( 'message' => 'invalid_endpoint' ), 400 ); }
    global $wpdb;
    $t = crmb_notice_tables();
    $hash = hash( 'sha256', $endpoint );
    $uid = get_current_user_id();
    if ( 'status' === $mode ) {
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['devices']} WHERE user_id=%d AND endpoint_hash=%s", $uid, $hash
        ) );
        wp_send_json_success( array( 'subscribed' => (bool) $count ) );
    }
    if ( 'unsubscribe' === $mode ) {
        // Browser may unsubscribe even if the server's record was already removed.
        $wpdb->delete( $t['devices'], array( 'user_id' => $uid, 'endpoint_hash' => $hash ) );
        wp_send_json_success( array( 'subscribed' => false ) );
    }
    if ( 'subscribe' !== $mode || ! crmb_push_public_key() ) { wp_send_json_error( array( 'message' => 'not_configured' ), 503 ); }
    $p256dh = $value['keys']['p256dh'] ?? null;
    $auth = $value['keys']['auth'] ?? null;
    if ( ! crmb_push_valid_key( $p256dh, 65 ) || ! crmb_push_valid_key( $auth, 16 ) ) {
        wp_send_json_error( array( 'message' => 'invalid_keys' ), 400 );
    }
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['devices']} WHERE endpoint_hash=%s", $hash ) );
    if ( ! $existing ) {
        $number = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['devices']} WHERE user_id=%d", $uid ) );
        if ( $number >= 5 ) { wp_send_json_error( array( 'message' => 'device_limit' ), 429 ); }
    }
    // A shared browser's subscription moves to the currently consenting account.
    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$t['devices']} (user_id,endpoint_hash,endpoint,p256dh,auth,created_utc) VALUES (%d,%s,%s,%s,%s,%s)
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),p256dh=VALUES(p256dh),auth=VALUES(auth)",
        $uid, $hash, $endpoint, $p256dh, $auth, gmdate( 'Y-m-d H:i:s' )
    ) );
    if ( false === $result ) { wp_send_json_error( array( 'message' => 'db_error' ), 500 ); }
    // The first opt-in must never flood a desktop with old unread events.
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$t['notices']} SET push_state='skipped' WHERE user_id=%d AND push_state IN ('pending','retry')", $uid
    ) );
    wp_send_json_success( array( 'subscribed' => true ) );
}
add_action( 'wp_ajax_crmb_push_device', 'crmb_push_subscription_action' );

/** Public worker script must remain available without an active WP login session. */
function crmb_push_service_worker() {
    $path = get_template_directory() . '/assets/js/crmb-push-sw.js';
    if ( ! is_readable( $path ) ) { status_header( 404 ); exit; }
    header( 'Content-Type: application/javascript; charset=UTF-8' );
    header( 'Service-Worker-Allowed: /' );
    header( 'Cache-Control: no-store, private, max-age=0' );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $path ); // Fixed, trusted theme asset, not user supplied.
    exit;
}
add_action( 'wp_ajax_crmb_push_sw', 'crmb_push_service_worker' );
add_action( 'wp_ajax_nopriv_crmb_push_sw', 'crmb_push_service_worker' );

add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['crmb_five_minutes'] = array( 'interval' => 300, 'display' => 'هر پنج دقیقه (اعلان‌های CRM)' );
    return $schedules;
} );
function crmb_push_schedule() {
    if ( ! wp_next_scheduled( 'crmb_notice_push_tick' ) ) {
        wp_schedule_event( time() + 300, 'crmb_five_minutes', 'crmb_notice_push_tick' );
    }
}
add_action( 'init', 'crmb_push_schedule', 18 );

/**
 * Send generic desktop messages only. No private message/announcement text
 * is included in a notification readable on a locked/shared computer.
 */
function crmb_push_worker() {
    if ( ! crmb_notice_ready() || ! crmb_push_keys() || ! crmb_push_library() ) { return; }
    global $wpdb;
    $t = crmb_notice_tables();
    $now = gmdate( 'Y-m-d H:i:s' );
    $jobs = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['notices']} WHERE push_state IN ('pending','retry','sending') AND next_try_utc<=%s AND attempts<3 ORDER BY id ASC LIMIT 15", $now
    ) );
    if ( ! $jobs ) { return; }
    $keys = crmb_push_keys();
    try {
        $webpush = new \Minishlink\WebPush\WebPush( array( 'VAPID' => array(
            'subject' => home_url( '/' ), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']
        ) ), array( 'TTL' => 3600, 'urgency' => 'normal' ) );
    } catch ( \Throwable $e ) { error_log( 'CRM push initialization failed' ); return; }
    foreach ( $jobs as $job ) {
        // Atomic claim avoids two overlapping WP cron runs sending the same job.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['notices']} SET push_state='sending',attempts=attempts+1,next_try_utc=%s
            WHERE id=%d AND push_state=%s AND attempts=%d",
            gmdate( 'Y-m-d H:i:s', time() + 300 ), $job->id, $job->push_state, $job->attempts
        ) );
        if ( 1 !== $claimed ) { continue; }
        $devices = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['devices']} WHERE user_id=%d", $job->user_id ) );
        if ( ! $devices ) {
            $wpdb->update( $t['notices'], array( 'push_state' => 'skipped' ), array( 'id' => $job->id ) );
            continue;
        }
        $body = array(
            // Only a bounded event type reaches the lock-screen notification.
            'kind' => $job->kind,
            'url' => $job->href,
            'tag' => 'crmb-' . (int) $job->id,
        );
        $payload = wp_json_encode( $body );
        $success = false;
        foreach ( $devices as $device ) {
            // Revalidate stored endpoints: do not turn the queue into an SSRF proxy.
            if ( ! crmb_push_safe_endpoint( $device->endpoint ) ) { continue; }
            try {
                $subscription = \Minishlink\WebPush\Subscription::create( array(
                    'endpoint' => $device->endpoint,
                    'keys' => array( 'p256dh' => $device->p256dh, 'auth' => $device->auth ),
                ) );
                $report = $webpush->sendOneNotification( $subscription, $payload );
                if ( $report->isSuccess() ) { $success = true; }
                elseif ( $report->isSubscriptionExpired() ) {
                    $wpdb->delete( $t['devices'], array( 'id' => $device->id, 'user_id' => $job->user_id ) );
                }
            } catch ( \Throwable $e ) {
                error_log( 'CRM push send failed for notification ' . (int) $job->id );
            }
        }
        $state = $success ? 'sent' : ( (int) $job->attempts >= 2 ? 'failed' : 'retry' );
        $wpdb->update( $t['notices'], array( 'push_state' => $state ), array( 'id' => $job->id ) );
    }
}
add_action( 'crmb_notice_push_tick', 'crmb_push_worker' );
add_action( 'crmb_notice_push_immediate', 'crmb_push_worker' );
