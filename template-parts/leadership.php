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
    array( 'key' => 'tasks_open', 'label' => 'وظایف باز تیم', 'url' => $task_url ),
    array( 'key' => 'tasks_overdue', 'label' => 'وظایف عقب‌افتاده', 'url' => $task_url ),
    array( 'key' => 'tasks_done', 'label' => 'وظایف تکمیل‌شده', 'url' => $task_url ),
    array( 'key' => 'timers_active', 'label' => 'زمان‌سنج‌های فعال', 'url' => $task_url ),
    array( 'key' => 'reports_pending', 'label' => 'گزارش‌های نیازمند بررسی', 'url' => $is_ceo ? add_query_arg( array( 'crm_section' => 'reports', 'scope' => 'team' ), $base ) : '' ),
    array( 'key' => 'leaves_pending', 'label' => 'مرخصی‌های منتظر تصمیم', 'url' => $is_ceo ? add_query_arg( 'crm_section', 'attendance', $base ) : '' ),
    array( 'key' => 'sales_followups', 'label' => 'پیگیری‌های فروش سررسید', 'url' => crmbarghbazar_can_view_section( 'sales' ) ? add_query_arg( 'crm_section', 'sales', $base ) : '' ),
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
        <section class="crm-access-card">
            <div class="crmb-leadership-heading"><h2>اولویت‌های اجرایی</h2><a href="<?php echo esc_url( $task_url ); ?>">مدیریت تمام وظایف</a></div>
            <?php if ( null === $stats['recent'] ) : ?>
                <p role="status">داده‌های وظایف در دسترس نیست؛ عدد ساختگی نمایش داده نمی‌شود.</p>
            <?php elseif ( ! $stats['recent'] ) : ?>
                <p class="crm-access-muted">فعلاً وظیفه بازی در تیم ثبت نشده است.</p>
            <?php else : ?>
                <div class="crmb-leadership-worklist">
                    <?php foreach ( $stats['recent'] as $task ) :
                        $overdue = $task->due_day && $task->due_day < crmb_att_today(); ?>
                        <div class="crmb-leadership-workrow">
                            <div><a href="<?php echo esc_url( add_query_arg( 'task_id', $task->id, $task_url ) ); ?>"><?php echo esc_html( $task->title ); ?></a>
                            <small><?php echo esc_html( crmb_work_user_name( $task->assigned_to ) ); ?> · <?php echo esc_html( crmb_work_statuses()[ $task->status ] ?? 'نامشخص' ); ?></small></div>
                            <span class="<?php echo $overdue ? 'crmb-work-overdue' : ''; ?>"><?php echo esc_html( $task->due_day ? crmb_att_jdate( $task->due_day ) : 'بدون مهلت' ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $task_url ); ?>">واگذاری یا پیگیری وظیفه</a>
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
</section>
