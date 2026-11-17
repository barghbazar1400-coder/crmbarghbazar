<?php
/** Render regressions for the default open tasks, completed archive and team access. */
define( 'ABSPATH', __DIR__ );
$as_manager = false; $checks = 0;
function is_user_logged_in() { return true; }
function crmb_work_manage_team() { global $as_manager; return $as_manager; }
function get_current_user_id() { global $as_manager; return $as_manager ? 1 : 3; }
function crmb_work_control_queues() { return array( 'overdue'=>'وظایف عقب‌افتاده', 'stalled'=>'وظایف بدون گزارش تازه (۴۸ ساعت)', 'timers'=>'زمان‌سنج‌های فعال', 'activity'=>'فعالیت امروز کارکنان' ); }
function crmb_work_task_counts( $team, $assigned_filter = 0, $queue = '' ) {
    global $count_filter, $count_queue;
    $count_filter = $assigned_filter;
    $count_queue = $queue;
    if ( ! $team && $GLOBALS['as_manager'] ) {
        return array( 'ready'=>true, 'all'=>3, 'open'=>2, 'review'=>1, 'done'=>1 );
    }
    return array( 'ready' => true, 'all' => $assigned_filter === 4 ? 0 : 2, 'open' => $assigned_filter === 4 ? 0 : 1, 'review' => 0, 'done' => $assigned_filter === 4 ? 0 : 1 );
}
function crmb_work_list( $kind, $page, $limit, $team, $sent = false, $active = false, $focus = 0, $view = 'all', $assigned_filter = 0, $queue = '' ) {
    global $list_filter, $list_queue;
    $list_filter = $assigned_filter;
    $list_queue = $queue;
    if ( $kind !== 'tasks' ) { throw new RuntimeException( 'Unexpected list' ); }
    $rows = array(
        (object) array( 'id' => 42, 'title' => 'مانیتورهای سوزوکی',
            'description' => 'شرح محرمانه <script>alert(1)</script>',
            'priority' => 'high', 'status' => 'todo', 'due_day' => '2026-11-11',
            'assigned_to' => 3, 'created_by' => 1, 'timer_started_utc' => null, 'repeat_series_id' => 12 ),
        (object) array( 'id' => 43, 'title' => 'تکمیل گزارش',
            'description' => 'گزارش آماده شد',
            'priority' => 'normal', 'status' => 'done', 'due_day' => null,
            'assigned_to' => 3, 'created_by' => 1, 'timer_started_utc' => null ),
    );
    if ( $GLOBALS['as_manager'] && ! $team ) {
        $rows[] = (object) array( 'id'=>44, 'title'=>'گزارش کارکنان برای تأیید',
            'description'=>'در انتظار تصمیم مدیر', 'priority'=>'normal',
            'status'=>'review', 'due_day'=>null,
            'assigned_to'=>3, 'created_by'=>1, 'timer_started_utc'=>null );
    }
    $rows = array_values( array_filter( $rows, static function ( $r ) use ( $view, $assigned_filter, $team ) {
        if ( $team && $assigned_filter && (int) $r->assigned_to !== $assigned_filter ) { return false; }
        return 'all' === $view || ( 'open' === $view && in_array( $r->status, array( 'todo', 'doing', 'paused', 'review' ), true ) )
            || ( 'done' === $view && 'done' === $r->status ) || ( 'review' === $view && 'review' === $r->status );
    } ) );
    return array( 'ready' => true, 'total' => count( $rows ), 'rows' => $rows );
}
function crmb_work_statuses() { return array( 'todo' => 'انجام‌نشده', 'doing' => 'در حال انجام', 'paused' => 'توقف موقت', 'review' => 'در انتظار تأیید مدیر', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده' ); }
function crmb_work_priorities() { return array( 'normal' => 'عادی', 'high' => 'مهم', 'urgent' => 'فوری' ); }
function crmb_work_user_name( $id ) { return (int) $id === 1 ? 'مدیر' : 'کارمند'; }
function crmb_att_today() { return '2026-11-14'; }
function crmb_att_jdate( $day ) { return '۱۴۰۵/۰۸/۲۳'; }
function crmb_work_id( $data, $key ) { return isset( $data[$key] ) ? (int) $data[$key] : 0; }
function home_url( $url ) { return $url; }
function admin_url( $url ) { return '/wp-admin/' . $url; }
function add_query_arg( $key, $value, $url = '' ) {
    if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
    return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function selected( $actual, $expected ) { if ( (string) $actual === (string) $expected ) { echo 'selected="selected"'; } }
function get_users( $args ) { return array( (object) array( 'ID' => 3, 'display_name' => 'کارمند اول' ), (object) array( 'ID' => 4, 'display_name' => 'کارمند دوم' ) ); }
function get_userdata( $id ) { return in_array( (int) $id, array( 3, 4 ), true ) ? (object) array( 'ID' => (int) $id, 'display_name' => (int) $id === 3 ? 'کارمند اول' : 'کارمند دوم' ) : false; }
function crmb_work_form_fields( $name, $id = 0 ) {
    echo '<input type="hidden" name="task" value="' . esc_attr( $name ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
}
function crmb_work_notice() {}
function crmb_work_review_state( $assigned_filter = 0, $team = true ) {
    return array( 'count'=>1, 'version'=>5 );
}
function wp_create_nonce( $action ) { return 'test-nonce'; }
function get_template_directory() { return __DIR__ . '/absent-assets'; }
function get_template_part( $name ) { throw new RuntimeException( 'Unexpected include ' . $name ); }
function verify( $condition, $name ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) { throw new RuntimeException( 'Task template assertion ' . $checks . ': ' . $name ); }
}
function render( $team = false, $view = null, $assignee = 0, $new = false, $managerPersonal = false, $queue = '' ) {
    global $as_manager;
    $as_manager = $team || $managerPersonal;
    $_GET = $team ? array( 'scope' => 'team' ) : array();
    if ( $assignee ) { $_GET['assigned_to'] = (string) $assignee; }
    if ( $new ) { $_GET['new_task'] = '1'; }
    if ( null !== $view ) { $_GET['view'] = $view; }
    if ( $queue ) { $_GET['queue'] = $queue; }
    ob_start();
    include __DIR__ . '/../template-parts/tasks.php';
    return ob_get_clean();
}
$employee = render();
verify( str_contains( $employee, '>کارهای من</h1>' ), 'Renamed employee view' );
verify( str_contains( $employee, 'crmb-tasks-overview' ), 'Filter navigation exists' );
verify( str_contains( $employee, 'aria-current="page"' ), 'Active filter accessible' );
verify( str_contains( $employee, 'کارهای باز' ), 'Open filter label' );
verify( str_contains( $employee, 'کارهای تکمیل‌شده' ), 'Completed archive label' );
verify( str_contains( $employee, 'در انتظار تأیید' ), 'Review filter available' );
verify( str_contains( $employee, 'ارسال برای تأیید مدیر' ), 'Employee completion label' );
verify( ! str_contains( $employee, 'value="review"' ), 'Cannot force review as a generic status' );
verify( substr_count( $employee, '<article class="crmb-task-card' ) === 1, 'Default excludes completed task' );
verify( str_contains( $employee, 'مانیتورهای سوزوکی' ), 'Open task present' );
verify( ! str_contains( $employee, 'تکمیل گزارش' ), 'Completed task absent' );
verify( ! str_contains( $employee, 'شرح محرمانه' ), 'Description stays in task detail' );
verify( str_contains( $employee, 'مهلت گذشته' ), 'Overdue warning' );
verify( str_contains( $employee, 'مشاهده جزئیات و ثبت پیشرفت' ), 'Detail action' );
verify( ! str_contains( $employee, 'value="cancelled"' ), 'Employee cannot cancel' );
verify( ! str_contains( $employee, 'واگذاری وظیفه جدید' ), 'No manager assignment form' );
verify( ! str_contains( $employee, 'name="stop_repeat"' ), 'Employee cannot stop task repetition' );
verify( ! str_contains( $employee, 'value="delete_task"' ), 'Employee cannot delete tasks' );
$archive = render( false, 'done' );
verify( substr_count( $archive, '<article class="crmb-task-card' ) === 1, 'Archive displays completed only' );
verify( str_contains( $archive, 'تکمیل گزارش' ), 'Archive item' );
verify( ! str_contains( $archive, 'مانیتورهای سوزوکی' ), 'Archive excludes open tasks' );
$all = render( false, 'all' );
verify( substr_count( $all, '<article class="crmb-task-card' ) === 2, 'All tasks shows both' );
$manager = render( true );
verify( str_contains( $manager, 'class="crm-access-card crmb-tasks-editor"' ), 'Manager assignment form' );
verify( str_contains( $manager, 'کارهای تیم' ), 'Team navigation' );
verify( str_contains( $manager, 'value="cancelled"' ), 'Manager status rights' );
verify( ! str_contains( $manager, '<option value="done"' ), 'Manager cannot directly complete another employee open task' );
verify( str_contains( $manager, 'مسئول:' ), 'Manager sees assignee' );
verify( str_contains( $manager, 'name="repeat"' ), 'Manager sees repeat selector' );
verify( str_contains( $manager, '>هر روز</option>' ), 'Daily repeat choice' );
verify( str_contains( $manager, 'value="delete_task"' ), 'Manager sees delete action' );
verify( str_contains( $manager, 'حذف وظیفه' ), 'Manager sees clear delete button' );
verify( str_contains( $manager, 'ویرایش وظیفه' ), 'Manager sees task edit button' );
verify( str_contains( $manager, 'class="crmb-task-action-buttons"' ), 'Manager actions have a dedicated shared row' );
verify( str_contains( $manager, 'class="crmb-task-delete-future"' ), 'Repeat checkbox remains visible for recurring task' );
verify( strpos( $manager, 'class="crmb-task-delete-future"' ) < strpos( $manager, 'class="crmb-task-action-buttons"' ), 'Repeat checkbox is above edit/delete actions' );
verify( (bool) preg_match( '/<div class="crmb-task-action-buttons">\s*<a class="crmb-task-card-edit"[^>]*>.*?<\/a>\s*<button type="submit" class="crmb-task-delete-button"/s', $manager ), 'Edit and delete are side-by-side in one action row' );

verify( str_contains( $manager, 'فیلتر کارهای کارکنان' ), 'Manager-only assignee filter' );
verify( ! str_contains( $employee, 'فیلتر کارهای کارکنان' ), 'Employee cannot access team filter' );
$filtered = render( true, 'open', 3, true );
verify( 3 === $list_filter && 3 === $count_filter, 'Both list and counters use selected employee' );
verify( str_contains( $filtered, 'تسک جدید برای کارمند اول' ), 'Employee-specific quick create link' );
verify( str_contains( $filtered, 'id="crmb-task-editor" class="crm-access-card crmb-tasks-editor" open' ), 'Quick create opens form' );
verify( str_contains( $filtered, 'name="return_assignee" value="3"' ), 'Creation remembers filter' );
verify( str_contains( $filtered, 'name="assigned_to"' ), 'Assignee is editable before creating' );
verify( str_contains( $filtered, 'assigned_to=3' ), 'Status links preserve selected employee' );
$empty_member = render( true, 'open', 4 );
verify( 4 === $list_filter && 4 === $count_filter, 'Second employee selects own tasks' );
verify( str_contains( $empty_member, 'فعلاً کار بازی ندارید' ), 'No results for selected assignee' );
$own = render( false, 'open', 3 );
verify( 0 === $list_filter && 0 === $count_filter, 'Forged employee filter ignored for non-managers' );
verify( ! str_contains( $own, 'تسک جدید برای' ), 'No employee creation UI' );
$managerInbox = render( false, 'review', 0, false, true );
verify( str_contains( $managerInbox, 'گزارش کارکنان برای تأیید' ), 'Manager personal review queue includes employee work' );
verify( str_contains( $managerInbox, 'نیازمند تأیید شما' ), 'Personal review distinguishes assigned employee from manager' );
verify( str_contains( $managerInbox, 'بررسی و تأیید یا درخواست اصلاح' ), 'Review card has manager review action' );
verify( str_contains( $managerInbox, 'name="scope"' ) === false, 'No team selector hidden in personal view' );
verify( str_contains( $managerInbox, 'data-scope="mine"' ), 'Personal review queue uses its own refresh scope' );
verify( ! str_contains( $managerInbox, 'name="status"' ), 'Manager cannot change employee task status in personal review' );
verify( ! str_contains( $managerInbox, 'value="delete_task"' ), 'Review inbox does not expose task deletion controls' );
$queueHtml = render( true, 'open', 3, false, false, 'overdue' );
verify( $list_queue === 'overdue' && $count_queue === 'overdue', 'List and all status counts use manager queue filter' );
verify( str_contains( $queueHtml, 'فیلتر مرکز کنترل: وظایف عقب‌افتاده' ), 'Context and applied queue visible' );
verify( str_contains( $queueHtml, 'name="queue" value="overdue"' ), 'Employee selector preserves queue filter' );
verify( str_contains( $queueHtml, 'queue=overdue' ), 'Status and pagination links preserve queue filter' );
verify( str_contains( $queueHtml, 'حذف فیلتر و نمایش همه کارهای تیم' ), 'Clear queue option exists' );
verify( str_contains( $queueHtml, 'حذف فیلتر کارمند' ), 'Clear assignee keeps separate queue filter' );
$notTeam = render( false, 'open', 0, false, false, 'overdue' );
verify( $list_queue === '' && $count_queue === '', 'Unprivileged user cannot apply manager queue filter' );
verify( ! str_contains( $notTeam, 'crmb-control-active-filter' ), 'No manager-only filter for employee' );
echo "$checks task view checks passed\n";
