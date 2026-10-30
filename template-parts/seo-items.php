<?php
/** Typed SEO records share one versioned submission/review workflow. */
defined('ABSPATH') || exit;
$kind=$seo_tab; $item_id=crmb_work_id($_GET,'item'); $item=$item_id?crmb_seo_item($item_id):null;
if($item && ((int)$item->project_id!==$seo_project_id || $item->kind!==$kind)) $item=null;
if($item_id && !$item) echo '<p role="status">رکورد در دسترس نیست یا اجازه مشاهده آن را ندارید.</p>';
$can_create=$seo_manage || ('expert'===crmb_seo_level() && 'training'!==$kind);
$can_edit=$item && in_array($item->status,array('draft','changes'),true) && ($seo_manage || (int)$item->owner_id===get_current_user_id());
$show_form=$can_edit || (!$item_id && $can_create);
$meta=$item?(json_decode($item->meta_json,true)?:array()):array();
$val=function($key,$default='') use($meta){return $meta[$key]??$default;};
$retry=get_transient(crmb_seo_retry_key($seo_project_id,$kind,$item_id));
?>
<?php if($show_form) : ?>
<section class="crm-access-card"><h2><?php echo $item?'ویرایش رکورد':'ثبت مورد جدید'; ?></h2>
    <?php if(is_array($retry)) : ?><p role="status">متن واردشده پیش از خطا بازیابی شده است؛ اطلاعات دیگر و نسخه جاری را پیش از ذخیره بررسی کنید.</p><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-access-form">
        <?php crmb_seo_form_fields('save_item',$seo_project_id,$kind); ?><input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>"><?php if($item) : ?><input type="hidden" name="item" value="<?php echo esc_attr($item->id); ?>"><input type="hidden" name="version" value="<?php echo esc_attr($item->version); ?>"><?php endif; ?>
        <label for="seo-item-title"><?php echo 'keyword'===$kind?'عبارت کلیدی':'عنوان'; ?></label><input id="seo-item-title" name="title" required maxlength="60" value="<?php echo esc_attr(is_array($retry)?$retry['title']:($item?$item->title:'')); ?>">
        <label for="seo-priority">اولویت</label><select id="seo-priority" name="priority"><?php foreach(crmb_work_priorities() as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($val('priority','normal'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        <?php if(in_array($kind,array('page','content'),true)) : ?><label for="seo-page-type">نوع صفحه / محتوا</label><select id="seo-page-type" name="page_type"><?php foreach(array('article'=>'مقاله','product'=>'محصول','category'=>'دسته‌بندی','landing'=>'صفحه فرود') as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($val('page_type','article'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if($seo_manage) : ?>
            <label for="seo-owner">مسئول</label><select id="seo-owner" name="owner" required><?php foreach(crmb_seo_members($seo_project_id) as $member) : ?><option value="<?php echo esc_attr($member); ?>" <?php selected($item?$item->owner_id:get_current_user_id(),$member); ?>><?php echo esc_html(crmb_work_user_name($member).' — '.(crmb_seo_tiers()[crmb_seo_level($member)]??'مدیر کل')); ?></option><?php endforeach; ?></select>
            <label for="seo-reviewer">بازبین کارشناس (برای کارآموز الزامی)</label><select id="seo-reviewer" name="reviewer"><option value="0">بازبینی مستقیم مدیر</option><?php foreach(crmb_seo_members($seo_project_id) as $member) : if(!in_array(crmb_seo_level($member),array('expert','manager'),true)) continue; ?><option value="<?php echo esc_attr($member); ?>" <?php selected($item?$item->reviewer_id:0,$member); ?>><?php echo esc_html(crmb_work_user_name($member)); ?></option><?php endforeach; ?></select>
            <label for="seo-due">مهلت (شمسی، اختیاری)</label><input id="seo-due" name="due_day" dir="ltr" placeholder="1405-08-15" value="<?php echo esc_attr($item && $item->due_day?crmb_att_jdate($item->due_day):''); ?>">
            <label for="seo-brief">شرح سفارش / راهنمای انجام</label><textarea id="seo-brief" name="brief" rows="4" maxlength="3000"><?php echo esc_textarea($val('brief')); ?></textarea>
            <label for="seo-checklist">چک‌لیست تحویل؛ هر معیار در یک خط (حداکثر ۱۲ مورد)</label><textarea id="seo-checklist" name="checklist" rows="4"><?php echo esc_textarea(implode("\n",$meta['checklist']??array())); ?></textarea>
        <?php endif; ?>
        <?php if(!empty($meta['checklist'])) : ?><fieldset class="crm-access-permissions"><legend>کنترل معیارهای تحویل</legend><?php foreach($meta['checklist'] as $index=>$line) : ?><label class="crm-access-check"><input type="checkbox" name="checked[]" value="<?php echo esc_attr($index); ?>" <?php checked(in_array($index,$meta['checked']??array(),true)); ?>><?php echo esc_html($line); ?></label><?php endforeach; ?></fieldset><?php endif; ?>
        <?php if(in_array($kind,array('page','keyword','content','technical','report'),true)) : ?><label for="seo-target">آدرس صفحه هدف (اختیاری)</label><input id="seo-target" name="target_url" type="url" dir="ltr" maxlength="500" value="<?php echo esc_attr($val('target_url')); ?>"><?php endif; ?>
        <?php if('keyword'===$kind) : ?><label for="seo-intent">هدف جست‌وجو</label><select id="seo-intent" name="intent"><?php foreach(array('informational'=>'اطلاعاتی','commercial'=>'بررسی و مقایسه','transactional'=>'خرید / اقدام','navigational'=>'یافتن برند یا صفحه') as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($val('intent','informational'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if('content'===$kind) : ?>
            <label for="seo-keyword">کلمه کلیدی اصلی</label><input id="seo-keyword" name="keyword" maxlength="60" value="<?php echo esc_attr($val('keyword')); ?>">
            <label for="seo-title-meta">عنوان سئو</label><input id="seo-title-meta" name="seo_title" maxlength="60" value="<?php echo esc_attr($val('seo_title')); ?>">
            <label for="seo-description-meta">توضیحات متا</label><textarea id="seo-description-meta" name="meta_description" maxlength="160" rows="2"><?php echo esc_textarea($val('meta_description')); ?></textarea>
            <p class="crm-access-muted">محتوا در CRM نوشته و تأیید می‌شود. ثبت «منتشر شد» فقط ثبت وضعیت است؛ هیچ تغییری روی سایت مقصد انجام نمی‌دهد.</p>
        <?php endif; ?>
        <?php if('technical'===$kind) : ?><label for="seo-severity">شدت مشکل</label><select id="seo-severity" name="severity"><?php foreach(array('low'=>'کم','normal'=>'متوسط','high'=>'زیاد') as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($val('severity','normal'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if('report'===$kind) : ?>
            <label for="seo-report-from">از تاریخ (شمسی)</label><input id="seo-report-from" name="date_from" dir="ltr" required value="<?php echo esc_attr($val('date_from')?crmb_att_jdate($val('date_from')):crmb_att_jdate(crmb_att_today())); ?>">
            <label for="seo-report-to">تا تاریخ (شمسی)</label><input id="seo-report-to" name="date_to" dir="ltr" required value="<?php echo esc_attr($val('date_to')?crmb_att_jdate($val('date_to')):crmb_att_jdate(crmb_att_today())); ?>">
            <label for="seo-source">منبع آمار و فیلترها</label><input id="seo-source" name="source" required maxlength="60" placeholder="مثلاً سرچ کنسول؛ کل سایت؛ جست‌وجوی وب" value="<?php echo esc_attr($val('source')); ?>">
            <label for="seo-clicks">کلیک (اختیاری)</label><input id="seo-clicks" name="clicks" type="number" min="0" max="2147483647" value="<?php echo esc_attr($val('clicks')); ?>">
            <label for="seo-impressions">نمایش (اختیاری)</label><input id="seo-impressions" name="impressions" type="number" min="0" max="2147483647" value="<?php echo esc_attr($val('impressions')); ?>">
            <label for="seo-position">جایگاه متوسط (اختیاری)</label><input id="seo-position" name="position" type="number" min="0" max="9999" step="0.001" value="<?php echo esc_attr($val('position')); ?>">
            <p class="crm-access-muted">آمار در این نسخه دستی و براساس منبعی است که ثبت می‌کنید؛ اتصال خودکار گوگل برقرار نشده است. خانه خالی به معنی «ثبت نشده» است، نه صفر.</p>
        <?php endif; ?>
        <label for="crmbseoeditor"><?php echo 'content'===$kind?'متن محتوا':'شرح، تحلیل یا نتیجه انجام'; ?></label>
        <?php wp_editor(is_array($retry)?$retry['body']:($item?$item->body:''),'crmbseoeditor',array('textarea_name'=>'body','textarea_rows'=>12,'media_buttons'=>false,'quicktags'=>false,'tinymce'=>array('directionality'=>'rtl','toolbar1'=>'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,undo,redo','toolbar2'=>'removeformat,hr'))); ?>
        <button class="crm-access-primary" type="submit">ذخیره پیش‌نویس</button>
    </form>
</section>
<?php endif; ?>
<?php if($item) : ?>
<section class="crm-access-card"><h2><?php echo esc_html($item->title); ?></h2>
    <p>وضعیت: <?php echo esc_html(crmb_seo_states()[$item->status]??'نامشخص'); ?> | مسئول: <?php echo esc_html(crmb_work_user_name($item->owner_id)); ?> | بازبین: <?php echo esc_html($item->reviewer_id?crmb_work_user_name($item->reviewer_id):'مدیر پروژه'); ?> | مهلت: <?php echo esc_html($item->due_day?crmb_att_jdate($item->due_day):'تعیین نشده'); ?></p>
    <?php if(!empty($meta['brief'])) : ?><h3>شرح سفارش</h3><p class="crmb-work-body"><?php echo esc_html($meta['brief']); ?></p><?php endif; ?>
    <?php if(!empty($meta['checklist'])) : ?><h3>چک‌لیست تحویل</h3><ul><?php foreach($meta['checklist'] as $index=>$line) : ?><li><?php echo in_array($index,$meta['checked']??array(),true)?'✓ ':'○ '; echo esc_html($line); ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php foreach(array('target_url'=>'صفحه هدف','keyword'=>'کلمه کلیدی','seo_title'=>'عنوان سئو','meta_description'=>'توضیحات متا','intent'=>'هدف جست‌وجو','severity'=>'شدت مشکل','priority'=>'اولویت','page_type'=>'نوع صفحه') as $key=>$label) : if(empty($meta[$key]) || ('intent'===$key && 'keyword'!==$kind) || ('severity'===$key && 'technical'!==$kind) || ('page_type'===$key && !in_array($kind,array('page','content'),true))) continue; ?><p><strong><?php echo esc_html($label); ?>:</strong> <?php $map=array('intent'=>array('informational'=>'اطلاعاتی','commercial'=>'بررسی و مقایسه','transactional'=>'خرید / اقدام','navigational'=>'یافتن برند یا صفحه'),'severity'=>array('low'=>'کم','normal'=>'متوسط','high'=>'زیاد'),'priority'=>crmb_work_priorities(),'page_type'=>array('article'=>'مقاله','product'=>'محصول','category'=>'دسته‌بندی','landing'=>'صفحه فرود')); echo esc_html($map[$key][$meta[$key]]??$meta[$key]); ?></p><?php endforeach; ?>
    <div class="crmb-report-content"><?php echo crmb_report_html(wpautop($item->body)); ?></div>
    <?php crmb_seo_image_gallery($item,$kind); ?>
    <?php if(crmb_seo_media_editable($item)) : ?><h3>تصاویر پیوست</h3><p class="crm-access-muted">ابتدا متن را ذخیره کنید، سپس عکس‌ها را بارگذاری کنید. حداکثر سه تصویر خصوصی، هرکدام تا یک مگابایت؛ تصاویر پایین متن نمایش داده می‌شوند.</p><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-access-form"><?php crmb_seo_form_fields('upload_seo_images',$seo_project_id,$kind); ?><input type="hidden" name="item" value="<?php echo esc_attr($item->id); ?>"><label for="seo-images">انتخاب تصاویر</label><input id="seo-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required><button class="crm-access-primary" type="submit">بارگذاری تصاویر</button></form><?php endif; ?>
    <?php if('report'===$kind) : ?><dl class="crmb-task-times"><?php foreach(array('date_from'=>'از روز','date_to'=>'تا روز','source'=>'منبع','clicks'=>'کلیک','impressions'=>'نمایش','position'=>'جایگاه متوسط') as $key=>$label) : ?><div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html(isset($meta[$key])?(str_starts_with($key,'date_')?crmb_att_jdate($meta[$key]):$meta[$key]):'ثبت نشده'); ?></dd></div><?php endforeach; ?><div><dt>نرخ کلیک</dt><dd><?php echo isset($meta['clicks'],$meta['impressions']) && $meta['impressions']>0?esc_html(number_format(100*$meta['clicks']/$meta['impressions'],2).'%'):'—'; ?></dd></div></dl><?php endif; ?>
    <?php $owner=(int)$item->owner_id===get_current_user_id(); $reviewer=(int)$item->reviewer_id===get_current_user_id(); $actions=array();
        if($owner && in_array($item->status,array('draft','changes'),true)) $actions['submitted']='ارسال برای بازبینی';
        if(!$owner && (($seo_manage && in_array($item->status,array('submitted','reviewed','approved','published'),true)) || ($reviewer && in_array($item->status,array('submitted','reviewed'),true)))) $actions['changes']='برگشت برای اصلاح';
        if(!$owner && $reviewer && 'submitted'===$item->status) $actions['reviewed']='بازبینی کارشناس انجام شد';
        if(!$owner && $seo_manage && ('intern'!==crmb_seo_level($item->owner_id) || 'reviewed'===$item->status) && in_array($item->status,array('submitted','reviewed'),true)) $actions['approved']='تأیید نهایی مدیر';
        if($seo_manage && 'content'===$kind && 'approved'===$item->status) $actions['published']='ثبت انتشار انجام‌شده در سایت';
        if($actions) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-access-form"><?php crmb_seo_form_fields('review_item',$seo_project_id,$kind); ?><input type="hidden" name="item" value="<?php echo esc_attr($item->id); ?>"><input type="hidden" name="version" value="<?php echo esc_attr($item->version); ?>"><label for="seo-review-state">اقدام</label><select id="seo-review-state" name="state"><?php foreach($actions as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select><label for="seo-feedback">بازخورد (برای برگشت به اصلاح الزامی)</label><textarea id="seo-feedback" name="feedback" maxlength="3000" rows="3"></textarea><button type="submit" class="crm-access-primary">ثبت اقدام</button></form>
    <?php endif; ?>
    <?php if($seo_manage) : ?><p><a href="<?php echo esc_url(add_query_arg(array('seo_tab'=>'tasks','item'=>$item->id),$seo_base)); ?>">ایجاد تسک مرتبط با این رکورد</a></p><?php endif; ?>
</section>
<section class="crm-access-card"><h2>سابقه و بازخورد</h2>
    <?php $hp=isset($_GET['history_page']) && is_scalar($_GET['history_page'])?max(1,min(100000,absint($_GET['history_page']))):1;
    $history=$wpdb->get_results($wpdb->prepare("SELECT actor_id,action,note,created_utc FROM {$seo_tables['history']} WHERE item_id=%d ORDER BY id DESC LIMIT 21 OFFSET %d",$item->id,($hp-1)*20));
    $history_error=(bool)$wpdb->last_error; $history_more=count($history?:array())>20;
    if($history_error) echo '<p role="status">دریافت سابقه انجام نشد.</p>';
    foreach(array_slice($history?:array(),0,20) as $event) : ?><article class="crmb-work-item"><p><?php echo esc_html(crmb_work_user_name($event->actor_id).' — '.(crmb_seo_states()[$event->action]??'ذخیره نسخه').' — '.crmb_work_date($event->created_utc)); ?></p><p class="crmb-work-body"><?php echo esc_html($event->note); ?></p></article><?php endforeach; $history_base=add_query_arg('item',$item->id,$seo_base); ?>
    <nav class="crm-access-pages"><?php if($hp>1) : ?><a href="<?php echo esc_url(add_query_arg('history_page',$hp-1,$history_base)); ?>">قبلی</a><?php endif; ?><?php if($history_more) : ?><a href="<?php echo esc_url(add_query_arg('history_page',$hp+1,$history_base)); ?>">بعدی</a><?php endif; ?></nav>
</section>
<?php endif; ?>
<section class="crm-access-card"><h2>فهرست <?php echo esc_html(crmb_seo_kinds()[$kind]); ?></h2>
    <?php $list_page=isset($_GET['seo_page']) && is_scalar($_GET['seo_page'])?max(1,min(100000,absint($_GET['seo_page']))):1; $scope=crmb_seo_item_scope();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.title,i.status,i.owner_id,i.due_day FROM {$seo_tables['items']} i WHERE i.project_id=%d AND i.kind=%s AND {$scope} ORDER BY i.updated_utc DESC,i.id DESC LIMIT 21 OFFSET %d",$seo_project_id,$kind,($list_page-1)*20)); $list_error=(bool)$wpdb->last_error; $more=count($rows?:array())>20;
    if($list_error) echo '<p role="status">دریافت فهرست انجام نشد.</p>'; elseif(!$rows) echo '<p class="crm-access-muted">هنوز رکورد مجازی برای نمایش وجود ندارد.</p>'; ?>
    <div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>عنوان</th><th>مسئول</th><th>وضعیت</th><th>مهلت</th></tr></thead><tbody><?php foreach(array_slice($rows?:array(),0,20) as $row) : ?><tr><td><a href="<?php echo esc_url(add_query_arg('item',$row->id,$seo_base)); ?>"><?php echo esc_html($row->title); ?></a></td><td><?php echo esc_html(crmb_work_user_name($row->owner_id)); ?></td><td><?php echo esc_html(crmb_seo_states()[$row->status]??'نامشخص'); ?></td><td><?php echo esc_html($row->due_day?crmb_att_jdate($row->due_day):'—'); ?></td></tr><?php endforeach; ?></tbody></table></div>
    <nav class="crm-access-pages"><?php if($list_page>1) : ?><a href="<?php echo esc_url(add_query_arg('seo_page',$list_page-1,$seo_base)); ?>">قبلی</a><?php endif; ?><?php if($more) : ?><a href="<?php echo esc_url(add_query_arg('seo_page',$list_page+1,$seo_base)); ?>">بعدی</a><?php endif; ?></nav>
    <?php if($item_id && $can_create) : ?><p><a href="<?php echo esc_url($seo_base); ?>">ثبت مورد جدید</a></p><?php endif; ?>
</section>
