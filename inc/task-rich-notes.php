<?php
/**
 * Rich progress notes and private task-event images.
 * Existing event rows are unchanged; rich entries use distinct event types.
 */
defined( 'ABSPATH' ) || exit;

function crmb_task_notes_table() {
    global $wpdb;
    return $wpdb->prefix . 'crmb_task_note_images';
}
function crmb_task_notes_ready() {
    return '1' === get_option( 'crmb_task_notes_schema_v1' );
}
function crmb_task_notes_install() {
    if ( crmb_task_notes_ready() || ! crmb_progress_ready() ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = crmb_task_notes_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        mime varchar(32) NOT NULL,
        image_data longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY event_id (event_id)
    ) ENGINE=InnoDB {$charset};" );
    if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
        update_option( 'crmb_task_notes_schema_v1', '1', false );
    }
}
add_action( 'init', 'crmb_task_notes_install', 12 );

/**
 * Register the table tool with the one CRM editor, without any TinyMCE external
 * plugin URL. This avoids failures when the host/CDN doesn't serve theme JS.
 */
function crmb_task_table_mce_init( $settings, $editor_id ) {
    if ( 'crmbtaskeditor' !== $editor_id ) { return $settings; }
    $plugins = isset( $settings['plugins'] ) ? trim( (string) $settings['plugins'] ) : '';
    $names = preg_split( '/[\s,]+/', $plugins, -1, PREG_SPLIT_NO_EMPTY );
    if ( ! in_array( 'crmbtasktable', $names, true ) ) {
        $names[] = 'crmbtasktable';
    }
    $settings['plugins'] = implode( ',', $names );
    return $settings;
}
add_filter( 'tiny_mce_before_init', 'crmb_task_table_mce_init', 10, 2 );

/** Called after TinyMCE loads but before WordPress creates its editors. */
function crmb_task_table_mce_inline( $editors ) {
    if ( ! isset( $editors['crmbtaskeditor'] ) ) { return; }
    ?>
    <script id="crmb-task-table-inline">
    (function () {
        "use strict";
        if (!window.tinymce || !tinymce.PluginManager) return;
        tinymce.PluginManager.add("crmbtasktable", function (editor) {
            function tableHtml(rows, columns) {
                var head = "";
                var body = "";
                for (var col = 1; col <= columns; col++) {
                    head += "<th>ستون " + col + "</th>";
                }
                for (var row = 0; row < rows; row++) {
                    body += "<tr>";
                    for (var cell = 0; cell < columns; cell++) {
                        body += "<td>متن</td>";
                    }
                    body += "</tr>";
                }
                return "<table><thead><tr>" + head + "</tr></thead><tbody>" +
                    body + "</tbody></table><p></p>";
            }
            editor.addButton("crmbtasktable", {
                text: "جدول",
                tooltip: "درج جدول در گزارش پیشرفت",
                onclick: function () {
                    editor.windowManager.open({
                        title: "درج جدول",
                        body: [
                            {type: "textbox", name: "rows", label: "تعداد ردیف", value: "3"},
                            {type: "textbox", name: "columns", label: "تعداد ستون", value: "3"}
                        ],
                        onsubmit: function (event) {
                            var rows = Number(event.data.rows);
                            var columns = Number(event.data.columns);
                            if (!Number.isInteger(rows) || !Number.isInteger(columns) ||
                                rows < 1 || rows > 10 || columns < 1 || columns > 8) {
                                editor.windowManager.alert("ردیف باید بین ۱ تا ۱۰ و ستون بین ۱ تا ۸ باشد.");
                                return false;
                            }
                            editor.focus();
                            editor.insertContent(tableHtml(rows, columns));
                        }
                    });
                }
            });
        });
    })();
    </script>
    <?php
}
add_action( 'wp_tiny_mce_init', 'crmb_task_table_mce_inline', 10, 1 );

/** Permit formatting and basic HTML tables, but no inline src, scripts or arbitrary CSS. */
function crmb_task_note_html( $html ) {
    if ( ! is_string( $html ) ) { return ''; }
    $allowed = array(
        'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(),
        'em' => array(), 'i' => array(), 'u' => array(), 's' => array(),
        'ul' => array(), 'ol' => array(), 'li' => array(),
        'h2' => array(), 'h3' => array(), 'h4' => array(),
        'blockquote' => array(), 'hr' => array(), 'pre' => array(), 'code' => array(),
        'a' => array( 'href' => true, 'title' => true ),
        'table' => array(), 'thead' => array(), 'tbody' => array(), 'tfoot' => array(),
        'tr' => array(),
        'th' => array( 'colspan' => true, 'rowspan' => true, 'scope' => true ),
        'td' => array( 'colspan' => true, 'rowspan' => true ),
    );
    return wp_kses( $html, $allowed, array( 'http', 'https', 'mailto' ) );
}
function crmb_task_note_is_manager( $task_id ) {
    return crmb_work_manage_team()
        || ( function_exists( 'crmb_seo_can_manage_task' ) && crmb_seo_can_manage_task( $task_id ) );
}

/**
 * All-or-nothing history and attachments, with task authorization rechecked
 * under the same InnoDB lock used by timer/status transitions.
 */
