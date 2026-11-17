<?php
/** One operational overview for decision makers; employees keep their own desk. */
defined( 'ABSPATH' ) || exit;
$is_ceo = isset( $crm_current_section ) && 'ceo' === $crm_current_section;
if ( $is_ceo ? ! current_user_can( 'manage_options' ) : ! crmb_leadership_can_operate() ) { return; }
$stats = crmb_leadership_data();
$base = home_url( '/main/' );
$task_url = add_query_arg( array( 'crm_section' => 'tasks', 'scope' => 'team' ), $base );
$seo_url = add_query_arg( array( 'crm_section' => 'seo', 'seo_tab' => 'workspace' ), $base );
$metrics = array(
    array( 'key' => 'tasks_open', 'label' => 'وظایف باز تیم', 'url' => add_query_arg( 'view', 'open', $task_url ) ),
    array( 'key' => 'tasks_overdue', 'label' => 'وظایف عقب‌افتاده', 'url' => add_query_arg( array( 'view' => 'open', 'queue' => 'overdue' ), $task_url ) ),
    array( 'key' => 'tasks_review', 'label' => 'در انتظار تأیید مدیر', 'url' => add_query_arg( 'view', 'review', $task_url ) ),
    array( 'key' => 'tasks_done', 'label' => 'وظایف تکمیل‌شده', 'url' => add_query_arg( 'view', 'done', $task_url ) ),
    array( 'key' => 'timers_active', 'label' => 'زمان‌سنج‌های فعال', 'url' => add_query_arg( array( 'view' => 'open', 'queue' => 'timers' ), $task_url ) ),
    array( 'key' => 'reports_pending', 'label' => 'گزارش‌های نیازمند بررسی', 'url' => $is_ceo ? add_query_arg( array( 'crm_section' => 'reports', 'scope' => 'team' ), $base ) : '' ),
    array( 'key' => 'leaves_pending', 'label' => 'مرخصی‌های منتظر تصمیم', 'url' => $is_ceo ? add_query_arg( 'crm_section', 'attendance', $base ) : '' ),
    array( 'key' => 'sales_followups', 'label' => 'پیگیری‌های فروش سررسید', 'url' => crmbarghbazar_can_view_section( 'sales' ) ? add_query_arg( array( 'crm_section'=>'sales', 'sales_queue'=>'followups' ), $base ) : '' ),
);
?>
<section class="crm-access-screen crmb-leadership" aria-labelledby="crmb-leadership-title">
    <header class="crm-access-header">
        <h1 id="crmb-leadership-title"><?php echo $is_ceo ? 'پیشخوان مدیرعامل (CEO)' : 'مرکز مدیریت عملیات (COO)'; ?></h1>
        <p><?php echo $is_ceo ? 'تصمیم‌ها و وضعیت واقعی تیم در یک نگاه؛ اطلاعات فروش تا زمان اتصال منبع داده نمایش داده نمی‌شود.' : 'رسیدگی به وظایف تیم، پیگیری کارهای معوق و هماهنگی با دپارتمان‌ها در یک جا.'; ?></p>
    </header>
    <div class="crmb-leadership-metrics">
        <?php foreach ( $metrics as $metric ) : ?>
            <div class="crmb-leadership-metric">
                <span><?php echo esc_html( $metric['label'] ); ?></span>
                <strong><?php echo null === $stats[ $metric['key'] ] ? '—' : esc_html( (string) $stats[ $metric['key'] ] ); ?></strong>
                <?php if ( $metric['url'] ) : ?><a href="<?php echo esc_url( $metric['url'] ); ?>">بررسی موارد</a><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="crmb-leadership-layout">
        <section class="crm-access-card crmb-leadership-priorities" aria-labelledby="crmb-priorities-title">
            <header class="crmb-priorities-header">
                <div class="crmb-priorities-heading">
                    <h2 id="crmb-priorities-title">اولویت‌های اجرایی</h2>
                    <p>پیگیری کارهای باز تیم بر اساس نزدیک‌ترین مهلت</p>
                </div>
                <a class="crmb-priorities-manage" href="<?php echo esc_url( $task_url ); ?>">
                    مدیریت وظایف <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </a>
            </header>
            <?php if ( null === $stats['recent'] ) : ?>
                <p class="crmb-priorities-empty" role="status">داده‌های وظایف در دسترس نیست. برای بررسی مجدد، فهرست وظایف را باز کنید.</p>
            <?php elseif ( ! $stats['recent'] ) : ?>
                <div class="crmb-priorities-empty">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <strong>وظیفه بازی برای پیگیری وجود ندارد.</strong>
                    <span>برای شروع، از بخش مدیریت وظایف کار جدیدی تعریف کنید.</span>
                </div>
            <?php else : ?>
                <div class="crmb-priorities-count"><?php echo esc_html( (string) count( $stats['recent'] ) ); ?> وظیفه در فهرست پیگیری</div>
                <ol class="crmb-priorities-list">
                    <?php foreach ( $stats['recent'] as $task ) :
                        $overdue = $task->due_day && $task->due_day < crmb_att_today();
                        $due_today = $task->due_day && $task->due_day === crmb_att_today();
                        $detail_url = add_query_arg( 'task_id', $task->id, $task_url );
                        $status = crmb_work_statuses()[ $task->status ] ?? 'نامشخص';
                        ?>
                        <li class="crmb-priority-item">
                            <div class="crmb-priority-main">
                                <a class="crmb-priority-title" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $task->title ); ?></a>
                                <div class="crmb-priority-meta">
                                    <span><i class="fa-solid fa-user" aria-hidden="true"></i><?php echo esc_html( crmb_work_user_name( $task->assigned_to ) ); ?></span>
                                    <span class="crmb-priority-status is-<?php echo esc_attr( $task->status ); ?>"><?php echo esc_html( $status ); ?></span>
                                </div>
                            </div>
                            <div class="crmb-priority-side">
                                <span class="crmb-priority-due <?php echo $overdue ? 'is-overdue' : ( $due_today ? 'is-today' : '' ); ?>">
                                    <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                    <?php echo esc_html( $overdue ? 'عقب‌افتاده: ' . crmb_att_jdate( $task->due_day ) : ( $due_today ? 'مهلت امروز' : ( $task->due_day ? crmb_att_jdate( $task->due_day ) : 'بدون مهلت' ) ) ); ?>
                                </span>
                                <a class="crmb-priority-detail" href="<?php echo esc_url( $detail_url ); ?>" aria-label="<?php echo esc_attr( 'مشاهده وظیفه ' . $task->title ); ?>">پیگیری <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
        <section class="crm-access-card">
            <h2>دسترسی سریع</h2>
            <div class="crmb-leadership-shortcuts">
                <a href="<?php echo esc_url( $task_url ); ?>">برنامه کار تیم و واگذاری</a>
                <?php if ( crmbarghbazar_can_view_section( 'seo' ) ) : ?>
                    <a href="<?php echo esc_url( $seo_url ); ?>">پروژه‌ها و گردش کار سئو</a>
                <?php endif; ?>
                <?php if ( crmbarghbazar_can_view_section( 'sales' ) ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'crm_section', 'sales', $base ) ); ?>">میز فروش، مشتریان و پیش‌فاکتورها</a>
                <?php endif; ?>
                <?php if ( $is_ceo ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'crm_section', 'departments', $base ) ); ?>">کارکنان و دسترسی‌ها</a>
                    <a href="<?php echo esc_url( add_query_arg( 'crm_section', 'attendance', $base ) ); ?>">حضور و غیاب و مرخصی</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'reports', 'scope' => 'team' ), $base ) ); ?>">بررسی گزارش‌های روزانه</a>
                <?php endif; ?>
            </div>
            <?php if ( null !== $stats['seo_projects'] ) : ?>
                <h3>دپارتمان سئو</h3>
                <p><?php echo esc_html( $stats['seo_projects'] ); ?> پروژه قابل مشاهده؛
                <?php echo esc_html( $stats['seo_review'] ); ?> خروجی در صف بازبینی.</p>
                <a href="<?php echo esc_url( $seo_url ); ?>">ورود به میز پروژه سئو</a>
            <?php endif; ?>
        </section>
    </div>
    <?php if ( function_exists( 'crmb_leadership_control_data' ) ) : ?>
        <?php get_template_part( 'template-parts/manager-control' ); ?>
    <?php endif; ?>
    <?php if ( function_exists( 'crmb_performance_data' ) ) : ?>
        <?php get_template_part( 'template-parts/employee-performance' ); ?>
    <?php endif; ?>
</section>
