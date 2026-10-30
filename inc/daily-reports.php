<?php
/** Rich daily reports; images are stored privately and served with ownership checks. */
defined( 'ABSPATH' ) || exit;
function crmb_report_html( $html ) {
    $allowed = array(
        'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(),
        'ul' => array(), 'ol' => array(), 'li' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'blockquote' => array(),
        'a' => array( 'href' => true, 'title' => true ), 'hr' => array(), 'pre' => array(), 'code' => array(),
    );
    return wp_kses( $html, $allowed, array( 'http', 'https', 'mailto' ) );
}
function crmb_report_get( $id, $lock = false ) {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() || ! $id ) { return null; }
    global $wpdb; $t = crmb_progress_tables();
    $where = current_user_can( 'manage_options' ) ? " AND status <> 'draft'" : $wpdb->prepare( ' AND user_id = %d', get_current_user_id() );
    // Managers can also edit their own drafts; no access to colleagues' drafts.
    if ( current_user_can( 'manage_options' ) ) { $where = $wpdb->prepare( " AND (status <> 'draft' OR user_id = %d)", get_current_user_id() ); }
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['reports']} WHERE id = %d{$where}" . ( $lock ? ' FOR UPDATE' : '' ), $id ) );
}
/** Validate actual uploaded images before opening the database transaction. */
function crmb_report_uploads( $files ) {
    if ( ! isset( $files['images'] ) ) { return array(); }
    $f = $files['images'];
    if ( ! is_array( $f ) || ! isset( $f['error'] ) || ! is_array( $f['error'] ) || count( $f['error'] ) > 3 ) { return new WP_Error( 'invalid_images' ); }
    $images = array();
    foreach ( $f['error'] as $i => $error ) {
        if ( UPLOAD_ERR_NO_FILE === $error ) { continue; }
        $tmp = $f['tmp_name'][$i] ?? null; $name = $f['name'][$i] ?? null;
        if ( UPLOAD_ERR_OK !== $error || ! is_string( $tmp ) || ! is_string( $name ) || ! is_uploaded_file( $tmp ) ) { return new WP_Error( 'invalid_images' ); }
        $size = filesize( $tmp );
        if ( ! $size || $size > 1048576 ) { return new WP_Error( 'invalid_images' ); }
        $types = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
        $type = wp_check_filetype_and_ext( $tmp, $name, $types );
        $dimensions = wp_getimagesize( $tmp );
        if ( ! in_array( $type['type'], $types, true ) || ! $dimensions || $dimensions[0] * $dimensions[1] > 16000000 || ( $dimensions['mime'] ?? '' ) !== $type['type'] ) { return new WP_Error( 'invalid_images' ); }
        $bytes = file_get_contents( $tmp );
        if ( false === $bytes || strlen( $bytes ) !== $size ) { return new WP_Error( 'invalid_images' ); }
        $images[] = array( 'mime' => $type['type'], 'image_data' => base64_encode( $bytes ) );
    }
    return $images;
}
/** Only the author can save; submitted reports become immutable. */
function crmb_report_save( $data, $files = array() ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( ! crmb_progress_ready() ) { return 'error'; }
    $day = crmb_leave_parse_day( crmb_work_text( $data, 'report_day' ) );
    $raw = isset( $data['report_body'] ) && is_string( $data['report_body'] ) ? $data['report_body'] : '';
    $body = crmb_report_html( $raw ); $blockers = crmb_work_text( $data, 'blockers', true ); $plan = crmb_work_text( $data, 'next_plan', true );
    $submit = isset( $data['mode'] ) && 'submit' === $data['mode'];
    if ( ! $day || $day > crmb_att_today() || $day < ( new DateTimeImmutable( crmb_att_today() ) )->modify( '-31 days' )->format( 'Y-m-d' ) || '' === trim( wp_strip_all_tags( $body ) ) || strlen( $raw ) > 30000 || strlen( $blockers ) > 10000 || strlen( $plan ) > 10000 ) { return 'invalid'; }
    $images = crmb_report_uploads( $files );
    if ( is_wp_error( $images ) ) { return 'image_error'; }
    global $wpdb; $t = crmb_progress_tables(); $uid = get_current_user_id();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['reports']} WHERE user_id = %d AND report_day = %s FOR UPDATE", $uid, $day ) );
    if ( $wpdb->last_error ) { return crmb_progress_fail(); }
    if ( $report && 'draft' !== $report->status ) { return crmb_progress_fail( 'locked' ); }
    $id = $report ? (int) $report->id : 0;
    $existing = $id ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['images']} WHERE report_id = %d", $id ) ) : 0;
    if ( $wpdb->last_error ) { return crmb_progress_fail(); }
    if ( (int) $existing + count( $images ) > 3 ) { return crmb_progress_fail( 'image_error' ); }
    $now = gmdate( 'Y-m-d H:i:s' );
    $values = array( 'body' => $body, 'blockers' => $blockers, 'next_plan' => $plan, 'status' => $submit ? 'submitted' : 'draft', 'updated_utc' => $now, 'submitted_utc' => $submit ? $now : null );
    if ( $id ) { $result = $wpdb->update( $t['reports'], $values, array( 'id' => $id, 'user_id' => $uid, 'status' => 'draft' ) ); }
    else { $result = $wpdb->insert( $t['reports'], $values + array( 'user_id' => $uid, 'report_day' => $day, 'created_utc' => $now ) ); $id = (int) $wpdb->insert_id; }
    if ( false === $result || ! $id ) { return crmb_progress_fail(); }
    foreach ( $images as $image ) {
        if ( false === $wpdb->insert( $t['images'], $image + array( 'report_id' => $id ) ) ) { return crmb_progress_fail(); }
    }
    return crmb_progress_commit();
}
function crmb_report_review( $id ) {
    if ( ! current_user_can( 'manage_options' ) || ! crmb_progress_ready() ) { return 'denied'; }
    global $wpdb; $t = crmb_progress_tables();
    $result = $wpdb->update( $t['reports'], array( 'status' => 'reviewed', 'reviewed_utc' => gmdate( 'Y-m-d H:i:s' ), 'reviewed_by' => get_current_user_id() ), array( 'id' => $id, 'status' => 'submitted' ) );
    return false === $result ? 'error' : ( 1 === $result ? 'saved' : 'changed' );
}
function crmb_report_remove_image( $id ) {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() ) { return 'denied'; }
    global $wpdb; $t = crmb_progress_tables();
    // Lock the report before changing attachments, same order as saving/submitting.
    $report_id = $wpdb->get_var( $wpdb->prepare( "SELECT report_id FROM {$t['images']} WHERE id = %d", $id ) );
    if ( ! $report_id || false === $wpdb->query( 'START TRANSACTION' ) ) { return 'invalid'; }
    $report = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$t['reports']} WHERE id = %d AND user_id = %d AND status = 'draft' FOR UPDATE", $report_id, get_current_user_id() ) );
    if ( ! $report ) { return crmb_progress_fail( 'denied' ); }
    if ( false === $wpdb->delete( $t['images'], array( 'id' => $id, 'report_id' => $report_id ) ) ) { return crmb_progress_fail(); }
    return crmb_progress_commit();
}
/** Authenticated image endpoint: no public uploads URL or media-library exposure. */
function crmb_report_image() {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() ) { wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) ); }
    global $wpdb; $t = crmb_progress_tables(); $id = crmb_work_id( $_GET, 'image' );
    $meta = $wpdb->get_row( $wpdb->prepare( "SELECT report_id,mime FROM {$t['images']} WHERE id = %d", $id ) );
    if ( ! $meta || ! crmb_report_get( (int) $meta->report_id ) || ! in_array( $meta->mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { wp_die( 'تصویر در دسترس نیست.', '', array( 'response' => 404 ) ); }
    $encoded = $wpdb->get_var( $wpdb->prepare( "SELECT image_data FROM {$t['images']} WHERE id = %d", $id ) );
    $bytes = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
    if ( false === $bytes ) { wp_die( 'تصویر در دسترس نیست.', '', array( 'response' => 404 ) ); }
    nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' );
    header( 'Content-Type: ' . $meta->mime ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: inline' );
    header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
    echo $bytes; exit;
}
add_action( 'admin_post_crmb_report_image', 'crmb_report_image' );
function crmb_progress_handle_post() {
    if ( ! is_user_logged_in() ) { wp_die( 'ابتدا وارد شوید.', '', array( 'response' => 403 ) ); }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'روش درخواست مجاز نیست.', '', array( 'response' => 405 ) ); }
    check_admin_referer( 'crmb_progress', 'crmb_progress_nonce' );
    $data = wp_unslash( $_POST ); $task = crmb_work_text( $data, 'task' ); $id = crmb_work_id( $data, 'id' );
    $args = array( 'crm_section' => 'reports' );
    if ( 'task_note' === $task ) {
        $notice = crmb_progress_note( $id, crmb_work_text( $data, 'note_type' ), crmb_work_text( $data, 'note_body', true ) );
        $args = array( 'crm_section' => 'tasks', 'task_id' => $id );
    } elseif ( 'save_report' === $task ) {
        $notice = crmb_report_save( $data, $_FILES );
        $day = crmb_leave_parse_day( crmb_work_text( $data, 'report_day' ) );
        $args['day'] = $day ? crmb_att_jdate( $day ) : crmb_att_jdate( crmb_att_today() );
        $retry_key = 'crmb_report_retry_' . get_current_user_id();
        if ( 'saved' === $notice ) { delete_transient( $retry_key ); }
        elseif ( 'locked' !== $notice ) {
            // Preserve typed text after validation/upload failure, scoped to this author.
            set_transient( $retry_key, array( 'day' => $args['day'], 'body' => crmb_report_html( substr( isset( $data['report_body'] ) && is_string( $data['report_body'] ) ? $data['report_body'] : '', 0, 30000 ) ), 'blockers' => substr( crmb_work_text( $data, 'blockers', true ), 0, 10000 ), 'next_plan' => substr( crmb_work_text( $data, 'next_plan', true ), 0, 10000 ) ), HOUR_IN_SECONDS );
        }
    } elseif ( 'review_report' === $task ) { $notice = crmb_report_review( $id ); $args['report'] = $id; $args['scope'] = 'team'; }
    elseif ( 'remove_image' === $task ) { $notice = crmb_report_remove_image( $id ); $args['day'] = crmb_work_text( $data, 'day' ); }
    else { $notice = 'invalid'; }
    $args['progress_notice'] = $notice;
    wp_safe_redirect( add_query_arg( $args, home_url( '/main/' ) ) ); exit;
}
add_action( 'admin_post_crmb_progress', 'crmb_progress_handle_post' );
function crmb_progress_form_fields( $task, $id = 0 ) {
    echo '<input type="hidden" name="action" value="crmb_progress"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
    if ( $id ) { echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">'; }
    wp_nonce_field( 'crmb_progress', 'crmb_progress_nonce' );
}
function crmb_progress_notice() {
    $key = isset( $_GET['progress_notice'] ) && is_string( $_GET['progress_notice'] ) ? $_GET['progress_notice'] : '';
    $messages = array( 'saved' => 'اطلاعات ذخیره شد.', 'invalid' => 'اطلاعات معتبر نیست؛ تاریخ و متن را بررسی کنید.', 'denied' => 'اجازه این عملیات را ندارید.', 'error' => 'ثبت انجام نشد؛ دوباره تلاش کنید.', 'locked' => 'گزارش ارسال‌شده قابل تغییر نیست.', 'image_error' => 'حداکثر ۳ عکس JPG، PNG یا WebP، هرکدام تا ۱ مگابایت و ۱۶ میلیون پیکسل مجاز است. متن شما حفظ شده؛ عکس‌ها را دوباره انتخاب کنید.', 'changed' => 'این مورد قبلاً تغییر کرده است.' );
    if ( isset( $messages[$key] ) ) { echo '<p class="crm-access-notice" role="status">' . esc_html( $messages[$key] ) . '</p>'; }
}
function crmb_report_image_list( $report ) {
    global $wpdb; $t = crmb_progress_tables();
    $images = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$t['images']} WHERE report_id = %d ORDER BY id", $report->id ) );
    foreach ( $images ?: array() as $image ) {
        $url = add_query_arg( array( 'action' => 'crmb_report_image', 'image' => $image->id ), admin_url( 'admin-post.php' ) );
        echo '<figure class="crmb-report-photo"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img loading="lazy" src="' . esc_url( $url ) . '" alt="تصویر پیوست گزارش"></a>';
        if ( (int) $report->user_id === get_current_user_id() && 'draft' === $report->status ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            crmb_progress_form_fields( 'remove_image', $image->id );
            echo '<input type="hidden" name="day" value="' . esc_attr( crmb_att_jdate( $report->report_day ) ) . '"><button class="crm-access-danger" type="submit">حذف تصویر</button></form>';
        }
        echo '</figure>';
    }
}
