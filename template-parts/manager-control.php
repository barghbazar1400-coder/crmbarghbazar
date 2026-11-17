<?php
/** Actionable read-only team queues for CEO/COO; no additional permissions. */
defined( 'ABSPATH' ) || exit;
if ( ! crmb_leadership_can_operate() ) { return; }
$control = crmb_leadership_control_data();
if ( ! $control ) { return; }
$base = home_url( '/main/' );
$tasks_url = add_query_arg( array( 'crm_section'=>'tasks', 'scope'=>'team' ), $base );
?>
<section class="crmb-manager-control" aria-labelledby="crmb-manager-control-title">
    <header class="crmb-control-heading">
        <div>
            <h2 id="crmb-manager-control-title">مرکز کنترل و پیگیری مدیر</h2>
            <p>صف‌های عملیاتی بر اساس اطلاعات ثبت‌شده CRM؛ برای ثبت دستور به جزئیات همان کار بروید.</p>
        </div>
        <a href="<?php echo esc_url( $tasks_url ); ?>">مشاهده همه کارهای تیم</a>
    </header>
    <div class="crmb-control-grid">
        <section class="crm-access-card crmb-control-panel crmb-control-review-panel" aria-labelledby="crmb-control-review">
            <h3 id="crmb-control-review"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> کارهای در انتظار تأیید شما</h3>
            <p class="crm-access-muted">کارهایی که مسئول تحویل داده اما هنوز بایگانی نشده‌اند</p>
            <?php if ( null === $control['reviews'] ) : ?>
                <p role="status">دریافت صف تأیید مدیر ممکن نشد.</p>
            <?php elseif ( ! $control['reviews'] ) : ?>
                <p class="crm-access-muted">کاری در انتظار تأیید نیست.</p>
            <?php else : ?>
                <ul class="crmb-control-list">
                    <?php foreach ( $control['reviews'] as $item ) :
                        $url = add_query_arg( 'task_id', $item->id, $tasks_url ); ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->title ); ?></a>
                            <small>مسئول: <?php echo esc_html( crmb_work_user_name( $item->assigned_to ) ); ?></small>
                            <a class="crmb-control-action" href="<?php echo esc_url( $url ); ?>">تأیید یا برگشت برای اصلاح</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="crmb-control-action" href="<?php echo esc_url( add_query_arg( 'view', 'review', $tasks_url ) ); ?>">مشاهده همه کارهای منتظر تأیید</a>
        </section>
        <section class="crm-access-card crmb-control-panel" aria-labelledby="crmb-control-overdue">
            <h3 id="crmb-control-overdue"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i> وظایف عقب‌افتاده</h3>
            <p class="crm-access-muted">قدیمی‌ترین موعدها · حداکثر ۱۰ مورد</p>
            <?php if ( null === $control['overdue'] ) : ?>
                <p role="status">اطلاعات وظایف در دسترس نیست.</p>
            <?php elseif ( ! $control['overdue'] ) : ?>
                <p class="crm-access-muted">وظیفه عقب‌افتاده‌ای وجود ندارد.</p>
            <?php else : ?>
                <ul class="crmb-control-list">
                    <?php foreach ( $control['overdue'] as $item ) :
                        $url = add_query_arg( 'task_id', $item->id, $tasks_url ); ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->title ); ?></a>
                            <small><?php echo esc_html( crmb_work_user_name( $item->assigned_to ) ); ?> · موعد <?php echo esc_html( crmb_att_jdate( $item->due_day ) ); ?></small>
                            <a class="crmb-control-action" href="<?php echo esc_url( $url ); ?>">مشاهده و ثبت راهنمایی</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="crm-access-card crmb-control-panel" aria-labelledby="crmb-control-stalled">
            <h3 id="crmb-control-stalled"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> وظایف بدون گزارش تازه</h3>
            <p class="crm-access-muted">کارهای شروع‌شده یا متوقف‌شده که بیش از ۴۸ ساعت بدون گزارش کارمند مانده‌اند</p>
            <?php if ( null === $control['stalled'] ) : ?>
                <p role="status">اطلاعات گزارش‌های پیشرفت در دسترس نیست.</p>
            <?php elseif ( ! $control['stalled'] ) : ?>
                <p class="crm-access-muted">موردی برای پیگیری گزارش وجود ندارد.</p>
            <?php else : ?>
                <ul class="crmb-control-list">
                    <?php foreach ( $control['stalled'] as $item ) :
                        $url = add_query_arg( 'task_id', $item->id, $tasks_url ); ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->title ); ?></a>
                            <small><?php echo esc_html( crmb_work_user_name( $item->assigned_to ) ); ?> · <?php echo esc_html( crmb_work_statuses()[ $item->status ] ?? 'نامشخص' ); ?></small>
                            <a class="crmb-control-action" href="<?php echo esc_url( $url ); ?>">درخواست گزارش / ثبت راهنمایی</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="crm-access-card crmb-control-panel" aria-labelledby="crmb-control-followups">
            <h3 id="crmb-control-followups"><i class="fa-solid fa-phone-volume" aria-hidden="true"></i> پیگیری مشتریان</h3>
            <p class="crm-access-muted">سررسیدشده بر اساس آخرین تماس هر مشتری و محدوده دسترسی فروش شما</p>
            <?php if ( ! $control['sales_access'] ) : ?>
                <p class="crm-access-muted">برای مشاهده این بخش به دسترسی «میز فروش» نیاز دارید.</p>
            <?php elseif ( ! $control['sales_ready'] || null === $control['followups'] ) : ?>
                <p role="status">اطلاعات تماس‌ها در دسترس نیست.</p>
            <?php elseif ( ! $control['followups'] ) : ?>
                <p class="crm-access-muted">پیگیری سررسیدشده‌ای وجود ندارد.</p>
            <?php else : ?>
                <ul class="crmb-control-list">
                    <?php foreach ( $control['followups'] as $item ) :
                        $url = add_query_arg( array( 'crm_section'=>'sales', 'sales_tab'=>'customers', 'customer'=>$item->customer_id ), $base ); ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->customer_name ); ?></a>
                            <small><?php echo esc_html( crmb_work_user_name( $item->owner_id ) ); ?> · موعد <?php echo esc_html( crmb_att_jdate( $item->next_followup_day ) ); ?></small>
                            <a class="crmb-control-action" href="<?php echo esc_url( $url ); ?>">مشاهده پرونده مشتری</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="crm-access-card crmb-control-panel" aria-labelledby="crmb-control-activity">
            <h3 id="crmb-control-activity"><i class="fa-solid fa-list-check" aria-hidden="true"></i> فعالیت امروز کارکنان</h3>
            <p class="crm-access-muted">۱۲ اقدام اخیر ثبت‌شده توسط مسئول هر کار (راهنمایی مدیر شامل نمی‌شود)</p>
            <?php if ( null === $control['activity'] ) : ?>
                <p role="status">فعالیت‌های امروز در دسترس نیست.</p>
            <?php elseif ( ! $control['activity'] ) : ?>
                <p class="crm-access-muted">امروز هنوز فعالیتی ثبت نشده است.</p>
            <?php else : ?>
                <ul class="crmb-control-list">
                    <?php foreach ( $control['activity'] as $item ) :
                        $url = add_query_arg( 'task_id', $item->task_id, $tasks_url ); ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->title ); ?></a>
                            <small><?php echo esc_html( crmb_work_user_name( $item->actor_id ) ); ?> · <?php echo esc_html( crmb_progress_event_labels()[ $item->event_type ] ?? 'فعالیت' ); ?> · <?php echo esc_html( crmb_work_date( $item->created_utc ) ); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php if ( $control['sales_access'] ) : ?>
        <section class="crm-access-card crmb-control-panel" aria-labelledby="crmb-control-calls">
            <div class="crmb-control-summary">
                <h3 id="crmb-control-calls"><i class="fa-solid fa-headset" aria-hidden="true"></i> تماس‌های ثبت‌شده امروز</h3>
                <strong><?php echo null === $control['calls_today'] ? '—' : esc_html( (string) $control['calls_today'] ); ?></strong>
            </div>
            <p class="crm-access-muted">آخرین ۸ تماس در محدوده دسترسی میز فروش</p>
            <?php if ( ! $control['sales_ready'] || null === $control['calls'] ) : ?>
                <p role="status">تاریخچه تماس‌ها در دسترس نیست.</p>
            <?php elseif ( ! $control['calls'] ) : ?>
                <p class="crm-access-muted">امروز تماسی ثبت نشده است.</p>
            <?php else : ?>
                <ul class="crmb-control-list">
                    <?php foreach ( $control['calls'] as $item ) :
                        $url = add_query_arg( array( 'crm_section'=>'sales', 'sales_tab'=>'customers', 'customer'=>$item->customer_id ), $base ); ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->customer_name ); ?></a>
                            <small><?php echo esc_html( crmb_work_user_name( $item->owner_id ) ); ?> · <?php echo esc_html( crmb_sales_call_outcomes()[ $item->outcome ] ?? 'تماس' ); ?> · <?php echo esc_html( crmb_work_date( $item->created_utc ) ); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="crmb-control-action" href="<?php echo esc_url( add_query_arg( 'crm_section', 'sales', $base ) ); ?>">ثبت تماس در میز فروش</a>
        </section>
        <?php endif; ?>
    </div>
</section>
