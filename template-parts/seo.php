<?php
/** SEO workspace entry: project selection never grants project membership. */
defined('ABSPATH') || exit;
if(!crmb_seo_access()) return;
$seo_tab=crmb_work_text($_GET,'seo_tab') ?: 'overview';
if(!isset(crmb_seo_tabs()[$seo_tab])) $seo_tab='overview';
$seo_base=add_query_arg(array('crm_section'=>'seo','seo_tab'=>$seo_tab),home_url('/main/'));
?>
<section class="crm-access-screen crmb-work crmb-seo" aria-labelledby="seo-title">
    <header class="crm-access-header"><h1 id="seo-title">دپارتمان سئو — <?php echo esc_html(crmb_seo_tabs()[$seo_tab]); ?></h1><p>برنامه‌ریزی، تولید و بازبینی؛ سطح شما: <?php echo esc_html(current_user_can('manage_options')?'مدیر کل':(crmb_seo_tiers()[crmb_seo_level()]??'')); ?></p></header>
    <?php $notice=crmb_work_text($_GET,'seo_notice'); $notices=array('saved'=>'اطلاعات ثبت شد.','image_error'=>'حداکثر سه تصویر JPG/PNG/WebP، هرکدام تا یک مگابایت مجاز است؛ فایل‌ها را بررسی کنید.','denied'=>'دسترسی این عملیات را ندارید.','invalid'=>'اطلاعات یا ارتباط پروژه و مسئول معتبر نیست.','error'=>'ذخیره انجام نشد؛ دوباره تلاش کنید.','locked'=>'ابتدا خروجی باید برای اصلاح بازگردانده شود؛ فقط مسئول یا مدیر امکان ویرایش دارد.','changed'=>'این رکورد در پنجره دیگری تغییر کرده است؛ صفحه را تازه کنید.','in_use'=>'ابتدا مسئولیت‌ها و بازبینی‌ها و وظایف باز این عضو را منتقل کنید.'); if(isset($notices[$notice])) : ?><p class="crm-access-notice" role="status"><?php echo esc_html($notices[$notice]); ?></p><?php endif; ?>
    <?php if(!crmb_seo_ready()) : ?><p role="status">راه‌اندازی بخش سئو کامل نشده است. دوباره تلاش کنید.</p></section><?php return; endif;
    $seo_projects=crmb_seo_projects();
    $seo_project_id=crmb_work_id($_GET,'project');
    if(!$seo_project_id && $seo_projects) $seo_project_id=(int)$seo_projects[0]->id;
    $seo_project=$seo_project_id?crmb_seo_project($seo_project_id):null;
    $seo_manage=$seo_project && crmb_seo_manage($seo_project_id);
    global $wpdb; $seo_tables=crmb_seo_tables();
    if('projects'===$seo_tab || 'team'===$seo_tab) {
        include __DIR__.'/seo-management.php';
    } else { ?>
        <form method="get" action="<?php echo esc_url(home_url('/main/')); ?>" class="crm-access-form crmb-work-inline"><input type="hidden" name="crm_section" value="seo"><input type="hidden" name="seo_tab" value="<?php echo esc_attr($seo_tab); ?>"><label for="seo-project">پروژه</label><select id="seo-project" name="project"><?php foreach($seo_projects as $p) : ?><option value="<?php echo esc_attr($p->id); ?>" <?php selected($seo_project_id,$p->id); ?>><?php echo esc_html($p->name); ?></option><?php endforeach; ?></select><button class="crm-access-primary crmb-work-button" type="submit">نمایش پروژه</button></form>
        <?php if(!$seo_project) : ?><p class="crm-access-muted">پروژه مجازی برای نمایش ندارید. مدیر پروژه را ایجاد و اعضا را اضافه می‌کند.</p><?php else :
            $seo_base=add_query_arg('project',$seo_project_id,$seo_base);
            if('overview'===$seo_tab) include __DIR__.'/seo-overview.php';
            elseif('tasks'===$seo_tab) include __DIR__.'/seo-tasks.php';
            elseif(isset(crmb_seo_kinds()[$seo_tab])) include __DIR__.'/seo-items.php';
        endif;
    } ?>
</section>
