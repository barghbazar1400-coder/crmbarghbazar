<?php
/**
 * Employee clock and leave form; only own leave request statuses are visible.
 */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }

global $wpdb;
$crmb_leave_rows = array();
if ( '1' === get_option( 'crmb_leave_schema_v1' ) ) {
    $table = crmb_leave_table();
    $crmb_leave_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,leave_day,time_from,time_to,status,created_utc FROM {$table}
         WHERE user_id = %d ORDER BY id DESC LIMIT 30",
        get_current_user_id()
    ) );
}
$clock_notices = array(
    'in' => 'ورود شما ثبت شد.',
    'out' => 'خروج شما ثبت شد.',
    'already' => 'این عملیات قبلاً ثبت شده است.',
    'missing_in' => 'ورود باز و معتبری برای خروج پیدا نشد؛ با مدیر هماهنگ کنید.',
    'closed' => 'ثبت این ماه بسته شده است؛ با مدیر هماهنگ کنید.',
    'invalid' => 'عملیات انجام نشد. دوباره تلاش کنید.',
);
$leave_notices = array(
    'submitted' => 'درخواست مرخصی ثبت شد و برای بررسی مدیر کل ارسال شد.',
    'invalid' => 'تاریخ یا ساعت معتبر نیست. تاریخ شمسی و بازه زمانی را بررسی کنید.',
    'overlap' => 'برای این بازه زمانی، درخواست تأییدشده یا در انتظار بررسی دارید.',
    'error' => 'خطا در ثبت درخواست. دوباره تلاش کنید.',
);
$clock_notice = isset( $_GET['clock_notice'] ) && is_string( $_GET['clock_notice'] )
    ? sanitize_key( wp_unslash( $_GET['clock_notice'] ) ) : '';
$leave_notice = isset( $_GET['leave_notice'] ) && is_string( $_GET['leave_notice'] )
    ? sanitize_key( wp_unslash( $_GET['leave_notice'] ) ) : '';
