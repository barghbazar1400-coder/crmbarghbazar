<?php
/**
 * Unlimited-count progressive image uploads for employee task progress.
 * Each AJAX request contains ONE validated private image; limits are imposed
 * by PHP/web-server request settings rather than the old 3 x 1 MiB UI cap.
 * Large encoded binaries still have a defensive cap to protect PHP memory.
 */
defined( 'ABSPATH' ) || exit;

/** Only JPG, PNG and WebP, with a generous 64 megapixel decode guard. */
function crmb_task_photo_validate( $file ) {
    if ( ! is_array( $file ) || ! isset( $file['error'] )
        || UPLOAD_ERR_OK !== (int) $file['error'] ) {
        return new WP_Error( 'invalid_images', 'ارسال عکس توسط سرور پذیرفته نشد؛ تنظیمات آپلود PHP را بررسی کنید.' );
    }
    $tmp = $file['tmp_name'] ?? null;
    $name = $file['name'] ?? null;
    if ( ! is_string( $tmp ) || ! is_string( $name ) || ! is_uploaded_file( $tmp ) ) {
        return new WP_Error( 'invalid_images', 'فایل آپلودشده معتبر نیست.' );
    }
    $size = filesize( $tmp );
    // Data is currently stored as base64 in a private longtext column.
    // Prevent a single request from exhausting PHP memory or DB packets.
    if ( ! $size || $size > 64 * 1024 * 1024 ) {
        return new WP_Error( 'invalid_images', 'حجم تک‌عکس بسیار بزرگ است (حد ایمنی ۶۴ مگابایت).' );
    }
    $types = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
    $type = wp_check_filetype_and_ext( $tmp, $name, $types );
    $dimensions = wp_getimagesize( $tmp );
    if ( ! is_array( $type ) || ! in_array( $type['type'] ?? null, $types, true )
        || ! is_array( $dimensions ) || ( $dimensions['mime'] ?? '' ) !== $type['type']
        || empty( $dimensions[0] ) || empty( $dimensions[1] )
        || $dimensions[0] * $dimensions[1] > 64000000 ) {
        return new WP_Error( 'invalid_images', 'نوع یا ابعاد تصویر معتبر نیست. فقط JPG، PNG و WebP مجاز است.' );
    }
    $bytes = file_get_contents( $tmp );
    if ( false === $bytes || strlen( $bytes ) !== $size ) {
        return new WP_Error( 'invalid_images', 'خواندن فایل آپلودشده انجام نشد.' );
    }
    return array( 'mime' => $type['type'], 'image_data' => base64_encode( $bytes ) );
}
/** Native no-JS form fallback, without a 3-photo or 1 MiB application cap. */
function crmb_task_photo_files( $files ) {
    if ( ! isset( $files['images'] ) ) { return array(); }
    $f = $files['images'];
    if ( ! is_array( $f ) || ! isset( $f['error'] ) || ! is_array( $f['error'] ) ) {
        return new WP_Error( 'invalid_images' );
    }
    $images = array();
    foreach ( $f['error'] as $i => $error ) {
        if ( UPLOAD_ERR_NO_FILE === $error ) { continue; }
        $image = crmb_task_photo_validate( array(
            'name' => $f['name'][$i] ?? null, 'tmp_name' => $f['tmp_name'][$i] ?? null,
            'error' => $error,
        ) );
        if ( is_wp_error( $image ) ) { return $image; }
        $images[] = $image;
    }
    return $images;
}
/**
 * Attach to one freshly created note. The current task owner must also be
 * the note author. Merely knowing task_id or event_id is insufficient.
 */
function crmb_task_photo_append( $task_id, $event_id, $file ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( ! $task_id || ! $event_id || ! crmb_progress_ready()
        || ! crmb_task_notes_ready() ) { return 'invalid'; }
    global $wpdb;
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $task = crmb_progress_task( $task_id, true );
    if ( ! $task || (int) $task->assigned_to !== get_current_user_id() ) {
        return crmb_progress_fail( 'denied' );
    }
    if ( ! in_array( $task->status, array( 'todo', 'doing', 'paused' ), true ) ) {
        return crmb_progress_fail( 'locked' );
    }
    $events = crmb_progress_tables()['events'];
    $event = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM {$events} WHERE id=%d AND task_id=%d
         AND actor_id=%d AND event_type IN ('rich_note','rich_blocker') FOR UPDATE",
        $event_id, $task_id, get_current_user_id()
    ) );
    if ( ! $event ) { return crmb_progress_fail( 'denied' ); }
    $image = crmb_task_photo_validate( $file );
    if ( is_wp_error( $image ) ) { return crmb_progress_fail( 'image_error' ); }
    $table = crmb_task_notes_table();
    if ( false === $wpdb->insert( $table, $image + array( 'event_id' => $event_id ) ) ) {
        return crmb_progress_fail();
    }
    return crmb_progress_commit();
}
/** Admin-AJAX response helpers intentionally avoid leaking task details. */
function crmb_task_photo_ajax_check() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'دسترسی مجاز نیست.' ), 403 );
    }
    if ( ! check_ajax_referer( 'crmb_progress', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'نشست منقضی شده؛ صفحه را تازه‌سازی کنید.' ), 403 );
    }
}
function crmb_task_photo_ajax_save() {
    crmb_task_photo_ajax_check();
    $task_id = crmb_work_id( $_POST, 'id' );
    $kind = isset( $_POST['note_type'] ) && is_string( $_POST['note_type'] )
        ? sanitize_key( wp_unslash( $_POST['note_type'] ) ) : '';
    $type = 'note' === $kind ? 'rich_note' : ( 'blocker' === $kind ? 'rich_blocker' : 'invalid' );
    $body = isset( $_POST['note_body'] ) && is_string( $_POST['note_body'] )
        ? wp_unslash( $_POST['note_body'] ) : '';
    if ( '' === trim( wp_strip_all_tags( $body ) )
        && isset( $_POST['has_images'] ) && '1' === (string) $_POST['has_images'] ) {
        $body = '<p>تصاویر گزارش پیشرفت</p>';
    }
    $event_id = 0;
    $result = crmb_task_rich_save( $task_id, $type, $body, array(), $event_id );
    if ( 'saved' !== $result || ! $event_id ) {
        wp_send_json_error( array( 'message' => 'ذخیره گزارش انجام نشد. (' . $result . ')' ), 400 );
    }
    wp_send_json_success( array( 'event_id' => $event_id ) );
}
function crmb_task_photo_ajax_upload() {
    crmb_task_photo_ajax_check();
    $task_id = crmb_work_id( $_POST, 'id' );
    $event_id = crmb_work_id( $_POST, 'event_id' );
    $result = crmb_task_photo_append( $task_id, $event_id, $_FILES['image'] ?? array() );
    if ( 'saved' !== $result ) {
        $message = 'image_error' === $result
            ? 'آپلود عکس انجام نشد. نوع فایل و محدودیت upload_max_filesize/post_max_size سرور را بررسی کنید.'
            : 'ثبت این عکس انجام نشد. (' . $result . ')';
        wp_send_json_error( array( 'message' => $message ), 400 );
    }
    wp_send_json_success( array( 'saved' => true ) );
}
add_action( 'wp_ajax_crmb_task_photo_save', 'crmb_task_photo_ajax_save' );
add_action( 'wp_ajax_crmb_task_photo_upload', 'crmb_task_photo_ajax_upload' );
