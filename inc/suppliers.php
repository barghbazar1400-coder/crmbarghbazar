<?php
/** Restricted supplier profiles for CRM sales management. */
defined('ABSPATH') || exit;
add_filter('crmbarghbazar_menu_registry', function($items) {
    $items['suppliers'] = array('label'=>'تأمین‌کنندگان');
    return $items;
});
function crmb_suppliers_can_manage() {
    return is_user_logged_in() && crmbarghbazar_can_view_section('suppliers');
}
function crmb_suppliers_table() {
    global $wpdb; return $wpdb->prefix.'crmb_suppliers';
}
function crmb_suppliers_ready() { return '1'===get_option('crmb_suppliers_schema_v1'); }
function crmb_suppliers_install() {
    if (crmb_suppliers_ready()) return;
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $t=crmb_suppliers_table(); $charset=$wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        supplier_name varchar(220) NOT NULL,
        family_name varchar(180) NOT NULL DEFAULT '',
        contact_first_name varchar(140) NOT NULL DEFAULT '',
        activity text NOT NULL,
        mobile varchar(255) NOT NULL DEFAULT '',
        website_social text NOT NULL,
        landline varchar(255) NOT NULL DEFAULT '',
        address text NOT NULL,
        notes longtext NOT NULL,
        supplier_type varchar(16) NOT NULL DEFAULT 'unknown',
        status varchar(16) NOT NULL DEFAULT 'new',
        email varchar(190) NOT NULL DEFAULT '',
        tags varchar(255) NOT NULL DEFAULT '',
        source varchar(140) NOT NULL DEFAULT '',
        source_hash char(64) DEFAULT NULL,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        deleted_utc datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY source_hash (source_hash),
        KEY supplier_name (supplier_name(120)),
        KEY status_deleted (status,deleted_utc),
        KEY updated_utc (updated_utc)
    ) {$charset};");
    if ($t===$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))) {
        add_option('crmb_suppliers_schema_v1','1','',false);
    }
}
add_action('init','crmb_suppliers_install',16);
function crmb_suppliers_types() {
    return array('unknown'=>'تعیین نشده','company'=>'شرکت / مجموعه','person'=>'شخص');
}
function crmb_suppliers_statuses() {
    return array('new'=>'در حال بررسی','active'=>'تأییدشده / فعال','inactive'=>'غیرفعال');
}
function crmb_suppliers_fields() {
    return array(
        'supplier_name'=>array('label'=>'نام تأمین‌کننده / مجموعه','max'=>220),
        'family_name'=>array('label'=>'نام خانوادگی / اطلاعات فرد مرتبط','max'=>180),
        'contact_first_name'=>array('label'=>'نام شخص رابط (تکمیلی)','max'=>140),
        'activity'=>array('label'=>'زمینه فعالیت','max'=>8000,'multiline'=>true),
        'mobile'=>array('label'=>'شماره همراه','max'=>255),
        'website_social'=>array('label'=>'وب‌سایت / شبکه‌های اجتماعی','max'=>3000,'multiline'=>true),
        'landline'=>array('label'=>'تلفن ثابت','max'=>255),
        'address'=>array('label'=>'آدرس','max'=>8000,'multiline'=>true),
        'notes'=>array('label'=>'توضیحات و سوابق ارتباط','max'=>30000,'multiline'=>true),
        'email'=>array('label'=>'ایمیل (تکمیلی)','max'=>190),
        'tags'=>array('label'=>'برچسب‌ها (با ویرگول جدا کنید)','max'=>255),
        'source'=>array('label'=>'منبع آشنایی','max'=>140),
    );
}
function crmb_suppliers_strlen($text) {
    return function_exists('mb_strlen') ? mb_strlen($text,'UTF-8') : preg_match_all('/./us',$text);
}
function crmb_suppliers_clean($data) {
    $out=array();
    foreach(crmb_suppliers_fields() as $key=>$config) {
        $raw=isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
        if (!preg_match('//u',$raw)) return false;
        $value=trim(!empty($config['multiline']) ? sanitize_textarea_field($raw) : sanitize_text_field($raw));
        if (crmb_suppliers_strlen($value)>$config['max']) return false;
        $out[$key]=$value;
    }
    if (''===$out['supplier_name'] || (''!==$out['email'] && !is_email($out['email']))) return false;
    $type=isset($data['supplier_type'])&&is_string($data['supplier_type'])?$data['supplier_type']:'unknown';
    $status=isset($data['status'])&&is_string($data['status'])?$data['status']:'new';
    if (!isset(crmb_suppliers_types()[$type]) || !isset(crmb_suppliers_statuses()[$status])) return false;
    $out['supplier_type']=$type; $out['status']=$status;
    return $out;
}
function crmb_suppliers_get($id,$include_deleted=false) {
    if (!crmb_suppliers_can_manage()||!crmb_suppliers_ready()||!$id) return null;
    global $wpdb; $t=crmb_suppliers_table();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t} WHERE id=%d".($include_deleted?'':' AND deleted_utc IS NULL'),$id
    ));
}
function crmb_suppliers_save($data) {
    if (!crmb_suppliers_can_manage()) return 'denied';
    if (!crmb_suppliers_ready()) return 'error';
    $values=crmb_suppliers_clean($data);
    if (false===$values) return 'invalid';
    $id=isset($data['id'])?absint($data['id']):0;
    if(isset($data['id'])&&!$id) return 'invalid';
    global $wpdb; $t=crmb_suppliers_table();
    $values['updated_by']=get_current_user_id();
    $values['updated_utc']=gmdate('Y-m-d H:i:s');
    if($id) {
        if(!crmb_suppliers_get($id)) return 'not_found';
        $result=$wpdb->update($t,$values,array('id'=>$id,'deleted_utc'=>null));
    } else {
        $values['created_by']=get_current_user_id();
        $values['created_utc']=$values['updated_utc'];
        $result=$wpdb->insert($t,$values);
    }
    return false===$result?'error':'saved';
}
function crmb_suppliers_archive($id,$restore=false) {
    if(!crmb_suppliers_can_manage()) return 'denied';
    if(!crmb_suppliers_ready()||!$id) return 'invalid';
    if(!crmb_suppliers_get($id,true)) return 'not_found';
    global $wpdb; $t=crmb_suppliers_table();
    $result=$wpdb->update($t,array(
        'deleted_utc'=>$restore?null:gmdate('Y-m-d H:i:s'),
        'updated_by'=>get_current_user_id(),
        'updated_utc'=>gmdate('Y-m-d H:i:s'),
    ),array('id'=>$id));
    return false===$result?'error':($restore?'restored':'archived');
}
function crmb_suppliers_search($search='',$status='',$page=1,$archived=false) {
    $empty=array('rows'=>array(),'total'=>0,'ready'=>false);
    if(!crmb_suppliers_can_manage()||!crmb_suppliers_ready()) return $empty;
    global $wpdb; $t=crmb_suppliers_table();
    $page=max(1,min(100000,absint($page)));
    $where=$archived?'deleted_utc IS NOT NULL':'deleted_utc IS NULL';
    if($status && isset(crmb_suppliers_statuses()[$status])) {
        $where.=$wpdb->prepare(' AND status=%s',$status);
    }
    $search=trim(sanitize_text_field($search));
    if($search) {
        $like='%'.$wpdb->esc_like($search).'%';
        $where.=$wpdb->prepare(
            ' AND (supplier_name LIKE %s OR family_name LIKE %s OR contact_first_name LIKE %s OR activity LIKE %s OR mobile LIKE %s OR landline LIKE %s OR website_social LIKE %s OR address LIKE %s OR tags LIKE %s OR notes LIKE %s OR email LIKE %s)',
            ...array_fill(0,11,$like)
        );
    }
    $total=$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$where}");
    $ok=!$wpdb->last_error;
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t} WHERE {$where} ORDER BY updated_utc DESC,id DESC LIMIT %d OFFSET %d",
        18,($page-1)*18
    ));
    return !$ok||$wpdb->last_error ? $empty : array('rows'=>$rows?:array(),'total'=>(int)$total,'ready'=>true);
}
function crmb_suppliers_form_fields($mode,$id=0) {
    echo '<input type="hidden" name="action" value="crmb_suppliers">';
    echo '<input type="hidden" name="mode" value="'.esc_attr($mode).'">';
    if($id) echo '<input type="hidden" name="id" value="'.esc_attr($id).'">';
    wp_nonce_field('crmb_suppliers','crmb_suppliers_nonce');
}
function crmb_suppliers_handle_post() {
    if(!crmb_suppliers_can_manage()) wp_die('دسترسی ندارید.','',array('response'=>403));
    if('POST'!==($_SERVER['REQUEST_METHOD']??'')) wp_die('روش درخواست مجاز نیست.','',array('response'=>405));
    check_admin_referer('crmb_suppliers','crmb_suppliers_nonce');
    $data=wp_unslash($_POST);
    $mode=isset($data['mode'])&&is_string($data['mode'])?sanitize_key($data['mode']):'';
    $id=isset($data['id'])?absint($data['id']):0;
    if('save'===$mode) $result=crmb_suppliers_save($data);
    elseif('archive'===$mode||'restore'===$mode) $result=crmb_suppliers_archive($id,'restore'===$mode);
    elseif('import'===$mode) {
        $import=crmb_suppliers_import_csv($_FILES['supplier_csv']??array(),$data['source']??'');
        set_transient('crmb_suppliers_import_'.get_current_user_id(),$import,120);
        $result=$import['status'];
    } else $result='invalid';
    $args=array('crm_section'=>'suppliers','supplier_notice'=>$result);
    if('saved'===$result&&$id) $args['supplier_id']=$id;
    wp_safe_redirect(add_query_arg($args,home_url('/main/')));
    exit;
}
add_action('admin_post_crmb_suppliers','crmb_suppliers_handle_post');
