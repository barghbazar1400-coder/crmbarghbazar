<?php
/** Sales partner network, invoices and shared notes: PDO SQLite regressions. */
define('ABSPATH',__DIR__);
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite required\n");exit(2);}
$logged=true;$sales=false;$admin=false;$uid=7;$checks=0;
function add_action($hook,$fn,$priority=10){}
function add_filter($hook,$fn){}
function is_user_logged_in(){global $logged;return $logged;}
function current_user_can($cap){global $admin;return $cap==='manage_options' && $admin;}
function crmbarghbazar_can_view_section($section){global $sales;return $section==='sales' && $sales;}
function get_option($key){return $key==='crmb_partner_schema_v1'?'1':false;}
function get_current_user_id(){global $uid;return $uid;}
function absint($value){return abs((int)$value);}
function crmb_work_id($data,$key){
    $value=$data[$key]??'';
    return is_scalar($value)&&preg_match('/^[1-9][0-9]*$/D',(string)$value)?absint($value):0;
}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function sanitize_textarea_field($value){return trim(strip_tags((string)$value));}
function is_email($value){return(bool)filter_var($value,FILTER_VALIDATE_EMAIL);}
function get_userdata($id){return (object)array('ID'=>(int)$id,'display_name'=>'کارمند '.(int)$id);}
function check($expected,$actual){
    global $checks;++$checks;
    if($expected!==$actual)throw new RuntimeException("Check $checks expected ".var_export($expected,true).
        " got ".var_export($actual,true));
}
class PartnerTestDB {
    public $prefix='wp_';public $last_error='';public $insert_id=0;public PDO $db;
    function __construct(){
        $this->db=new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_crmb_partner_network (
            id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,partner_type TEXT,contact_name TEXT,
            mobile TEXT,phone TEXT,whatsapp TEXT,email TEXT,website TEXT,province TEXT,city TEXT,
            address TEXT,products TEXT,tags TEXT,payment_terms TEXT,referral_source TEXT,
            status TEXT,followup_day TEXT,owner_id INTEGER,created_by INTEGER,updated_by INTEGER,
            created_utc TEXT,updated_utc TEXT,archived_utc TEXT DEFAULT NULL
        );
        CREATE TABLE wp_crmb_partner_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,partner_id INTEGER,invoice_no TEXT,invoice_day TEXT,
            due_day TEXT,amount_toman INTEGER,status TEXT,description TEXT,created_by INTEGER,
            updated_by INTEGER,created_utc TEXT,updated_utc TEXT,UNIQUE(partner_id,invoice_no)
        );
        CREATE TABLE wp_crmb_partner_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,partner_id INTEGER,author_id INTEGER,
            kind TEXT,note_text TEXT,created_utc TEXT
        );");
    }
    function esc_like($value){return addcslashes($value,'%_');}
    function prepare($sql,...$args){
        foreach($args as $arg){
            $replace=is_int($arg)?(string)$arg:$this->db->quote((string)$arg);
            $sql=preg_replace_callback('/%[ds]/',fn($m)=>$m[0]==='%d'?(string)(int)$arg:$replace,$sql,1);
        }
        return $sql;
    }
    function run($sql){
        $this->last_error='';
        try{return $this->db->query($sql);}
        catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    function get_var($sql){$r=$this->run($sql);return $r?$r->fetchColumn():null;}
    function get_row($sql){$r=$this->run($sql);return $r?($r->fetch(PDO::FETCH_OBJ)?:null):null;}
    function get_results($sql){$r=$this->run($sql);return $r?$r->fetchAll(PDO::FETCH_OBJ):null;}
    function insert($table,$fields){
        $keys=array_keys($fields);
        $stmt=$this->db->prepare("INSERT INTO $table (".implode(',',$keys).") VALUES (".
            implode(',',array_fill(0,count($keys),'?')).")");
        try{$stmt->execute(array_values($fields));$this->insert_id=(int)$this->db->lastInsertId();return $stmt->rowCount();}
        catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    function update($table,$fields,$where){
        $sets=array();$args=array();foreach($fields as $key=>$value){$sets[]="$key=?";$args[]=$value;}
        $conditions=array();
        foreach($where as $key=>$value){
            if($value===null)$conditions[]="$key IS NULL";
            else{$conditions[]="$key=?";$args[]=$value;}
        }
        $stmt=$this->db->prepare("UPDATE $table SET ".implode(',',$sets)." WHERE ".implode(' AND ',$conditions));
        try{$stmt->execute($args);return $stmt->rowCount();}
        catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
}
$wpdb=new PartnerTestDB();
require __DIR__.'/../inc/sales-partners.php';
check(false,crmb_partner_access());
check('denied',crmb_partner_save(array('name'=>'مرکز برق')));
$admin=true;
check(true,crmb_partner_access());
$admin=false;$sales=true;
check(true,crmb_partner_access());
$record=array(
    'name'=>'همکار برق بازار','partner_type'=>'wholesaler',
    'contact_name'=>'سعید رضایی','mobile'=>'09121111111','city'=>'تهران','province'=>'تهران',
    'products'=>"سیم و کابل\nتجهیزات صنعتی",'status'=>'prospect','payment_terms'=>'تسویه پس از تحویل',
    'followup_day'=>'2026-12-01'
);
check(1,crmb_partner_save($record));
check('همکار برق بازار',crmb_partner_get(1)->name);
check(1,crmb_partner_search('تهران')['total']);
check(1,crmb_partner_search('سیم و کابل')['total']);
check(0,crmb_partner_search('تبریز')['total']);
check(1,crmb_partner_stats()['total']);
check(1,crmb_partner_stats()['prospect']);
check('invalid',crmb_partner_save(array('name'=>'')));
check('invalid',crmb_partner_save(array('name'=>'test','email'=>'wrong')));
check('invalid',crmb_partner_save(array('name'=>'test','followup_day'=>'2026-02-30')));
check('invalid',crmb_partner_save(array('name'=>'test','status'=>'superuser')));
check(1,crmb_partner_save($record+array('partner_id'=>'1')));
$uid=8;
check('noted',crmb_partner_add_note(array('partner_id'=>'1','note_text'=>"گفتگوی خوبی داشتیم.\nپیگیری شود.")));
$uid=7;
check('noted',crmb_partner_add_note(array('partner_id'=>'1','note_text'=>'یادداشت مشترک بعدی')));
check(2,count(crmb_partner_notes(1)));
check(8,(int)crmb_partner_notes(1)[1]->author_id);
check('invalid',crmb_partner_add_note(array('partner_id'=>'1','note_text'=>'')));
$invoice=array('partner_id'=>'1','invoice_no'=>'FA-100','amount_toman'=>'120000000',
    'invoice_day'=>'2026-11-15','due_day'=>'2026-12-15','description'=>'سفارش کابل');
check('invoice_added',crmb_partner_add_invoice($invoice));
check(1,count(crmb_partner_invoices(1)));
check('duplicate',crmb_partner_add_invoice($invoice));
check('invalid',crmb_partner_add_invoice(array_merge($invoice,array('invoice_no'=>'other','amount_toman'=>'-5'))));
check('invalid',crmb_partner_add_invoice(array_merge($invoice,array('invoice_no'=>'other','due_day'=>'2026-10-01'))));
check('invalid',crmb_partner_add_invoice(array_merge($invoice,array('invoice_no'=>'other','amount_toman'=>'1000000000000'))));
check('invoice_updated',crmb_partner_invoice_transition(array('partner_id'=>'1','invoice_id'=>'1','status'=>'paid')));
check('invalid',crmb_partner_invoice_transition(array('partner_id'=>'1','invoice_id'=>'1','status'=>'cancelled')));
check(4,count(crmb_partner_notes(1)));
check('archived',crmb_partner_archive(1));
check(null,crmb_partner_get(1));
check(0,crmb_partner_stats()['total']);
check(1,crmb_partner_stats()['archived']);
check('not_found',crmb_partner_add_invoice($invoice));
check('restored',crmb_partner_archive(1,true));
check(1,crmb_partner_stats()['total']);
$sales=false;
check('denied',crmb_partner_add_invoice($invoice));
check(false,crmb_partner_search()['ready']);
check(0,count(crmb_partner_notes(1)));
$admin=true;
check(1,crmb_partner_search()['total']);

$template=file_get_contents(__DIR__.'/../template-parts/sales-partners.php');
$css=file_get_contents(__DIR__.'/../style.css');
check(true,str_contains($template,'شبکه فروش همکاران'));
check(true,str_contains($template,'crmb-partner-invoices'));
check(true,str_contains($template,'crmb-partner-note-timeline'));
check(true,str_contains($template,'crmb_partner_form_hidden'));
check(true,str_contains($css,'.crmb-partner-cards'));
echo "$checks partner profiles, invoice records, statuses, role permissions and shared notes checks passed\n";
