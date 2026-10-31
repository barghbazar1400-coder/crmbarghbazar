<?php
/** Independent authorization and real-data query checks: php tests/leadership.php */
define( 'ABSPATH', __DIR__ );
$hooks = array(); $logged = true; $admin = false; $coo = false; $checks = 0;
function add_action( $name, $callback ) {}
function add_filter( $name, $callback ) { global $hooks; $hooks[ $name ][] = $callback; }
function apply_filters( $name, $value ) { global $hooks; foreach ( $hooks[ $name ] ?? array() as $callback ) { $value = $callback( $value ); } return $value; }
function is_user_logged_in() { global $logged; return $logged; }
function current_user_can( $cap ) { global $admin, $coo; return $cap === 'manage_options' ? $admin : ( $cap === 'crm_menu_operations' && $coo ); }
function get_current_user_id() { return 2; }
function metadata_exists( $type, $id, $key ) { return false; }
function get_option( $key, $default = false ) { return in_array( $key, array( 'crmb_work_schema_v1', 'crmb_leave_schema_v1' ), true ) ? '1' : $default; }
function crmb_work_tables() { return array( 'tasks' => 'wp_tasks' ); }
function crmb_progress_tables() { return array( 'tasks' => 'wp_tasks', 'reports' => 'wp_reports' ); }
function crmb_progress_ready() { return true; }
function crmb_leave_table() { return 'wp_leaves'; }
function crmb_att_today() { return '2026-10-30'; }
function crmb_seo_access() { return false; }
function crmb_seo_ready() { return false; }

class LeadershipDB {
    public $last_error = '';
    public $queries = array();
    public $error = false;
    function prepare( $sql, $day ) { return str_replace( '%s', "'$day'", $sql ); }
    function get_var( $sql ) {
        $this->queries[] = $sql;
        $this->last_error = $this->error ? 'database error' : '';
        return $this->error ? null : 2;
    }
    function get_results( $sql ) {
        $this->queries[] = $sql;
        $this->last_error = $this->error ? 'database error' : '';
        return $this->error ? null : array( (object) array( 'id' => 1, 'title' => 'Task', 'assigned_to' => 2, 'status' => 'todo', 'due_day' => '2026-10-31' ) );
    }
}
$wpdb = new LeadershipDB();
require __DIR__ . '/../inc/access-management.php';
require __DIR__ . '/../inc/leadership.php';
function expect( $expected, $actual ) { global $checks; if ( $expected !== $actual ) { throw new RuntimeException( 'Check ' . ( $checks + 1 ) . ' failed: ' . var_export( $actual, true ) ); } ++$checks; }

expect( false, crmbarghbazar_can_view_section( 'ceo' ) );
expect( false, crmbarghbazar_can_view_section( 'operations' ) );
expect( true, isset( crmbarghbazar_assignable_sections()['operations'] ) );
expect( false, isset( crmbarghbazar_assignable_sections()['ceo'] ) );
$empty = crmb_leadership_data();
expect( null, $empty['tasks_open'] );
expect( 0, count( $wpdb->queries ) );
$coo = true;
expect( true, crmb_leadership_can_manage_tasks() );
expect( false, crmbarghbazar_can_view_section( 'ceo' ) );
expect( true, crmbarghbazar_can_view_section( 'operations' ) );
$results = crmb_leadership_data();
expect( 2, $results['tasks_open'] );
expect( 2, $results['tasks_overdue'] );
expect( 2, $results['leaves_pending'] );
expect( 1, count( $results['recent'] ) );
expect( null, $results['seo_review'] );
$wpdb->error = true;
$results = crmb_leadership_data();
expect( null, $results['tasks_open'] );
expect( null, $results['recent'] );
$wpdb->error = false; $coo = false; $admin = true;
expect( true, crmbarghbazar_can_view_section( 'ceo' ) );
expect( true, crmb_leadership_can_manage_tasks() );
$logged = false;
expect( false, crmb_leadership_can_manage_tasks() );
expect( null, crmb_leadership_data()['tasks_open'] );
echo "$checks leadership checks passed\n";
