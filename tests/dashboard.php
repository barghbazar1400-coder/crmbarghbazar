<?php
/** Standalone personal-dashboard query and rendering checks. */
define('ABSPATH', __DIR__);
$logged = true; $admin = false; $ready = true; $count = 0;
function add_filter($key, $callback) { global $filter; $filter = $callback; }
function is_user_logged_in() { global $logged; return $logged; }
function current_user_can($cap) { global $admin; return $admin; }
function get_current_user_id() { return 7; }
function get_option($key) { global $ready; return $ready ? '1' : false; }
function crmb_att_tables() { return array('days' => 'wp_days'); }
function crmb_att_today() { return '2026-10-30'; }
function crmb_leave_table() { return 'wp_leave'; }
function wp_get_current_user() { return (object) array('display_name' => '<Employee>'); }
function home_url($s) { return $s; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function esc_url($s) { return htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars((string) $s); }
function crmb_att_jdate($s) { return $s; }
function crmb_att_utc_to_local($s, $seconds) { return '13:30:00'; }
function crmb_att_timezone() { return new DateTimeZone('Asia/Tehran'); }
function crmb_leave_status_label($s) { return $s; }
class DashboardDB {
    public $last_error = ''; public $queries = array(); public $fail = false;
    function prepare($sql, ...$args) {
        // Verify the identity is always supplied by the session, ignoring request parameters.
        if ($args[0] !== 7) { throw new RuntimeException('Wrong employee scope'); }
        return str_replace('%d', '7', $sql);
    }
    function track($sql) { $this->queries[] = $sql; $this->last_error = $this->fail ? 'unavailable' : ''; }
    function get_row($sql) { $this->track($sql); return null; }
    function get_results($sql) { $this->track($sql); return $this->fail ? null : array((object) array('id' => 1, 'leave_day' => '2026-10-30', 'time_from' => '09:00', 'time_to' => '10:00', 'status' => 'approved', 'created_utc' => '2026-10-29 08:00:00', 'reviewed_utc' => '2026-10-30 10:00:00')); }
    function get_var($sql) { $this->track($sql); return $this->fail ? null : 2; }
}
$wpdb = new DashboardDB();
require __DIR__ . '/../inc/dashboard.php';
function expect($expected, $actual) { global $count; if ($expected !== $actual) { throw new RuntimeException('Check ' . ($count + 1) . ' failed'); } $count++; }
$items = $filter(array('clock' => array()));
expect('dashboard', array_key_first($items));
expect(true, $items['dashboard']['always_visible']);
$_GET['employee'] = 99; $_GET['user_id'] = 99;
$d = crmb_dashboard_data();
expect(true, $d['attendance_ready']); expect(true, $d['leave_ready']); expect(2, $d['pending']); expect(null, $d['team_pending']);
expect(4, count($wpdb->queries));
foreach ($wpdb->queries as $sql) { expect(true, str_contains($sql, 'user_id = 7')); }
expect(true, str_contains($wpdb->queries[2], 'COALESCE(reviewed_utc, created_utc) DESC'));
expect(true, str_contains($wpdb->queries[2], 'LIMIT 5'));
ob_start(); include __DIR__ . '/../template-parts/dashboard.php'; $html = ob_get_clean();
expect(false, str_contains($html, '<Employee>')); expect(true, str_contains($html, '&lt;Employee&gt;'));
expect(false, str_contains($html, 'نیازمند رسیدگی مدیر')); expect(true, str_contains($html, '13:30:00'));
$admin = true; $d = crmb_dashboard_data(); expect(2, $d['team_pending']);
ob_start(); include __DIR__ . '/../template-parts/dashboard.php'; $html = ob_get_clean();
expect(true, str_contains($html, 'نیازمند رسیدگی مدیر'));
$admin = false; $wpdb->fail = true; $d = crmb_dashboard_data();
expect(false, $d['attendance_ready']); expect(false, $d['leave_ready']); expect(null, $d['pending']); expect(array(), $d['requests']);
$ready = false; $wpdb->queries = array(); $d = crmb_dashboard_data(); expect(0, count($wpdb->queries));
$logged = false; $ready = true; crmb_dashboard_data(); expect(0, count($wpdb->queries));
ob_start(); include __DIR__ . '/../template-parts/dashboard.php'; expect('', ob_get_clean());
echo "$count dashboard checks passed\n";
