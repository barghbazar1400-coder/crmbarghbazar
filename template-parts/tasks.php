<?php
/** Own tasks for employees, optional team list and assignment form for managers. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
if ( isset( $_GET['task_id'] ) ) { get_template_part( 'template-parts/task-progress' ); return; }
$work_admin = crmb_work_manage_team();
$team = $work_admin && isset( $_GET['scope'] ) && 'team' === $_GET['scope'];
$page = isset( $_GET['work_page'] ) && is_scalar( $_GET['work_page'] ) ? max( 1, absint( $_GET['work_page'] ) ) : 1;
$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? $_GET['view'] : 'open';
if ( ! in_array( $view, array( 'all', 'open', 'review', 'done' ), true ) ) { $view = 'open'; }
$members = $work_admin ? get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) ) : array();
$assigned_filter = $team ? crmb_work_id( $_GET, 'assigned_to' ) : 0;
if ( $assigned_filter && ! get_userdata( $assigned_filter ) ) { $assigned_filter = 0; }
$tasks = crmb_work_list( 'tasks', $page, 20, $team, false, false, 0, $view, $assigned_filter );
$counts = crmb_work_task_counts( $team, $assigned_filter );
$base_args = array( 'crm_section' => 'tasks', 'scope' => $team ? 'team' : 'mine', 'view' => $view );
if ( $team && $assigned_filter ) { $base_args['assigned_to'] = $assigned_filter; }
$base = add_query_arg( $base_args, home_url( '/main/' ) );
$selected_member = $assigned_filter ? get_userdata( $assigned_filter ) : null;
$new_task_url = add_query_arg( array( 'new_task' => 1, 'view' => 'open', 'work_page' => 1 ), $base ) . '#crmb-task-editor';
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
        <nav class="crm-access-tabs" aria-label="نمای کارهای شخصی و تیم"><a class="<?php echo $team ? '' : 'is-active'; ?>" href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'tasks', 'scope' => 'mine', 'view' => $view, 'work_page' => 1 ), home_url( '/main/' ) ) ); ?>">کارهای من</a><a class="<?php echo $team ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'tasks', 'scope' => 'team', 'view' => $view ), home_url( '/main/' ) ) ); ?>">کارهای تیم</a></nav>
        <?php if ( $team ) : ?>
        <section class="crmb-team-task-filter" aria-labelledby="crmb-team-task-filter-title">
            <div class="crmb-team-task-filter-heading">
                <div><h2 id="crmb-team-task-filter-title">فیلتر کارهای کارکنان</h2>
                    <p>مسئول را انتخاب کنید تا تسک‌ها و چهار شمارنده بالا فقط برای همان شخص نمایش داده شوند.</p></div>
                <?php if ( $selected_member ) : ?>
                    <a class="crmb-team-task-add" href="<?php echo esc_url( $new_task_url ); ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> تسک جدید برای <?php echo esc_html( $selected_member->display_name ); ?></a>
                <?php endif; ?>
            </div>
            <form method="get" action="<?php echo esc_url( home_url( '/main/' ) ); ?>" class="crmb-team-task-filter-form">
                <input type="hidden" name="crm_section" value="tasks">
                <input type="hidden" name="scope" value="team">
                <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
                <label for="crmb-team-assignee">مسئول انجام کار</label>
                <select id="crmb-team-assignee" name="assigned_to">
                    <option value="0">همه کارکنان</option>
                    <?php foreach ( $members as $member ) : ?>
                        <option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( $assigned_filter, $member->ID ); ?>><?php echo esc_html( $member->display_name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="crm-access-primary">نمایش کارها</button>
                <?php if ( $assigned_filter ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'tasks', 'scope' => 'team', 'view' => $view ), home_url( '/main/' ) ) ); ?>">حذف فیلتر</a><?php endif; ?>
            </form>
        </section>
        <?php endif; ?>
        <?php if ( $tasks['ready'] ) : ?>
        <details id="crmb-task-editor" class="crm-access-card crmb-tasks-editor" <?php echo $editing || isset( $_GET['new_task'] ) ? 'open' : ''; ?>><summary><?php echo $editing ? 'ویرایش وظیفه' : ( $selected_member ? 'واگذاری کار جدید به ' . esc_html( $selected_member->display_name ) : '+ واگذاری وظیفه جدید' ); ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <?php crmb_work_form_fields( 'save_task', $editing ? $editing->id : 0 ); ?>
                <input type="hidden" name="return_assignee" value="<?php echo esc_attr( $assigned_filter ); ?>">
                <input type="hidden" name="return_view" value="<?php echo esc_attr( $view ); ?>">
                <label for="task-title">عنوان</label><input id="task-title" name="title" required maxlength="180" value="<?php echo esc_attr( $editing ? $editing->title : '' ); ?>">
                <label for="task-description">شرح کار</label><textarea id="task-description" name="description" rows="4" maxlength="3000"><?php echo esc_textarea( $editing ? $editing->description : '' ); ?></textarea>
                <label for="task-assignee">مسئول انجام</label><select id="task-assignee" name="assigned_to" required><option value="">انتخاب کارمند</option><?php foreach ( $members as $member ) : ?><option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( $editing ? $editing->assigned_to : $assigned_filter, $member->ID ); ?>><?php echo esc_html( $member->display_name ); ?></option><?php endforeach; ?></select>
                <label for="task-priority">اولویت</label><select id="task-priority" name="priority"><?php foreach ( crmb_work_priorities() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $editing ? $editing->priority : 'normal', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
                <label for="task-repeat">تکرار وظیفه</label>
                <select id="task-repeat" name="repeat">
                    <option value="none" <?php selected( ! empty( $editing->repeat_series_id ) ? 'daily' : 'none', 'none' ); ?>>بدون تکرار</option>
                    <option value="daily" <?php selected( ! empty( $editing->repeat_series_id ) ? 'daily' : 'none', 'daily' ); ?>>هر روز</option>
                </select>
                <p class="crm-access-muted">با انتخاب «هر روز»، برای هر روز یک کار جدا با تاریخ همان روز ساخته می‌شود؛ گزارش و زمان‌سنج روزهای قبل حفظ خواهد شد.</p>
                <label for="task-due">مهلت انجام / شروع تکرار (شمسی؛ اختیاری)</label>
                <input id="task-due" name="due_day" dir="ltr" placeholder="1405-08-25" value="<?php echo esc_attr( $editing && $editing->due_day ? crmb_att_jdate( $editing->due_day ) : '' ); ?>">
                <p class="crm-access-muted">اگر شروع تکرار خالی باشد، از امروز آغاز می‌شود. برای تکرار روزانه، پایان هر روز مهلت همان نوبت است.</p>
                <button class="crm-access-primary" type="submit"><?php echo $editing ? 'ذخیره تغییرات وظیفه' : 'ایجاد وظیفه'; ?></button>
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
        'review' => array( 'label' => 'در انتظار تأیید', 'icon' => 'fa-clipboard-check' ),
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
            <div><h2 id="crmb-tasks-list-title"><?php echo esc_html( $views[ $view ]['label'] ); ?><?php echo $team ? ( $selected_member ? ' — ' . esc_html( $selected_member->display_name ) : ' تیم' ) : ''; ?></h2>
                <p><?php echo 'done' === $view ? 'فقط کارهای تأیید نهایی‌شده در بایگانی قرار می‌گیرند.' : ( 'review' === $view ? 'کارهایی که تحویل داده شده‌اند و منتظر تصمیم مدیر هستند.' : ( 'open' === $view ? 'کارهای در حال انجام و منتظر تأیید، تا تصمیم نهایی باز می‌مانند.' : 'تمام کارها، شامل موارد بایگانی‌شده و لغوشده.' ) ); ?></p></div>
            <span class="crmb-tasks-section-count"><?php echo $tasks['ready'] ? esc_html( (string) count( $tasks['rows'] ) ) : '—'; ?> مورد در این صفحه</span>
        </div>
        <?php if ( ! $tasks['ready'] ) : ?>
            <p class="crmb-tasks-empty" role="status">دریافت وظایف انجام نشد؛ لطفاً صفحه را دوباره بارگذاری کنید.</p>
        <?php elseif ( ! $tasks['rows'] ) : ?>
            <div class="crmb-tasks-empty"><span aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
                <strong><?php echo 'done' === $view ? 'هنوز کار تأییدشده‌ای ندارید.' : ( 'review' === $view ? 'کاری در انتظار تأیید نیست.' : ( 'open' === $view ? 'فعلاً کار بازی ندارید.' : 'کاری برای نمایش وجود ندارد.' ) ); ?></strong>
                <p><?php echo 'done' === $view ? 'پس از تکمیل هر کار، در این بایگانی قابل مشاهده خواهد بود.' : 'برای دیدن وضعیت‌های دیگر، از فیلترهای بالای صفحه استفاده کنید.'; ?></p>
            </div>
        <?php else : ?>
            <div class="crmb-tasks-grid">
            <?php foreach ( $tasks['rows'] as $task ) :
                $status_key = isset( $status_labels[ $task->status ] ) ? $task->status : 'todo';
                $priority_key = isset( $priority_labels[ $task->priority ] ) ? $task->priority : 'normal';
                $is_open = in_array( $task->status, array( 'todo', 'doing', 'paused', 'review' ), true );
                $overdue = $task->due_day && $task->due_day < crmb_att_today() && $is_open;
                $due_today = $task->due_day && $task->due_day === crmb_att_today() && $is_open;
                $task_url = add_query_arg( 'task_id', $task->id, $base );
                ?>
                <article class="crmb-task-card is-status-<?php echo esc_attr( $status_key ); ?>" id="task-<?php echo esc_attr( $task->id ); ?>">
                    <div class="crmb-task-card-top">
                        <span class="crmb-task-card-number">کار #<?php echo esc_html( (string) $task->id ); ?></span>
                        <div class="crmb-task-card-flags">
                            <?php if ( ! empty( $task->repeat_series_id ) ) : ?>
                                <span class="crmb-task-card-repeat" title="یک نمونه مستقل از کار تکراری روزانه"><i class="fa-solid fa-repeat" aria-hidden="true"></i> روزانه</span>
                            <?php endif; ?>
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
                        <?php if ( 'review' !== $task->status && ( $work_admin || ! in_array( $task->status, array( 'cancelled', 'done' ), true ) ) ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crmb-task-card-status-form">
                                <?php crmb_work_form_fields( 'task_status', $task->id ); ?>
                                <label for="status-<?php echo esc_attr( $task->id ); ?>">تغییر وضعیت</label>
                                <select id="status-<?php echo esc_attr( $task->id ); ?>" name="status">
                                <?php foreach ( $status_labels as $key => $label ) :
                                    $is_assignee = (int) $task->assigned_to === get_current_user_id();
                                    // Final approval has a dedicated manager action; the
                                    // ordinary dropdown only sends the assignee's own work.
                                    if ( 'review' === $key || ( ! $work_admin && 'cancelled' === $key )
                                        || ( 'done' === $key && ! $is_assignee && 'done' !== $task->status ) ) { continue; }
                                    $status_option_label = 'done' === $key && $is_assignee ? 'ارسال برای تأیید مدیر' : $label; ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $task->status, $key ); ?>><?php echo esc_html( $status_option_label ); ?></option>
                                <?php endforeach; ?>
                                </select><button type="submit">ذخیره</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ( $work_admin ) : ?>
                        <div class="crmb-task-admin-actions">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                class="crmb-task-delete-form" onsubmit="return confirm('این وظیفه از فهرست کارمندان حذف شود؟ سوابق برای بررسی مدیریتی نگه داشته می‌شود.');">
                                <?php crmb_work_form_fields( 'delete_task', $task->id ); ?>
                                <?php if ( ! empty( $task->repeat_series_id ) ) : ?>
                                    <label class="crmb-task-delete-future"><input type="checkbox" name="stop_repeat" value="1" checked> توقف تکرارهای روزهای بعد</label>
                                <?php endif; ?>
                                <div class="crmb-task-action-buttons">
                                    <a class="crmb-task-card-edit" href="<?php echo esc_url( add_query_arg( array( 'edit_task' => $task->id, 'scope' => 'team' ), $base ) ); ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> ویرایش وظیفه</a>
                                    <button type="submit" class="crmb-task-delete-button"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> حذف وظیفه</button>
                                </div>
                            </form>
                        </div>
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
