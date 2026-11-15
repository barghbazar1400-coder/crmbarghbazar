<?php
/** Private files security tests with temporary disk fixtures and SQLite. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CRMB_CHAT_PRIVATE_DIR', sys_get_temp_dir() . '/crmb-chat-private-ci-' . getmypid() );
if ( ! extension_loaded( 'pdo_sqlite' ) || ! extension_loaded( 'fileinfo' ) ) {
    fwrite( STDERR, "PDO SQLite + fileinfo are required\n" ); exit( 2 );
}
$uid = 7; $logged = true; $file_ready = true; $checks = 0;
function add_action( $name, $fn, $priority = 10 ) {}
function get_option( $key ) {
    global $file_ready;
    if ( $key === 'crmb_chat_files_schema_v1' ) return $file_ready ? '1' : '';
    return '1';
}
function is_user_logged_in() { global $logged; return $logged; }
function crmb_chat_ready() { global $logged; return $logged; }
function crmb_chat_peer( $peer ) { global $uid; return $peer > 0 && $peer !== $uid; }
function crmb_work_tables() { return array( 'messages' => 'wp_crmb_messages' ); }
function get_current_user_id() { global $uid; return $uid; }
function absint( $val ) { return abs( (int) $val ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function admin_url( $path ) { return 'https://crmbarghbazar.com/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function check( $expected, $actual ) {
    global $checks;
    $checks++;
    if ( $expected !== $actual ) throw new RuntimeException(
        "Check $checks expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
    );
}
class AttachmentTestDB {
    public $prefix='wp_'; public $last_error=''; public $insert_id=0; public PDO $db;
    function __construct() {
        $this->db = new PDO( 'sqlite::memory:' );
        $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->db->exec( "CREATE TABLE wp_crmb_messages
            (id INTEGER PRIMARY KEY,sender_id INTEGER,recipient_id INTEGER,subject TEXT,body TEXT);
            CREATE TABLE wp_crmb_chat_files
            (id INTEGER PRIMARY KEY,message_id INTEGER,uploader_id INTEGER,stored_name TEXT,
            original_name TEXT,mime_type TEXT,size_bytes INTEGER,created_utc TEXT);" );
    }
    function prepare( $sql, ...$values ) {
        foreach ( $values as $value ) {
            $replacement = is_int( $value ) ? (string) $value : $this->db->quote( (string) $value );
            $sql = preg_replace_callback( '/%[ds]/', fn($match) =>
                $match[0] === '%d' ? (string) (int) $value : $replacement, $sql, 1 );
        }
        return $sql;
    }
    function get_row( $sql ) {
        $this->last_error='';
        try { $r = $this->db->query( $sql ); return $r->fetch( PDO::FETCH_OBJ ) ?: null; }
        catch ( Throwable $e ) { $this->last_error=$e->getMessage(); return null; }
    }
    function get_results( $sql ) {
        $this->last_error='';
        try { return $this->db->query( $sql )->fetchAll( PDO::FETCH_OBJ ); }
        catch ( Throwable $e ) { $this->last_error=$e->getMessage(); return array(); }
    }
}
$wpdb = new AttachmentTestDB();
require __DIR__ . '/../inc/chat-attachments.php';

check( 3, crmb_chat_file_limits()['count'] );
check( 8388608, crmb_chat_file_limits()['each'] );
check( 'image/png', crmb_chat_file_types()['png'] );
check( false, isset( crmb_chat_file_types()['php'] ) );
check( false, isset( crmb_chat_file_types()['svg'] ) );
check( 'report.pdf', crmb_chat_clean_filename( '../../report.pdf' ) );
check( 'attachment.jpg', crmb_chat_clean_filename( '..\\attachment.jpg' ) );
check( 2, count( crmb_chat_file_input( array(
    'name' => array( 'one.png', 'two.pdf' ), 'size' => array( 20, 100 ),
    'tmp_name' => array( 't1', 't2' ), 'error' => array( 0, 0 )
) ) ) );
check( 0, count( crmb_chat_file_input( array() ) ) );
check( true, crmb_chat_check_files( array() )['files'] === array() );

$pngFile = tempnam( sys_get_temp_dir(), 'crmb-img-' );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLTTAAAAABJRU5ErkJggg==' );
file_put_contents( $pngFile, $png );
$one = array( 'name' => 'screenshot.png', 'tmp_name' => $pngFile, 'size' => strlen( $png ), 'error' => 0 );
$valid = crmb_chat_check_files( array( $one ) );
check( false, isset( $valid['error'] ) );
check( 'image/png', $valid['files'][0]['mime'] );
check( true, crmb_chat_check_files( array_merge( array_fill( 0, 3, $one ), array( $one ) ) )['error'] !== '' );
check( true, isset( crmb_chat_check_files( array( array_merge( $one, array( 'name' => 'screenshot.php' ) ) ) )['error'] ) );
check( true, isset( crmb_chat_check_files( array( array_merge( $one, array( 'name' => 'screenshot.svg' ) ) ) )['error'] ) );
check( true, isset( crmb_chat_check_files( array( array_merge( $one, array( 'size' => 9000000 ) ) ) )['error'] ) );
$fake = tempnam( sys_get_temp_dir(), 'crmb-fake-' );
file_put_contents( $fake, "<?php echo 'malware';" );
check( true, isset( crmb_chat_check_files( array( array_merge(
    $one, array( 'tmp_name' => $fake, 'size' => filesize( $fake ) )
) ) )['error'] ) );
$txtFile = tempnam( sys_get_temp_dir(), 'crmb-txt-' );
file_put_contents( $txtFile, "درخواست جدید\nمتن فایل" );
$txt = array( 'name' => 'notes.txt', 'tmp_name' => $txtFile, 'size' => filesize( $txtFile ), 'error' => 0 );
check( false, isset( crmb_chat_check_files( array( $txt ) )['error'] ) );
$pdfFile = tempnam( sys_get_temp_dir(), 'crmb-pdf-' );
file_put_contents( $pdfFile, "%PDF-1.3\n1 0 obj\n<<>>\nendobj\n%%EOF\n" );
$pdf = array( 'name' => 'invoice.pdf', 'tmp_name' => $pdfFile, 'size' => filesize( $pdfFile ), 'error' => 0 );
check( false, isset( crmb_chat_check_files( array( $pdf ) )['error'] ) );

$dir = crmb_chat_private_directory();
check( true, is_string( $dir ) && is_dir( $dir ) );
check( true, ( fileperms( $dir ) & 0007 ) === 0 );
check( true, str_starts_with( $dir, sys_get_temp_dir() ) );
$wpdb->db->exec( "INSERT INTO wp_crmb_messages VALUES (1,7,8,'test','hi'),(2,9,10,'test','other');
INSERT INTO wp_crmb_chat_files VALUES
(1,1,7,'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdef.png','private.png','image/png',100,'2026-11-15 10:00:00'),
(2,2,9,'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdff.pdf','other.pdf','application/pdf',200,'2026-11-15 10:00:00');" );
check( 1, (int) crmb_chat_file_owned_row( 1 )->id );
check( null, crmb_chat_file_owned_row( 2 ) );
$rows = array( (object) array( 'id' => 1 ) );
check( 'private.png', crmb_chat_files_for_messages( $rows )[1][0]['name'] );
check( true, crmb_chat_files_for_messages( $rows )[1][0]['image'] );
check( true, str_contains( crmb_chat_files_for_messages( $rows )[1][0]['url'], 'crmb_chat_attachment' ) );
$uid = 8;
check( 1, (int) crmb_chat_file_owned_row( 1 )->id );
check( null, crmb_chat_file_owned_row( 2 ) );
$uid = 9;
check( null, crmb_chat_file_owned_row( 1 ) );
check( 2, (int) crmb_chat_file_owned_row( 2 )->id );
$logged = false;
check( null, crmb_chat_file_owned_row( 2 ) );
$logged = true; $uid = 7;
$file_ready = false;
check( null, crmb_chat_file_owned_row( 1 ) );
$file_ready = true;
check( false, is_uploaded_file( $pngFile ) ); // Local fixture cannot bypass HTTP upload validation.
@unlink( $pngFile ); @unlink( $fake ); @unlink( $txtFile ); @unlink( $pdfFile );
@rmdir( $dir );
echo "$checks private chat file validation, ownership and storage checks passed\n";
