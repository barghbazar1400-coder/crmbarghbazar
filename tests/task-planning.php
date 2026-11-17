<?php
/** SQLite behavioral tests for subtasks, checklist, templates and permissions. */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function get_users( $args = array() ) {
    return array(
        (object) array( 'ID'=>7, 'display_name'=>'کارمند اصلی' ),
        (object) array( 'ID'=>8, 'display_name'=>'کارمند زیرتسک' ),
        (object) array( 'ID'=>9, 'display_name'=>'مدیر' ),
    );
}
$wpdb->db->exec("CREATE TABLE wp_crmb_task_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,kind TEXT,title TEXT,
    assigned_to INTEGER,due_day TEXT,is_done INTEGER DEFAULT 0,
    completed_by INTEGER DEFAULT 0,completed_utc TEXT,
    created_by INTEGER,created_utc TEXT,updated_utc TEXT,deleted_utc TEXT
);
CREATE TABLE wp_crmb_task_plan_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,items_json TEXT,created_by INTEGER,created_utc TEXT
);");
require __DIR__ . '/../inc/task-planning.php';
$now = gmdate( 'Y-m-d H:i:s' );
function plan_task( $owner, $title = 'کار مادر', $status = 'todo' ) {
    global $wpdb, $now;
    $wpdb->insert( 'wp_crmb_tasks', array(
        'title'=>$title, 'description'=>'توضیح خصوصی کار', 'assigned_to'=>$owner,
        'created_by'=>9, 'priority'=>'normal', 'status'=>$status,
        'created_utc'=>$now, 'updated_utc'=>$now
    ) );
    return (int) $wpdb->insert_id;
}
function plan_action( $task_id, $action, $fields = array() ) {
    return crmb_task_plan_mutate( array_merge(
        array( 'id'=>$task_id, 'plan_action'=>$action ), $fields
    ) );
}
$parent = plan_task( 7 );
$other = plan_task( 8, 'کار دیگری' );
$uid=9; $admin=true;
check( true, crmb_task_plan_ready() );
check( array( 'total'=>0, 'done'=>0, 'percent'=>0 ),
    crmb_task_plan_summary( crmb_task_plan_steps( $parent ) ) );
check( 'invalid', plan_action( $parent, 'add', array(
    'step_kind'=>'unknown', 'step_title'=>'test' ) ) );
check( 'invalid', plan_action( $parent, 'add', array(
    'step_kind'=>'subtask', 'step_title'=>'کار نامعتبر', 'assigned_to'=>500 ) ) );
check( 'saved', plan_action( $parent, 'add', array(
    'step_kind'=>'check', 'step_title'=>'بازبینی تصاویر' ) ) );
$first = (int) $wpdb->get_var( "SELECT id FROM wp_crmb_task_steps WHERE kind='check'" );
check( 'saved', plan_action( $parent, 'add', array(
    'step_kind'=>'subtask', 'step_title'=>'بررسی فنی محصول',
    'assigned_to'=>8, 'due_day'=>'2026-10-30'
) ) );
$second = (int) $wpdb->get_var( "SELECT id FROM wp_crmb_task_steps WHERE kind='subtask'" );
check( 7, (int) $wpdb->get_var( "SELECT assigned_to FROM wp_crmb_task_steps WHERE id={$first}" ) );
check( 8, (int) $wpdb->get_var( "SELECT assigned_to FROM wp_crmb_task_steps WHERE id={$second}" ) );
check( 2, crmb_task_plan_summary( crmb_task_plan_steps( $parent ) )['total'] );
check( 0, crmb_task_plan_summary( crmb_task_plan_steps( $parent ) )['percent'] );

