<?php
/** Render-only regression for the employee and manager task cards.
 * Run with php tests/tasks-view.php (WordPress not required).
 */
define( 'ABSPATH', __DIR__ );
$as_manager = false;
$checks = 0;
function is_user_logged_in() { return true; }
function crmb_work_manage_team() { global $as_manager; return $as_manager; }
function crmb_work_list( $kind, $page, $limit, $team ) {
    if ( $kind !== 'tasks' ) { throw new RuntimeException( 'Unexpected list' ); }
    return array(
        'ready' => true, 'total' => 2, 'rows' => array(
            (object) array( 'id' => 42, 'title' => 'مانیتورهای سوزوکی',
                'description' => 'بررسی وضعیت قیمت و موجودی <script>alert(1)</script>',
                'priority' => 'high', 'status' => 'todo', 'due_day' => '2026-11-11',
                'assigned_to' => 3, 'created_by' => 1, 'timer_started_utc' => null ),
            (object) array( 'id' => 43, 'title' => 'تکمیل گزارش',
                'description' => 'گزارش آماده شد',
                'priority' => 'normal', 'status' => 'done', 'due_day' => null,
                'assigned_to' => 3, 'created_by' => 1, 'timer_started_utc' => null ),
        ),
    );
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
function render( $team = false ) {
    global $as_manager;
    $as_manager = $team;
    $_GET = $team ? array( 'scope' => 'team' ) : array();
    ob_start();
    include __DIR__ . '/../template-parts/tasks.php';
    return ob_get_clean();
}
$employee = render( false );
verify( str_contains( $employee, 'crmb-tasks-overview' ), 'Employee overview exists' );
verify( substr_count( $employee, '<article class="crmb-task-card' ) === 2, 'One card for each task' );
verify( str_contains( $employee, 'is-priority-high' ), 'Priority badge' );
verify( str_contains( $employee, 'مهلت گذشته' ), 'Overdue warning' );
verify( str_contains( $employee, 'مشاهده و ثبت پیشرفت' ), 'Progress action' );
verify( str_contains( $employee, '&lt;script&gt;' ), 'Description escaped' );
verify( ! str_contains( $employee, '<script>' ), 'Unsafe markup not rendered' );
verify( ! str_contains( $employee, 'value="cancelled"' ), 'Employee cannot cancel' );
verify( ! str_contains( $employee, 'واگذاری وظیفه جدید' ), 'No manager assignment form' );
$manager = render( true );
verify( str_contains( $manager, '<details class="crm-access-card crmb-tasks-editor"' ), 'Collapsed assignment form' );
verify( str_contains( $manager, 'همه وظایف تیم' ), 'Team scope' );
verify( str_contains( $manager, 'value="cancelled"' ), 'Manager status options' );
verify( str_contains( $manager, 'مسئول:' ), 'Manager sees assignee' );
echo "$checks task view checks passed\n";
