<?php
/**
 * Shared B2B resale partner network for CRM Sales.
 * Deliberately separate from internal WordPress users, suppliers and sales quotes.
 */
defined('ABSPATH') || exit;
add_filter('crmbarghbazar_menu_registry', function($items){
    $items['sales_partners']=array(
        'label'=>'شبکه فروش همکاران',
        'access_callback'=>'crmb_partner_access'
    );
    return $items;
});
function crmb_partner_access(){
    return is_user_logged_in() && (current_user_can('manage_options') ||
        crmbarghbazar_can_view_section('sales'));
}
function crmb_partner_tables(){
    global $wpdb;
    return array(
        'partners'=>$wpdb->prefix.'crmb_partner_network',
        'invoices'=>$wpdb->prefix.'crmb_partner_invoices',
        'notes'=>$wpdb->prefix.'crmb_partner_notes',
    );
}
function crmb_partner_ready(){return '1'===get_option('crmb_partner_schema_v1');}
function crmb_partner_install(){
    if(crmb_partner_ready())return;
    global $wpdb; $t=crmb_partner_tables(); $charset=$wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$t['partners']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(200) NOT NULL,
        partner_type varchar(24) NOT NULL DEFAULT 'retailer',
        contact_name varchar(170) NOT NULL DEFAULT '',
        mobile varchar(90) NOT NULL DEFAULT '',
        phone varchar(90) NOT NULL DEFAULT '',
        whatsapp varchar(90) NOT NULL DEFAULT '',
        email varchar(190) NOT NULL DEFAULT '',
        website varchar(250) NOT NULL DEFAULT '',
        province varchar(100) NOT NULL DEFAULT '',
        city varchar(100) NOT NULL DEFAULT '',
        address text NOT NULL,
        products text NOT NULL,
        tags varchar(240) NOT NULL DEFAULT '',
        payment_terms varchar(200) NOT NULL DEFAULT '',
        referral_source varchar(190) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'prospect',
        followup_day date DEFAULT NULL,
        owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        archived_utc datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY name (name(120)),
        KEY partner_status (status,archived_utc),
        KEY owner_id (owner_id),
        KEY followup_day (followup_day)
    ) {$charset};");
    dbDelta("CREATE TABLE {$t['invoices']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        partner_id bigint(20) unsigned NOT NULL,
        invoice_no varchar(100) NOT NULL,
        invoice_day date NOT NULL,
        due_day date DEFAULT NULL,
        amount_toman bigint(20) unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'issued',
        description text NOT NULL,
        created_by bigint(20) unsigned NOT NULL,
        updated_by bigint(20) unsigned NOT NULL,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY partner_invoice (partner_id,invoice_no),
        KEY partner_status (partner_id,status),
        KEY due_day (due_day)
    ) {$charset};");
    dbDelta("CREATE TABLE {$t['notes']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        partner_id bigint(20) unsigned NOT NULL,
        author_id bigint(20) unsigned NOT NULL,
        kind varchar(20) NOT NULL DEFAULT 'note',
        note_text text NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY partner_time (partner_id,id)
    ) {$charset};");
    foreach($t as $table){
        if($table!==$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))))return;
    }
    add_option('crmb_partner_schema_v1','1','',false);
}
add_action('init','crmb_partner_install',17);
function crmb_partner_types(){
    return array('retailer'=>'فروشگاه و خرده‌فروش','wholesaler'=>'عمده‌فروش','contractor'=>'مجری / پیمانکار','distributor'=>'توزیع‌کننده','other'=>'سایر');
}
function crmb_partner_statuses(){
    return array('prospect'=>'در حال مذاکره','active'=>'فعال','inactive'=>'غیرفعال');
}
function crmb_partner_invoice_statuses(){
    return array('draft'=>'پیش‌نویس ثبت داخلی','issued'=>'ثبت‌شده','overdue'=>'سررسید گذشته','paid'=>'تسویه‌شده','cancelled'=>'لغوشده');
}
function crmb_partner_fields(){
    return array(
        'name'=>array('label'=>'نام فروشگاه / مجموعه','max'=>200,'required'=>true),
        'contact_name'=>array('label'=>'نام شخص رابط','max'=>170),
        'mobile'=>array('label'=>'موبایل رابط','max'=>90),
        'phone'=>array('label'=>'تلفن ثابت','max'=>90),
        'whatsapp'=>array('label'=>'شماره واتساپ','max'=>90),
        'email'=>array('label'=>'ایمیل','max'=>190),
        'website'=>array('label'=>'وب‌سایت / صفحه اجتماعی','max'=>250),
        'province'=>array('label'=>'استان','max'=>100),
        'city'=>array('label'=>'شهر','max'=>100),
        'address'=>array('label'=>'نشانی','max'=>4000,'multiline'=>true),
        'products'=>array('label'=>'کالاها و زمینه همکاری','max'=>4000,'multiline'=>true),
        'tags'=>array('label'=>'برچسب‌ها (با ویرگول)','max'=>240),
        'payment_terms'=>array('label'=>'شرایط معمول پرداخت','max'=>200),
        'referral_source'=>array('label'=>'منبع آشنایی','max'=>190),
    );
}
function crmb_partner_clean_text($value,$max,$multiline=false){
    if(!is_string($value)||!preg_match('//u',$value))return null;
    $value=trim($multiline?sanitize_textarea_field($value):sanitize_text_field($value));
    $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
    return $length<=$max?$value:null;
}
function crmb_partner_date($value,$optional=true){
    if(($value===null||$value==='')&&$optional)return null;
    if(!is_string($value)||!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$value,$m)||
        !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return false;
    return $value;
}
function crmb_partner_clean($data){
    if(!is_array($data))return false;
    $out=array();
    foreach(crmb_partner_fields() as $field=>$meta){
        $out[$field]=crmb_partner_clean_text($data[$field]??'', $meta['max'],!empty($meta['multiline']));
        if($out[$field]===null || (!empty($meta['required'])&&$out[$field]===''))return false;
    }
    if($out['email']!==''&&!is_email($out['email']))return false;
    $type=is_string($data['partner_type']??null)?$data['partner_type']:'retailer';
    $status=is_string($data['status']??null)?$data['status']:'prospect';
    if(!isset(crmb_partner_types()[$type])||!isset(crmb_partner_statuses()[$status]))return false;
    $day=crmb_partner_date($data['followup_day']??'');
    if($day===false)return false;
    $out['partner_type']=$type;
    $out['status']=$status;
    $out['followup_day']=$day;
    return $out;
}
function crmb_partner_get($id,$archived=false){
    if(!crmb_partner_access()||!crmb_partner_ready()||!$id)return null;
    global $wpdb; $t=crmb_partner_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['partners']} WHERE id=%d".($archived?'':' AND archived_utc IS NULL'),absint($id)
    ));
}
function crmb_partner_save($data){
    if(!crmb_partner_access())return 'denied';
    if(!crmb_partner_ready())return 'error';
    $values=crmb_partner_clean($data);
    if($values===false)return 'invalid';
    $id=crmb_work_id($data,'partner_id');
    if(isset($data['partner_id'])&&!$id)return 'invalid';
    global $wpdb;$t=crmb_partner_tables();$now=gmdate('Y-m-d H:i:s');
    $values['updated_utc']=$now;
    $values['updated_by']=get_current_user_id();
    if($id){
        if(!crmb_partner_get($id))return 'not_found';
        $ok=$wpdb->update($t['partners'],$values,array('id'=>$id,'archived_utc'=>null));
    }else{
        $values['created_by']=get_current_user_id();
        $values['owner_id']=get_current_user_id();
        $values['created_utc']=$now;
        $ok=$wpdb->insert($t['partners'],$values);
        if($ok!==false)$id=(int)$wpdb->insert_id;
    }
    return $ok===false?'error':$id;
}
function crmb_partner_archive($id,$restore=false){
    if(!crmb_partner_access())return 'denied';
    $partner=crmb_partner_get($id,true);
    if(!$partner)return 'not_found';
    if($restore?!$partner->archived_utc:!!$partner->archived_utc)return 'invalid';
    global $wpdb;$t=crmb_partner_tables();
    $ok=$wpdb->update($t['partners'],array(
        'archived_utc'=>$restore?null:gmdate('Y-m-d H:i:s'),
        'updated_by'=>get_current_user_id(),'updated_utc'=>gmdate('Y-m-d H:i:s')
    ),array('id'=>$id,'archived_utc'=>$partner->archived_utc));
    return $ok===false?'error':($restore?'restored':'archived');
}
function crmb_partner_search($search='',$status='',$page=1,$archived=false){
    $empty=array('rows'=>array(),'total'=>0,'ready'=>false);
    if(!crmb_partner_access()||!crmb_partner_ready())return $empty;
    global $wpdb;$t=crmb_partner_tables();$page=max(1,min(10000,absint($page)));
    $where=$archived?'archived_utc IS NOT NULL':'archived_utc IS NULL';
    if(isset(crmb_partner_statuses()[$status]))$where.=$wpdb->prepare(' AND status=%s',$status);
    $search=trim(sanitize_text_field($search));
    if($search!==''){
        $like='%'.$wpdb->esc_like($search).'%';
        $where.=$wpdb->prepare(' AND (name LIKE %s OR contact_name LIKE %s OR mobile LIKE %s OR phone LIKE %s OR city LIKE %s OR province LIKE %s OR products LIKE %s OR tags LIKE %s)',
            ...array_fill(0,8,$like));
    }
    $total=$wpdb->get_var("SELECT COUNT(*) FROM {$t['partners']} WHERE {$where}");
    if($wpdb->last_error)return $empty;
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['partners']} WHERE {$where} ORDER BY updated_utc DESC,id DESC LIMIT %d OFFSET %d",
        18,($page-1)*18
    ));
    return $wpdb->last_error?$empty:array('ready'=>true,'rows'=>$rows?:array(),'total'=>(int)$total);
}
function crmb_partner_stats(){
    $empty=array('total'=>0,'active'=>0,'prospect'=>0,'archived'=>0);
    if(!crmb_partner_access()||!crmb_partner_ready())return $empty;
    global $wpdb;$t=crmb_partner_tables();
    $row=$wpdb->get_row("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status='active' AND archived_utc IS NULL THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='prospect' AND archived_utc IS NULL THEN 1 ELSE 0 END) AS prospect,
        SUM(CASE WHEN archived_utc IS NOT NULL THEN 1 ELSE 0 END) AS archived
        FROM {$t['partners']} WHERE archived_utc IS NULL OR archived_utc IS NOT NULL");
    if(!$row||$wpdb->last_error)return $empty;
    // Total shown in the header excludes archived profiles.
    $empty['total']=(int)$row->total-(int)$row->archived;
    foreach(array('active','prospect','archived') as $key)$empty[$key]=(int)($row->$key??0);
    return $empty;
}
function crmb_partner_invoices($id){
    if(!crmb_partner_get($id))return array();
    global $wpdb;$t=crmb_partner_tables();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['invoices']} WHERE partner_id=%d ORDER BY invoice_day DESC,id DESC LIMIT 150",absint($id)
    ))?:array();
}
function crmb_partner_notes($id){
    if(!crmb_partner_get($id))return array();
    global $wpdb;$t=crmb_partner_tables();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['notes']} WHERE partner_id=%d ORDER BY id DESC LIMIT 100",absint($id)
    ))?:array();
}
function crmb_partner_log_note($id,$text,$kind='note'){
    global $wpdb;$t=crmb_partner_tables();
    return $wpdb->insert($t['notes'],array('partner_id'=>$id,'author_id'=>get_current_user_id(),
        'kind'=>$kind,'note_text'=>$text,'created_utc'=>gmdate('Y-m-d H:i:s')));
}
function crmb_partner_add_note($data){
    if(!crmb_partner_access())return 'denied';
    $id=crmb_work_id($data,'partner_id');
    if(!crmb_partner_get($id))return 'not_found';
    $text=crmb_partner_clean_text($data['note_text']??'',4000,true);
    if(!$text)return 'invalid';
    return crmb_partner_log_note($id,$text)===false?'error':'noted';
}
/** Invoice metadata registry only, not a legal/accounting invoice generator. */
function crmb_partner_add_invoice($data){
    if(!crmb_partner_access())return 'denied';
    $id=crmb_work_id($data,'partner_id');
    if(!crmb_partner_get($id))return 'not_found';
    $number=crmb_partner_clean_text($data['invoice_no']??'',100);
    $description=crmb_partner_clean_text($data['description']??'',4000,true);
    $day=crmb_partner_date($data['invoice_day']??'',false);
    $due=crmb_partner_date($data['due_day']??'');
    $amount=is_string($data['amount_toman']??null)?$data['amount_toman']:'';
    if(!$number||$description===null||!$day||$due===false||
        ($due!==null&&$due<$day)||!preg_match('/^[1-9][0-9]{0,11}$/D',$amount))return 'invalid';
    global $wpdb;$t=crmb_partner_tables();
    $existing=$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t['invoices']} WHERE partner_id=%d AND invoice_no=%s",$id,$number));
    if($existing)return 'duplicate';
    $now=gmdate('Y-m-d H:i:s');
    $ok=$wpdb->insert($t['invoices'],array(
        'partner_id'=>$id,'invoice_no'=>$number,'invoice_day'=>$day,'due_day'=>$due,
        'amount_toman'=>(int)$amount,'status'=>'issued','description'=>$description,
        'created_by'=>get_current_user_id(),'updated_by'=>get_current_user_id(),
        'created_utc'=>$now,'updated_utc'=>$now
    ));
    if($ok===false)return 'error';
    crmb_partner_log_note($id,'فاکتور شماره «'.$number.'» به مبلغ '.number_format((int)$amount).' تومان ثبت شد.','event');
    return 'invoice_added';
}
function crmb_partner_invoice_transition($data){
    if(!crmb_partner_access())return 'denied';
    $id=crmb_work_id($data,'partner_id');
    $invoice_id=crmb_work_id($data,'invoice_id');
    if(!crmb_partner_get($id))return 'not_found';
    global $wpdb;$t=crmb_partner_tables();
    $row=$wpdb->get_row($wpdb->prepare(
        "SELECT id,invoice_no,status FROM {$t['invoices']} WHERE partner_id=%d AND id=%d",$id,$invoice_id));
    if(!$row)return 'not_found';
    $next=is_string($data['status']??null)?$data['status']:'';
    $allowed=array('draft'=>array('issued','cancelled'),
        'issued'=>array('overdue','paid','cancelled'),
        'overdue'=>array('paid','cancelled'),'paid'=>array(),'cancelled'=>array());
    if(!in_array($next,$allowed[$row->status]??array(),true))return 'invalid';
    $ok=$wpdb->update($t['invoices'],array('status'=>$next,
        'updated_by'=>get_current_user_id(),'updated_utc'=>gmdate('Y-m-d H:i:s')),
        array('id'=>$invoice_id,'partner_id'=>$id,'status'=>$row->status));
    if($ok!==1)return 'error';
    crmb_partner_log_note($id,'وضعیت فاکتور «'.$row->invoice_no.'» به «'.
        crmb_partner_invoice_statuses()[$next].'» تغییر یافت.','event');
    return 'invoice_updated';
}
function crmb_partner_handle_post(){
    if(!crmb_partner_access())wp_die('اجازه دسترسی ندارید.','',array('response'=>403));
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('درخواست مجاز نیست.','',array('response'=>405));
    check_admin_referer('crmb_sales_partner','crmb_partner_nonce');
    $data=wp_unslash($_POST);$mode=isset($data['mode'])&&is_string($data['mode'])?sanitize_key($data['mode']):'';
    $id=crmb_work_id($data,'partner_id');
    if($mode==='save_partner'){
        $result=crmb_partner_save($data);
        if(is_int($result)){$id=$result;$result='saved';}
    }elseif($mode==='archive_partner'){$result=crmb_partner_archive($id,false);}
    elseif($mode==='restore_partner'){$result=crmb_partner_archive($id,true);}
    elseif($mode==='add_invoice'){$result=crmb_partner_add_invoice($data);}
    elseif($mode==='invoice_status'){$result=crmb_partner_invoice_transition($data);}
    elseif($mode==='add_note'){$result=crmb_partner_add_note($data);}
    else{$result='invalid';}
    $args=array('crm_section'=>'sales_partners','partner_notice'=>$result);
    if($id&&$result!=='archived')$args['partner_id']=$id;
    if($result==='archived')$args['archived']='1';
    if($result==='restored')$args['partner_id']=$id;
    wp_safe_redirect(add_query_arg($args,home_url('/main/')));
    exit;
}
add_action('admin_post_crmb_sales_partner','crmb_partner_handle_post');
function crmb_partner_form_hidden($mode,$id=0){
    echo '<input type="hidden" name="action" value="crmb_sales_partner">';
    echo '<input type="hidden" name="mode" value="'.esc_attr($mode).'">';
    if($id)echo '<input type="hidden" name="partner_id" value="'.esc_attr($id).'">';
    wp_nonce_field('crmb_sales_partner','crmb_partner_nonce');
}