function crmb_task_rich_save( $id, $type, $raw_body, $files = array(), &$created_event_id = null ) {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() ) { return 'denied'; }
    if ( ! crmb_task_notes_ready() ) { return 'error'; }
    if ( ! $id || ! in_array( $type, array( 'rich_note', 'rich_blocker', 'manager_note' ), true )
        || ! is_string( $raw_body ) || strlen( $raw_body ) > 30000 ) { return 'invalid'; }

    $is_manager = crmb_task_note_is_manager( $id );
    if ( 'manager_note' === $type && ! $is_manager ) { return 'denied'; }
    $body = crmb_task_note_html( $raw_body );
    if ( strlen( $body ) > 30000 ) { return 'invalid'; }

    // Task images use their own validated upload flow, independent from
    // daily-report limits. AJAX uploads individual images after this note.
    $images = crmb_task_photo_files( $files );
    if ( is_wp_error( $images ) ) { return 'image_error'; }
    if ( '' === trim( wp_strip_all_tags( $body ) ) && ! $images ) { return 'invalid'; }

    global $wpdb;
    $table = crmb_task_notes_table();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $task = crmb_progress_task( $id, true );
    if ( ! $task ) { return crmb_progress_fail( 'denied' ); }
    if ( ! in_array( $task->status, array( 'todo', 'doing', 'paused' ), true ) ) {
        return crmb_progress_fail( 'locked' );
    }
    $owner = (int) $task->assigned_to === get_current_user_id();
    if ( 'manager_note' === $type ? ! $is_manager : ! $owner ) {
        return crmb_progress_fail( 'denied' );
    }
    if ( ! crmb_progress_event( $id, $type, $body ) ) { return crmb_progress_fail(); }
    $event_id = (int) $wpdb->insert_id;
    if ( ! $event_id ) { return crmb_progress_fail(); }
    foreach ( $images as $image ) {
        if ( false === $wpdb->insert( $table, $image + array( 'event_id' => $event_id ) ) ) {
            return crmb_progress_fail();
        }
    }
    $result = crmb_progress_commit();
    if ( 'saved' === $result ) { $created_event_id = $event_id; }
    if ( 'saved' === $result && 'manager_note' === $type && function_exists( 'do_action' ) ) {
        do_action( 'crmb_manager_guidance', $id, (int) $task->assigned_to, get_current_user_id(), $event_id );
    }
    return $result;
}

/** Return a gallery map using one database query per visible history page. */
function crmb_task_note_gallery( $events ) {
    if ( ! crmb_task_notes_ready() || ! is_array( $events ) || ! $events ) { return array(); }
    $ids = array_values( array_filter( array_map( static function ( $event ) {
        return isset( $event->id ) ? absint( $event->id ) : 0;
    }, $events ) ) );
    if ( ! $ids ) { return array(); }
    global $wpdb;
    $table = crmb_task_notes_table();
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,event_id FROM {$table} WHERE event_id IN ({$placeholders}) ORDER BY id",
        ...$ids
    ) );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { return array(); }
    $gallery = array();
    foreach ( $rows as $row ) {
        $gallery[(int) $row->event_id][] = (int) $row->id;
    }
    return $gallery;
}
/** Permission-checked, private image response. No public media library URLs. */
function crmb_task_note_serve_image() {
    if ( ! is_user_logged_in() || ! crmb_progress_ready() || ! crmb_task_notes_ready() ) {
        wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) );
    }
    $image_id = crmb_work_id( $_GET, 'image' );
    global $wpdb;
    $t = crmb_progress_tables();
    $table = crmb_task_notes_table();
    $meta = $wpdb->get_row( $wpdb->prepare(
        "SELECT n.event_id,n.mime,e.task_id FROM {$table} n
         INNER JOIN {$t['events']} e ON e.id=n.event_id WHERE n.id=%d",
        $image_id
    ) );
    if ( ! $meta || ! crmb_progress_task( (int) $meta->task_id )
        || ! in_array( $meta->mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
        wp_die( 'تصویر در دسترس نیست.', '', array( 'response' => 404 ) );
    }
    $encoded = $wpdb->get_var( $wpdb->prepare(
        "SELECT image_data FROM {$table} WHERE id=%d AND event_id=%d", $image_id, $meta->event_id
    ) );
    $bytes = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
    if ( false === $bytes ) { wp_die( 'تصویر در دسترس نیست.', '', array( 'response' => 404 ) ); }
    nocache_headers();
    header( 'Cache-Control: private, no-store, max-age=0' );
    header( 'Content-Type: ' . $meta->mime );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Disposition: inline' );
    header( "Content-Security-Policy: default-src 'none'; sandbox" );
    echo $bytes;
    exit;
}
add_action( 'admin_post_crmb_task_note_image', 'crmb_task_note_serve_image' );

function crmb_task_note_images_markup( $image_ids ) {
    if ( ! $image_ids ) { return; }
    echo '<div class="crmb-task-note-gallery">';
    foreach ( $image_ids as $image_id ) {
        $url = add_query_arg(
            array( 'action' => 'crmb_task_note_image', 'image' => $image_id ),
            admin_url( 'admin-post.php' )
        );
        echo '<a class="crmb-task-gallery-trigger" data-crmb-task-image href="' . esc_url( $url ) . '" aria-label="نمایش تصویر پیوست در همین صفحه">';
        echo '<img loading="lazy" src="' . esc_url( $url ) . '" alt="تصویر پیوست گزارش پیشرفت"></a>';
    }
    echo '</div>';
}
