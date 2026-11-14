<?php
/**
 * Compact daily-work summary for the signed-in employee.
 * Team activity and report approval are visible only to the general manager.
 */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() || ! crmb_progress_ready() ) { return; }
global $wpdb;
$t = crmb_progress_tables();
$uid = get_current_user_id();
$manager = current_user_can( 'manage_options' );

$running = $wpdb->get_row( $wpdb->prepare(
    "SELECT id,title,timer_started_utc FROM {$t['tasks']} WHERE assigned_to = %d AND timer_started_utc IS NOT NULL ORDER BY id LIMIT 1",
    $uid
) );
$running_ok = ! $wpdb->last_error;
$report = $wpdb->get_row( $wpdb->prepare(
    "SELECT status FROM {$t['reports']} WHERE user_id = %d AND report_day = %s",
    $uid, crmb_att_today()
) );
$report_ok = ! $wpdb->last_error;
$report_status = $report_ok && $report ? $report->status : 'missing';
$report_labels = array(
    'draft' => 'پیش‌نویس', 'submitted' => 'ارسال‌شده',
    'reviewed' => 'بررسی‌شده', 'missing' => 'هنوز ثبت نشده',
);
$report_label = ! $report_ok ? 'دریافت اطلاعات ناموفق' : ( $report_labels[ $report_status ] ?? 'نامشخص' );
$report_url = add_query_arg( 'crm_section', 'reports', home_url( '/main/' ) );
$task_url = $running ? add_query_arg(
    array( 'crm_section' => 'tasks', 'task_id' => $running->id ),
    home_url( '/main/' )
) : '';
$pending = null;
$events = array();
$events_ok = true;
if ( $manager ) {
    $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$t['reports']} WHERE status = 'submitted'" );
    if ( ! $wpdb->last_error ) { $pending = (int) $count; }
    $events = $wpdb->get_results(
        "SELECT e.*,t.title FROM {$t['events']} e INNER JOIN {$t['tasks']} t ON e.task_id = t.id ORDER BY e.id DESC LIMIT 6"
    );
    $events_ok = ! $wpdb->last_error;
}
?>
<section class="crm-access-card crmb-progress-summary <?php echo $manager ? 'has-team-activity' : 'is-personal'; ?>" aria-labelledby="crmb-progress-summary-title">
    <header class="crmb-progress-summary-header">
        <div>
            <span class="crmb-progress-eyebrow">خلاصه فعالیت‌ها</span>
            <h2 id="crmb-progress-summary-title">کار امروز و گزارش روزانه</h2>
            <p>وضعیت کار و گزارش امروز را یکجا ببینید.</p>
        </div>
        <a class="crmb-progress-header-action" href="<?php echo esc_url( $report_url ); ?>">رفتن به گزارش روزانه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
    </header>
    <div class="crmb-progress-summary-layout">
        <div class="crmb-progress-today">
            <section class="crmb-progress-info-card">
                <div class="crmb-progress-info-head">
                    <span class="crmb-progress-info-icon is-timer"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i></span>
                    <div><h3>وضعیت کار من</h3><p>زمان‌سنج فعالیت</p></div>
                    <?php if ( $running_ok ) : ?>
                        <span class="crmb-progress-state <?php echo $running ? 'is-active' : 'is-idle'; ?>">
                            <?php echo $running ? 'در حال انجام' : 'غیرفعال'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ( ! $running_ok ) : ?>
                    <p class="crmb-progress-info-message" role="status">دریافت وضعیت زمان‌سنج انجام نشد.</p>
                <?php elseif ( $running ) : ?>
                    <a class="crmb-progress-task-name" href="<?php echo esc_url( $task_url ); ?>"><?php echo esc_html( $running->title ); ?></a>
                    <p class="crmb-progress-info-message">شروع: <?php echo esc_html( crmb_work_date( $running->timer_started_utc ) ); ?></p>
                    <a class="crmb-progress-inline-action" href="<?php echo esc_url( $task_url ); ?>">مشاهده زمان‌سنج و ادامه کار</a>
                <?php else : ?>
                    <p class="crmb-progress-info-message">در حال حاضر زمان‌سنج فعالی ندارید.</p>
                    <a class="crmb-progress-inline-action" href="<?php echo esc_url( add_query_arg( 'crm_section', 'tasks', home_url( '/main/' ) ) ); ?>">رفتن به وظایف من</a>
                <?php endif; ?>
            </section>
            <section class="crmb-progress-info-card">
                <div class="crmb-progress-info-head">
                    <span class="crmb-progress-info-icon is-report"><i class="fa-solid fa-file-lines" aria-hidden="true"></i></span>
                    <div><h3>گزارش امروز</h3><p>ثبت فعالیت‌های روزانه</p></div>
                    <span class="crmb-progress-state is-report-<?php echo esc_attr( isset( $report_labels[ $report_status ] ) && $report_ok ? $report_status : 'unknown' ); ?>"><?php echo esc_html( $report_label ); ?></span>
                </div>
                <p class="crmb-progress-info-message">
                    <?php
                    if ( ! $report_ok ) { echo 'اطلاعات گزارش امروز در دسترس نیست.'; }
                    elseif ( ! $report ) { echo 'گزارش فعالیت‌های امروز هنوز نوشته نشده است.'; }
                    elseif ( 'draft' === $report->status ) { echo 'پیش‌نویس ذخیره شده و هنوز برای مدیر ارسال نشده است.'; }
                    elseif ( 'submitted' === $report->status ) { echo 'گزارش برای مدیر ارسال شده و منتظر بررسی است.'; }
                    elseif ( 'reviewed' === $report->status ) { echo 'مدیر گزارش امروز را بررسی کرده است.'; }
                    else { echo 'برای بررسی جزئیات به گزارش روزانه بروید.'; }
                    ?>
                </p>
                <a class="crmb-progress-inline-action" href="<?php echo esc_url( $report_url ); ?>">
                    <?php echo $report_ok && ! $report ? 'نوشتن گزارش امروز' : 'مشاهده گزارش امروز'; ?>
                </a>
            </section>
            <?php if ( $manager && null !== $pending ) : ?>
                <a class="crmb-progress-review-link" href="<?php echo esc_url( add_query_arg( 'scope', 'team', $report_url ) ); ?>">
                    <span class="crmb-progress-review-icon"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
                    <span><strong><?php echo esc_html( (string) $pending ); ?> گزارش منتظر بررسی</strong><small>مشاهده گزارش‌های ارسالی تیم</small></span>
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <p class="crmb-progress-footnote">زمان‌سنج با خروج از این صفحه یا ثبت خروج تردد متوقف نمی‌شود.</p>
        </div>
        <?php if ( $manager ) : ?>
        <section class="crmb-progress-team-activity" aria-labelledby="crmb-team-activity-title">
            <header class="crmb-progress-team-header">
                <div><h3 id="crmb-team-activity-title">آخرین فعالیت‌های تیم</h3><p>تغییرات و گزارش‌های ثبت‌شده در وظایف</p></div>
                <?php if ( $events_ok ) : ?><span class="crmb-progress-activity-count"><?php echo esc_html( (string) count( $events ?: array() ) ); ?> فعالیت اخیر</span><?php endif; ?>
            </header>
            <?php if ( ! $events_ok ) : ?>
                <p class="crmb-progress-activity-empty" role="status">دریافت فعالیت‌های تیم انجام نشد.</p>
            <?php elseif ( ! $events ) : ?>
                <p class="crmb-progress-activity-empty">هنوز فعالیتی از تیم ثبت نشده است.</p>
            <?php else : ?>
                <ol class="crmb-progress-activity-list">
                    <?php foreach ( $events as $event ) :
                        $type = isset( crmb_progress_event_labels()[ $event->event_type ] ) ? $event->event_type : 'other';
                        $event_url = add_query_arg(
                            array( 'crm_section' => 'tasks', 'task_id' => $event->task_id ),
                            home_url( '/main/' )
                        );
                        $summary = trim( (string) $event->body );
                        ?>
                    <li class="crmb-progress-activity-item">
                        <span class="crmb-progress-activity-marker is-<?php echo esc_attr( $type ); ?>" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
                        <div class="crmb-progress-activity-content">
                            <div class="crmb-progress-activity-line">
                                <a class="crmb-progress-activity-title" href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->title ); ?></a>
                                <span class="crmb-progress-activity-type is-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( crmb_progress_event_labels()[ $event->event_type ] ?? 'تغییر وضعیت' ); ?></span>
                            </div>
                            <p class="crmb-progress-activity-meta"><span><?php echo esc_html( crmb_work_user_name( $event->actor_id ) ); ?></span><span><?php echo esc_html( crmb_work_date( $event->created_utc ) ); ?></span></p>
                            <?php if ( '' !== $summary ) : ?><p class="crmb-progress-activity-description"><?php echo esc_html( wp_trim_words( $summary, 22 ) ); ?></p><?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</section>
