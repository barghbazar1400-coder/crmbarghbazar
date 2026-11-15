<?php
/** Independent PHP/PDO SQLite supplier checks. Run: php tests/suppliers.php */
define('ABSPATH',__DIR__);
if(!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"pdo_sqlite unavailable\n"); exit(2); }
$logged=true; $allowed=false; $ready=true; $checks=0; $uid=7;
function add_filter($hook,$fn) {}
function add_action($hook,$fn,$priority=10) {}
function is_user_logged_in() { global $logged; return $logged; }
function crmbarghbazar_can_view_section($section) { global $allowed; return $allowed && 'suppliers'===$section; }
function get_option($key) { global $ready; return $ready && 'crmb_suppliers_schema_v1'===$key ? '1' : false; }
function get_current_user_id() { global $uid; return $uid; }
function absint($value) { return abs((int)$value); }
function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string)$value)); }
function is_email($value) { return (bool)filter_var($value,FILTER_VALIDATE_EMAIL); }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
function check($expected,$actual) {
    global $checks;
    if($expected!==$actual) {
        throw new RuntimeException('Check '.($checks+1).' expected '.var_export($expected,true).' got '.var_export($actual,true));
    }
    ++$checks;
}
class SupplierTestDB {
    public $prefix='wp_'; public $last_error=''; public $insert_id=0; public PDO $db;
    function __construct() {
        $this->db=new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_crmb_suppliers(
            id INTEGER PRIMARY KEY AUTOINCREMENT,supplier_name TEXT,family_name TEXT,
            contact_first_name TEXT,activity TEXT,mobile TEXT,mobile_search TEXT,
            website_social TEXT,landline TEXT,landline_search TEXT,address TEXT,notes TEXT,
            supplier_type TEXT,status TEXT,email TEXT,tags TEXT,source TEXT,
            source_hash TEXT UNIQUE,created_by INTEGER,updated_by INTEGER,
            created_utc TEXT,updated_utc TEXT,deleted_utc TEXT DEFAULT NULL
        );");
    }
    function esc_like($value) { return addcslashes($value,'%_'); }
    function prepare($sql,...$values) {
        foreach($values as $value) {
            $replacement=is_int($value)?(string)$value:$this->db->quote((string)$value);
            $sql=preg_replace_callback('/%[ds]/', fn($m)=>$m[0]==='%d'?(string)(int)$value:$replacement,$sql,1);
        }
        return $sql;
    }
    function run($sql) {
        $this->last_error='';
        if($sql==='START TRANSACTION')$sql='BEGIN TRANSACTION';
        try{return $this->db->query($sql);}
        catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    function query($sql){$stmt=$this->run($sql);return $stmt?$stmt->rowCount():false;}
    function get_var($sql){$stmt=$this->run($sql);return $stmt?$stmt->fetchColumn():null;}
    function get_row($sql){$stmt=$this->run($sql);return $stmt?($stmt->fetch(PDO::FETCH_OBJ)?:null):null;}
    function get_results($sql){$stmt=$this->run($sql);return $stmt?$stmt->fetchAll(PDO::FETCH_OBJ):null;}
    function insert($table,$values) {
        $columns=array_keys($values);
        $stmt=$this->db->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        try{$stmt->execute(array_values($values));$this->insert_id=(int)$this->db->lastInsertId();return $stmt->rowCount();}
        catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    function update($table,$values,$where) {
        $parts=array();$arguments=array();
        foreach($values as $k=>$v){$parts[]=$k.'=?';$arguments[]=$v;}
        $conditions=array();
        foreach($where as $k=>$v){
            if($v===null){$conditions[]=$k.' IS NULL';}
            else{$conditions[]=$k.'=?';$arguments[]=$v;}
        }
        $stmt=$this->db->prepare('UPDATE '.$table.' SET '.implode(',',$parts).' WHERE '.implode(' AND ',$conditions));
        try{$stmt->execute($arguments);return $stmt->rowCount();}
        catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
}
$wpdb=new SupplierTestDB();
require __DIR__.'/../inc/suppliers.php';
require __DIR__.'/../inc/suppliers-csv.php';

check(false,crmb_suppliers_can_manage());
check('denied',crmb_suppliers_save(array('supplier_name'=>'شخص')));
$allowed=true;
check(true,crmb_suppliers_can_manage());
check('0123456789',crmb_suppliers_search_digits('۰۱۲۳۴۵۶۷۸۹'));
check('0123456789',crmb_suppliers_search_digits('٠١٢٣٤٥٦٧٨٩'));
$record=array('supplier_name'=>'فانوس شب','family_name'=>'صادقی','activity'=>'روشنایی و چراغ','mobile'=>'۰۹۱۲۳۷۵۱۹۳۹','landline'=>'021-66348224','notes'=>"کارت ویزیت\nنمونه متن",'source'=>'نمایشگاه');
check('saved',crmb_suppliers_save($record));
check(1,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_suppliers'));
check('09123751939',crmb_suppliers_get(1)->mobile_search);
check('saved',crmb_suppliers_save($record));
check(2,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_suppliers'));
check(2,crmb_suppliers_search('09123751939')['total']);
check(2,crmb_suppliers_search('روشنایی')['total']);
check('invalid',crmb_suppliers_save(array('supplier_name'=>'')));
check('invalid',crmb_suppliers_save(array('supplier_name'=>'Test','email'=>'wrong-email')));
check('invalid',crmb_suppliers_save(array('supplier_name'=>'Test','status'=>'root')));
check('saved',crmb_suppliers_save($record+array('id'=>1)));
check('archived',crmb_suppliers_archive(1));
check(null,crmb_suppliers_get(1));
check(1,crmb_suppliers_search()['total']);
check(1,crmb_suppliers_search('', '', 1, true)['total']);
check('restored',crmb_suppliers_archive(1,true));
check(2,crmb_suppliers_search()['total']);
$allowed=false;
check(null,crmb_suppliers_get(1));
check('denied',crmb_suppliers_archive(1));
check(false,crmb_suppliers_search()['ready']);
$allowed=true;

$handle=fopen('php://temp','w+');
fwrite($handle,"\xEF\xBB\xBF");
fputcsv($handle,array_keys(crmb_suppliers_csv_mapping()));
$row=array('شرکت نمونه','محمدی','سیم و کابل','۰۹۱۲۰۰۰۰۰۰۰','https://example.org','021-1234','تهران',"چند خط\nیادداشت");
fputcsv($handle,$row);
fputcsv($handle,$row);
rewind($handle);
$parsed=crmb_suppliers_parse_csv($handle,'نمایشگاه');
fclose($handle);
check('parsed',$parsed['status']);
check(2,count($parsed['rows']));
check(false,$parsed['rows'][0]['source_hash']===$parsed['rows'][1]['source_hash']);
check("چند خط\nیادداشت",$parsed['rows'][0]['notes']);
check('09120000000',$parsed['rows'][0]['mobile_search']);
$missing=fopen('php://temp','w+');fputcsv($missing,array('نام','توضیحات'));rewind($missing);
check('invalid_header',crmb_suppliers_parse_csv($missing,'نمایشگاه')['status']);fclose($missing);
check('invalid_csv',crmb_suppliers_import_csv(array('name'=>'import.txt'),'نمایشگاه')['status']);
echo "$checks supplier CRUD, search, scope and CSV regression checks passed\n";
