<?php
/** Accessible task-plan panel inside an authorized parent task detail. */
defined( 'ABSPATH' ) || exit;
if ( ! function_exists( 'crmb_task_plan_ready' ) || ! crmb_task_plan_ready() ) { return; }
$plan_steps = crmb_task_plan_steps( (int) $task->id );
if ( null === $plan_steps ) { ?>
    <section class="crm-access-card"><p role="status">دریافت مراحل انجام کار ممکن نشد.</p></section>
<?php return; }
$plan_summary = crmb_task_plan_summary( $plan_steps );
$plan_templates = $admin ? crmb_task_plan_templates() : array();
?>
<section class="crm-access-card crmb-plan-panel" id="crmb-task-plan">
    <div class="crmb-plan-heading">
        <div><h2><i class="fa-solid fa-list-check" aria-hidden="true"></i> چک‌لیست و زیرتسک‌ها</h2>
            <p class="crm-access-muted">هر مرحله مسئول مشخص دارد؛ وضعیت این مراحل مستقل از تأیید نهایی مدیر ثبت می‌شود.</p></div>
        <strong class="crmb-plan-percent"><?php echo esc_html( (string) $plan_summary['percent'] ); ?>٪</strong>
    </div>
    <div class="crmb-plan-progress" role="progressbar" aria-label="پیشرفت مراحل" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $plan_summary['percent'] ); ?>">
        <span style="width:<?php echo esc_attr( $plan_summary['percent'] ); ?>%"></span>
    </div>
    <p class="crm-access-muted"><?php echo esc_html( (string) $plan_summary['done'] ); ?> مرحله انجام‌شده از <?php echo esc_html( (string) $plan_summary['total'] ); ?> مرحله</p>
    <?php if ( ! $plan_steps ) : ?>
        <p class="crm-access-muted">هنوز مرحله‌ای برای این کار ثبت نشده است.</p>
    <?php else : ?>
        <div class="crmb-plan-list">
            <?php foreach ( $plan_steps as $plan_step ) :
                $can_toggle = $is_open && (int) $plan_step->assigned_to === get_current_user_id();
                ?>
                <article class="crmb-plan-row <?php echo $plan_step->is_done ? 'is-done' : ''; ?>">
                    <div class="crmb-plan-row-body">
                        <span class="crmb-plan-step-kind"><?php echo 'check' === $plan_step->kind ? 'چک‌لیست' : 'زیرتسک'; ?></span>
                        <strong><?php echo esc_html( $plan_step->title ); ?></strong>
                        <small>مسئول: <?php echo esc_html( crmb_work_user_name( $plan_step->assigned_to ) ); ?>
                            <?php if ( $plan_step->due_day ) : ?> · مهلت: <?php echo esc_html( crmb_att_jdate( $plan_step->due_day ) ); ?><?php endif; ?>
                            · <?php echo $plan_step->is_done ? 'انجام‌شده' : 'در انتظار انجام'; ?></small>
                    </div>
                    <div class="crmb-plan-row-actions">
                        <?php if ( $can_toggle ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php crmb_progress_form_fields( 'plan_action', $id ); ?>
                                <input type="hidden" name="plan_action" value="toggle">
                                <input type="hidden" name="step_id" value="<?php echo esc_attr( $plan_step->id ); ?>">
                                <input type="hidden" name="is_done" value="<?php echo $plan_step->is_done ? '0' : '1'; ?>">
                                <button type="submit" class="crmb-plan-toggle"><?php echo $plan_step->is_done ? 'بازگشایی مرحله' : 'انجام شد'; ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ( $admin && $is_open ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php crmb_progress_form_fields( 'plan_action', $id ); ?>
                                <input type="hidden" name="plan_action" value="remove">
                                <input type="hidden" name="step_id" value="<?php echo esc_attr( $plan_step->id ); ?>">
                                <button type="submit" class="crmb-plan-remove">حذف مرحله</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ( $admin && $is_open ) : ?>
        <details class="crmb-plan-editor">
            <summary>+ افزودن مرحله یا زیرتسک</summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <?php crmb_progress_form_fields( 'plan_action', $id ); ?>
                <input type="hidden" name="plan_action" value="add">
                <label for="crmb-plan-step-kind">نوع مرحله</label>
                <select id="crmb-plan-step-kind" name="step_kind">
                    <option value="check">چک‌لیست مسئول اصلی</option>
                    <option value="subtask">زیرتسک با مسئول مستقل</option>
                </select>
                <label for="crmb-plan-step-title">عنوان مرحله</label>
                <input id="crmb-plan-step-title" name="step_title" maxlength="180" required>
                <label for="crmb-plan-assignee">مسئول زیرتسک (برای چک‌لیست، مسئول تسک اصلی است)</label>
                <select id="crmb-plan-assignee" name="assigned_to">
                    <?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $member ) : ?>
                        <option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( (int) $member->ID, (int) $task->assigned_to ); ?>><?php echo esc_html( $member->display_name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="crmb-plan-due">مهلت مرحله (شمسی؛ اختیاری)</label>
                <input id="crmb-plan-due" name="due_day" placeholder="1405-08-26" dir="ltr">
                <button type="submit" class="crm-access-primary">ثبت مرحله</button>
            </form>
        </details>
        <?php if ( $plan_steps ) : ?>
        <details class="crmb-plan-editor">
            <summary>ذخیره مراحل این کار به‌عنوان قالب آماده</summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <?php crmb_progress_form_fields( 'plan_action', $id ); ?>
                <input type="hidden" name="plan_action" value="save_template">
                <label for="crmb-plan-template-name">نام قالب</label>
                <input id="crmb-plan-template-name" name="template_name" maxlength="140" placeholder="مثلاً ثبت محصول جدید" required>
                <button type="submit">ساخت قالب آماده از مراحل</button>
            </form>
        </details>
        <?php endif; ?>
        <?php if ( ! $plan_steps && $plan_templates ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crmb-plan-apply">
            <?php crmb_progress_form_fields( 'plan_action', $id ); ?>
            <input type="hidden" name="plan_action" value="apply_template">
            <label for="crmb-plan-template-select">افزودن مراحل از یک قالب آماده</label>
            <select id="crmb-plan-template-select" name="template_id">
                <?php foreach ( $plan_templates as $plan_template ) : ?>
                <option value="<?php echo esc_attr( $plan_template->id ); ?>"><?php echo esc_html( $plan_template->name ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="crm-access-primary">اعمال قالب روی این کار</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
