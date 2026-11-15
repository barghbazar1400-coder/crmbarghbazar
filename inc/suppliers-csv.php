<?php
/** Strict UTF-8 importer for the eight columns of the supplier exhibition CSV. */
defined('ABSPATH') || exit;
function crmb_suppliers_csv_mapping() {
    return array(
        'نام'=>'supplier_name',
        'نام خانوادگی'=>'family_name',
        'زمینه فعالیت'=>'activity',
        'تلفن همراه'=>'mobile',
        'آدرس وبسایت /شبکه های مجازی'=>'website_social',
        'تلفن ثابت'=>'landline',
        'آدرس'=>'address',
        'توضیحات'=>'notes',
    );
}
function crmb_suppliers_parse_csv($handle,$source) {
    $header=fgetcsv($handle,0,',','"','');
    if(!is_array($header)) return array('status'=>'invalid_csv');
    $index=array();
    foreach($header as $i=>$name) {
        $name=trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$name));
        if($name!==''&&!array_key_exists($name,$index)) $index[$name]=$i;
    }
    foreach(crmb_suppliers_csv_mapping() as $label=>$field) {
        if(!array_key_exists($label,$index)) return array('status'=>'invalid_header');
    }
    $result=array(); $occurrences=array(); $line=1;
    while(false!==($cells=fgetcsv($handle,0,',','"',''))) {
        ++$line;
        if(count($result)>=5000) return array('status'=>'too_many');
        $raw=array(); $filled=false;
        foreach(crmb_suppliers_csv_mapping() as $label=>$field) {
            $value=isset($cells[$index[$label]])?(string)$cells[$index[$label]]:'';
            if(trim($value)!=='') $filled=true;
            $raw[$field]=$value;
        }
        if(!$filled) continue;
        $raw['source']=$source;
        $clean=crmb_suppliers_clean($raw);
        if(false===$clean) return array('status'=>'invalid_row','line'=>$line);
        // Include each occurrence to retain separate identical-looking contacts.
        $hash=hash('sha256',wp_json_encode($raw,JSON_UNESCAPED_UNICODE));
        $occurrences[$hash]=($occurrences[$hash]??0)+1;
        $clean['source_hash']=hash('sha256',$hash.'#'.$occurrences[$hash]);
        $result[]=$clean;
    }
    return $result ? array('status'=>'parsed','rows'=>$result) : array('status'=>'empty');
}
function crmb_suppliers_import_csv($file,$source='') {
    if(!crmb_suppliers_can_manage()) return array('status'=>'denied');
    if(!crmb_suppliers_ready()) return array('status'=>'error');
    if(!is_array($file) || (int)($file['error']??1)!==UPLOAD_ERR_OK
        || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])
        || (int)($file['size']??0)<=0 || (int)($file['size']??0)>3145728
        || strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv'
    ) return array('status'=>'invalid_csv');
    $source=trim(sanitize_text_field($source));
    if($source==='') $source='ورود فایل CSV';
    if(crmb_suppliers_strlen($source)>140) return array('status'=>'invalid');
    $handle=fopen($file['tmp_name'],'rb');
    if(!$handle) return array('status'=>'invalid_csv');
    $parsed=crmb_suppliers_parse_csv($handle,$source);
    fclose($handle);
    if($parsed['status']!=='parsed') return $parsed;

    // All-or-nothing import. Never overwrite a manually edited existing profile.
    global $wpdb; $t=crmb_suppliers_table(); $now=gmdate('Y-m-d H:i:s');
    if(false===$wpdb->query('START TRANSACTION')) return array('status'=>'error');
    $created=0; $skipped=0;
    foreach($parsed['rows'] as $values) {
        $values['created_by']=get_current_user_id();
        $values['updated_by']=get_current_user_id();
        $values['created_utc']=$now;
        $values['updated_utc']=$now;
        $columns=array_keys($values);
        $names=implode(',', $columns);
        $placeholders=implode(',',array_fill(0,count($columns),'%s'));
        $written=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$t} ({$names}) VALUES ({$placeholders})",
            ...array_values($values)
        ));
        if(false===$written) {
            $wpdb->query('ROLLBACK');
            return array('status'=>'error');
        }
        if($written===1) ++$created; else ++$skipped;
    }
    if(false===$wpdb->query('COMMIT')) {
        $wpdb->query('ROLLBACK');
        return array('status'=>'error');
    }
    return array(
        'status'=>'imported', 'created'=>$created,'skipped'=>$skipped,
        'total'=>count($parsed['rows'])
    );
}
