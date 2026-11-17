<?php
/** Staff metrics inside the existing CEO/COO overview. */
defined( 'ABSPATH' ) || exit;
if ( ! function_exists( 'crmb_leadership_can_operate' ) || ! crmb_leadership_can_operate()
    || ! function_exists( 'crmb_performance_data' ) ) { return; }
$performance_period = isset( $_GET['performance_period'] ) && is_string( $_GET['performance_period'] )
    ? sanitize_key( wp_unslash( $_GET['performance_period'] ) ) : 'week';
if ( ! isset( crmb_performance_periods()[$performance_period] ) ) { $performance_period = 'week'; }
$performance_user = isset( $_GET['performance_user'] ) ? absint( $_GET['performance_user'] ) : 0;
$performance = crmb_performance_data( $performance_period, $performance_user );
if ( ! $performance ) { return; }
$performance_base = home_url( '/main/' );
$performance_section = isset( $crm_current_section ) && 'ceo' === $crm_current_section
    ? 'ceo' : 'operations';
$performance_link = static function ( $period, $user = 0 ) use ( $performance_base, $performance_section ) {
    $args = array( 'crm_section' => $performance_section, 'performance_period' => $period );
    if ( $user ) { $args['performance_user'] = $user; }
    return add_query_arg( $args, $performance_base ) . '#crmb-employee-performance';
};
$performance_people = $performance['rows'];
$performance_totals = array( 'completed' => 0, 'reports' => 0, 'overdue' => 0, 'review' => 0 );
foreach ( $performance_people as $person ) {
    foreach ( $performance_totals as $key => $value ) {
        $performance_totals[$key] += $person[$key];
    }
}
$performance_selected = null;
foreach ( $performance_people as $person ) {
    if ( $performance['selected'] && $performance['selected'] === $person['user_id'] ) {
        $performance_selected = $person;
        break;
    }
}
?>
<section class="crmb-employee-performance" id="crmb-employee-performance" aria-labelledby="crmb-performance-title">
    <header class="crmb-performance-heading">
        <div>
            <h2 id="crmb-performance-title"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> عملکرد و حجم کار کارکنان</h2>
            <p>آمار واقعی تسک‌ها و گزارش‌های ثبت‌شده؛ بدون امتیازدهی یا رتبه‌بندی کارکنان</p>
        </div>
        <nav class="crmb-performance-periods" aria-label="بازه گزارش عملکرد">
            <?php foreach ( crmb_performance_periods() as $code => $caption ) : ?>
                <a href="<?php echo esc_url( $performance_link( $code, $performance['selected'] ) ); ?>"
                   class="<?php echo $code === $performance_period ? 'is-current' : ''; ?>"
                   <?php if ( $code === $performance_period ) : ?>aria-current="page"<?php endif; ?>>
                    <?php echo esc_html( $caption ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </header>
    <?php if ( ! $performance['ready'] ) : ?>
        <div class="crm-access-card" role="status">
            <p>اطلاعات عملکرد کارکنان در دسترس نیست. اتصال به جداول وظایف و تاریخچه گزارش‌ها را بررسی کنید.</p>
        </div>
    <?php else : ?>
        <p class="crmb-performance-context">
            بازه فعالیت: از <?php echo esc_html( crmb_att_jdate( $performance['bounds']['first_day'] ) ); ?>
            تا <?php echo esc_html( crmb_att_jdate( $performance['bounds']['last_day'] ) ); ?>
            · کارهای باز، معوق و در انتظار تأیید، <strong>وضعیت فعلی</strong> هستند.
        </p>
        <div class="crmb-performance-kpis">
            <div><span>تکمیل و تأیید نهایی در بازه</span><strong><?php echo esc_html( (string) $performance_totals['completed'] ); ?></strong></div>
            <div><span>گزارش پیشرفت ثبت‌شده در بازه</span><strong><?php echo esc_html( (string) $performance_totals['reports'] ); ?></strong></div>
            <div><span>وظایف عقب‌افتاده فعلی</span><strong><?php echo esc_html( (string) $performance_totals['overdue'] ); ?></strong></div>
            <div><span>وظایف منتظر تأیید فعلی</span><strong><?php echo esc_html( (string) $performance_totals['review'] ); ?></strong></div>
        </div>
        <div class="crm-access-card crmb-performance-board">
            <div class="crmb-performance-board-head">
                <div>
                    <h3>گزارش به تفکیک کارمند</h3>
                    <p>ترتیب نمایش بر اساس نام است، نه میزان عملکرد؛ تعداد کارها به‌تنهایی معیار کیفیت نیست.</p>
                </div>
                <form class="crmb-performance-select" method="get" action="<?php echo esc_url( $performance_base ); ?>">
                    <input type="hidden" name="crm_section" value="<?php echo esc_attr( $performance_section ); ?>">
                    <input type="hidden" name="performance_period" value="<?php echo esc_attr( $performance_period ); ?>">
                    <label for="crmb-performance-member">جزئیات کارمند</label>
                    <select id="crmb-performance-member" name="performance_user">
                        <option value="0">همه کارکنان</option>
                        <?php foreach ( $performance_people as $person ) : ?>
                            <option value="<?php echo esc_attr( $person['user_id'] ); ?>"
                                <?php selected( $performance['selected'], $person['user_id'] ); ?>>
                                <?php echo esc_html( $person['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">نمایش</button>
                </form>
            </div>
            <?php if ( ! $performance_people ) : ?>
                <p class="crm-access-muted">هنوز وظیفه یا گزارش کاری قابل محاسبه ثبت نشده است.</p>
            <?php else : ?>
                <div class="crmb-performance-table-scroll" role="region" aria-label="آمار عملکرد کارکنان" tabindex="0">
                    <table class="crmb-performance-table">
                        <thead><tr>
                            <th scope="col">کارمند</th>
                            <th scope="col">کارهای باز فعلی</th>
                            <th scope="col">منتظر تأیید فعلی</th>
                            <th scope="col">عقب‌افتاده فعلی</th>
                            <th scope="col">کارهای جدید در بازه*</th>
                            <th scope="col">تأیید نهایی در بازه</th>
                            <th scope="col">گزارش پیشرفت در بازه</th>
                            <th scope="col">کارهای دارای گزارش</th>
                            <th scope="col">درخواست اصلاح در بازه*</th>
                            <th scope="col">میانگین زمان‌سنج تکمیل‌ها*</th>
                            <th scope="col">جزئیات</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $performance_people as $person ) : ?>
                            <tr class="<?php echo $performance['selected'] === $person['user_id'] ? 'is-selected' : ''; ?>">
                                <th scope="row"><?php echo esc_html( $person['name'] ); ?></th>
                                <td><?php echo esc_html( (string) $person['open'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['review'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['overdue'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['created'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['completed'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['reports'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['reported_tasks'] ); ?></td>
                                <td><?php echo esc_html( (string) $person['corrections'] ); ?></td>
                                <td><?php echo null === $person['avg_seconds'] ? '—'
                                    : esc_html( crmb_progress_duration( $person['avg_seconds'] ) ); ?></td>
                                <td><a href="<?php echo esc_url( $performance_link( $performance_period, $person['user_id'] ) ); ?>">مشاهده</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="crmb-performance-method">
                    * «کار جدید» به تسک‌های ایجادشده در بازه با مسئول فعلی اشاره دارد؛ انتقال‌های قبلی از این شاخص قابل بازسازی نیست.
                    درخواست‌های اصلاح به مسئول <strong>فعلی</strong> هر تسک نسبت داده می‌شوند؛ سوابق قدیمی مسئول هنگام اصلاح را ثبت نکرده‌اند.
                    میانگین زمان فقط برای تسک‌های تأیید نهایی‌شده‌ای محاسبه می‌شود که زمان‌سنج ثبت‌شده دارند؛ مدت کل انجام کار یا کیفیت آن نیست.
                    گزارش‌ها به نام نویسنده واقعی هر گزارش ثبت می‌شوند، حتی اگر تسک بعداً منتقل شده باشد.
                </p>
            <?php endif; ?>
        </div>
        <?php if ( $performance_selected ) : ?>
            <div class="crm-access-card crmb-performance-detail" id="crmb-performance-detail">
                <div class="crmb-performance-detail-head">
                    <div><h3>جزئیات <?php echo esc_html( $performance_selected['name'] ); ?></h3>
                        <p>فقط تسک‌های مرتبط؛ لینک‌ها به همان تسک در «کارهای تیم» باز می‌شوند.</p>
                    </div>
                    <a href="<?php echo esc_url( $performance_link( $performance_period ) ); ?>">بازگشت به همه کارکنان</a>
                </div>
                <div class="crmb-performance-detail-grid">
                    <?php foreach ( array( 'overdue' => 'کارهای عقب‌افتاده فعلی', 'completed' => 'کارهای تأیید نهایی‌شده در بازه' ) as $kind => $label ) : ?>
                        <section class="crmb-performance-detail-section">
                            <h4><?php echo esc_html( $label ); ?></h4>
                            <?php if ( empty( $performance['details'][$kind] ) ) : ?>
                                <p>موردی وجود ندارد.</p>
                            <?php else : ?>
                                <ol>
                                    <?php foreach ( $performance['details'][$kind] as $task ) :
                                        $task_url = add_query_arg( array(
                                            'crm_section' => 'tasks', 'scope' => 'team', 'task_id' => (int) $task->id,
                                        ), $performance_base );
                                        ?>
                                        <li>
                                            <a href="<?php echo esc_url( $task_url ); ?>"><?php echo esc_html( $task->title ); ?></a>
                                            <small><?php echo 'overdue' === $kind
                                                ? esc_html( 'موعد: ' . crmb_att_jdate( $task->due_day ) )
                                                : esc_html( 'تکمیل: ' . crmb_work_date( $task->completed_utc ) ); ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
