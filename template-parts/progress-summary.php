<?php
/** A compact daily-work overview backed by real records. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() || ! crmb_progress_ready() ) { return; }
global $wpdb; $t = crmb_progress_tables(); $uid = get_current_user_id();
$running = $wpdb->get_row( $wpdb->prepare( "SELECT id,title,timer_started_utc FROM {$t['tasks']} WHERE assigned_to = %d AND timer_started_utc IS NOT NULL ORDER BY id LIMIT 1", $uid ) );
$running_ok = ! $wpdb->last_error;
$report = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$t['reports']} WHERE user_id = %d AND report_day = %s", $uid, crmb_att_today() ) );
$report_ok = ! $wpdb->last_error;
$report_url = add_query_arg( 'crm_section', 'reports', home_url( '/main/' ) );
?>
<section class="crm-access-card crmb-progress-summary"><h2>کار امروز و گزارش روزانه</h2>
    <?php if ( $running_ok && $running ) : ?><p class="crmb-task-running">زمان‌سنج فعال: <a href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'tasks', 'task_id' => $running->id ), home_url( '/main/' ) ) ); ?>"><?php echo esc_html( $running->title ); ?></a> — شروع <?php echo esc_html( crmb_work_date( $running->timer_started_utc ) ); ?></p><p class="crm-access-muted">هنگام توقف کار، زمان‌سنج را متوقف کنید؛ خروج از صفحه یا ثبت خروج حضور و غیاب آن را متوقف نمی‌کند.</p><?php elseif ( $running_ok ) : ?><p>زمان‌سنج فعالی ندارید.</p><?php else : ?><p>دریافت زمان‌سنج انجام نشد.</p><?php endif; ?>
    <p>گزارش امروز: <?php echo $report_ok ? esc_html( $report ? ( array( 'draft' => 'پیش‌نویس', 'submitted' => 'ارسال‌شده', 'reviewed' => 'بررسی‌شده' )[$report->status] ?? 'نامشخص' ) : 'هنوز ثبت نشده' ) : 'دریافت اطلاعات انجام نشد'; ?></p>
    <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $report_url ); ?>">نوشتن / مشاهده گزارش امروز</a>
    <?php if ( current_user_can( 'manage_options' ) ) :
        $pending = $wpdb->get_var( "SELECT COUNT(*) FROM {$t['reports']} WHERE status = 'submitted'" );
        if ( ! $wpdb->last_error ) : ?><p><a href="<?php echo esc_url( add_query_arg( 'scope', 'team', $report_url ) ); ?>"><?php echo esc_html( $pending . ' گزارش روزانه در انتظار بررسی' ); ?></a></p><?php endif;
        $events = $wpdb->get_results( "SELECT e.*,t.title FROM {$t['events']} e INNER JOIN {$t['tasks']} t ON e.task_id = t.id ORDER BY e.id DESC LIMIT 6" ); ?>
        <h3>آخرین فعالیت‌های تیم</h3>
        <?php if ( $wpdb->last_error ) : ?><p>دریافت فعالیت‌ها انجام نشد.</p><?php elseif ( ! $events ) : ?><p class="crm-access-muted">هنوز فعالیتی ثبت نشده است.</p><?php endif; ?>
        <ul class="crmb-work-summary"><?php foreach ( $events ?: array() as $event ) : ?><li><a href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'tasks', 'task_id' => $event->task_id ), home_url( '/main/' ) ) ); ?>"><?php echo esc_html( $event->title ); ?></a><p><?php echo esc_html( crmb_work_user_name( $event->actor_id ) . ' — ' . ( crmb_progress_event_labels()[$event->event_type] ?? 'تغییر' ) . ' — ' . crmb_work_date( $event->created_utc ) ); ?></p><p class="crmb-work-body"><?php echo esc_html( wp_trim_words( $event->body, 25 ) ); ?></p></li><?php endforeach; ?></ul>
    <?php endif; ?>
</section>
