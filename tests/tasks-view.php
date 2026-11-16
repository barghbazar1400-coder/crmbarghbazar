<?php
/** Render regressions for the default open tasks, completed archive and team access. */
define( 'ABSPATH', __DIR__ );
$as_manager = false; $checks = 0;
function is_user_logged_in() { return true; }
function crmb_work_manage_team() { global $as_manager; return $as_manager; }
function crmb_work_task_counts( $team ) {
    return array( 'ready' => true, 'all' => 2, 'open' => 1, 'done' => 1 );
}
function crmb_work_list( $kind, $page, $limit, $team, $sent = false, $active = false, $focus = 0, $view = 'all' ) {
    if ( $kind !== 'tasks' ) { throw new RuntimeException( 'Unexpected list' ); }
    $rows = array(
        (object) array( 'id' => 42, 'title' => 'مانیتورهای سوزوکی',
            'description' => 'شرح محرمانه <script>alert(1)</script>',
            'priority' => 'high', 'status' => 'todo', 'due_day' => '2026-11-11',
            'assigned_to' => 3, 'created_by' => 1, 'timer_started_utc' => null ),
        (object) array( 'id' => 43, 'title' => 'تکمیل گزارش',
            'description' => 'گزارش آماده شد',
            'priority' => 'normal', 'status' => 'done', 'due_day' => null,
            'assigned_to' => 3, 'created_by' => 1, 'timer_started_utc' => null ),
    );
    $rows = array_values( array_filter( $rows, static function ( $r ) use ( $view ) {
        return 'all' === $view || ( 'open' === $view && in_array( $r->status, array( 'todo', 'doing', 'paused' ), true ) )
            || ( 'done' === $view && 'done' === $r->status );
    } ) );
    return array( 'ready' => true, 'total' => count( $rows ), 'rows' => $rows );
}
function crmb_work_statuses() { return array( 'todo' => 'انجام‌نشده', 'doing' => 'در حال انجام', 'paused' => 'توقف موقت', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده' ); }
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
function get_users( $args ) { return array( (object) array( 'ID' => 3, 'display_name' => 'کارمند' ) ); }
function crmb_work_form_fields( $name, $id = 0 ) {
    echo '<input type="hidden" name="task" value="' . esc_attr( $name ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
}
function crmb_work_notice() {}
function get_template_part( $name ) { throw new RuntimeException( 'Unexpected include ' . $name ); }
function verify( $condition, $name ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) { throw new RuntimeException( 'Task template assertion ' . $checks . ': ' . $name ); }
}
function render( $team = false, $view = null ) {
    global $as_manager;
    $as_manager = $team;
    $_GET = $team ? array( 'scope' => 'team' ) : array();
    if ( null !== $view ) { $_GET['view'] = $view; }
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
verify( str_contains( $manager, '<details class="crm-access-card crmb-tasks-editor"' ), 'Manager assignment form' );
verify( str_contains( $manager, 'کارهای تیم' ), 'Team navigation' );
verify( str_contains( $manager, 'value="cancelled"' ), 'Manager status rights' );
verify( str_contains( $manager, 'مسئول:' ), 'Manager sees assignee' );
verify( str_contains( $manager, 'name="repeat"' ), 'Manager sees repeat selector' );
verify( str_contains( $manager, '>هر روز</option>' ), 'Daily repeat choice' );
verify( str_contains( $manager, 'value="delete_task"' ), 'Manager sees delete action' );
verify( str_contains( $manager, 'حذف وظیفه' ), 'Manager sees clear delete button' );
verify( str_contains( $manager, 'ویرایش وظیفه' ), 'Manager sees task edit button' );
echo "$checks task view checks passed\n";
