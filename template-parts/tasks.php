<?php
/** Own tasks for employees, optional team list and assignment form for managers. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
if ( isset( $_GET['task_id'] ) ) { get_template_part( 'template-parts/task-progress' ); return; }
$work_admin = crmb_work_manage_team();
$team = $work_admin && isset( $_GET['scope'] ) && 'team' === $_GET['scope'];
$page = isset( $_GET['work_page'] ) && is_scalar( $_GET['work_page'] ) ? max( 1, absint( $_GET['work_page'] ) ) : 1;
$tasks = crmb_work_list( 'tasks', $page, 20, $team );
$base = add_query_arg( array( 'crm_section' => 'tasks', 'scope' => $team ? 'team' : 'mine' ), home_url( '/main/' ) );
$editing = null;
if ( $work_admin && isset( $_GET['edit_task'] ) ) {
    $edit_id = crmb_work_id( $_GET, 'edit_task' );
    if ( $edit_id && $tasks['ready'] ) {
        global $wpdb; $tables = crmb_work_tables();
        $editing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['tasks']} WHERE id = %d", $edit_id ) );
    }
}
?>
<section class="crm-access-screen crmb-work" aria-labelledby="crmb-tasks-title">
    <header class="crm-access-header"><h1 id="crmb-tasks-title">وظایف</h1><p>پیگیری کارهای محول‌شده، اولویت و مهلت انجام</p></header>
    <?php crmb_work_notice(); ?>
    <?php if ( $work_admin ) : ?>
        <nav class="crm-access-tabs" aria-label="فیلتر وظایف"><a class="<?php echo $team ? '' : 'is-active'; ?>" href="<?php echo esc_url( add_query_arg( 'scope', 'mine', $base ) ); ?>">وظایف من</a><a class="<?php echo $team ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'scope', 'team', $base ) ); ?>">همه وظایف تیم</a></nav>
        <?php if ( $tasks['ready'] ) : ?>
        <section class="crm-access-card"><h2><?php echo $editing ? 'ویرایش وظیفه' : 'واگذاری وظیفه جدید'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <?php crmb_work_form_fields( 'save_task', $editing ? $editing->id : 0 ); ?>
                <label for="task-title">عنوان</label><input id="task-title" name="title" required maxlength="60" value="<?php echo esc_attr( $editing ? $editing->title : '' ); ?>">
                <label for="task-description">شرح کار</label><textarea id="task-description" name="description" rows="4" maxlength="3000"><?php echo esc_textarea( $editing ? $editing->description : '' ); ?></textarea>
                <label for="task-assignee">مسئول انجام</label><select id="task-assignee" name="assigned_to" required><option value="">انتخاب کارمند</option><?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $member ) : ?><option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( $editing ? $editing->assigned_to : 0, $member->ID ); ?>><?php echo esc_html( $member->display_name ); ?></option><?php endforeach; ?></select>
                <label for="task-priority">اولویت</label><select id="task-priority" name="priority"><?php foreach ( crmb_work_priorities() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $editing ? $editing->priority : 'normal', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
                <label for="task-due">مهلت انجام (شمسی؛ اختیاری)</label><input id="task-due" name="due_day" dir="ltr" placeholder="1405-08-15" value="<?php echo esc_attr( $editing && $editing->due_day ? crmb_att_jdate( $editing->due_day ) : '' ); ?>"><p class="crm-access-muted">مهلت تا پایان روز انتخابی به وقت تهران است.</p>
                <button class="crm-access-primary" type="submit">ذخیره وظیفه</button>
                <?php if ( $editing ) : ?><a href="<?php echo esc_url( $base ); ?>">انصراف از ویرایش</a><?php endif; ?>
            </form>
        </section>
        <?php endif; ?>
    <?php endif; ?>
    <section class="crm-access-card"><h2><?php echo $team ? 'وظایف تیم' : 'وظایف من'; ?></h2>
    <?php if ( ! $tasks['ready'] ) : ?><p role="status">دریافت وظایف انجام نشد؛ دوباره تلاش کنید.</p>
    <?php elseif ( ! $tasks['rows'] ) : ?><p class="crm-access-muted">وظیفه‌ای برای نمایش وجود ندارد.</p>
    <?php else : ?>
        <?php foreach ( $tasks['rows'] as $task ) :
            $overdue = $task->due_day && $task->due_day < crmb_att_today() && in_array( $task->status, array( 'todo', 'doing', 'paused' ), true ); ?>
            <article class="crmb-work-item" id="task-<?php echo esc_attr( $task->id ); ?>">
                <h3><?php echo esc_html( $task->title ); ?></h3>
                <p><a class="crmb-task-detail-link" href="<?php echo esc_url( add_query_arg( 'task_id', $task->id, $base ) ); ?>">شروع / پایان کار و مشاهده روند انجام</a></p>
                <?php if ( ! empty( $task->timer_started_utc ) ) : ?><p class="crmb-task-running">زمان‌سنج در حال اجراست — شروع: <?php echo esc_html( crmb_work_date( $task->timer_started_utc ) ); ?></p><?php endif; ?>
                <p class="crmb-work-body"><?php echo esc_html( $task->description ); ?></p>
                <p class="crm-access-muted">مسئول: <?php echo esc_html( crmb_work_user_name( $task->assigned_to ) ); ?> | واگذارکننده: <?php echo esc_html( crmb_work_user_name( $task->created_by ) ); ?></p>
                <p>اولویت: <?php echo esc_html( crmb_work_priorities()[ $task->priority ] ?? 'عادی' ); ?> | وضعیت: <?php echo esc_html( crmb_work_statuses()[ $task->status ] ?? 'نامشخص' ); ?> | مهلت: <?php echo esc_html( $task->due_day ? crmb_att_jdate( $task->due_day ) : 'بدون مهلت' ); ?> <?php if ( $overdue ) : ?><strong class="crmb-work-overdue">مهلت گذشته</strong><?php endif; ?></p>
                <?php if ( $work_admin || 'cancelled' !== $task->status ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form crmb-work-inline">
                        <?php crmb_work_form_fields( 'task_status', $task->id ); ?>
                        <label for="status-<?php echo esc_attr( $task->id ); ?>">وضعیت جدید</label><select id="status-<?php echo esc_attr( $task->id ); ?>" name="status"><?php foreach ( crmb_work_statuses() as $key => $label ) : if ( ! $work_admin && 'cancelled' === $key ) { continue; } ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $task->status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><button class="crm-access-primary" type="submit">ثبت وضعیت</button>
                    </form>
                <?php endif; ?>
                <?php if ( $work_admin ) : ?><a href="<?php echo esc_url( add_query_arg( 'edit_task', $task->id, $base ) ); ?>">ویرایش شرح، مسئول و مهلت</a><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <nav class="crm-access-pages" aria-label="صفحات وظایف">
            <?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'work_page', $page - 1, $base ) ); ?>">قبلی</a><?php endif; ?>
            <span><?php echo esc_html( $page ); ?> / <?php echo esc_html( max( 1, ceil( $tasks['total'] / 20 ) ) ); ?></span>
            <?php if ( $page * 20 < $tasks['total'] ) : ?><a href="<?php echo esc_url( add_query_arg( 'work_page', $page + 1, $base ) ); ?>">بعدی</a><?php endif; ?>
        </nav>
    <?php endif; ?>
    </section>
</section>
