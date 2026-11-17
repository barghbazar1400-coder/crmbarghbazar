<?php
/** Private subtask view: an independently assigned employee sees only this unit. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() || ! function_exists( 'crmb_task_plan_subtask' ) ) { return; }
$step_id = crmb_work_id( $_GET, 'subtask_id' );
$step = crmb_task_plan_subtask( $step_id );
$back = add_query_arg( array( 'crm_section'=>'tasks', 'scope'=>'mine' ), home_url( '/main/' ) );
?>
<section class="crm-access-screen crmb-task-detail-page" aria-labelledby="crmb-subtask-title">
    <header class="crm-access-header">
        <h1 id="crmb-subtask-title">زیرتسک واگذارشده</h1>
        <p><a href="<?php echo esc_url( $back ); ?>">بازگشت به کارهای من</a></p>
    </header>
    <?php crmb_progress_notice(); ?>
    <?php if ( ! $step ) : ?>
        <p class="crm-access-notice" role="status">زیرتسک در دسترس نیست یا اجازه مشاهده آن را ندارید.</p>
    <?php else :
        $can_toggle = (int) $step->assigned_to === get_current_user_id()
            && in_array( $step->parent_status, array( 'todo', 'doing', 'paused' ), true );
        ?>
        <section class="crm-access-card crmb-plan-panel">
            <p class="crm-access-muted">وابسته به وظیفه: <?php echo esc_html( $step->parent_title ); ?></p>
            <h2><?php echo esc_html( $step->title ); ?></h2>
            <p>مسئول: <?php echo esc_html( crmb_work_user_name( $step->assigned_to ) ); ?> ·
                وضعیت: <?php echo $step->is_done ? 'انجام‌شده' : 'در انتظار انجام'; ?></p>
            <?php if ( $step->due_day ) : ?>
                <p>مهلت انجام: <?php echo esc_html( crmb_att_jdate( $step->due_day ) ); ?></p>
            <?php endif; ?>
            <?php if ( $can_toggle ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php crmb_progress_form_fields( 'plan_action', $step->task_id ); ?>
                    <input type="hidden" name="plan_action" value="toggle">
                    <input type="hidden" name="step_id" value="<?php echo esc_attr( $step->id ); ?>">
                    <input type="hidden" name="return_step" value="1">
                    <input type="hidden" name="is_done" value="<?php echo $step->is_done ? '0' : '1'; ?>">
                    <button type="submit" class="crm-access-primary"><?php echo $step->is_done ? 'بازگشایی زیرتسک' : 'ثبت انجام زیرتسک'; ?></button>
                </form>
            <?php endif; ?>
            <p class="crm-access-muted">این صفحه فقط مربوط به زیرتسک شماست. مشاهده تاریخچه و گزارش‌های کار اصلی همچنان تابع دسترسی مسئول اصلی یا مدیر است.</p>
        </section>
    <?php endif; ?>
</section>
