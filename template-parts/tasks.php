<?php
/** Own tasks for employees, optional team list and assignment form for managers. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
if ( isset( $_GET['task_id'] ) ) { get_template_part( 'template-parts/task-progress' ); return; }
$work_admin = crmb_work_manage_team();
$team = $work_admin && isset( $_GET['scope'] ) && 'team' === $_GET['scope'];
$page = isset( $_GET['work_page'] ) && is_scalar( $_GET['work_page'] ) ? max( 1, absint( $_GET['work_page'] ) ) : 1;
$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? $_GET['view'] : 'open';
if ( ! in_array( $view, array( 'all', 'open', 'done' ), true ) ) { $view = 'open'; }
$tasks = crmb_work_list( 'tasks', $page, 20, $team, false, false, 0, $view );
$counts = crmb_work_task_counts( $team );
$base = add_query_arg( array( 'crm_section' => 'tasks', 'scope' => $team ? 'team' : 'mine', 'view' => $view ), home_url( '/main/' ) );
$editing = null;
if ( $work_admin && isset( $_GET['edit_task'] ) ) {
    $edit_id = crmb_work_id( $_GET, 'edit_task' );
    if ( $edit_id && $tasks['ready'] ) {
        global $wpdb; $tables = crmb_work_tables();
        $editing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['tasks']} WHERE id = %d", $edit_id ) );
    }
}
?>
<section class="crm-access-screen crmb-work crmb-tasks-page" aria-labelledby="crmb-tasks-title">
    <header class="crm-access-header"><h1 id="crmb-tasks-title"><?php echo $team ? 'کارهای تیم' : 'کارهای من'; ?></h1><p>کارهای باز را پیگیری کنید؛ کارهای تکمیل‌شده در بایگانی باقی می‌مانند.</p></header>
    <?php crmb_work_notice(); ?>
    <?php if ( $work_admin ) : ?>
        <nav class="crm-access-tabs" aria-label="نمای کارهای شخصی و تیم"><a class="<?php echo $team ? '' : 'is-active'; ?>" href="<?php echo esc_url( add_query_arg( array( 'scope' => 'mine', 'work_page' => 1 ), $base ) ); ?>">کارهای من</a><a class="<?php echo $team ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'scope' => 'team', 'work_page' => 1 ), $base ) ); ?>">کارهای تیم</a></nav>
        <?php if ( $tasks['ready'] ) : ?>
        <details class="crm-access-card crmb-tasks-editor" <?php echo $editing ? 'open' : ''; ?>><summary><?php echo $editing ? 'ویرایش وظیفه' : '+ واگذاری وظیفه جدید'; ?></summary>
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
        </details>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    $status_labels = crmb_work_statuses();
    $priority_labels = crmb_work_priorities();
    $views = array(
        'all' => array( 'label' => 'همه کارها', 'icon' => 'fa-list-check' ),
        'open' => array( 'label' => 'کارهای باز', 'icon' => 'fa-clock' ),
        'done' => array( 'label' => 'کارهای تکمیل‌شده', 'icon' => 'fa-circle-check' ),
    );
    ?>
    <nav class="crmb-tasks-overview" aria-label="فیلتر وضعیت کارها">
        <?php foreach ( $views as $key => $option ) :
            $url = add_query_arg( array( 'view' => $key, 'work_page' => 1 ), $base );
            $selected = $view === $key;
            $count = $counts['ready'] ? (string) $counts[$key] : '—';
            ?>
            <a class="crmb-tasks-overview-item is-view-<?php echo esc_attr( $key ); ?> <?php echo $selected ? 'is-selected' : ''; ?>"
               href="<?php echo esc_url( $url ); ?>" <?php echo $selected ? 'aria-current="page"' : ''; ?>>
                <span class="crmb-tasks-overview-icon is-<?php echo esc_attr( $key ); ?>">
                    <i class="fa-solid <?php echo esc_attr( $option['icon'] ); ?>" aria-hidden="true"></i>
                </span>
                <span class="crmb-tasks-overview-copy">
                    <span><?php echo esc_html( $option['label'] ); ?></span>
                    <strong><?php echo esc_html( $count ); ?></strong>
                </span>
                <i class="fa-solid fa-arrow-left crmb-tasks-overview-arrow" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </nav>
    <section class="crmb-tasks-section" aria-labelledby="crmb-tasks-list-title">
        <div class="crmb-tasks-section-heading">
            <div><h2 id="crmb-tasks-list-title"><?php echo esc_html( $views[ $view ]['label'] ); ?><?php echo $team ? ' تیم' : ''; ?></h2>
                <p><?php echo 'done' === $view ? 'بایگانی کارهای انجام‌شده؛ برای دیدن جزئیات، هر کار را باز کنید.' : ( 'open' === $view ? 'فقط کارهای انجام‌نشده، در حال انجام و توقف موقت نمایش داده می‌شوند.' : 'تمام کارها، شامل موارد بایگانی‌شده و لغوشده.' ); ?></p></div>
            <span class="crmb-tasks-section-count"><?php echo $tasks['ready'] ? esc_html( (string) count( $tasks['rows'] ) ) : '—'; ?> مورد در این صفحه</span>
        </div>
        <?php if ( ! $tasks['ready'] ) : ?>
            <p class="crmb-tasks-empty" role="status">دریافت وظایف انجام نشد؛ لطفاً صفحه را دوباره بارگذاری کنید.</p>
        <?php elseif ( ! $tasks['rows'] ) : ?>
            <div class="crmb-tasks-empty"><span aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
                <strong><?php echo 'done' === $view ? 'هنوز کار تکمیل‌شده‌ای ندارید.' : ( 'open' === $view ? 'فعلاً کار بازی ندارید.' : 'کاری برای نمایش وجود ندارد.' ); ?></strong>
                <p><?php echo 'done' === $view ? 'پس از تکمیل هر کار، در این بایگانی قابل مشاهده خواهد بود.' : 'برای دیدن وضعیت‌های دیگر، از فیلترهای بالای صفحه استفاده کنید.'; ?></p>
            </div>
        <?php else : ?>
            <div class="crmb-tasks-grid">
            <?php foreach ( $tasks['rows'] as $task ) :
                $status_key = isset( $status_labels[ $task->status ] ) ? $task->status : 'todo';
                $priority_key = isset( $priority_labels[ $task->priority ] ) ? $task->priority : 'normal';
                $is_open = in_array( $task->status, array( 'todo', 'doing', 'paused' ), true );
                $overdue = $task->due_day && $task->due_day < crmb_att_today() && $is_open;
                $due_today = $task->due_day && $task->due_day === crmb_att_today() && $is_open;
                $task_url = add_query_arg( 'task_id', $task->id, $base );
                ?>
                <article class="crmb-task-card is-status-<?php echo esc_attr( $status_key ); ?>" id="task-<?php echo esc_attr( $task->id ); ?>">
                    <div class="crmb-task-card-top">
                        <span class="crmb-task-card-number">کار #<?php echo esc_html( (string) $task->id ); ?></span>
                        <div class="crmb-task-card-flags">
                            <span class="crmb-task-card-priority is-priority-<?php echo esc_attr( $priority_key ); ?>"><?php echo esc_html( $priority_labels[ $priority_key ] ); ?></span>
                            <span class="crmb-task-card-status is-<?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status_labels[ $status_key ] ); ?></span>
                        </div>
                    </div>
                    <h3 class="crmb-task-card-title"><a href="<?php echo esc_url( $task_url ); ?>"><?php echo esc_html( $task->title ); ?></a></h3>
                    <div class="crmb-task-card-info">
                        <div><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                            <span><?php echo $overdue ? 'مهلت گذشته: ' : ( $due_today ? 'مهلت امروز: ' : 'مهلت انجام: ' ); ?>
                            <strong class="<?php echo $overdue ? 'crmb-work-overdue' : ''; ?>"><?php echo esc_html( $task->due_day ? crmb_att_jdate( $task->due_day ) : 'تعیین نشده' ); ?></strong></span>
                        </div>
                        <div><i class="fa-solid fa-user-check" aria-hidden="true"></i>
                            <span><?php echo $team ? 'مسئول: ' : 'واگذارکننده: '; ?><strong><?php echo esc_html( crmb_work_user_name( $team ? $task->assigned_to : $task->created_by ) ); ?></strong></span>
                        </div>
                    </div>
                    <?php if ( ! empty( $task->timer_started_utc ) ) : ?>
                        <div class="crmb-task-card-running"><span class="crmb-task-card-live-dot" aria-hidden="true"></span> زمان‌سنج فعال است؛ جزئیات زمان کار را ببینید.</div>
                    <?php endif; ?>
                    <div class="crmb-task-card-footer">
                        <a class="crmb-task-card-action" href="<?php echo esc_url( $task_url ); ?>">
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                            مشاهده جزئیات و ثبت پیشرفت
                        </a>
                        <?php if ( $work_admin || 'cancelled' !== $task->status ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crmb-task-card-status-form">
                                <?php crmb_work_form_fields( 'task_status', $task->id ); ?>
                                <label for="status-<?php echo esc_attr( $task->id ); ?>">تغییر وضعیت</label>
                                <select id="status-<?php echo esc_attr( $task->id ); ?>" name="status">
                                <?php foreach ( $status_labels as $key => $label ) :
                                    if ( ! $work_admin && 'cancelled' === $key ) { continue; } ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $task->status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                                </select><button type="submit">ذخیره</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ( $work_admin ) : ?>
                        <a class="crmb-task-card-edit" href="<?php echo esc_url( add_query_arg( 'edit_task', $task->id, $base ) ); ?>">ویرایش توضیحات و مسئول</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>
            <nav class="crm-access-pages crmb-tasks-pagination" aria-label="صفحات وظایف">
                <?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'work_page', $page - 1, $base ) ); ?>">صفحه قبل</a><?php endif; ?>
                <span>صفحه <?php echo esc_html( (string) $page ); ?> از <?php echo esc_html( (string) max( 1, ceil( $tasks['total'] / 20 ) ) ); ?></span>
                <?php if ( $page * 20 < $tasks['total'] ) : ?><a href="<?php echo esc_url( add_query_arg( 'work_page', $page + 1, $base ) ); ?>">صفحه بعد</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</section>
