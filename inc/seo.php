<?php
/** SEO workspace. CRM tiers are deliberately separate from WordPress administrator roles. */
defined( 'ABSPATH' ) || exit;
function crmb_seo_tables() { global $wpdb; return array('projects'=>$wpdb->prefix.'crmb_seo_projects','members'=>$wpdb->prefix.'crmb_seo_members','items'=>$wpdb->prefix.'crmb_seo_items','history'=>$wpdb->prefix.'crmb_seo_history','tasks'=>$wpdb->prefix.'crmb_seo_task_links','images'=>$wpdb->prefix.'crmb_seo_images'); }
function crmb_seo_tiers() { return array('manager'=>'مدیر سئو','expert'=>'کارشناس سئو','intern'=>'کارآموز سئو'); }
function crmb_seo_level($user_id=0) { return get_user_meta($user_id ?: get_current_user_id(),'crmb_seo_level',true); }
function crmb_seo_access() { return is_user_logged_in() && (current_user_can('manage_options') || isset(crmb_seo_tiers()[crmb_seo_level()])); }
function crmb_seo_ready() { return '1'===get_option('crmb_seo_schema_v1'); }
function crmb_seo_tabs() { return array('overview'=>'پیش‌خوان سئو','projects'=>'پروژه‌ها','page'=>'صفحات هدف','keyword'=>'کلمات کلیدی','content'=>'درج و برنامه محتوا','tasks'=>'وظایف و کنترل کیفیت','technical'=>'بررسی فنی','report'=>'گزارش‌های سئو','training'=>'آموزش کارآموز','team'=>'اعضا و دسترسی‌ها'); }
function crmb_seo_kinds() { return array_intersect_key(crmb_seo_tabs(),array_flip(array('page','keyword','content','technical','report','training'))); }
function crmb_seo_states() { return array('draft'=>'در حال آماده‌سازی','submitted'=>'منتظر بازبینی','changes'=>'نیازمند اصلاح','reviewed'=>'بازبینی کارشناس انجام شد','approved'=>'تأیید نهایی مدیر','published'=>'انتشار در سایت ثبت شد'); }
function crmb_seo_install() {
    if(crmb_seo_ready() || !crmb_progress_ready()) return;
    global $wpdb; $t=crmb_seo_tables(); $charset=$wpdb->get_charset_collate(); require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$t['projects']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(180) NOT NULL,
        site_url varchar(500) NOT NULL,
        created_by bigint(20) unsigned NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id)
    ) ENGINE=InnoDB $charset;");
    dbDelta("CREATE TABLE {$t['members']} (
        project_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (project_id,user_id),
        KEY employee (user_id)
    ) ENGINE=InnoDB $charset;");
    dbDelta("CREATE TABLE {$t['items']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        project_id bigint(20) unsigned NOT NULL,
        kind varchar(16) NOT NULL,
        title varchar(180) NOT NULL,
        body text NOT NULL,
        meta_json text NOT NULL,
        owner_id bigint(20) unsigned NOT NULL,
        reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
        due_day date DEFAULT NULL,
        status varchar(16) NOT NULL DEFAULT 'draft',
        version int(10) unsigned NOT NULL DEFAULT 1,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY project_kind (project_id,kind,status),
        KEY employee (owner_id,reviewer_id)
    ) ENGINE=InnoDB $charset;");
    dbDelta("CREATE TABLE {$t['history']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        item_id bigint(20) unsigned NOT NULL,
        actor_id bigint(20) unsigned NOT NULL,
        action varchar(20) NOT NULL,
        note text NOT NULL,
        snapshot longtext NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY item_history (item_id,id)
    ) ENGINE=InnoDB $charset;");
    dbDelta("CREATE TABLE {$t['tasks']} (
        task_id bigint(20) unsigned NOT NULL,
        project_id bigint(20) unsigned NOT NULL,
        item_id bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (task_id),
        KEY project_tasks (project_id)
    ) ENGINE=InnoDB $charset;");
    dbDelta("CREATE TABLE {$t['images']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        item_id bigint(20) unsigned NOT NULL,
        mime varchar(32) NOT NULL,
        image_data longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY item_images (item_id)
    ) ENGINE=InnoDB $charset;");
    foreach($t as $table) {
        $info=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)));
        if(!$info || 'innodb'!==strtolower($info->Engine)) return;
    }
    update_option('crmb_seo_schema_v1','1',false);
}
add_action('init','crmb_seo_install',10);
add_filter('crmbarghbazar_menu_registry',function($items){$items['seo']=array('label'=>'دپارتمان سئو','access_callback'=>'crmb_seo_access','children'=>crmb_seo_tabs());return $items;});
function crmb_seo_is_member($project,$user) { global $wpdb; $t=crmb_seo_tables(); return (bool)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$t['members']} WHERE project_id=%d AND user_id=%d",$project,$user)); }
function crmb_seo_project($id) {
    if(!crmb_seo_access() || !crmb_seo_ready() || !$id) return null;
    global $wpdb; $t=crmb_seo_tables();
    $scope=current_user_can('manage_options')?'':$wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$t['members']} m WHERE m.project_id=p.id AND m.user_id=%d)",get_current_user_id());
    return $wpdb->get_row($wpdb->prepare("SELECT p.* FROM {$t['projects']} p WHERE p.id=%d{$scope}",$id));
}
function crmb_seo_manage($project) { return crmb_seo_project($project) && (current_user_can('manage_options') || 'manager'===crmb_seo_level()); }
function crmb_seo_projects() {
    if(!crmb_seo_access() || !crmb_seo_ready()) return array();
    global $wpdb; $t=crmb_seo_tables();
    $scope=current_user_can('manage_options')?'':$wpdb->prepare(" WHERE EXISTS (SELECT 1 FROM {$t['members']} m WHERE m.project_id=p.id AND m.user_id=%d)",get_current_user_id());
    return $wpdb->get_results("SELECT p.* FROM {$t['projects']} p{$scope} ORDER BY p.name,p.id") ?: array();
}
function crmb_seo_item_scope($alias='i') {
    if(current_user_can('manage_options') || 'manager'===crmb_seo_level()) return '1=1';
    global $wpdb; $uid=get_current_user_id();
    return $wpdb->prepare("({$alias}.owner_id=%d OR {$alias}.reviewer_id=%d OR ({$alias}.kind IN ('page','keyword') AND {$alias}.status='approved'))",$uid,$uid);
}
function crmb_seo_item($id,$lock=false) {
    if(!crmb_seo_access() || !crmb_seo_ready() || !$id) return null;
    global $wpdb; $t=crmb_seo_tables(); $scope=crmb_seo_item_scope();
    $item=$wpdb->get_row($wpdb->prepare("SELECT i.* FROM {$t['items']} i WHERE i.id=%d AND {$scope}".($lock?' FOR UPDATE':''),$id));
    return $item && crmb_seo_project((int)$item->project_id) ? $item : null;
}
function crmb_seo_url($value) { $url=esc_url_raw($value,array('http','https')); return $url && wp_http_validate_url($url) ? $url : ''; }
function crmb_seo_history($id,$action,$note,$snapshot) { global $wpdb; $t=crmb_seo_tables(); return false!==$wpdb->insert($t['history'],array('item_id'=>$id,'actor_id'=>get_current_user_id(),'action'=>$action,'note'=>$note,'snapshot'=>wp_json_encode($snapshot,JSON_UNESCAPED_UNICODE),'created_utc'=>gmdate('Y-m-d H:i:s'))); }
function crmb_seo_project_save($data) {
    if(!current_user_can('manage_options') && 'manager'!==crmb_seo_level()) return 'denied';
    global $wpdb; $t=crmb_seo_tables(); $id=crmb_work_id($data,'project'); $name=crmb_work_text($data,'name'); $url=crmb_seo_url(crmb_work_text($data,'site_url'));
    if(!$name || strlen($name)>180 || !$url || strlen($url)>500) return 'invalid';
    if($id) {
        if(!crmb_seo_manage($id)) return 'denied';
        return false===$wpdb->update($t['projects'],array('name'=>$name,'site_url'=>$url),array('id'=>$id))?'error':'saved';
    }
    if(false===$wpdb->query('START TRANSACTION')) return 'error';
    if(false===$wpdb->insert($t['projects'],array('name'=>$name,'site_url'=>$url,'created_by'=>get_current_user_id(),'created_utc'=>gmdate('Y-m-d H:i:s')))) return crmb_progress_fail();
    $id=(int)$wpdb->insert_id;
    if(false===$wpdb->insert($t['members'],array('project_id'=>$id,'user_id'=>get_current_user_id()))) return crmb_progress_fail();
    return crmb_progress_commit();
}
function crmb_seo_membership($data) {
    $project=crmb_work_id($data,'project'); $user=crmb_work_id($data,'member'); $tier=crmb_seo_level($user);
    if(!crmb_seo_manage($project)) return 'denied';
    if(!$user || !get_userdata($user) || (!isset(crmb_seo_tiers()[$tier]) && !user_can($user,'manage_options'))) return 'invalid';
    global $wpdb; $t=crmb_seo_tables();
    if('remove_member'===crmb_work_text($data,'task')) {
        if($user===get_current_user_id() && !current_user_can('manage_options')) return 'denied';
        // Retain membership while assignments/reviews or unfinished tasks still require this person.
        $busy=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['items']} WHERE project_id=%d AND (owner_id=%d OR reviewer_id=%d) AND status NOT IN ('approved','published') LIMIT 1",$project,$user,$user));
        if($wpdb->last_error) return 'error';
        if($busy) return 'in_use';
        $work=crmb_work_tables();
        $busy=$wpdb->get_var($wpdb->prepare("SELECT w.id FROM {$work['tasks']} w INNER JOIN {$t['tasks']} l ON l.task_id=w.id WHERE l.project_id=%d AND w.assigned_to=%d AND w.status NOT IN ('done','cancelled') LIMIT 1",$project,$user));
        if($wpdb->last_error) return 'error';
        if($busy) return 'in_use';
        return false===$wpdb->delete($t['members'],array('project_id'=>$project,'user_id'=>$user))?'error':'saved';
    }
    if(crmb_seo_is_member($project,$user)) return 'saved';
    return false===$wpdb->insert($t['members'],array('project_id'=>$project,'user_id'=>$user))?'error':'saved';
}
/** Validate typed SEO metadata. No traffic metric is invented or fetched implicitly. */
function crmb_seo_meta($data,$kind,$old=array(),$manager=false) {
    $meta=array();
    foreach(array('target_url'=>500,'keyword'=>180,'seo_title'=>180,'meta_description'=>500,'source'=>180,'brief'=>10000) as $key=>$max) {
        $value=crmb_work_text($data,$key,'brief'===$key);
        if(strlen($value)>$max) return false;
        if('target_url'===$key && $value) { $value=crmb_seo_url($value); if(!$value) return false; }
        $meta[$key]=$value;
    }
    if(!$manager && isset($old['brief'])) $meta['brief']=$old['brief'];
    $meta['intent']=crmb_work_text($data,'intent') ?: 'informational';
    if(!in_array($meta['intent'],array('informational','commercial','transactional','navigational'),true)) return false;
    $meta['severity']=crmb_work_text($data,'severity') ?: 'normal';
    if(!in_array($meta['severity'],array('low','normal','high'),true)) return false;
    $meta['priority']=crmb_work_text($data,'priority') ?: 'normal';
    if(!isset(crmb_work_priorities()[$meta['priority']])) return false;
    $meta['page_type']=crmb_work_text($data,'page_type') ?: 'article';
    if(!in_array($meta['page_type'],array('article','product','category','landing'),true)) return false;
    $lines=$manager?preg_split('/\R/u',crmb_work_text($data,'checklist',true)):($old['checklist']??array());
    $lines=array_values(array_filter(array_map('trim',$lines?:array()),function($line){return ''!==$line;}));
    if(count($lines)>12) return false;
    foreach($lines as $line) if(strlen($line)>180) return false;
    $checked=isset($data['checked']) && is_array($data['checked'])?$data['checked']:array(); $done=array();
    foreach($checked as $index) { if(!is_scalar($index) || !preg_match('/^[0-9]{1,2}$/D',(string)$index) || !isset($lines[(int)$index])) return false; $done[]=(int)$index; }
    if($manager && $lines!==($old['checklist']??array())) $done=array();
    $meta['checklist']=$lines; $meta['checked']=array_values(array_unique($done));
    if('report'===$kind) {
        $from=crmb_leave_parse_day(crmb_work_text($data,'date_from')); $to=crmb_leave_parse_day(crmb_work_text($data,'date_to'));
        if(!$from || !$to || $from>$to || $to>crmb_att_today() || !$meta['source']) return false;
        $meta['date_from']=$from; $meta['date_to']=$to;
        foreach(array('clicks','impressions') as $key) { $value=crmb_work_text($data,$key); if(''!==$value && (!preg_match('/^[0-9]{1,10}$/D',$value) || (float)$value>2147483647)) return false; $meta[$key]=''===$value?null:(int)$value; }
        if(null!==$meta['clicks'] && null!==$meta['impressions'] && $meta['clicks']>$meta['impressions']) return false;
        $position=crmb_work_text($data,'position');
        if(''!==$position && (!preg_match('/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/D',$position) || (float)$position>9999)) return false;
        $meta['position']=''===$position?null:(float)$position;
    }
    return $meta;
}
function crmb_seo_item_save($data) {
    global $wpdb; $t=crmb_seo_tables(); $project=crmb_work_id($data,'project'); $id=crmb_work_id($data,'item'); $kind=crmb_work_text($data,'kind'); $uid=get_current_user_id();
    if(!crmb_seo_project($project) || !isset(crmb_seo_kinds()[$kind])) return 'denied';
    $manager=(bool)crmb_seo_manage($project); $tier=crmb_seo_level();
    if(!$id && !$manager && ('intern'===$tier || 'training'===$kind)) return 'denied';
    $title=crmb_work_text($data,'title'); $body_raw=isset($data['body']) && is_string($data['body'])?$data['body']:'';
    if(!$title || strlen($title)>180 || strlen($body_raw)>30000) return 'invalid';
    if(false===$wpdb->query('START TRANSACTION')) return 'error';
    $old=$id?crmb_seo_item($id,true):null;
    if($id && (!$old || (int)$old->project_id!==$project || $old->kind!==$kind)) return crmb_progress_fail('denied');
    if($old && (!in_array($old->status,array('draft','changes'),true) || (!$manager && (int)$old->owner_id!==$uid))) return crmb_progress_fail('locked');
    if($old && crmb_work_id($data,'version')!==(int)$old->version) return crmb_progress_fail('changed');
    $owner=$manager?crmb_work_id($data,'owner'):$uid; $reviewer=$manager?crmb_work_id($data,'reviewer'):($old?(int)$old->reviewer_id:0);
    if(!$owner || !crmb_seo_is_member($project,$owner) || ($reviewer && ($reviewer===$owner || !crmb_seo_is_member($project,$reviewer) || !in_array(crmb_seo_level($reviewer),array('expert','manager'),true)))) return crmb_progress_fail('invalid');
    if('intern'===crmb_seo_level($owner) && !$reviewer) return crmb_progress_fail('invalid');
    $raw_due=crmb_work_text($data,'due_day'); $due=$raw_due?crmb_leave_parse_day($raw_due):null;
    if(false===$due) return crmb_progress_fail('invalid');
    if(!$manager && $old) $due=$old->due_day;
    $old_meta=$old?json_decode($old->meta_json,true):array(); $meta=crmb_seo_meta($data,$kind,$old_meta?:array(),$manager);
    if(false===$meta) return crmb_progress_fail('invalid');
    $values=array('title'=>$title,'body'=>crmb_report_html($body_raw),'meta_json'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE),'owner_id'=>$owner,'reviewer_id'=>$reviewer,'due_day'=>$due,'updated_utc'=>gmdate('Y-m-d H:i:s'),'version'=>$old?(int)$old->version+1:1);
    if($old) $result=$wpdb->update($t['items'],$values,array('id'=>$id,'version'=>$old->version));
    else { $result=$wpdb->insert($t['items'],$values+array('project_id'=>$project,'kind'=>$kind,'status'=>'draft','created_utc'=>gmdate('Y-m-d H:i:s'))); $id=(int)$wpdb->insert_id; }
    if(false===$result || !$id || !crmb_seo_history($id,'saved','',$values)) return crmb_progress_fail();
    return crmb_progress_commit();
}
function crmb_seo_review($data) {
    global $wpdb; $t=crmb_seo_tables(); $id=crmb_work_id($data,'item'); $next=crmb_work_text($data,'state'); $note=crmb_work_text($data,'feedback',true); $uid=get_current_user_id();
    if(strlen($note)>10000 || false===$wpdb->query('START TRANSACTION')) return 'invalid';
    $item=crmb_seo_item($id,true);
    if(!$item) return crmb_progress_fail('denied');
    if(crmb_work_id($data,'version')!==(int)$item->version) return crmb_progress_fail('changed');
    $manager=crmb_seo_manage((int)$item->project_id); $owner=(int)$item->owner_id===$uid;
    $reviewer=(int)$item->reviewer_id===$uid && in_array(crmb_seo_level(),array('expert','manager'),true);
    $allowed=false;
    if('submitted'===$next && $owner && in_array($item->status,array('draft','changes'),true)) {
        $meta=json_decode($item->meta_json,true)?:array();
        $allowed=''!==trim(wp_strip_all_tags($item->body)) && ('intern'!==crmb_seo_level($uid) || (int)$item->reviewer_id>0);
        if(count($meta['checklist']??array())!==count($meta['checked']??array())) $allowed=false;
    }
    if('changes'===$next && !$owner && $note && (($manager && in_array($item->status,array('submitted','reviewed','approved','published'),true)) || ($reviewer && in_array($item->status,array('submitted','reviewed'),true)))) $allowed=true;
    if('reviewed'===$next && $reviewer && !$owner && 'submitted'===$item->status) $allowed=true;
    // Intern output must pass the assigned expert before final managerial acceptance.
    if('approved'===$next && $manager && !$owner && (('intern'===crmb_seo_level((int)$item->owner_id) && 'reviewed'===$item->status) || ('intern'!==crmb_seo_level((int)$item->owner_id) && in_array($item->status,array('submitted','reviewed'),true)))) $allowed=true;
    if('published'===$next && $manager && 'content'===$item->kind && 'approved'===$item->status) {
        $meta=json_decode($item->meta_json,true)?:array();
        $allowed=!empty($meta['target_url']);
    }
    if(!$allowed) return crmb_progress_fail('denied');
    $values=array('status'=>$next,'version'=>(int)$item->version+1,'updated_utc'=>gmdate('Y-m-d H:i:s'));
    if(false===$wpdb->update($t['items'],$values,array('id'=>$id,'version'=>$item->version)) || !crmb_seo_history($id,$next,$note,(array)$item)) return crmb_progress_fail();
    return crmb_progress_commit();
}
/** Project managers can manage linked tasks, never all CRM tasks or HR. */
function crmb_seo_can_manage_task($task_id) {
    if(!crmb_seo_access() || !crmb_seo_ready() || (!current_user_can('manage_options') && 'manager'!==crmb_seo_level())) return false;
    global $wpdb; $t=crmb_seo_tables(); $project=$wpdb->get_var($wpdb->prepare("SELECT project_id FROM {$t['tasks']} WHERE task_id=%d",$task_id));
    return $project && crmb_seo_manage((int)$project);
}
function crmb_seo_task_save($data) {
    global $wpdb; $t=crmb_seo_tables(); $work=crmb_work_tables(); $project=crmb_work_id($data,'project');
    if(!crmb_seo_manage($project)) return 'denied';
    $owner=crmb_work_id($data,'owner'); $title=crmb_work_text($data,'title'); $body=crmb_work_text($data,'body',true); $priority=crmb_work_text($data,'priority');
    $item_id=crmb_work_id($data,'item'); $raw_due=crmb_work_text($data,'due_day'); $due=$raw_due?crmb_leave_parse_day($raw_due):null;
    if(!$owner || !crmb_seo_is_member($project,$owner) || !$title || strlen($title)>180 || strlen($body)>10000 || !isset(crmb_work_priorities()[$priority]) || false===$due) return 'invalid';
    if($item_id) { $item=crmb_seo_item($item_id); if(!$item || (int)$item->project_id!==$project) return 'invalid'; }
    $work_id=crmb_work_id($data,'work_id');
    if($work_id) {
        $linked=$wpdb->get_var($wpdb->prepare("SELECT project_id FROM {$t['tasks']} WHERE task_id=%d",$work_id));
        if((int)$linked!==$project) return 'denied';
        return crmb_progress_edit_task($work_id,array('title'=>$title,'description'=>$body,'assigned_to'=>$owner,'priority'=>$priority,'due_day'=>$due,'updated_utc'=>gmdate('Y-m-d H:i:s')));
    }
    if(false===$wpdb->query('START TRANSACTION')) return 'error';
    if(false===$wpdb->insert($work['tasks'],array('title'=>$title,'description'=>$body,'assigned_to'=>$owner,'created_by'=>get_current_user_id(),'priority'=>$priority,'due_day'=>$due,'status'=>'todo','created_utc'=>gmdate('Y-m-d H:i:s'),'updated_utc'=>gmdate('Y-m-d H:i:s')))) return crmb_progress_fail();
    $id=(int)$wpdb->insert_id;
    if(false===$wpdb->insert($t['tasks'],array('task_id'=>$id,'project_id'=>$project,'item_id'=>$item_id)) || !crmb_progress_event($id,'edited','وظیفه در دپارتمان سئو واگذار شد.')) return crmb_progress_fail();
    return crmb_progress_commit();
}
function crmb_seo_handle_post() {
    if(!crmb_seo_access()) wp_die('دسترسی مجاز نیست.','',array('response'=>403));
    if('POST'!==($_SERVER['REQUEST_METHOD']??'')) wp_die('روش درخواست مجاز نیست.','',array('response'=>405));
    check_admin_referer('crmb_seo','crmb_seo_nonce');
    $data=wp_unslash($_POST); $task=crmb_work_text($data,'task'); $notice='invalid';
    if(!crmb_seo_ready()) $notice='error';
    elseif('save_project'===$task) $notice=crmb_seo_project_save($data);
    elseif(in_array($task,array('add_member','remove_member'),true)) $notice=crmb_seo_membership($data);
    elseif('set_tier'===$task) $notice=crmb_seo_set_tier(crmb_work_id($data,'member'),crmb_work_text($data,'tier'));
    elseif('save_item'===$task) $notice=crmb_seo_item_save($data);
    elseif('review_item'===$task) $notice=crmb_seo_review($data);
    elseif('save_seo_task'===$task) $notice=crmb_seo_task_save($data);
    elseif('upload_seo_images'===$task) $notice=crmb_seo_images_save(crmb_work_id($data,'item'),$_FILES);
    elseif('delete_seo_image'===$task) $notice=crmb_seo_image_delete(crmb_work_id($data,'image'));
    if('save_item'===$task && !in_array($notice,array('denied','locked'),true)) {
        $key=crmb_seo_retry_key(crmb_work_id($data,'project'),crmb_work_text($data,'kind'),crmb_work_id($data,'item'));
        if('saved'===$notice) delete_transient($key);
        else set_transient($key,array('title'=>substr(crmb_work_text($data,'title'),0,180),'body'=>crmb_report_html(substr(isset($data['body']) && is_string($data['body'])?$data['body']:'',0,30000))),HOUR_IN_SECONDS);
    }
    $tab=crmb_work_text($data,'tab'); if(!isset(crmb_seo_tabs()[$tab])) $tab='overview';
    $args=array('crm_section'=>'seo','seo_tab'=>$tab,'seo_notice'=>$notice);
    $project=crmb_work_id($data,'project'); if($project) $args['project']=$project;
    $item=crmb_work_id($data,'item'); if($item && 'save_seo_task'!==$task) $args['item']=$item;
    wp_safe_redirect(add_query_arg($args,home_url('/main/'))); exit;
}
add_action('admin_post_crmb_seo','crmb_seo_handle_post');
function crmb_seo_form_fields($task,$project=0,$tab='overview') {
    echo '<input type="hidden" name="action" value="crmb_seo"><input type="hidden" name="task" value="'.esc_attr($task).'"><input type="hidden" name="project" value="'.esc_attr($project).'"><input type="hidden" name="tab" value="'.esc_attr($tab).'">'; wp_nonce_field('crmb_seo','crmb_seo_nonce');
}
function crmb_seo_members($project) {
    global $wpdb; $t=crmb_seo_tables();
    return $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$t['members']} WHERE project_id=%d ORDER BY user_id",$project)) ?: array();
}
function crmb_seo_retry_key($project,$kind,$item) { return 'crmb_seo_retry_'.get_current_user_id().'_'.absint($project).'_'.sanitize_key($kind).'_'.absint($item); }
function crmb_seo_set_tier($user,$tier) {
    if(!current_user_can('manage_options')) return 'denied';
    if(!$user || !get_userdata($user) || (''!==$tier && !isset(crmb_seo_tiers()[$tier]))) return 'invalid';
    if(''===$tier) delete_user_meta($user,'crmb_seo_level'); else update_user_meta($user,'crmb_seo_level',$tier);
    return crmb_seo_level($user)===$tier?'saved':'error';
}
