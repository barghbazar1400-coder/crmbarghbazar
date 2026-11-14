<?php
/** Standalone rendering checks for the dashboard daily summary. */
define( 'ABSPATH', __DIR__ );
$logged = true;
$manager = false;
$checks = 0;
function is_user_logged_in() { global $logged; return $logged; }
function crmb_progress_ready() { return true; }
function crmb_progress_tables() { return array( 'tasks' => 'wp_tasks', 'reports' => 'wp_reports', 'events' => 'wp_events' ); }
function current_user_can( $cap ) { global $manager; return 'manage_options' === $cap && $manager; }
function get_current_user_id() { return 3; }
function crmb_att_today() { return '2026-11-14'; }
function crmb_work_date( $date ) { return $date; }
function crmb_work_user_name( $id ) { return 'همکار نمونه'; }
function crmb_progress_event_labels() { return array( 'done' => 'پایان کار', 'note' => 'گزارش پیشرفت' ); }
function wp_trim_words( $text, $count ) { return implode( ' ', array_slice( explode( ' ', $text ), 0, $count ) ); }
function home_url( $path ) { return $path; }
function add_query_arg( $key, $value, $url = '' ) {
    if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
    return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
class DailySummaryFakeDB {
    public $last_error = '';
    public $queries = array();
    function prepare( $query, ...$args ) { return str_replace( array( '%d', '%s' ), array( (string) $args[0], (string) ( $args[1] ?? '' ) ), $query ); }
    function get_row( $query ) {
        $this->queries[] = $query;
        if ( str_contains( $query, 'timer_started_utc IS NOT NULL' ) ) {
            return (object) array( 'id' => 5, 'title' => 'بررسی محصولات', 'timer_started_utc' => '2026-11-14 09:30:00' );
        }
        return (object) array( 'status' => 'draft' );
    }
    function get_var( $query ) { $this->queries[] = $query; return 2; }
    function get_results( $query ) {
        $this->queries[] = $query;
        return array(
            (object) array( 'id' => 1, 'task_id' => 5, 'title' => 'بررسی محصولات',
                'actor_id' => 3, 'event_type' => 'done', 'created_utc' => '2026-11-14 10:12:00',
                'body' => 'کار آماده شد <script>alert(1)</script>' ),
            (object) array( 'id' => 2, 'task_id' => 5, 'title' => 'بررسی محصولات',
                'actor_id' => 3, 'event_type' => 'note', 'created_utc' => '2026-11-14 09:54:00',
                'body' => 'گزارش پیشرفت ثبت شد' ),
        );
    }
}
$wpdb = new DailySummaryFakeDB();
function check_summary( $ok, $label ) {
    global $checks; ++$checks;
    if ( ! $ok ) { throw new RuntimeException( "Check $checks: $label" ); }
}
function render_daily_summary() {
    ob_start();
    include __DIR__ . '/../template-parts/progress-summary.php';
    return ob_get_clean();
}
$employee = render_daily_summary();
check_summary( str_contains( $employee, 'crmb-progress-summary-layout' ), 'Compact layout' );
check_summary( str_contains( $employee, 'crmb-progress-timer-card' ), 'Independent timer card' );
check_summary( str_contains( $employee, 'crmb-progress-report-card' ), 'Independent report card' );
check_summary( ! str_contains( $employee, 'crmb-progress-team-activity' ), 'No manager card for employee' );
check_summary( ! str_contains( $employee, 'crm-access-card crmb-progress-summary' ), 'No outer enclosing card' );
check_summary( str_contains( $employee, 'بررسی محصولات' ), 'Own active task' );
check_summary( str_contains( $employee, 'پیش‌نویس' ), 'Own report status' );
check_summary( ! str_contains( $employee, 'آخرین فعالیت‌های تیم' ), 'No team activity for employee' );
check_summary( count( $wpdb->queries ) === 2, 'Employee queries only own task and report' );
$manager = true;
$wpdb->queries = array();
$admin_html = render_daily_summary();
check_summary( str_contains( $admin_html, 'آخرین فعالیت‌های تیم' ), 'Manager activity list' );
check_summary( str_contains( $admin_html, 'crm-access-card crmb-progress-team-activity' ), 'Independent team activity card' );
check_summary( substr_count( $admin_html, 'crm-access-card crmb-progress-' ) === 3, 'Three sibling cards' );
check_summary( str_contains( $admin_html, '2 گزارش منتظر بررسی' ), 'Pending approvals count' );
check_summary( substr_count( $admin_html, 'crmb-progress-activity-item' ) === 2, 'Recent events' );
check_summary( str_contains( $admin_html, '&lt;script&gt;' ), 'Event text escaped' );
check_summary( ! str_contains( $admin_html, '<script>' ), 'No raw event HTML' );
check_summary( count( $wpdb->queries ) === 4, 'Manager obtains team queries' );
$logged = false;
check_summary( render_daily_summary() === '', 'Guest cannot view dashboard' );
echo "$checks daily summary checks passed\n";
