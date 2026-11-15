<?php
/**
 * Private chat attachments: validated uploads, storage outside the web root,
 * and downloads authorized against BOTH participants of the message.
 */
defined( 'ABSPATH' ) || exit;

function crmb_chat_files_table() {
    global $wpdb;
    return $wpdb->prefix . 'crmb_chat_files';
}
function crmb_chat_files_ready() {
    return '1' === get_option( 'crmb_chat_files_schema_v1' );
}
function crmb_chat_files_install() {
    if ( crmb_chat_files_ready() || ! crmb_chat_ready() && '1' !== get_option( 'crmb_work_schema_v1' ) ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = crmb_chat_files_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        message_id bigint(20) unsigned NOT NULL,
        uploader_id bigint(20) unsigned NOT NULL,
        original_name varchar(240) NOT NULL,
        stored_name varchar(100) NOT NULL,
        mime_type varchar(120) NOT NULL,
        size_bytes bigint(20) unsigned NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY message_id (message_id),
        KEY uploader_id (uploader_id)
    ) {$charset};" );
    if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
        add_option( 'crmb_chat_files_schema_v1', '1', '', false );
    }
}
add_action( 'init', 'crmb_chat_files_install', 16 );

function crmb_chat_file_limits() {
    return array( 'count' => 3, 'each' => 8 * 1024 * 1024, 'total' => 20 * 1024 * 1024 );
}
function crmb_chat_file_types() {
    return array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'csv' => 'text/csv',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    );
}
/** Normalise PHP's multiple file field without trusting provided content type. */
function crmb_chat_file_input( $input ) {
    if ( ! is_array( $input ) || ! isset( $input['name'] ) ) { return array(); }
    $entries = array();
    if ( is_array( $input['name'] ) ) {
        foreach ( $input['name'] as $key => $name ) {
            $entries[] = array(
                'name' => $name, 'tmp_name' => $input['tmp_name'][$key] ?? '',
                'error' => $input['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size' => $input['size'][$key] ?? 0,
            );
        }
    } else {
        $entries[] = $input;
    }
    return $entries;
}
/** Preflight checks are completed BEFORE any message or file is written. */
function crmb_chat_check_files( $uploads ) {
    if ( ! is_array( $uploads ) ) { return array( 'error' => 'فایل‌های ارسالی معتبر نیستند.' ); }
    if ( ! $uploads ) { return array( 'files' => array() ); }
    $limits = crmb_chat_file_limits();
    if ( count( $uploads ) > $limits['count'] ) {
        return array( 'error' => 'حداکثر ۳ فایل در هر پیام مجاز است.' );
    }
    if ( ! function_exists( 'finfo_open' ) ) {
        return array( 'error' => 'پشتیبانی بررسی نوع فایل در سرور فعال نیست.' );
    }
    $finfo = finfo_open( FILEINFO_MIME_TYPE );
    if ( ! $finfo ) { return array( 'error' => 'بررسی نوع فایل ممکن نیست.' ); }
    $clean = array();
    $total = 0;
    foreach ( $uploads as $upload ) {
        if ( ! is_array( $upload ) || (int) ( $upload['error'] ?? 1 ) !== UPLOAD_ERR_OK
            || ! is_string( $upload['name'] ?? null ) || ! is_string( $upload['tmp_name'] ?? null ) ) {
            finfo_close( $finfo );
            return array( 'error' => 'آپلود یکی از فایل‌ها ناموفق بود.' );
        }
        $size = (int) ( $upload['size'] ?? 0 );
        $total += $size;
        if ( $size < 1 || $size > $limits['each'] || $total > $limits['total'] ) {
            finfo_close( $finfo );
            return array( 'error' => 'هر فایل حداکثر ۸ مگابایت و مجموع حداکثر ۲۰ مگابایت است.' );
        }
        $name = crmb_chat_clean_filename( $upload['name'] );
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $types = crmb_chat_file_types();
        if ( ! isset( $types[$ext] ) || ! is_file( $upload['tmp_name'] ) ||
            (int) filesize( $upload['tmp_name'] ) !== $size ) {
            finfo_close( $finfo );
            return array( 'error' => 'پسوند، محتوا یا حجم فایل معتبر نیست.' );
        }
        $detected = finfo_file( $finfo, $upload['tmp_name'] );
        if ( ! is_string( $detected ) ) {
            finfo_close( $finfo );
            return array( 'error' => 'تشخیص نوع فایل ممکن نیست.' );
        }
        $allowed = false;
        if ( str_starts_with( $types[$ext], 'image/' ) ) {
            $info = @getimagesize( $upload['tmp_name'] );
            $allowed = is_array( $info ) && ( $info['mime'] ?? '' ) === $types[$ext]
                && $detected === $types[$ext];
        } elseif ( 'pdf' === $ext ) {
            $allowed = $detected === 'application/pdf' && crmb_chat_has_magic( $upload['tmp_name'], '%PDF-' );
        } elseif ( in_array( $ext, array( 'txt', 'csv' ), true ) ) {
            $allowed = in_array( $detected, array( 'text/plain', 'text/csv', 'application/csv' ), true ) &&
                ! crmb_chat_has_binary_prefix( $upload['tmp_name'] );
        } else {
            // OpenXML documents are ZIP containers. Reject arbitrary ZIP files:
            // require the Office content-types manifest and expected component.
            $allowed = in_array( $detected, array(
                'application/zip', 'application/x-zip',
                $types[$ext],
            ), true ) && crmb_chat_valid_openxml( $upload['tmp_name'], $ext );
        }
        if ( ! $allowed ) {
            finfo_close( $finfo );
            return array( 'error' => 'فرمت واقعی فایل با نوع مجاز مطابقت ندارد.' );
        }
        $clean[] = array(
            'name' => $name, 'tmp' => $upload['tmp_name'],
            'size' => $size, 'ext' => $ext, 'mime' => $types[$ext],
        );
    }
    finfo_close( $finfo );
    return array( 'files' => $clean );
}
function crmb_chat_clean_filename( $name ) {
    $name = basename( str_replace( '\\', '/', (string) $name ) );
    $name = preg_replace( '/[\x00-\x1F\x7F]+/u', '', $name );
    $name = trim( $name, " .\t\n\r" );
    if ( '' === $name ) { $name = 'file.bin'; }
    // Preserve UTF-8 names but cap safely at the DB limit.
    return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 220, 'UTF-8' ) : substr( $name, 0, 220 );
}
function crmb_chat_has_magic( $path, $signature ) {
    $handle = @fopen( $path, 'rb' );
    if ( ! $handle ) { return false; }
    $prefix = fread( $handle, strlen( $signature ) );
    fclose( $handle );
    return $prefix === $signature;
}
function crmb_chat_has_binary_prefix( $path ) {
    $handle = @fopen( $path, 'rb' );
    if ( ! $handle ) { return true; }
    $prefix = fread( $handle, 4096 );
    fclose( $handle );
    return str_contains( $prefix, "\0" );
}
function crmb_chat_valid_openxml( $path, $extension ) {
    if ( ! class_exists( 'ZipArchive' ) || ! crmb_chat_has_magic( $path, "PK\x03\x04" ) ) { return false; }
    $zip = new ZipArchive();
    if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) { return false; }
    $parts = array( 'docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml',
        'pptx' => 'ppt/presentation.xml' );
    $ok = false !== $zip->locateName( '[Content_Types].xml' )
        && false !== $zip->locateName( $parts[$extension] );
    $zip->close();
    return $ok;
}
/**
 * Fail closed for public document roots and symlinked directories.
 * Override CRMB_CHAT_PRIVATE_DIR in wp-config.php on nonstandard installations.
 */
