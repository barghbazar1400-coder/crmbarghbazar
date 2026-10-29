<?php
/**
 * Employee punch controls: do not reveal attendance records or totals.
 */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$notices = array(
    'in' => 'ورود شما ثبت شد.',
    'out' => 'خروج شما ثبت شد.',
    'already' => 'این عملیات قبلاً ثبت شده است.',
    'missing_in' => 'ورود باز و معتبری برای ثبت خروج پیدا نشد؛ با مدیر هماهنگ کنید.',
    'closed' => 'ثبت این ماه بسته شده است؛ با مدیر هماهنگ کنید.',
    'invalid' => 'عملیات انجام نشد. دوباره تلاش کنید.',
);
$notice = isset( $_GET['clock_notice'] ) && is_string( $_GET['clock_notice'] ) ? sanitize_key( wp_unslash( $_GET['clock_notice'] ) ) : '';
?>
<section class="crmb-att" aria-label="ثبت ورود و خروج">
    <header class="crmb-att-heading">
        <h1>ثبت ورود و خروج</h1>
        <p>زمان ثبت به‌صورت خودکار از ساعت سرور دریافت می‌شود.</p>
    </header>
    <?php if ( isset( $notices[ $notice ] ) ) : ?>
        <p class="crmb-att-alert" role="status"><?php echo esc_html( $notices[ $notice ] ); ?></p>
    <?php endif; ?>
    <div class="crmb-att-clock-card">
        <h2>ثبت تردد امروز</h2>
        <div class="crmb-att-clock-buttons">
            <?php foreach ( array( 'in' => 'ثبت ورود', 'out' => 'ثبت خروج' ) as $action => $label ) : ?>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="crmb_att_clock">
                    <input type="hidden" name="clock_action" value="<?php echo esc_attr( $action ); ?>">
                    <?php wp_nonce_field( 'crmb_att_clock', 'crmb_att_nonce' ); ?>
                    <button class="<?php echo 'in' === $action ? 'crmb-att-primary' : 'crmb-att-secondary'; ?>" type="submit">
                        <?php echo esc_html( $label ); ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
        <p class="crmb-att-hint">گزارش ساعت‌ها، مرخصی و اضافه‌کاری فقط در پنل مدیر کل قابل مشاهده است.</p>
    </div>
</section>
