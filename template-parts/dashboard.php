<?php
/** First landing screen, limited to the current user's own records. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$dashboard = crmb_dashboard_data();
$person = wp_get_current_user();
$clock_url = add_query_arg( 'crm_section', 'clock', home_url( '/main/' ) );
$attendance_url = add_query_arg( 'crm_section', 'attendance', home_url( '/main/' ) );
$day = $dashboard['day'];
$open = $dashboard['open'];
$today = crmb_att_today();
?>
<section class="crm-access-screen crmb-dashboard" aria-labelledby="crmb-dashboard-title">
    <header class="crm-access-header">
        <h1 id="crmb-dashboard-title">پیش‌خوان</h1>
        <p><?php echo esc_html( $person->display_name ); ?>، خوش آمدید. خلاصه وضعیت و آخرین تغییرات درخواست‌های شما</p>
    </header>
    <div class="crmb-dashboard-grid">
        <section class="crm-access-card">
            <h2>تردد امروز</h2>
            <p class="crm-access-muted">ساعت‌ها به وقت تهران نمایش داده می‌شوند.</p>
            <?php if ( ! $dashboard['attendance_ready'] ) : ?>
                <p role="status">اطلاعات تردد فعلاً در دسترس نیست. صفحه را دوباره باز کنید.</p>
            <?php else : ?>
                <p class="crmb-dashboard-state"><?php
                    if ( $open && $open->work_day !== $today ) {
                        echo 'ورود باز از روز قبل دارید؛ برای بررسی به ثبت تردد مراجعه کنید.';
                    } elseif ( $open ) { echo 'ورود ثبت شده؛ خروج هنوز ثبت نشده است.';
                    } elseif ( $day && $day->checkout_utc ) { echo 'خروج امروز ثبت شده است.';
                    } elseif ( $day && 'present' !== $day->status ) {
                        echo 'امروز وضعیت غیرحضوری ثبت شده است؛ در صورت نیاز با مدیر هماهنگ کنید.';
                    } else { echo 'هنوز ورود امروز ثبت نشده است.'; }
                ?></p>
                <dl class="crmb-dashboard-times">
                    <div><dt>ورود امروز</dt><dd dir="ltr"><?php echo esc_html( $day && $day->checkin_utc ? crmb_att_utc_to_local( $day->checkin_utc, true ) : '—' ); ?></dd></div>
                    <div><dt>خروج امروز</dt><dd dir="ltr"><?php echo esc_html( $day && $day->checkout_utc ? crmb_att_utc_to_local( $day->checkout_utc, true ) : '—' ); ?></dd></div>
                </dl>
            <?php endif; ?>
            <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $clock_url ); ?>">ثبت ورود / خروج</a>
        </section>
        <section class="crm-access-card">
            <h2>درخواست‌های من</h2>
            <?php if ( $dashboard['leave_ready'] ) : ?>
                <p><strong class="crmb-dashboard-number"><?php echo esc_html( $dashboard['pending'] ); ?></strong> درخواست مرخصی در انتظار بررسی</p>
                <p class="crm-access-muted">آخرین نتیجه‌ها و تغییرات در فهرست پایین نمایش داده می‌شوند.</p>
            <?php else : ?>
                <p role="status">اطلاعات درخواست‌ها فعلاً در دسترس نیست.</p>
            <?php endif; ?>
            <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $clock_url . '#crmb-my-leave' ); ?>">درخواست مرخصی و مشاهده سوابق</a>
        </section>
        <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <section class="crm-access-card">
                <h2>نیازمند رسیدگی مدیر</h2>
                <p><?php echo null === $dashboard['team_pending'] ? 'اطلاعات فعلاً در دسترس نیست.' : esc_html( $dashboard['team_pending'] . ' درخواست مرخصی در انتظار بررسی تیم' ); ?></p>
                <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $attendance_url . '#crmb-leave-inbox-title' ); ?>">بررسی درخواست‌های تیم</a>
            </section>
        <?php endif; ?>
    </div>
    <?php get_template_part( 'template-parts/work-summary' ); ?>
    <section class="crm-access-card">
        <h2>آخرین تغییرات درخواست‌های مرخصی</h2>
        <?php if ( $dashboard['leave_ready'] && $dashboard['requests'] ) : ?>
            <div class="crm-access-table-wrap"><table class="crm-access-table">
                <thead><tr><th scope="col">تاریخ مرخصی</th><th scope="col">بازه ساعت</th><th scope="col">وضعیت</th><th scope="col">آخرین تغییر</th></tr></thead>
                <tbody><?php foreach ( $dashboard['requests'] as $request ) :
                    $changed = $request->reviewed_utc ?: $request->created_utc;
                    $local = new DateTimeImmutable( $changed, new DateTimeZone( 'UTC' ) );
                    $local = $local->setTimezone( crmb_att_timezone() ); ?>
                    <tr><td><?php echo esc_html( crmb_att_jdate( $request->leave_day ) ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $request->time_from . ' – ' . $request->time_to ); ?></td>
                        <td><span class="crmb-dashboard-status"><?php echo esc_html( crmb_leave_status_label( $request->status ) ); ?></span></td>
                        <td><?php echo esc_html( crmb_att_jdate( $local->format( 'Y-m-d' ) ) . '، ' . $local->format( 'H:i:s' ) ); ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <p class="crm-access-muted">۵ درخواست اخیر بر اساس زمان آخرین تغییر؛ به وقت تهران</p>
        <?php elseif ( $dashboard['leave_ready'] ) : ?>
            <p class="crm-access-muted">هنوز درخواست مرخصی ثبت نکرده‌اید.</p>
        <?php else : ?>
            <p role="status">دریافت آخرین تغییرات انجام نشد؛ دوباره تلاش کنید.</p>
        <?php endif; ?>
    </section>
</section>