// An independent subtask worker can work on the child but cannot read
// parent progress notes, bypass parent task review or tick the owner's item.
$admin=false; $uid=8;
check( null, crmb_progress_task( $parent ) );
check( null, crmb_task_plan_steps( $parent ) );
check( $second, (int) crmb_task_plan_subtask( $second )->id );
check( null, crmb_task_plan_subtask( $first ) );
check( 1, count( crmb_task_plan_my_subtasks() ) );
check( 'denied', plan_action( $parent, 'toggle', array( 'step_id'=>$first, 'is_done'=>'1' ) ) );
check( 'saved', plan_action( $parent, 'toggle', array( 'step_id'=>$second, 'is_done'=>'1' ) ) );
check( 'changed', plan_action( $parent, 'toggle', array( 'step_id'=>$second, 'is_done'=>'1' ) ) );
check( 0, count( crmb_task_plan_my_subtasks() ) );
check( 8, (int) $wpdb->get_var( "SELECT actor_id FROM wp_crmb_task_events
    WHERE task_id={$parent} AND event_type='step_done' ORDER BY id DESC LIMIT 1" ) );
check( 8, (int) $wpdb->get_var( "SELECT completed_by FROM wp_crmb_task_steps WHERE id={$second}" ) );
check( 'denied', plan_action( $parent, 'save_template', array( 'template_name'=>'بدون دسترسی' ) ) );
check( 'denied', plan_action( $parent, 'remove', array( 'step_id'=>$first ) ) );
check( 'denied', plan_action( $other, 'add', array( 'step_kind'=>'check', 'step_title'=>'wrong' ) ) );

// Parent owner can only check their own checklist.
$uid=7;
check( null, crmb_task_plan_subtask( $second ) );
check( 'denied', plan_action( $parent, 'toggle', array( 'step_id'=>$second, 'is_done'=>'0' ) ) );
check( 'saved', plan_action( $parent, 'toggle', array( 'step_id'=>$first, 'is_done'=>'1' ) ) );
$summary = crmb_task_plan_summary( crmb_task_plan_steps( $parent ) );
check( 2, $summary['done'] );
check( 100, $summary['percent'] );
// Event failure rolls back the step change.
$wpdb->fail_table='wp_crmb_task_events';
check( 'error', plan_action( $parent, 'toggle', array( 'step_id'=>$first, 'is_done'=>'0' ) ) );
$wpdb->fail_table='';
check( 1, (int) $wpdb->get_var( "SELECT is_done FROM wp_crmb_task_steps WHERE id={$first}" ) );
check( 'saved', plan_action( $parent, 'toggle', array( 'step_id'=>$first, 'is_done'=>'0' ) ) );
check( 50, crmb_task_plan_summary( crmb_task_plan_steps( $parent ) )['percent'] );

// Cannot mutate any step while parent is awaiting manager approval.
$admin=true; $uid=9;
$wpdb->update( 'wp_crmb_tasks', array( 'status'=>'review' ), array( 'id'=>$parent ) );
check( 'denied', plan_action( $parent, 'remove', array( 'step_id'=>$first ) ) );
$admin=false; $uid=7;
check( 'denied', plan_action( $parent, 'toggle', array( 'step_id'=>$first, 'is_done'=>'1' ) ) );
$admin=true; $uid=9;
$wpdb->update( 'wp_crmb_tasks', array( 'status'=>'todo' ), array( 'id'=>$parent ) );

// Save a reusable checklist/subtask template; applying it to another task
// gives that task's owner the actions, not the former subtask worker.
check( 'saved', plan_action( $parent, 'save_template', array(
    'template_name'=>'ثبت محصول جدید'
) ) );
$template = (int) $wpdb->get_var( 'SELECT id FROM wp_crmb_task_plan_templates ORDER BY id DESC LIMIT 1' );
check( 2, count( json_decode( $wpdb->get_var(
    "SELECT items_json FROM wp_crmb_task_plan_templates WHERE id={$template}"
), true ) ) );
check( 1, count( crmb_task_plan_templates() ) );
check( 'saved', plan_action( $other, 'apply_template', array( 'template_id'=>$template ) ) );
check( 2, crmb_task_plan_summary( crmb_task_plan_steps( $other ) )['total'] );
check( 0, crmb_task_plan_summary( crmb_task_plan_steps( $other ) )['percent'] );
check( 8, (int) $wpdb->get_var( "SELECT assigned_to FROM wp_crmb_task_steps
    WHERE task_id={$other} ORDER BY id DESC LIMIT 1" ) );
check( 'changed', plan_action( $other, 'apply_template', array( 'template_id'=>$template ) ) );
check( 'invalid', plan_action( $other, 'apply_template', array( 'template_id'=>9876 ) ) );

// Deletion archives the step and leaves an auditable history.
check( 'saved', plan_action( $parent, 'remove', array( 'step_id'=>$first ) ) );
check( 1, crmb_task_plan_summary( crmb_task_plan_steps( $parent ) )['total'] );
check( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_steps WHERE task_id={$parent}" ) );
check( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_crmb_task_events
    WHERE task_id={$parent} AND event_type='step_removed'" ) );
check( 'invalid', plan_action( $parent, 'remove', array( 'step_id'=>$first ) ) );
$admin=false; $uid=8;
check( null, crmb_task_plan_subtask( $first ) );
$uid=7; $logged=false;
check( 'denied', plan_action( $parent, 'toggle', array( 'step_id'=>$second, 'is_done'=>'0' ) ) );
$logged=true; $ready=false;
check( 'error', plan_action( $parent, 'toggle', array( 'step_id'=>$second, 'is_done'=>'0' ) ) );
$ready=true;

// Template contains real, escaped data and cannot expose another employee's parent.
// Presentation stubs are defined in tests/progress.php.
$uid=9; $admin=true;
$task = crmb_progress_task( $parent );
$id = $parent; $owner = false; $is_open = true;
ob_start();
include __DIR__ . '/../template-parts/task-plan.php';
$markup = ob_get_clean();
check( true, str_contains( $markup, 'چک‌لیست و زیرتسک‌ها' ) );
check( true, str_contains( $markup, 'name="plan_action" value="add"' ) );
check( true, str_contains( $markup, 'name="plan_action" value="save_template"' ) );
check( true, str_contains( $markup, 'مسئول: Employee' ) );
check( false, str_contains( $markup, 'name="is_done" value="0"' ) ); // Manager cannot falsely tick assigned employee items.
$uid=8; $admin=false;
$step = crmb_task_plan_subtask( $second );
$_GET = array( 'subtask_id'=>$second );
ob_start();
include __DIR__ . '/../template-parts/task-plan-child.php';
$child_html = ob_get_clean();
check( true, str_contains( $child_html, 'بررسی فنی محصول' ) );
check( false, str_contains( $child_html, 'توضیح خصوصی کار' ) );
check( true, str_contains( $child_html, 'name="return_step" value="1"' ) );
echo "$checks task planning, independent ownership, rollback, template and rendering checks passed\n";
