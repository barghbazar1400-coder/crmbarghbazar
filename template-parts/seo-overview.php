<?php
/** Operational overview uses project-scoped records, not fabricated SEO metrics. */
defined('ABSPATH') || exit;
$scope=crmb_seo_item_scope();
$counts=$wpdb->get_results($wpdb->prepare("SELECT i.status,COUNT(*) AS total FROM {$seo_tables['items']} i WHERE i.project_id=%d AND {$scope} GROUP BY i.status",$seo_project_id));
$error=(bool)$wpdb->last_error; $stats=array(); foreach($counts?:array() as $row) $stats[$row->status]=(int)$row->total;
?>
<section class="crm-access-card"><h2><?php echo esc_html($seo_project->name); ?></h2><p dir="ltr"><?php echo esc_html($seo_project->site_url); ?></p>
    <?php if($error) : ?><p role="status">دریافت آمار پروژه انجام نشد.</p><?php else : ?><div class="crmb-seo-stats"><?php foreach(crmb_seo_states() as $key=>$label) : ?><div><strong><?php echo esc_html($stats[$key]??0); ?></strong><span><?php echo esc_html($label); ?></span></div><?php endforeach; ?></div><?php endif; ?>
    <p class="crm-access-muted">این اعداد تعداد خروجی‌های قابل مشاهده شما هستند؛ پایان زمان‌سنج به معنی تأیید خروجی نیست.</p>
</section>
<?php $due=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.title,i.kind,i.status,i.due_day FROM {$seo_tables['items']} i WHERE i.project_id=%d AND {$scope} AND i.status NOT IN ('approved','published') ORDER BY i.due_day IS NULL,i.due_day,i.id DESC LIMIT 10",$seo_project_id)); $due_error=(bool)$wpdb->last_error; ?>
<section class="crm-access-card"><h2>کارهای جاری و موعدهای نزدیک</h2><?php if($due_error) : ?><p>دریافت کارها انجام نشد.</p><?php elseif(!$due) : ?><p>کار جاری برای نمایش وجود ندارد.</p><?php endif; ?>
<ul class="crmb-work-summary"><?php foreach($due?:array() as $row) : ?><li><a href="<?php echo esc_url(add_query_arg(array('seo_tab'=>$row->kind,'item'=>$row->id),$seo_base)); ?>"><?php echo esc_html($row->title); ?></a><p><?php echo esc_html(crmb_seo_states()[$row->status]??''); ?> — <?php echo esc_html($row->due_day?crmb_att_jdate($row->due_day):'بدون مهلت'); ?> <?php if($row->due_day && $row->due_day<crmb_att_today()) : ?><strong class="crmb-work-overdue">مهلت گذشته</strong><?php endif; ?></p></li><?php endforeach; ?></ul></section>
<?php if($seo_manage) : $work=crmb_work_tables(); $events=crmb_progress_tables();
$activity=$wpdb->get_results($wpdb->prepare("SELECT e.*,w.title FROM {$events['events']} e INNER JOIN {$seo_tables['tasks']} l ON l.task_id=e.task_id INNER JOIN {$work['tasks']} w ON w.id=e.task_id WHERE l.project_id=%d ORDER BY e.id DESC LIMIT 8",$seo_project_id)); $activity_error=(bool)$wpdb->last_error; ?>
<section class="crm-access-card"><h2>روند و موانع اخیر تیم پروژه</h2><?php if($activity_error) : ?><p>دریافت فعالیت‌ها انجام نشد.</p><?php elseif(!$activity) : ?><p>هنوز فعالیتی ثبت نشده است.</p><?php endif; ?><ul class="crmb-work-summary"><?php foreach($activity?:array() as $event) : ?><li><a href="<?php echo esc_url(add_query_arg(array('crm_section'=>'tasks','task_id'=>$event->task_id),home_url('/main/'))); ?>"><?php echo esc_html($event->title); ?></a><p><?php echo esc_html(crmb_work_user_name($event->actor_id).' — '.(crmb_progress_event_labels()[$event->event_type]??'تغییر').' — '.crmb_work_date($event->created_utc)); ?></p><p class="crmb-work-body"><?php echo esc_html(wp_trim_words($event->body,30)); ?></p></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<section class="crm-access-card"><h2>راهنمای مسیر کار</h2><p>پروژه و اعضا ← تعریف صفحه، کلمه یا سفارش محتوا ← واگذاری تسک ← ثبت زمان و خروجی ← ارسال برای بازبینی ← اصلاح یا تأیید نهایی</p><p>برای کارآموز، بازبینی کارشناس قبل از تأیید مدیر الزامی است. گزارش سئو شامل تحلیل و آمار با منبع و بازه زمانی مشخص است.</p></section>
