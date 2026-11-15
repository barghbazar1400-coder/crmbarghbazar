<?php
/** Test-only HTTP harness: exercises PHP's real multipart is_uploaded_file() path. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CRMB_CHAT_PRIVATE_DIR', getenv( 'CRMB_CHAT_HTTP_TEST_DIR' ) . '/private' );
$uid = (int) ( $_SERVER['HTTP_X_TEST_USER'] ?? 7 );
$logged = $uid > 0;
function add_action( $name, $fn, $priority = 10 ) {}
function is_user_logged_in() { global $logged; return $logged; }
function crmb_chat_ready() { global $logged; return $logged; }
function get_option( $key ) { return '1'; }
function crmb_chat_peer( $peer ) {
    global $uid; return in_array( (int) $peer, array( 7, 8, 9 ), true ) && (int) $peer !== $uid;
}
function get_current_user_id() { global $uid; return $uid; }
function crmb_work_tables() { return array( 'messages' => 'messages' ); }
function absint( $value ) { return abs( (int) $value ); }
function crmb_work_id( $data, $key ) {
    return isset( $data[$key] ) && is_scalar( $data[$key] ) && preg_match( '/^[1-9]\d*$/D', (string) $data[$key] )
        ? (int) $data[$key] : 0;
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $token, $action ) { return $token === 'nonce-' . $action; }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $path ) { return 'http://127.0.0.1:8954/' . $path; }
function status_header( $code ) { http_response_code( $code ); }
function nocache_headers() {}
function do_action( $name, ...$args ) {}
class AttachmentHttpDB {
    public $prefix = 'test_'; public $last_error = ''; public $insert_id = 0; public PDO $db;
    function __construct( $dbPath ) {
        $this->db = new PDO( 'sqlite:' . $dbPath );
        $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->db->exec( 'CREATE TABLE IF NOT EXISTS messages(
            id INTEGER PRIMARY KEY AUTOINCREMENT,sender_id INTEGER,recipient_id INTEGER,
            subject TEXT,body TEXT,created_utc TEXT,read_utc TEXT);
        CREATE TABLE IF NOT EXISTS test_crmb_chat_files(
            id INTEGER PRIMARY KEY AUTOINCREMENT,message_id INTEGER,uploader_id INTEGER,
            original_name TEXT,stored_name TEXT,mime_type TEXT,size_bytes INTEGER,created_utc TEXT
        );' );
    }
    function prepare( $query, ...$args ) {
        foreach ( $args as $v ) {
            $q = is_int( $v ) ? (string) $v : $this->db->quote( (string) $v );
            $query = preg_replace_callback( '/%[ds]/', fn($m) => $m[0] === '%d' ? (string) (int) $v : $q, $query, 1 );
        }
        return $query;
    }
    function query( $query ) {
        $this->last_error = '';
        try {
            if ( $query === 'START TRANSACTION' ) $query = 'BEGIN TRANSACTION';
            return $this->db->exec( $query );
        } catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
    }
    function insert( $table, $fields ) {
        $columns = array_keys( $fields );
        $stmt = $this->db->prepare( "INSERT INTO $table (" . implode( ',', $columns ) . ') VALUES (' .
            implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')' );
        try {
            $stmt->execute( array_values( $fields ) );
            $this->insert_id = (int) $this->db->lastInsertId();
            return $stmt->rowCount();
        } catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
    }
    function get_row( $query ) {
        try { return $this->db->query( $query )->fetch( PDO::FETCH_OBJ ) ?: null; }
        catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return null; }
    }
    function get_results( $query ) {
        try { return $this->db->query( $query )->fetchAll( PDO::FETCH_OBJ ); }
        catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return array(); }
    }
}
$root = getenv( 'CRMB_CHAT_HTTP_TEST_DIR' );
if ( ! $root || ! is_dir( $root ) ) { http_response_code( 503 ); exit; }
$wpdb = new AttachmentHttpDB( $root . '/files.sqlite' );
require dirname( __DIR__ ) . '/inc/chat-attachments.php';
if ( $_SERVER['REQUEST_METHOD'] === 'GET' && isset( $_GET['action'] ) &&
    $_GET['action'] === 'crmb_chat_attachment' ) {
    crmb_chat_file_download();
}
if ( $_SERVER['REQUEST_METHOD'] === 'GET' && ( $_GET['mode'] ?? '' ) === 'stats' ) {
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'messages' => (int) $wpdb->db->query( 'SELECT COUNT(*) FROM messages' )->fetchColumn(),
        'files' => (int) $wpdb->db->query( 'SELECT COUNT(*) FROM test_crmb_chat_files' )->fetchColumn(),
    ) );
    exit;
}
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $result = crmb_chat_send_files( 8, $_POST['body'] ?? '', $_FILES['attachments'] ?? array() );
    header( 'Content-Type: application/json' );
    http_response_code( isset( $result['error'] ) ? 400 : 200 );
    echo json_encode( $result );
    exit;
}
header( 'Content-Type: text/plain' );
echo 'ready';
