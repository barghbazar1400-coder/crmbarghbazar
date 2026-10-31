<?php
/**
 * Isolated sales regression checks using PDO SQLite.
 * Execute from the repository root: php tests/sales.php
 * This does not replace a WordPress/MySQL upload test.
 */
define( 'ABSPATH', __DIR__ );
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "pdo_sqlite unavailable; sales tests not executed\n" ); exit( 2 ); }
$logged = true; $admin = false; $sales = true; $uid = 7; $checks = 0;
function add_filter( $name, $callable ) {}
function add_action( $name, $callable, $priority = 10 ) {}
function is_user_logged_in() { global $logged; return $logged; }
function current_user_can( $cap ) { global $admin; return $cap === 'manage_options' && $admin; }
function crmbarghbazar_can_view_section( $section ) { global $sales, $admin; return $section === 'sales' && ( $sales || $admin ); }
function get_current_user_id() { global $uid; return $uid; }
function user_can( $user, $cap ) { return in_array( $cap, array( 'crm_menu_sales', 'manage_options' ), true ) && isset( $user->ID ); }
function get_userdata( $id ) { return in_array( $id, array( 7, 8, 9 ), true ) ? (object) array( 'ID' => $id, 'display_name' => 'Seller #' . $id ) : false; }
function get_option( $key, $default = false ) { return in_array( $key, array( 'crmb_sales_schema_v1', 'crmb_sales_ops_schema_v1' ), true ) ? '1' : $default; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function crmb_leave_parse_day( $day ) { return $day === '1405-08-10' ? '2026-11-01' : false; }
function check( $expected, $actual ) { global $checks; if ( $expected !== $actual ) { throw new RuntimeException( 'Check ' . ( $checks + 1 ) . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); } ++$checks; }

class SalesTestDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public PDO $db;
    function __construct() {
        $this->db = new PDO( 'sqlite::memory:' );
        $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->db->exec(
            'CREATE TABLE wp_crmb_sales_products(id INTEGER PRIMARY KEY AUTOINCREMENT,external_key TEXT,sku TEXT,title TEXT,price_toman INTEGER,stock_status TEXT,updated_utc TEXT);' .
            'CREATE TABLE wp_crmb_sales_customers(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_id INTEGER,name TEXT,phone TEXT,email TEXT,company TEXT,notes TEXT,created_utc TEXT,updated_utc TEXT);' .
            'CREATE TABLE wp_crmb_sales_opportunities(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,owner_id INTEGER,title TEXT,stage TEXT,followup_day TEXT,notes TEXT,created_utc TEXT,updated_utc TEXT);' .
            'CREATE TABLE wp_crmb_sales_quotes(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,opportunity_id INTEGER DEFAULT 0,owner_id INTEGER,status TEXT,notes TEXT,subtotal_toman INTEGER,created_utc TEXT,updated_utc TEXT);' .
            'CREATE TABLE wp_crmb_sales_quote_lines(id INTEGER PRIMARY KEY AUTOINCREMENT,quote_id INTEGER,product_id INTEGER,product_title TEXT,sku TEXT,quantity TEXT,unit_price_toman INTEGER,total_toman INTEGER);' .
            'CREATE TABLE wp_crmb_sales_quote_history(id INTEGER PRIMARY KEY AUTOINCREMENT,quote_id INTEGER,actor_id INTEGER,event_type TEXT,created_utc TEXT);' .
            'CREATE TABLE wp_crmb_sales_calls(id INTEGER PRIMARY KEY AUTOINCREMENT,customer_id INTEGER,opportunity_id INTEGER,owner_id INTEGER,outcome TEXT,note TEXT,next_followup_day TEXT,created_utc TEXT);' .
            'CREATE TABLE wp_crmb_sales_discounts(quote_id INTEGER PRIMARY KEY,percent TEXT,status TEXT,requested_by INTEGER,reviewed_by INTEGER DEFAULT 0,reviewed_utc TEXT);'
        );
    }
    function prepare( $sql, ...$values ) {
        foreach ( $values as $value ) {
            $quoted = is_int( $value ) ? (string) $value : $this->db->quote( (string) $value );
            $sql = preg_replace( '/%[ds]/', str_replace( '\\', '\\\\', $quoted ), $sql, 1 );
        }
        return $sql;
    }
    function run( $sql ) {
        $this->last_error = '';
        if ( $sql === 'START TRANSACTION' ) { $sql = 'BEGIN TRANSACTION'; }
        $sql = preg_replace( '/ FOR UPDATE$/', '', $sql );
        try { return $this->db->query( $sql ); }
        catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
    }
    function query( $sql ) { $statement = $this->run( $sql ); return $statement ? $statement->rowCount() : false; }
    function get_var( $sql ) { $statement = $this->run( $sql ); return $statement ? $statement->fetchColumn() : null; }
    function get_row( $sql ) { $statement = $this->run( $sql ); return $statement ? ( $statement->fetch( PDO::FETCH_OBJ ) ?: null ) : null; }
    function get_results( $sql ) { $statement = $this->run( $sql ); return $statement ? $statement->fetchAll( PDO::FETCH_OBJ ) : null; }
    function insert( $table, $values ) {
        $columns = array_keys( $values );
        $sql = "INSERT INTO $table (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', array_fill( 0, count( $values ), '?' ) ) . ')';
        try {
            $statement = $this->db->prepare( $sql ); $statement->execute( array_values( $values ) );
            $this->insert_id = (int) $this->db->lastInsertId(); $this->last_error = '';
            return $statement->rowCount();
        } catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
    }
    function update( $table, $values, $where ) {
        $set = implode( ',', array_map( static fn($k) => "$k=?", array_keys( $values ) ) );
        $conditions = implode( ' AND ', array_map( static fn($k) => "$k=?", array_keys( $where ) ) );
        try {
            $statement = $this->db->prepare( "UPDATE $table SET $set WHERE $conditions" );
            $statement->execute( array_merge( array_values( $values ), array_values( $where ) ) );
            $this->last_error = '';
            return $statement->rowCount();
        } catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
    }
}
$wpdb = new SalesTestDB();
require __DIR__ . '/../inc/sales.php';
require __DIR__ . '/../inc/sales-operations.php';
check( true, crmb_sales_access() );
check( false, crmb_sales_admin() );
check( 15000, crmb_sales_csv_price( '15,000', 'toman' ) );
check( 15000, crmb_sales_csv_price( '۱۵۰۰۰۰', 'rial' ) );
check( null, crmb_sales_csv_price( 'abc', 'toman' ) );
check( 'price', array_key_exists( 'regular', crmb_sales_csv_header( array( 'ID', 'Type', 'SKU', 'Name', 'Regular price', 'Sale price' ) ) ) ? 'price' : 'none' );
check( 0, crmb_sales_csv_header( array( 'نام', 'شناسه کالا', 'قیمت عادی' ) )['title'] );
check( 'saved', crmb_sales_customer_save( array( 'name' => 'مشتری نمونه', 'phone' => '09121234567' ) ) );
check( 'invalid', crmb_sales_customer_save( array( 'name' => 'Bad', 'email' => 'not-an-email' ) ) );
check( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_sales_customers' ) );
check( 'saved', crmb_sales_opportunity_save( array( 'customer_id' => '1', 'title' => 'سیم افشان', 'followup_day' => '1405-08-10' ) ) );
check( '2026-11-01', $wpdb->get_var( 'SELECT followup_day FROM wp_crmb_sales_opportunities WHERE id=1' ) );
check( 'invalid', crmb_sales_opportunity_save( array( 'customer_id' => '1', 'title' => 'Bad', 'followup_day' => '1405-99-99' ) ) );
$wpdb->insert( 'wp_crmb_sales_products', array(
    'external_key' => 'id:10', 'sku' => 'W-10', 'title' => 'سیم تست', 'price_toman' => 120000,
    'stock_status' => 'instock', 'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
) );
check( 'invalid', crmb_sales_quote_create( array( 'customer_id' => '1', 'product_ids' => array( '1' ), 'quantities' => array( '-3' ) ) ) );
$quote = crmb_sales_quote_create( array( 'customer_id' => '1', 'opportunity_id' => '1', 'product_ids' => array( '1' ), 'quantities' => array( '0.50' ), 'notes' => 'قیمت مقطوع' ) );
check( 1, $quote );
check( 60000, (int) crmb_sales_quote( $quote )->subtotal_toman );
check( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_sales_quote_lines' ) );
$wpdb->update( 'wp_crmb_sales_products', array( 'price_toman' => 150000 ), array( 'id' => 1 ) );
check( 120000, (int) $wpdb->get_var( 'SELECT unit_price_toman FROM wp_crmb_sales_quote_lines WHERE quote_id=1' ) );
check( 'invalid', crmb_sales_quote_transition( array( 'quote_id' => '1', 'status' => 'accepted' ) ) );
check( 'saved', crmb_sales_quote_transition( array( 'quote_id' => '1', 'status' => 'sent' ) ) );
check( 'quoted', $wpdb->get_var( 'SELECT stage FROM wp_crmb_sales_opportunities WHERE id=1' ) );
check( 'saved', crmb_sales_quote_transition( array( 'quote_id' => '1', 'status' => 'accepted' ) ) );
check( 'accepted', crmb_sales_quote( 1 )->status );
check( 'invalid', crmb_sales_quote_transition( array( 'quote_id' => '1', 'status' => 'sent' ) ) );
$uid = 8;
check( null, crmb_sales_customer( 1 ) );
check( null, crmb_sales_quote( 1 ) );
check( 'denied', crmb_sales_customer_save( array( 'customer_id' => '1', 'name' => 'Try overwrite' ) ) );
check( 'invalid', crmb_sales_quote_create( array( 'customer_id' => '1', 'product_ids' => array( '1' ), 'quantities' => array( '1' ) ) ) );
$uid = 7; $admin = false; $sales = true;
check( 'saved', crmb_sales_ops_call( array( 'customer_id' => '1', 'opportunity_id' => '1', 'outcome' => 'answered', 'note' => 'مکالمه انجام شد', 'next_followup_day' => '1405-08-10' ) ) );
check( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_sales_calls' ) );
check( 'saved', crmb_sales_ops_move( array( 'opportunity_id' => '1', 'stage' => 'won' ) ) );
check( 'won', $wpdb->get_var( 'SELECT stage FROM wp_crmb_sales_opportunities WHERE id=1' ) );
$discounted = crmb_sales_quote_create( array( 'customer_id' => '1', 'product_ids' => array( '1' ), 'quantities' => array( '1' ), 'discount_percent' => '10' ) );
check( 2, $discounted );
check( 'needs_approval', crmb_sales_quote_transition( array( 'quote_id' => '2', 'status' => 'sent' ) ) );
check( null, crmb_sales_ops_discount( 9999 ) );
check( 'denied', crmb_sales_ops_review_discount( array( 'quote_id' => '2', 'decision' => 'approved' ) ) );
$admin = true; $sales = false;
check( 'saved', crmb_sales_ops_review_discount( array( 'quote_id' => '2', 'decision' => 'approved' ) ) );
check( 135000, crmb_sales_ops_total( 150000, crmb_sales_ops_discount( 2 ) ) );
check( 'saved', crmb_sales_quote_transition( array( 'quote_id' => '2', 'status' => 'sent' ) ) );
check( 'saved', crmb_sales_ops_assign( array( 'customer_id' => '1', 'owner_id' => '8' ) ) );
check( 8, (int) $wpdb->get_var( 'SELECT owner_id FROM wp_crmb_sales_customers WHERE id=1' ) );
check( 8, (int) $wpdb->get_var( 'SELECT owner_id FROM wp_crmb_sales_opportunities WHERE id=1' ) );
check( 8, (int) $wpdb->get_var( 'SELECT owner_id FROM wp_crmb_sales_quotes WHERE id=2' ) );
check( 8, (int) $wpdb->get_var( 'SELECT owner_id FROM wp_crmb_sales_calls WHERE id=1' ) );
$admin = false; $sales = true; $uid = 7;
check( null, crmb_sales_quote( 2 ) );
check( null, crmb_sales_customer( 1 ) );
$uid = 8;
check( true, (bool) crmb_sales_quote( 2 ) );
$admin = true; $sales = false;
check( true, crmb_sales_access() );
check( true, (bool) crmb_sales_quote( 1 ) );
$admin = false; $logged = false;
check( false, crmb_sales_access() );
check( 'denied', crmb_sales_catalog_import( array(), array( 'price_unit' => 'toman' ) ) );
echo "$checks sales checks passed\n";