$timezone = wp_timezone_string();
$clock_offset_seconds = ( new DateTimeImmutable( 'now', wp_timezone() ) )->getOffset();
?>
<section class="crmb-att crmb-att-clock-screen" aria-label="ثبت تردد و مرخصی">
    <div class="crmb-att-clock-card">
        <header class="crmb-att-live-header">
            <div class="crmb-att-live-title">
                <h1>ثبت تردد</h1>
                <p>ثبت ساعت ورود و خروج بر اساس زمان سرور انجام می‌شود.</p>
            </div>
            <div class="crmb-att-live-display" data-clock-timezone="<?php echo esc_attr( $timezone ); ?>" data-clock-offset="<?php echo esc_attr( $clock_offset_seconds ); ?>">
                <time class="crmb-att-live-date" id="crmb-live-date" aria-label="تاریخ امروز"><?php echo esc_html( crmb_att_jdate( wp_date( 'Y-m-d' ) ) ); ?></time>
                <span class="crmb-att-live-time" id="crmb-live-time" dir="ltr" aria-label="ساعت جاری"><?php echo esc_html( wp_date( 'H:i:s' ) ); ?></span>
            </div>
        </header>
        <?php if ( isset( $clock_notices[ $clock_notice ] ) ) : ?>
            <p class="crmb-att-alert" role="status"><?php echo esc_html( $clock_notices[ $clock_notice ] ); ?></p>
        <?php endif; ?>
        <div class="crmb-att-clock-actions">
            <div class="crmb-att-clock-buttons">
                <?php foreach ( array( 'in' => 'ثبت ورود', 'out' => 'ثبت خروج' ) as $action => $label ) : ?>
                    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                        <input type="hidden" name="action" value="crmb_att_clock">
                        <input type="hidden" name="clock_action" value="<?php echo esc_attr( $action ); ?>">
                        <?php wp_nonce_field( 'crmb_att_clock', 'crmb_att_nonce' ); ?>
                        <button class="<?php echo 'in' === $action ? 'crmb-att-primary' : 'crmb-att-secondary'; ?>" type="submit"><?php echo esc_html( $label ); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p class="crmb-att-hint">گزارش حضور، ساعات فعالیت، اضافه‌کاری و تأخیر فقط برای مدیر کل نمایش داده می‌شود.</p>
        </div>
    </div>

    <section class="crmb-att-card crmb-att-leave-card">
        <div class="crmb-att-leave-heading">
            <h2>درخواست مرخصی</h2>
            <p>تاریخ موردنظر، ساعت شروع و پایان مرخصی را مشخص کنید. توضیحات اختیاری است.</p>
        </div>
        <?php if ( isset( $leave_notices[ $leave_notice ] ) ) : ?>
            <p class="crmb-att-alert" role="status"><?php echo esc_html( $leave_notices[ $leave_notice ] ); ?></p>
        <?php endif; ?>
        <form class="crmb-att-leave-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="crmb_leave_submit">
            <?php wp_nonce_field( 'crmb_leave_submit', 'crmb_leave_nonce' ); ?>
            <div class="crmb-att-leave-fields">
                <label for="crmb-leave-day">تاریخ مرخصی (شمسی)
                    <input id="crmb-leave-day" name="leave_day" type="text" inputmode="numeric"
                           placeholder="۱۴۰۵-۰۸-۱۵" pattern="[0-9۰-۹٠-٩]{4}-[0-9۰-۹٠-٩]{2}-[0-9۰-۹٠-٩]{2}"
                           maxlength="10" required>
                </label>
                <label for="crmb-leave-from">از ساعت
                    <input id="crmb-leave-from" name="time_from" type="time" required>
                </label>
                <label for="crmb-leave-to">تا ساعت
                    <input id="crmb-leave-to" name="time_to" type="time" required>
                </label>
            </div>
            <label class="crmb-att-leave-reason" for="crmb-leave-reason">توضیحات (اختیاری)
                <textarea id="crmb-leave-reason" name="reason" rows="4" maxlength="1000"
                          placeholder="در صورت نیاز، دلیل درخواست مرخصی را توضیح دهید."></textarea>
            </label>
            <button type="submit" class="crmb-att-primary crmb-att-leave-submit">ارسال درخواست مرخصی</button>
        </form>
    </section>

    <section class="crmb-att-card crmb-att-leave-history">
        <h2>وضعیت درخواست‌های مرخصی من</h2>
        <div class="crmb-att-table-wrap">
            <table class="crmb-att-table">
                <thead><tr><th>تاریخ مرخصی</th><th>ساعت شروع</th><th>ساعت پایان</th><th>نتیجه بررسی</th></tr></thead>
                <tbody>
                <?php foreach ( $crmb_leave_rows as $request ) : ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html( crmb_att_jdate( $request->leave_day ) ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $request->time_from ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $request->time_to ); ?></td>
                        <td>
                            <span class="crmb-leave-status crmb-leave-status-<?php echo esc_attr( $request->status ); ?>">
                                <?php echo esc_html( crmb_leave_status_label( $request->status ) ); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $crmb_leave_rows ) ) : ?>
                    <tr><td colspan="4" class="crmb-att-no-requests">درخواستی ثبت نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<script>
(function () {
    "use strict";
    const root = document.querySelector(".crmb-att-live-display");
    if (!root) return;
    const dateElement = document.getElementById("crmb-live-date");
    const timeElement = document.getElementById("crmb-live-time");
    const zone = root.dataset.clockTimezone || "Asia/Tehran";
    let dateFormatter, timeFormatter;
    let usesOffset = false;
    const offsetMs = (Number(root.dataset.clockOffset) || 0) * 1000;
    try {
        dateFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", {
            timeZone: zone, weekday: "long", day: "numeric", month: "long", year: "numeric"
        });
        timeFormatter = new Intl.DateTimeFormat("en-GB", {
            timeZone: zone, hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23"
        });
        dateFormatter.format(new Date());
        timeFormatter.format(new Date());
    } catch (error) {
        try {
            usesOffset = true;
            dateFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", {
                timeZone: "UTC", weekday: "long", day: "numeric", month: "long", year: "numeric"
            });
            timeFormatter = new Intl.DateTimeFormat("en-GB", {
                timeZone: "UTC", hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23"
            });
        } catch (unsupported) {
            return;
        }
    }
    function tick() {
        const now = new Date(Date.now() + (usesOffset ? offsetMs : 0));
        dateElement.textContent = dateFormatter.format(now);
        timeElement.textContent = timeFormatter.format(now);
    }
    tick();
    window.setInterval(tick, 1000);
})();
</script>
