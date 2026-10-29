<?php
/**
 * Administrator-only daily attendance and monthly summary dashboard.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { return; }

crmb_att_install();
global $wpdb;
$t = crmb_att_tables();
$users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
$uid = isset( $_GET['employee'] ) ? absint( $_GET['employee'] ) : 0;
if ( ! $uid && $users ) { $uid = (int) $users[0]->ID; }
$user = $uid ? get_userdata( $uid ) : false;
$month_now = crmb_att_current_month();
$month = isset( $_GET['month'] ) && is_string( $_GET['month'] )
    ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : $month_now;
if ( ! crmb_att_month_bounds( $month ) || $month > $month_now ) { $month = $month_now; }

$bounds = crmb_att_month_bounds( $month );
$archived = $user ? crmb_att_month_summary( $uid, $month ) : false;
$rows = $user && ! $archived ? crmb_att_rows( $uid, $month ) : array();
$totals = $archived ? (array) $archived : ( $user ? crmb_att_month_metrics( $uid, $month, $rows ) : array() );
$schedule = $user ? crmb_att_settings( $uid ) : array();
$statuses = array(
    'present' => 'حضور',
    'paid_leave' => 'مرخصی باحقوق',
    'unpaid_leave' => 'مرخصی بدون حقوق',
    'absence' => 'غیبت',
    'holiday' => 'تعطیل',
);
$notice_codes = array(
    'saved' => 'اطلاعات ثبت شد.',
    'invalid' => 'اطلاعات نامعتبر است یا ثبت انجام نشد.',
    'closed' => 'این ماه بایگانی شده و دیگر قابل ویرایش نیست.',
);
$notice = isset( $_GET['attendance_notice'] ) && is_string( $_GET['attendance_notice'] )
    ? sanitize_key( wp_unslash( $_GET['attendance_notice'] ) ) : '';
$base = add_query_arg( 'crm_section', 'attendance', home_url( '/main/' ) );
$summary_rows = $user ? $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$t['months']} WHERE user_id = %d ORDER BY jalali_month DESC LIMIT 36", $uid
) ) : array();

$selected = isset( $_GET['edit_day'] ) && is_string( $_GET['edit_day'] )
    ? sanitize_text_field( wp_unslash( $_GET['edit_day'] ) ) : '';
if ( ! preg_match( '/^1[2345][0-9]{2}-(?:0[1-9]|1[0-2])-[0-3][0-9]$/', $selected )
    || substr( $selected, 0, 7 ) !== $month ) {
    $selected = $month . '-01';
}
$s_parts = array_map( 'intval', explode( '-', $selected ) );
$selected_day = crmb_att_to_gregorian( $s_parts[0], $s_parts[1], $s_parts[2] );
if ( ! $selected_day || $selected_day < $bounds[0] || $selected_day > $bounds[1] ) {
    $selected = $month . '-01';
    $selected_day = $bounds[0];
}
$selected_row = $rows[ $selected_day ] ?? null;
$day_options = array();
foreach ( new DatePeriod( new DateTimeImmutable( $bounds[0] ), new DateInterval( 'P1D' ), new DateTimeImmutable( $bounds[2] ) ) as $d ) {
    $day_options[] = array( $d->format( 'Y-m-d' ), crmb_att_jdate( $d->format( 'Y-m-d' ) ) );
}
?>
<section class="crmb-att" aria-label="مدیریت حضور و غیاب">
    <header class="crmb-att-heading">
        <h1>حضور و غیاب کارکنان</h1>
        <p>ثبت تردد، برنامه حضور، تأخیر، اضافه‌کاری و مرخصی ـ فقط مدیر کل</p>
    </header>

    <?php if ( isset( $notice_codes[ $notice ] ) ) : ?>
        <p class="crmb-att-alert" role="status"><?php echo esc_html( $notice_codes[ $notice ] ); ?></p>
    <?php endif; ?>

    <form class="crmb-att-filters" method="get" action="<?php echo esc_url( home_url( '/main/' ) ); ?>">
        <input type="hidden" name="crm_section" value="attendance">
        <label for="crmb-employee">کارمند
            <select id="crmb-employee" name="employee" required>
                <?php foreach ( $users as $employee ) : ?>
                    <?php $label = trim( $employee->first_name . ' ' . $employee->last_name ) ?: $employee->display_name; ?>
                    <option value="<?php echo esc_attr( $employee->ID ); ?>" <?php selected( $uid, $employee->ID ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="crmb-month">ماه شمسی
            <select id="crmb-month" name="month">
                <?php
                $n = array_map( 'intval', explode( '-', $month_now ) );
                for ( $i = 0; $i < 18; $i++ ) :
                    $index = $n[0] * 12 + $n[1] - 1 - $i;
                    $y = intdiv( $index, 12 );
                    $m = $index % 12 + 1;
                    $mkey = sprintf( '%04d-%02d', $y, $m );
                    ?>
                    <option value="<?php echo esc_attr( $mkey ); ?>" <?php selected( $month, $mkey ); ?>>
                        <?php echo esc_html( crmb_att_month_name( $mkey ) ); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </label>
        <button type="submit" class="crmb-att-primary">نمایش</button>
    </form>

    <?php if ( ! $user ) : ?>
        <p class="crmb-att-alert">هنوز کاربری برای بررسی وجود ندارد.</p>
    <?php else : ?>
        <div class="crmb-att-summary">
            <?php
            $metrics = array(
                'scheduled_minutes' => 'ساعت موظفی',
                'worked_minutes' => 'ساعت فعالیت',
                'regular_minutes' => 'ساعت عادی',
                'overtime_minutes' => 'اضافه‌کاری',
                'delay_minutes' => 'تأخیر',
                'leave_days' => 'روز مرخصی',
                'leave_minutes' => 'ساعت مرخصی',
                'absence_days' => 'روز غیبت',
                'missing_days' => 'روز بدون ثبت',
            );
            foreach ( $metrics as $key => $label ) :
                $value = isset( $totals[ $key ] ) ? (int) $totals[ $key ] : 0;
                $display = in_array( $key, array( 'leave_days', 'absence_days', 'missing_days' ), true )
                    ? number_format_i18n( $value ) : crmb_att_format_minutes( $value );
                ?>
                <div class="crmb-att-stat">
                    <span><?php echo esc_html( $label ); ?></span>
                    <strong dir="ltr"><?php echo esc_html( $display ); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $archived ) : ?>
            <p class="crmb-att-alert">خلاصه نهایی <?php echo esc_html( crmb_att_month_name( $month ) ); ?> ذخیره شده است. ریز ورود و خروج این ماه حذف شده و قابل مشاهده نیست.</p>
        <?php else : ?>
            <?php if ( $month < $month_now ) : ?>
                <p class="crmb-att-alert">این ماه هنوز بایگانی نشده است؛ اگر ورودِ بدون خروج وجود دارد، آن را اصلاح کنید. سپس بایگانی خودکار اجرا می‌شود.</p>
            <?php endif; ?>

            <?php if ( $month === $month_now ) : ?>
                <section class="crmb-att-card">
                    <h2>ساعت کاری موظفی کارمند</h2>
                    <form class="crmb-att-schedule" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="crmb_att_admin">
                        <input type="hidden" name="attendance_task" value="schedule">
                        <input type="hidden" name="employee" value="<?php echo esc_attr( $uid ); ?>">
                        <input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
                        <?php wp_nonce_field( 'crmb_att_admin', 'crmb_att_nonce' ); ?>
                        <label>ساعت شروع موظفی<input name="start" type="time" value="<?php echo esc_attr( $schedule['start'] ); ?>" required></label>
                        <label>مدت موظفی روزانه (دقیقه)<input name="minutes" type="number" min="0" max="1440" value="<?php echo esc_attr( $schedule['minutes'] ); ?>" required></label>
                        <fieldset class="crmb-att-weekdays">
                            <legend>روزهای موظفی هفته</legend>
                            <?php foreach ( array( 6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه' ) as $day_idx => $day_label ) : ?>
                                <label><input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( $day_idx ); ?>" <?php checked( in_array( $day_idx, $schedule['weekdays'], true ) ); ?>><?php echo esc_html( $day_label ); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <button type="submit" class="crmb-att-primary">ذخیره ساعت موظفی</button>
                    </form>
                    <p class="crmb-att-hint">پیش‌فرض ۹ صبح، ۸ ساعت در روز و جمعه تعطیل است. ساعات ثبت‌شده هر روز هنگام ثبت تردد ثابت می‌مانند و با تغییر برنامه، به گذشته اعمال نمی‌شوند.</p>
                </section>
            <?php endif; ?>

            <section class="crmb-att-card">
                <h2>ثبت و اصلاح روزانه ـ <?php echo esc_html( crmb_att_month_name( $month ) ); ?></h2>
                <form class="crmb-att-editor" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="crmb_att_admin">
                    <input type="hidden" name="attendance_task" value="day">
                    <input type="hidden" name="employee" value="<?php echo esc_attr( $uid ); ?>">
                    <input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
                    <?php wp_nonce_field( 'crmb_att_admin', 'crmb_att_nonce' ); ?>
                    <label>تاریخ شمسی
                        <select name="day" required>
                            <?php foreach ( $day_options as $item ) : ?>
                                <?php if ( $item[0] <= wp_date( 'Y-m-d' ) ) : ?>
                                    <option value="<?php echo esc_attr( $item[1] ); ?>" <?php selected( $item[1], $selected ); ?>><?php echo esc_html( $item[1] ); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>وضعیت روز
                        <select name="day_status">
                            <?php foreach ( $statuses as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_row ? $selected_row->status : 'present', $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>ورود<input type="time" name="checkin" value="<?php echo esc_attr( $selected_row ? crmb_att_utc_to_local( $selected_row->checkin_utc ) : '' ); ?>"></label>
                    <label>خروج<input type="time" name="checkout" value="<?php echo esc_attr( $selected_row ? crmb_att_utc_to_local( $selected_row->checkout_utc ) : '' ); ?>"></label>
                    <label>مرخصی (دقیقه)<input type="number" min="0" max="1440" name="leave_minutes" value="<?php echo esc_attr( $selected_row ? (int) $selected_row->leave_minutes : 0 ); ?>"></label>
                    <button type="submit" class="crmb-att-primary">ذخیره روز</button>
                </form>
                <p class="crmb-att-hint">برای حضور، ساعت ورود الزامی است. برای مرخصی و غیبت، وضعیت را انتخاب کنید؛ ساعت ورود و خروج لازم نیست. پس از انتخاب «ویرایش»، فرم برای آن روز پر می‌شود.</p>
            </section>

            <section class="crmb-att-card">
                <h2>ریز روزهای <?php echo esc_html( crmb_att_month_name( $month ) ); ?></h2>
                <div class="crmb-att-table-wrap">
                    <table class="crmb-att-table">
                        <thead><tr><th>روز</th><th>وضعیت</th><th>ورود</th><th>خروج</th><th>فعالیت</th><th>اضافه‌کاری</th><th>تأخیر</th><th>مرخصی</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php foreach ( $day_options as $item ) :
                            $r = $rows[ $item[0] ] ?? null;
                            $m = crmb_att_day_metrics( $r );
                            ?>
                            <tr>
                                <td dir="ltr"><?php echo esc_html( $item[1] ); ?></td>
                                <td><?php echo esc_html( $r ? ( $statuses[ $r->status ] ?? $r->status ) : 'ثبت نشده' ); ?></td>
                                <td dir="ltr"><?php echo esc_html( $r ? crmb_att_utc_to_local( $r->checkin_utc ) : '—' ); ?></td>
                                <td dir="ltr"><?php echo esc_html( $r ? crmb_att_utc_to_local( $r->checkout_utc ) : '—' ); ?></td>
                                <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $m['worked'] ) ); ?></td>
                                <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $m['overtime'] ) ); ?></td>
                                <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $m['delay'] ) ); ?></td>
                                <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $m['leave'] ) ); ?></td>
                                <td>
                                    <?php if ( $item[0] <= wp_date( 'Y-m-d' ) ) : ?>
                                        <a href="<?php echo esc_url( add_query_arg( array( 'employee' => $uid, 'month' => $month, 'edit_day' => $item[1] ), $base ) ); ?>">ویرایش</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="crmb-att-card">
            <h2>خلاصه ماه‌های بایگانی‌شده</h2>
            <div class="crmb-att-table-wrap">
                <table class="crmb-att-table">
                    <thead><tr><th>ماه</th><th>موظفی</th><th>فعالیت</th><th>اضافه‌کاری</th><th>تأخیر</th><th>مرخصی</th><th>غیبت</th><th>بدون ثبت</th></tr></thead>
                    <tbody>
                    <?php foreach ( $summary_rows as $summary ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( add_query_arg( array( 'employee' => $uid, 'month' => $summary->jalali_month ), $base ) ); ?>"><?php echo esc_html( crmb_att_month_name( $summary->jalali_month ) ); ?></a></td>
                            <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $summary->scheduled_minutes ) ); ?></td>
                            <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $summary->worked_minutes ) ); ?></td>
                            <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $summary->overtime_minutes ) ); ?></td>
                            <td dir="ltr"><?php echo esc_html( crmb_att_format_minutes( $summary->delay_minutes ) ); ?></td>
                            <td><?php echo esc_html( $summary->leave_days ); ?> روز</td>
                            <td><?php echo esc_html( $summary->absence_days ); ?> روز</td>
                            <td><?php echo esc_html( $summary->missing_days ); ?> روز</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( ! $summary_rows ) : ?>
                        <tr><td colspan="8">هنوز ماهی بایگانی نشده است.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</section>
