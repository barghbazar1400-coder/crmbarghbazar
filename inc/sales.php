<?php
/**
 * Offline sales desk. Products are a local WooCommerce CSV snapshot; no remote
 * shop API, webhooks, inventory reservations or automatic orders are used.
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    $items['sales'] = array( 'label' => 'میز فروش' );
    return $items;
} );

function crmb_sales_access() {
    return is_user_logged_in() && crmbarghbazar_can_view_section( 'sales' );
}
function crmb_sales_admin() { return current_user_can( 'manage_options' ); }
function crmb_sales_tables() {
    global $wpdb;
    $p = $wpdb->prefix . 'crmb_sales_';
    return array(
        'products' => $p . 'products', 'customers' => $p . 'customers',
        'opportunities' => $p . 'opportunities', 'quotes' => $p . 'quotes',
        'lines' => $p . 'quote_lines', 'history' => $p . 'quote_history',
    );
}
function crmb_sales_ready() { return '1' === get_option( 'crmb_sales_schema_v1' ); }
function crmb_sales_install() {
    if ( crmb_sales_ready() ) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = crmb_sales_tables();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$t['products']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        external_key varchar(190) NOT NULL,
        sku varchar(190) NOT NULL DEFAULT '',
        title varchar(240) NOT NULL,
        price_toman bigint(20) unsigned NOT NULL DEFAULT 0,
        stock_status varchar(24) NOT NULL DEFAULT 'unknown',
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY external_key (external_key),
        KEY sku (sku),
        KEY title (title(180))
    ) $charset;" );
    dbDelta( "CREATE TABLE {$t['customers']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        owner_id bigint(20) unsigned NOT NULL,
        name varchar(180) NOT NULL,
        phone varchar(40) NOT NULL DEFAULT '',
        email varchar(190) NOT NULL DEFAULT '',
        company varchar(180) NOT NULL DEFAULT '',
        notes text NOT NULL,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY owner_id (owner_id),
        KEY phone (phone)
    ) $charset;" );
    dbDelta( "CREATE TABLE {$t['opportunities']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) unsigned NOT NULL,
        owner_id bigint(20) unsigned NOT NULL,
        title varchar(180) NOT NULL,
        stage varchar(24) NOT NULL DEFAULT 'lead',
        followup_day date DEFAULT NULL,
        notes text NOT NULL,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY owner_followup (owner_id,followup_day),
        KEY customer_id (customer_id)
    ) $charset;" );
    dbDelta( "CREATE TABLE {$t['quotes']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) unsigned NOT NULL,
        owner_id bigint(20) unsigned NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'draft',
        notes text NOT NULL,
        subtotal_toman bigint(20) unsigned NOT NULL DEFAULT 0,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY owner_id (owner_id),
        KEY customer_id (customer_id)
    ) $charset;" );
    dbDelta( "CREATE TABLE {$t['lines']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        quote_id bigint(20) unsigned NOT NULL,
        product_id bigint(20) unsigned NOT NULL,
        product_title varchar(240) NOT NULL,
        sku varchar(190) NOT NULL DEFAULT '',
        quantity decimal(12,2) NOT NULL,
        unit_price_toman bigint(20) unsigned NOT NULL,
        total_toman bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (id),
        KEY quote_id (quote_id)
    ) $charset;" );
    dbDelta( "CREATE TABLE {$t['history']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        quote_id bigint(20) unsigned NOT NULL,
        actor_id bigint(20) unsigned NOT NULL,
        event_type varchar(24) NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY quote_id (quote_id)
    ) $charset;" );
    foreach ( $t as $table ) {
        // Table names are fixed by our own code; LIKE is used only for existence.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            return;
        }
    }
    update_option( 'crmb_sales_schema_v1', '1', false );
}
add_action( 'init', 'crmb_sales_install', 12 );

function crmb_sales_scope( $alias = '' ) {
    if ( crmb_sales_admin() ) { return ''; }
    global $wpdb;
    $field = $alias ? $alias . '.owner_id' : 'owner_id';
    return $wpdb->prepare( " AND {$field}=%d", get_current_user_id() );
}
function crmb_sales_customer( $id ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() || ! $id ) { return null; }
    global $wpdb; $t = crmb_sales_tables();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['customers']} WHERE id=%d" . crmb_sales_scope(), $id
    ) );
}
function crmb_sales_quote( $id ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() || ! $id ) { return null; }
    global $wpdb; $t = crmb_sales_tables();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['quotes']} WHERE id=%d" . crmb_sales_scope(), $id
    ) );
}
function crmb_sales_stages() {
    return array( 'lead' => 'سرنخ جدید', 'contacted' => 'تماس انجام شد', 'quoted' => 'پیش‌فاکتور ارسال شد', 'won' => 'موفق', 'lost' => 'ناموفق' );
}
function crmb_sales_quote_states() {
    return array( 'draft' => 'پیش‌نویس', 'sent' => 'ارسال‌شده', 'accepted' => 'تأیید مشتری', 'rejected' => 'ردشده' );
}
function crmb_sales_clean( $data, $key, $max = 180, $multiline = false ) {
    $value = $data[$key] ?? '';
    if ( ! is_string( $value ) ) { return ''; }
    $value = trim( $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );
    return strlen( $value ) <= $max ? $value : '';
}
function crmb_sales_id( $data, $key ) {
    $value = $data[$key] ?? null;
    return is_scalar( $value ) && preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ? absint( $value ) : 0;
}
function crmb_sales_customer_save( $data ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() ) { return 'denied'; }
    $id = crmb_sales_id( $data, 'customer_id' );
    $name = crmb_sales_clean( $data, 'name' );
    $phone = crmb_sales_clean( $data, 'phone', 40 );
    $email = crmb_sales_clean( $data, 'email', 190 );
    $company = crmb_sales_clean( $data, 'company' );
    $notes = crmb_sales_clean( $data, 'notes', 5000, true );
    if ( ! $name || ( $email && ! is_email( $email ) ) || ( $phone && ! preg_match( '/^[+0-9() .\-]{7,40}$/', $phone ) ) ) {
        return 'invalid';
    }
    global $wpdb; $t = crmb_sales_tables(); $now = gmdate( 'Y-m-d H:i:s' );
    $values = compact( 'name', 'phone', 'email', 'company', 'notes' );
    $values['updated_utc'] = $now;
    if ( $id ) {
        if ( ! crmb_sales_customer( $id ) ) { return 'denied'; }
        $result = $wpdb->update( $t['customers'], $values, array( 'id' => $id ) );
    } else {
        $values['created_utc'] = $now;
        $values['owner_id'] = get_current_user_id();
        $result = $wpdb->insert( $t['customers'], $values );
    }
    return false === $result ? 'error' : 'saved';
}
function crmb_sales_opportunity_save( $data ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() ) { return 'denied'; }
    $customer_id = crmb_sales_id( $data, 'customer_id' );
    $title = crmb_sales_clean( $data, 'title' );
    $stage = crmb_sales_clean( $data, 'stage', 24 ) ?: 'lead';
    $notes = crmb_sales_clean( $data, 'notes', 4000, true );
    $raw_followup = crmb_sales_clean( $data, 'followup_day', 30 );
    $followup_day = $raw_followup ? crmb_leave_parse_day( $raw_followup ) : null;
    if ( ! crmb_sales_customer( $customer_id ) || ! $title || ! isset( crmb_sales_stages()[$stage] ) || false === $followup_day ) {
        return 'invalid';
    }
    global $wpdb; $t = crmb_sales_tables(); $now = gmdate( 'Y-m-d H:i:s' );
    $id = crmb_sales_id( $data, 'opportunity_id' );
    $values = compact( 'customer_id', 'title', 'stage', 'notes', 'followup_day' );
    $values['updated_utc'] = $now;
    if ( $id ) {
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['opportunities']} WHERE id=%d" . crmb_sales_scope(), $id ) );
        if ( ! $old ) { return 'denied'; }
        $result = $wpdb->update( $t['opportunities'], $values, array( 'id' => $id ) );
    } else {
        $values['owner_id'] = get_current_user_id();
        $values['created_utc'] = $now;
        $result = $wpdb->insert( $t['opportunities'], $values );
    }
    return false === $result ? 'error' : 'saved';
}
/** No client-submitted price is accepted: lines snapshot the imported catalog. */
function crmb_sales_quote_create( $data ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() ) { return 'denied'; }
    $customer = crmb_sales_customer( crmb_sales_id( $data, 'customer_id' ) );
    $ids = $data['product_ids'] ?? null;
    $quantities = $data['quantities'] ?? null;
    $notes = crmb_sales_clean( $data, 'notes', 4000, true );
    if ( ! $customer || ! is_array( $ids ) || ! is_array( $quantities ) ||
        ! count( $ids ) || count( $ids ) !== count( $quantities ) || count( $ids ) > 30 ) { return 'invalid'; }
    global $wpdb; $t = crmb_sales_tables(); $lines = array(); $subtotal = 0;
    foreach ( $ids as $index => $value ) {
        $id = crmb_sales_id( array( 'product' => $value ), 'product' );
        $raw_quantity = $quantities[$index] ?? null;
        if ( ! $id || ! is_string( $raw_quantity ) ||
            ! preg_match( '/^(?:[1-9][0-9]{0,4}(?:\.[0-9]{1,2})?|0\.(?:0[1-9]|[1-9][0-9]?))$/D', $raw_quantity ) ) {
            return 'invalid';
        }
        $product = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['products']} WHERE id=%d AND price_toman>0", $id ) );
        if ( ! $product || $wpdb->last_error ) { return 'invalid'; }
        $price = (int) $product->price_toman;
        $line_total = (int) round( $price * (float) $raw_quantity );
        if ( $price < 1 || $price > 100000000000 || $line_total < 1 || $subtotal > 1000000000000 - $line_total ) { return 'invalid'; }
        $subtotal += $line_total;
        $lines[] = array(
            'product_id' => $id, 'product_title' => $product->title, 'sku' => $product->sku,
            'quantity' => $raw_quantity, 'unit_price_toman' => $price, 'total_toman' => $line_total,
        );
    }
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $now = gmdate( 'Y-m-d H:i:s' );
    $ok = $wpdb->insert( $t['quotes'], array(
        'customer_id' => $customer->id, 'owner_id' => get_current_user_id(),
        'status' => 'draft', 'notes' => $notes, 'subtotal_toman' => $subtotal,
        'created_utc' => $now, 'updated_utc' => $now,
    ) );
    if ( false === $ok || ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
    $quote_id = (int) $wpdb->insert_id;
    foreach ( $lines as $line ) {
        $line['quote_id'] = $quote_id;
        if ( false === $wpdb->insert( $t['lines'], $line ) ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
    }
    if ( false === $wpdb->insert( $t['history'], array(
        'quote_id' => $quote_id, 'actor_id' => get_current_user_id(),
        'event_type' => 'created', 'created_utc' => $now,
    ) ) || false === $wpdb->query( 'COMMIT' ) ) {
        $wpdb->query( 'ROLLBACK' ); return 'error';
    }
    return $quote_id;
}
function crmb_sales_quote_transition( $data ) {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() ) { return 'denied'; }
    $quote = crmb_sales_quote( crmb_sales_id( $data, 'quote_id' ) );
    $next = crmb_sales_clean( $data, 'status', 20 );
    $allowed = array( 'draft' => array( 'sent' ), 'sent' => array( 'accepted', 'rejected' ), 'accepted' => array(), 'rejected' => array() );
    if ( ! $quote ) { return 'denied'; }
    if ( ! in_array( $next, $allowed[$quote->status] ?? array(), true ) ) { return 'invalid'; }
    global $wpdb; $t = crmb_sales_tables(); $now = gmdate( 'Y-m-d H:i:s' );
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $result = $wpdb->update( $t['quotes'], array( 'status' => $next, 'updated_utc' => $now ), array( 'id' => $quote->id, 'status' => $quote->status ) );
    if ( 1 !== $result || false === $wpdb->insert( $t['history'], array(
        'quote_id' => $quote->id, 'actor_id' => get_current_user_id(),
        'event_type' => $next, 'created_utc' => $now,
    ) ) || false === $wpdb->query( 'COMMIT' ) ) {
        $wpdb->query( 'ROLLBACK' ); return 'error';
    }
    return 'saved';
}
function crmb_sales_csv_header( $header ) {
    $map = array(
        'id' => array( 'id', 'شناسه', 'شناسه محصول' ),
        'sku' => array( 'sku', 'شناسه کالا', 'کد محصول' ),
        'title' => array( 'name', 'نام', 'نام محصول' ),
        'type' => array( 'type', 'نوع' ),
        'regular' => array( 'regular price', 'regular_price', 'قیمت عادی', 'قیمت اصلی' ),
        'sale' => array( 'sale price', 'sale_price', 'قیمت فروش ویژه', 'قیمت ویژه' ),
        'stock' => array( 'in stock?', 'in stock', 'در انبار؟', 'موجود؟', 'وضعیت موجودی' ),
    );
    $normalized = array();
    foreach ( $header as $i => $name ) {
        $normalized[$i] = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $name ) ) );
    }
    $found = array();
    foreach ( $map as $key => $candidates ) {
        foreach ( $normalized as $i => $name ) {
            if ( in_array( $name, $candidates, true ) ) { $found[$key] = $i; break; }
        }
    }
    return $found;
}
function crmb_sales_csv_price( $value, $unit ) {
    $value = strtr( trim( (string) $value ), array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) );
    $value = str_replace( array( ',', ' ', "\xC2\xA0" ), '', $value );
    if ( ! preg_match( '/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/D', $value ) ) { return null; }
    $price = (float) $value / ( 'rial' === $unit ? 10 : 1 );
    return $price > 100000000000 ? null : (int) round( $price );
}
/** Atomic import: no existing product is removed when omitted from a CSV. */
function crmb_sales_catalog_import( $files, $data ) {
    if ( ! crmb_sales_admin() || ! crmb_sales_ready() ) { return 'denied'; }
    $file = $files['catalog'] ?? null;
    $unit = crmb_sales_clean( $data, 'price_unit', 10 );
    if ( ! in_array( $unit, array( 'toman', 'rial' ), true ) || ! is_array( $file ) ||
        ( $file['error'] ?? 1 ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) ||
        ! is_uploaded_file( $file['tmp_name'] ) || (int) ( $file['size'] ?? 0 ) < 1 ||
        (int) $file['size'] > 8 * 1024 * 1024 ) { return 'invalid_file'; }
    $handle = fopen( $file['tmp_name'], 'rb' );
    if ( ! $handle ) { return 'invalid_file'; }
    $header_line = fgets( $handle, 32768 );
    if ( ! is_string( $header_line ) ) { fclose( $handle ); return 'invalid_file'; }
    $counts = array();
    foreach ( array( ',', ';', "\t" ) as $delimiter ) { $counts[$delimiter] = count( str_getcsv( $header_line, $delimiter ) ); }
    arsort( $counts );
    $delimiter = array_key_first( $counts );
    rewind( $handle );
    $header = fgetcsv( $handle, 32768, $delimiter );
    $columns = crmb_sales_csv_header( is_array( $header ) ? $header : array() );
    if ( ! isset( $columns['title'] ) || ( ! isset( $columns['id'] ) && ! isset( $columns['sku'] ) ) ||
        ( ! isset( $columns['regular'] ) && ! isset( $columns['sale'] ) ) ) { fclose( $handle ); return 'bad_columns'; }
    $read = 0; $skipped = 0; $rows = array();
    while ( ( $row = fgetcsv( $handle, 32768, $delimiter ) ) !== false ) {
        if ( count( $row ) === 1 && trim( (string) $row[0] ) === '' ) { continue; }
        ++$read;
        if ( $read > 5000 ) { fclose( $handle ); return 'too_many'; }
        $cell = static function ( $key ) use ( $columns, $row ) {
            return isset( $columns[$key] ) ? trim( (string) ( $row[$columns[$key]] ?? '' ) ) : '';
        };
        $type = strtolower( $cell( 'type' ) );
        if ( $type && ! in_array( $type, array( 'simple', 'variation', 'variable' ), true ) ) { ++$skipped; continue; }
        $name = sanitize_text_field( $cell( 'title' ) );
        $id = $cell( 'id' ); $sku = sanitize_text_field( $cell( 'sku' ) );
        $key = preg_match( '/^[1-9][0-9]*$/D', $id ) ? 'id:' . $id : ( $sku ? 'sku:' . $sku : '' );
        $price_raw = $cell( 'sale' );
        if ( '' === $price_raw ) { $price_raw = $cell( 'regular' ); }
        $price = crmb_sales_csv_price( $price_raw, $unit );
        if ( ! $key || ! $name || strlen( $name ) > 240 || strlen( $sku ) > 190 ||
            ! preg_match( '//u', $name ) || null === $price ) { ++$skipped; continue; }
        // Products with no price remain visible as catalog entries, but cannot be quoted.
        $stock = strtolower( $cell( 'stock' ) );
        $status = in_array( $stock, array( '1', 'yes', 'instock', 'موجود' ), true ) ? 'instock' :
            ( in_array( $stock, array( '0', 'no', 'outofstock', 'ناموجود' ), true ) ? 'outofstock' : 'unknown' );
        $rows[$key] = array(
            'external_key' => $key, 'sku' => $sku, 'title' => $name,
            'price_toman' => $price, 'stock_status' => $status, 'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
        );
    }
    fclose( $handle );
    if ( ! $rows ) { return 'empty_catalog'; }
    global $wpdb; $t = crmb_sales_tables();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $created = 0; $updated = 0;
    foreach ( $rows as $values ) {
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['products']} WHERE external_key=%s", $values['external_key'] ) );
        if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
        $result = $existing ?
            $wpdb->update( $t['products'], $values, array( 'id' => (int) $existing ) ) :
            $wpdb->insert( $t['products'], $values );
        if ( false === $result ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
        if ( $existing ) { ++$updated; } else { ++$created; }
    }
    if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return 'error'; }
    update_option( 'crmb_sales_last_import', array(
        'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'processed' => $read,
        'unit' => $unit, 'at_utc' => gmdate( 'Y-m-d H:i:s' ),
    ), false );
    return 'imported';
}
function crmb_sales_handle_post() {
    if ( ! crmb_sales_access() ) { wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) ); }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'روش درخواست مجاز نیست.', '', array( 'response' => 405 ) ); }
    check_admin_referer( 'crmb_sales', 'crmb_sales_nonce' );
    $data = wp_unslash( $_POST );
    $task = crmb_sales_clean( $data, 'task', 40 );
    $result = 'invalid';
    $section = 'sales';
    if ( 'save_customer' === $task ) { $result = crmb_sales_customer_save( $data ); $section = 'customers'; }
    elseif ( 'save_opportunity' === $task ) { $result = crmb_sales_opportunity_save( $data ); $section = 'opportunities'; }
    elseif ( 'create_quote' === $task ) { $result = crmb_sales_quote_create( $data ); $section = 'quotes'; }
    elseif ( 'quote_status' === $task ) { $result = crmb_sales_quote_transition( $data ); $section = 'quotes'; }
    elseif ( 'import_catalog' === $task ) { $result = crmb_sales_catalog_import( $_FILES, $data ); $section = 'catalog'; }
    $args = array( 'crm_section' => 'sales', 'sales_notice' => is_int( $result ) ? 'saved' : $result );
    if ( is_int( $result ) ) {
        $args['sales_view'] = 'quote';
        $args['quote'] = $result;
    } elseif ( 'quote_status' === $task ) {
        $args['sales_view'] = 'quote';
        $args['quote'] = crmb_sales_id( $data, 'quote_id' );
    } else {
        $args['sales_anchor'] = $section;
    }
    wp_safe_redirect( add_query_arg( $args, home_url( '/main/' ) ) );
    exit;
}
add_action( 'admin_post_crmb_sales', 'crmb_sales_handle_post' );
function crmb_sales_form_fields( $task ) {
    echo '<input type="hidden" name="action" value="crmb_sales"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
    wp_nonce_field( 'crmb_sales', 'crmb_sales_nonce' );
}
function crmb_sales_catalog_ajax() {
    if ( ! crmb_sales_access() || ! crmb_sales_ready() ) { wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 ); }
    check_ajax_referer( 'crmb_sales_catalog', 'nonce' );
    $term = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    if ( strlen( $term ) < 2 || strlen( $term ) > 240 ) { wp_send_json_success( array() ); }
    global $wpdb; $t = crmb_sales_tables();
    $like = '%' . $wpdb->esc_like( $term ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,title,sku,price_toman,stock_status FROM {$t['products']} WHERE price_toman>0 AND (title LIKE %s OR sku LIKE %s) ORDER BY title,id LIMIT 15", $like, $like
    ) );
    if ( $wpdb->last_error ) { wp_send_json_error( array( 'message' => 'خطای دریافت محصولات.' ), 500 ); }
    wp_send_json_success( $rows ?: array() );
}
add_action( 'wp_ajax_crmb_sales_catalog', 'crmb_sales_catalog_ajax' );