function crmb_chat_private_directory() {
    $path = defined( 'CRMB_CHAT_PRIVATE_DIR' ) ? CRMB_CHAT_PRIVATE_DIR
        : dirname( rtrim( ABSPATH, '/\\' ) ) . '/.crmbarghbazar-chat-files';
    if ( ! is_string( $path ) || '' === $path || ! str_starts_with( $path, '/' ) ) { return false; }
    if ( is_link( $path ) ) { return false; }
    if ( ! is_dir( $path ) && ! @mkdir( $path, 0700, true ) ) { return false; }
    $real = realpath( $path );
    if ( ! $real || is_link( $path ) ) { return false; }
    foreach ( array( ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $public ) {
        $root = is_string( $public ) && $public !== '' ? realpath( $public ) : false;
        if ( $root && ( $real === $root || str_starts_with( $real . '/', rtrim( $root, '/' ) . '/' ) ) ) {
            return false;
        }
    }
    @chmod( $real, 0700 );
    return is_writable( $real ) && ( ( fileperms( $real ) & 0007 ) === 0 ) ? $real : false;
}
function crmb_chat_file_download_url( $id ) {
    return add_query_arg( array(
        'action' => 'crmb_chat_attachment', 'file' => (int) $id,
        '_wpnonce' => wp_create_nonce( 'crmb_chat_attachment_' . (int) $id ),
    ), admin_url( 'admin-ajax.php' ) );
}
function crmb_chat_file_public_row( $row ) {
    return array(
        'id' => (int) $row->id, 'name' => (string) $row->original_name,
        'mime' => (string) $row->mime_type, 'size' => (int) $row->size_bytes,
        'image' => str_starts_with( (string) $row->mime_type, 'image/' ),
        'url' => crmb_chat_file_download_url( (int) $row->id ),
    );
}
function crmb_chat_files_for_messages( $rows ) {
    $result = array();
    if ( ! crmb_chat_files_ready() || ! $rows ) { return $result; }
    global $wpdb;
    $ids = array_values( array_unique( array_map( static fn( $row ) => (int) $row->id, $rows ) ) );
    if ( ! $ids ) { return $result; }
    $table = crmb_chat_files_table();
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $files = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,message_id,original_name,mime_type,size_bytes FROM {$table}
         WHERE message_id IN ({$placeholders}) ORDER BY message_id,id", ...$ids
    ) );
    foreach ( $files ?: array() as $row ) {
        $result[(int) $row->message_id][] = crmb_chat_file_public_row( $row );
    }
    return $result;
}
/** Stages all validated files, then commits message + file metadata together. */
function crmb_chat_send_files( $peer, $body, $input ) {
    if ( ! crmb_chat_ready() || ! crmb_chat_files_ready() || ! crmb_chat_peer( $peer ) ) {
        return array( 'error' => 'ارسال فایل در سرور آماده نیست.' );
    }
    $files = crmb_chat_file_input( $input );
    $checked = crmb_chat_check_files( $files );
    if ( isset( $checked['error'] ) ) { return $checked; }
    if ( ! $checked['files'] ) { return array( 'error' => 'فایلی انتخاب نشده است.' ); }
    if ( ! is_string( $body ) || ! preg_match( '//u', $body ) ) {
        return array( 'error' => 'متن پیام معتبر نیست.' );
    }
    $text = trim( sanitize_textarea_field( $body ) );
    if ( strlen( $text ) > 10000 ) { return array( 'error' => 'متن پیام بیش از حد طولانی است.' ); }
    $dir = crmb_chat_private_directory();
    if ( ! $dir ) { return array( 'error' => 'محل نگهداری خصوصی فایل‌ها در هاست آماده نیست.' ); }
    $staged = array();
    foreach ( $checked['files'] as $file ) {
        if ( ! is_uploaded_file( $file['tmp'] ) ) {
            crmb_chat_cleanup_files( $staged );
            return array( 'error' => 'فایل باید مستقیماً از مرورگر آپلود شود.' );
        }
        $file['stored'] = bin2hex( random_bytes( 24 ) ) . '.' . $file['ext'];
        $file['path'] = $dir . '/' . $file['stored'];
        if ( ! move_uploaded_file( $file['tmp'], $file['path'] ) ) {
            crmb_chat_cleanup_files( $staged );
            return array( 'error' => 'نگهداری فایل در فضای خصوصی انجام نشد.' );
        }
        @chmod( $file['path'], 0600 );
        $staged[] = $file;
    }
    global $wpdb;
    $messages = crmb_work_tables()['messages'];
    $attachments = crmb_chat_files_table();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
        crmb_chat_cleanup_files( $staged );
        return array( 'error' => 'ثبت پیام انجام نشد.' );
    }
    $uid = get_current_user_id();
    $now = gmdate( 'Y-m-d H:i:s' );
    $ok = $wpdb->insert( $messages, array(
        'sender_id' => $uid, 'recipient_id' => (int) $peer,
        'subject' => 'پیام گفتگو', 'body' => $text, 'created_utc' => $now,
    ) );
    $message_id = (int) $wpdb->insert_id;
    if ( false !== $ok && $message_id ) {
        foreach ( $staged as $file ) {
            if ( false === $wpdb->insert( $attachments, array(
                'message_id' => $message_id, 'uploader_id' => $uid,
                'original_name' => $file['name'], 'stored_name' => $file['stored'],
                'mime_type' => $file['mime'], 'size_bytes' => $file['size'],
                'created_utc' => $now,
            ) ) ) { $ok = false; break; }
        }
    } else { $ok = false; }
    if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) {
        $wpdb->query( 'ROLLBACK' );
        crmb_chat_cleanup_files( $staged );
        return array( 'error' => 'ثبت فایل یا پیام انجام نشد.' );
    }
    if ( function_exists( 'do_action' ) ) { do_action( 'crmb_message_sent', $message_id, (int) $peer, $uid ); }
    return array( 'status' => 'sent', 'message_id' => $message_id );
}
function crmb_chat_cleanup_files( $files ) {
    foreach ( $files as $file ) {
        if ( ! empty( $file['path'] ) && is_file( $file['path'] ) ) { @unlink( $file['path'] ); }
    }
}
/** Download URL is useless unless authenticated requester belongs to the conversation. */
function crmb_chat_file_owned_row( $id ) {
    if ( ! crmb_chat_ready() || ! crmb_chat_files_ready() || ! $id ) { return null; }
    global $wpdb;
    $t = crmb_chat_files_table();
    $messages = crmb_work_tables()['messages'];
    $uid = get_current_user_id();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT f.* FROM {$t} f INNER JOIN {$messages} m ON m.id=f.message_id
         WHERE f.id=%d AND (m.sender_id=%d OR m.recipient_id=%d) LIMIT 1", (int) $id, $uid, $uid
    ) );
}
function crmb_chat_file_download() {
    if ( ! crmb_chat_ready() ) { status_header( 403 ); exit; }
    $id = isset( $_GET['file'] ) ? crmb_work_id( $_GET, 'file' ) : 0;
    if ( ! $id || ! isset( $_GET['_wpnonce'] ) || ! is_string( $_GET['_wpnonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
            'crmb_chat_attachment_' . $id ) ) {
        status_header( 403 ); exit;
    }
    $row = crmb_chat_file_owned_row( $id );
    if ( ! $row || ! preg_match( '/^[a-f0-9]{48}\.(?:jpe?g|png|gif|webp|pdf|txt|csv|docx|xlsx|pptx)$/D', $row->stored_name ) ) {
        status_header( 404 ); exit;
    }
    $directory = crmb_chat_private_directory();
    if ( ! $directory ) { status_header( 503 ); exit; }
    $path = $directory . '/' . $row->stored_name;
    if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { status_header( 404 ); exit; }
    $mime = (string) $row->mime_type;
    $types = crmb_chat_file_types();
    if ( ! in_array( $mime, array_values( $types ), true ) ) { status_header( 404 ); exit; }
    $inline = str_starts_with( $mime, 'image/' );
    if ( headers_sent() ) { status_header( 500 ); exit; }
    nocache_headers();
    header( 'Cache-Control: private, no-store, max-age=0' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: DENY' );
    header( 'Content-Security-Policy: sandbox' );
    header( 'Content-Type: ' . $mime );
    header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' )
        . '; filename="chat-file"; filename*=UTF-8\'\'' . rawurlencode( $row->original_name ) );
    header( 'Content-Length: ' . (string) filesize( $path ) );
    readfile( $path );
    exit;
}
add_action( 'wp_ajax_crmb_chat_attachment', 'crmb_chat_file_download' );
