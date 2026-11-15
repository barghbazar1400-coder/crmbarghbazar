<?php
/** Standalone render regression for compact CEO/COO priorities.
 * Execute from repository root: php tests/leadership-view.php
 */
define( 'ABSPATH', __DIR__ );
$admin = false;
$allowed = true;
$checks = 0;
$crm_current_section = 'operations';
function current_user_can( $cap ) { global $admin; return $cap === 'manage_options' && $admin; }
function crmb_leadership_can_operate() { global $allowed; return $allowed; }
function crmbarghbazar_can_view_section( $section ) { return in_array( $section, array( 'operations', 'tasks' ), true ); }
function home_url( $path ) { return $path; }
function add_query_arg( $key, $value, $url = '' ) {
    if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
    return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function crmb_att_today() { return '2026-11-15'; }
function crmb_att_jdate( $day ) { return '1405/08/24'; }
function crmb_work_user_name( $id ) { return 'همکار آزمایشی'; }
function crmb_work_statuses() { return array( 'todo' => 'انجام‌نشده', 'doing' => 'در حال انجام', 'paused' => 'توقف موقت' ); }
$stats = array_fill_keys( array(
    'tasks_open','tasks_overdue','tasks_done','timers_active','reports_pending',
    'leaves_pending','sales_followups','seo_projects','seo_review','recent',
), null );
$stats['recent'] = array( (object) array(
    'id' => 51, 'title' => 'بازبینی <script>escape</script>',
    'assigned_to' => 7, 'status' => 'paused', 'due_day' => '2026-11-14',
) );
function crmb_leadership_data() { global $stats; return $stats; }
function verify_view( $ok, $name ) {
    global $checks;
    ++$checks;
    if ( ! $ok ) { throw new RuntimeException( 'Check ' . $checks . ': ' . $name ); }
}
function render_leadership() { ob_start(); include __DIR__ . '/../template-parts/leadership.php'; return ob_get_clean(); }
$html = render_leadership();
verify_view( str_contains( $html, 'crmb-leadership-priorities' ), 'Independent priorities section' );
verify_view( str_contains( $html, 'crmb-priorities-list' ), 'Compact list exists' );
verify_view( substr_count( $html, 'class="crmb-priority-item"' ) === 1, 'One row per task' );
verify_view( str_contains( $html, 'عقب‌افتاده' ), 'Overdue label' );
verify_view( str_contains( $html, 'توقف موقت' ), 'Pause status' );
verify_view( str_contains( $html, 'همکار آزمایشی' ), 'Assignee name' );
verify_view( str_contains( $html, '&lt;script&gt;' ), 'Task title escaped' );
verify_view( ! str_contains( $html, '<script>' ), 'No raw script' );
verify_view( ! str_contains( $html, 'واگذاری یا پیگیری وظیفه' ), 'Old oversized button removed' );
$stats['recent'] = array();
$html = render_leadership();
verify_view( str_contains( $html, 'وظیفه بازی برای پیگیری وجود ندارد' ), 'Empty state' );
verify_view( ! str_contains( $html, 'crmb-priority-item' ), 'No fake task rows' );
$stats['recent'] = null;
$html = render_leadership();
verify_view( str_contains( $html, 'داده‌های وظایف در دسترس نیست' ), 'Unavailable state' );
$allowed = false;
verify_view( '' === render_leadership(), 'COO access protected' );
echo "$checks leadership view checks passed\n";
