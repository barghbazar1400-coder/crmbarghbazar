<?php
/** SEO project tasks use the existing task/timer engine. */
defined('ABSPATH') || exit;
$work_tables=crmb_work_tables(); $work_id=crmb_work_id($_GET,'edit_work'); $edit_work=null;
if($work_id && $seo_manage) $edit_work=$wpdb->get_row($wpdb->prepare("SELECT w.* FROM {$work_tables['tasks']} w INNER JOIN {$seo_tables['tasks']} l ON l.task_id=w.id WHERE w.id=%d AND l.project_id=%d",$work_id,$seo_project_id));
?>
<?php if($seo_manage) : ?>
<section class="crm-access-card"><h2><?php echo $edit_work?'ویرایش تسک پروژه':'واگذاری تسک سئو'; ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-access-form"><?php crmb_seo_form_fields('save_seo_task',$seo_project_id,'tasks'); ?>
        <?php if($edit_work) : ?><input type="hidden" name="work_id" value="<?php echo esc_attr($edit_work->id); ?>"><?php endif; ?>
        <label for="seo-task-title">عنوان</label><input id="seo-task-title" name="title" required maxlength="60" value="<?php echo esc_attr($edit_work?$edit_work->title:''); ?>">
        <label for="seo-task-body">شرح کار و معیار تحویل</label><textarea id="seo-task-body" name="body" rows="4" maxlength="3000"><?php echo esc_textarea($edit_work?$edit_work->description:''); ?></textarea>
        <label for="seo-task-owner">مسئول</label><select id="seo-task-owner" name="owner"><?php foreach(crmb_seo_members($seo_project_id) as $member) : ?><option value="<?php echo esc_attr($member); ?>" <?php selected($edit_work?$edit_work->assigned_to:0,$member); ?>><?php echo esc_html(crmb_work_user_name($member)); ?></option><?php endforeach; ?></select>
        <label for="seo-task-priority">اولویت</label><select id="seo-task-priority" name="priority"><?php foreach(crmb_work_priorities() as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($edit_work?$edit_work->priority:'normal',$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        <label for="seo-task-due">مهلت شمسی (اختیاری)</label><input id="seo-task-due" name="due_day" dir="ltr" value="<?php echo esc_attr($edit_work && $edit_work->due_day?crmb_att_jdate($edit_work->due_day):''); ?>">
        <?php if(!$edit_work) : $linked_item=crmb_work_id($_GET,'item'); ?><label for="seo-task-item">رکورد مرتبط</label><select id="seo-task-item" name="item"><option value="0">بدون رکورد مرتبط</option><?php $choices=$wpdb->get_results($wpdb->prepare("SELECT id,title,kind FROM {$seo_tables['items']} WHERE project_id=%d ORDER BY id DESC LIMIT 500",$seo_project_id)); foreach($choices?:array() as $choice) : ?><option value="<?php echo esc_attr($choice->id); ?>" <?php selected($linked_item,$choice->id); ?>><?php echo esc_html($choice->title.' — '.(crmb_seo_kinds()[$choice->kind]??'')); ?></option><?php endforeach; ?></select><?php endif; ?>
        <button class="crm-access-primary" type="submit">ذخیره تسک</button>
    </form>
</section>
<?php endif;
$scope=$seo_manage?'':$wpdb->prepare(' AND w.assigned_to=%d',get_current_user_id());
$page=isset($_GET['seo_page']) && is_scalar($_GET['seo_page'])?max(1,min(100000,absint($_GET['seo_page']))):1;
$rows=$wpdb->get_results($wpdb->prepare("SELECT w.*,l.item_id FROM {$work_tables['tasks']} w INNER JOIN {$seo_tables['tasks']} l ON l.task_id=w.id WHERE l.project_id=%d{$scope} ORDER BY w.updated_utc DESC,w.id DESC LIMIT 21 OFFSET %d",$seo_project_id,($page-1)*20)); $error=(bool)$wpdb->last_error; $more=count($rows?:array())>20; ?>
<section class="crm-access-card"><h2>تسک‌های پروژه</h2>
    <?php if($error) : ?><p role="status">دریافت وظایف انجام نشد.</p><?php elseif(!$rows) : ?><p class="crm-access-muted">تسکی برای نمایش وجود ندارد.</p><?php endif; ?>
    <?php foreach(array_slice($rows?:array(),0,20) as $row) : ?><article class="crmb-work-item"><h3><?php echo esc_html($row->title); ?></h3><p>مسئول: <?php echo esc_html(crmb_work_user_name($row->assigned_to)); ?> | وضعیت: <?php echo esc_html(crmb_work_statuses()[$row->status]??''); ?> | زمان ثبت‌شده: <span dir="ltr"><?php echo esc_html(crmb_progress_duration(crmb_progress_seconds($row))); ?></span></p>
        <p><a href="<?php echo esc_url(add_query_arg(array('crm_section'=>'tasks','task_id'=>$row->id),home_url('/main/'))); ?>">شروع / پایان، یادداشت و تاریخچه</a></p>
        <?php if($seo_manage) : ?><p><a href="<?php echo esc_url(add_query_arg('edit_work',$row->id,$seo_base)); ?>">ویرایش / انتقال مسئول</a></p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-access-form crmb-work-inline"><?php crmb_work_form_fields('task_status',$row->id); ?><label for="seo-status-<?php echo esc_attr($row->id); ?>">اصلاح وضعیت</label><select id="seo-status-<?php echo esc_attr($row->id); ?>" name="status"><?php foreach(crmb_work_statuses() as $key=>$label) : if('doing'===$key && (int)$row->assigned_to!==get_current_user_id()) continue; ?><option value="<?php echo esc_attr($key); ?>" <?php selected($row->status,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><button class="crm-access-primary" type="submit">ثبت</button></form><?php endif; ?>
        <?php if($row->item_id) : $related=crmb_seo_item($row->item_id); if($related) : ?><p><a href="<?php echo esc_url(add_query_arg(array('seo_tab'=>$related->kind,'item'=>$related->id),$seo_base)); ?>">مشاهده خروجی مرتبط و بازبینی</a></p><?php endif; endif; ?>
    </article><?php endforeach; ?>
    <nav class="crm-access-pages"><?php if($page>1) : ?><a href="<?php echo esc_url(add_query_arg('seo_page',$page-1,$seo_base)); ?>">قبلی</a><?php endif; ?><?php if($more) : ?><a href="<?php echo esc_url(add_query_arg('seo_page',$page+1,$seo_base)); ?>">بعدی</a><?php endif; ?></nav>
</section>
