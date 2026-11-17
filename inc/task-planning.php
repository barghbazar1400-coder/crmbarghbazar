<?php
/**
 * Task planning: independently assigned subtasks, employee checklists and
 * reusable manager-owned workflow templates. Additive schema, no task rewrite.
 */
defined( 'ABSPATH' ) || exit;

function crmb_task_plan_tables() {
    global $wpdb;
    return array(
        'steps' => $wpdb->prefix . 'crmb_task_steps',
        'templates' => $wpdb->prefix . 'crmb_task_plan_templates',
    );
}
function crmb_task_plan_ready() {
    return '1' === get_option( 'crmb_task_plan_schema_v1' )
        && function_exists( 'crmb_progress_ready' ) && crmb_progress_ready();
}
function crmb_task_plan_install() {
    if ( crmb_task_plan_ready() || ! crmb_progress_ready() ) { return; }
    global $wpdb;
    $t = crmb_task_plan_tables();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$t['steps']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        task_id bigint(20) unsigned NOT NULL,
        kind varchar(12) NOT NULL,
        title varchar(180) NOT NULL,
        assigned_to bigint(20) unsigned NOT NULL,
        due_day date DEFAULT NULL,
        is_done tinyint(1) NOT NULL DEFAULT 0,
        completed_by bigint(20) unsigned NOT NULL DEFAULT 0,
        completed_utc datetime DEFAULT NULL,
        created_by bigint(20) unsigned NOT NULL,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        deleted_utc datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY task_steps (task_id,deleted_utc,id),
        KEY assignee_steps (assigned_to,is_done,id)
    ) ENGINE=InnoDB {$charset};" );
    dbDelta( "CREATE TABLE {$t['templates']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(140) NOT NULL,
        items_json longtext NOT NULL,
        created_by bigint(20) unsigned NOT NULL,
        created_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY created_by (created_by,id)
    ) ENGINE=InnoDB {$charset};" );
    foreach ( $t as $name ) {
        if ( $name !== $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s', $wpdb->esc_like( $name )
        ) ) ) { return; }
    }
    update_option( 'crmb_task_plan_schema_v1', '1', false );
}
add_action( 'init', 'crmb_task_plan_install', 19 );

function crmb_task_plan_steps( $task_id ) {
    if ( ! crmb_task_plan_ready() || ! crmb_progress_task( $task_id ) ) { return null; }
    global $wpdb;
    $t = crmb_task_plan_tables()['steps'];
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t} WHERE task_id=%d AND deleted_utc IS NULL ORDER BY id ASC",
        $task_id
    ) );
    return $wpdb->last_error ? null : $rows;
}
function crmb_task_plan_summary( $steps ) {
    if ( ! is_array( $steps ) ) { return null; }
    $total = count( $steps );
    $done = count( array_filter( $steps, static fn( $item ) => (int) $item->is_done === 1 ) );
    return array( 'total' => $total, 'done' => $done,
        'percent' => $total ? (int) round( $done * 100 / $total ) : 0 );
}
/** Subtask assignees can see their own assignment WITHOUT gaining parent history access. */
function crmb_task_plan_subtask( $step_id ) {
    if ( ! is_user_logged_in() || ! crmb_task_plan_ready() || ! $step_id ) { return null; }
    global $wpdb;
    $steps = crmb_task_plan_tables()['steps'];
    $tasks = crmb_work_tables()['tasks'];
    $where_deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
        ? ' AND t.deleted_utc IS NULL' : '';
    $step = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.*,t.title AS parent_title,t.status AS parent_status,t.assigned_to AS parent_owner
           FROM {$steps} s JOIN {$tasks} t ON t.id=s.task_id
          WHERE s.id=%d AND s.kind='subtask' AND s.deleted_utc IS NULL{$where_deleted}",
        $step_id
    ) );
    if ( ! $step || ( ! crmb_work_manage_team() && (int) $step->assigned_to !== get_current_user_id() ) ) {
        return null;
    }
    return $step;
}
function crmb_task_plan_my_subtasks( $limit = 20 ) {
    if ( ! is_user_logged_in() || ! crmb_task_plan_ready() ) { return null; }
    global $wpdb;
    $steps = crmb_task_plan_tables()['steps'];
    $tasks = crmb_work_tables()['tasks'];
    $where_deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
        ? ' AND t.deleted_utc IS NULL' : '';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.*,t.title AS parent_title FROM {$steps} s
          JOIN {$tasks} t ON t.id=s.task_id
          WHERE s.assigned_to=%d AND s.kind='subtask' AND s.is_done=0
            AND s.deleted_utc IS NULL AND t.status IN ('todo','doing','paused')
            {$where_deleted}
          ORDER BY s.due_day IS NULL,s.due_day ASC,s.id ASC LIMIT %d",
        get_current_user_id(), max( 1, min( 50, (int) $limit ) )
    ) );
    return $wpdb->last_error ? null : $rows;
}
function crmb_task_plan_templates() {
    if ( ! crmb_work_manage_team() || ! crmb_task_plan_ready() ) { return array(); }
    global $wpdb;
    $t = crmb_task_plan_tables()['templates'];
    $rows = $wpdb->get_results( "SELECT id,name FROM {$t} ORDER BY id DESC LIMIT 80" );
    return $rows ?: array();
}
function crmb_task_plan_fail( $code = 'error' ) {
    global $wpdb;
    $wpdb->query( 'ROLLBACK' );
    return $code;
}
function crmb_task_plan_mutate( $data ) {
    if ( ! is_user_logged_in() ) { return 'denied'; }
    if ( ! crmb_task_plan_ready() ) { return 'error'; }
    $action = isset( $data['plan_action'] ) && is_string( $data['plan_action'] )
        ? sanitize_key( $data['plan_action'] ) : '';
    $task_id = isset( $data['id'] ) && is_scalar( $data['id'] ) ? absint( $data['id'] ) : 0;
    $step_id = isset( $data['step_id'] ) && is_scalar( $data['step_id'] ) ? absint( $data['step_id'] ) : 0;
    if ( ! $task_id || ! in_array( $action,
        array( 'add', 'toggle', 'remove', 'save_template', 'apply_template' ), true ) ) { return 'invalid'; }
    global $wpdb;
    $t = crmb_task_plan_tables();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }

    // The parent is looked up directly so a different subtask assignee can
    // update only that subtask, without gaining permission to view/edit the task.
    $tasks = crmb_work_tables()['tasks'];
    $task_deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
        ? ' AND deleted_utc IS NULL' : '';
    $parent = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$tasks} WHERE id=%d{$task_deleted} FOR UPDATE", $task_id
    ) );
    if ( ! $parent ) { return crmb_task_plan_fail( $wpdb->last_error ? 'error' : 'invalid' ); }
    $manager = crmb_work_manage_team();
    $open = in_array( $parent->status, array( 'todo', 'doing', 'paused' ), true );
    $now = gmdate( 'Y-m-d H:i:s' );
    $uid = get_current_user_id();

    if ( 'toggle' === $action ) {
        if ( ! $step_id ) { return crmb_task_plan_fail( 'invalid' ); }
        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['steps']} WHERE id=%d AND task_id=%d
                AND deleted_utc IS NULL FOR UPDATE", $step_id, $task_id
        ) );
        if ( ! $step || ! $open || (int) $step->assigned_to !== $uid ) {
            return crmb_task_plan_fail( 'denied' );
        }
        $done = isset( $data['is_done'] ) && '1' === (string) $data['is_done'] ? 1 : 0;
        if ( (int) $step->is_done === $done ) { return crmb_task_plan_fail( 'changed' ); }
        $changed = $wpdb->update( $t['steps'], array(
            'is_done' => $done, 'completed_by' => $done ? $uid : 0,
            'completed_utc' => $done ? $now : null, 'updated_utc' => $now,
        ), array( 'id' => $step_id, 'task_id' => $task_id, 'is_done' => (int) $step->is_done ) );
        if ( 1 !== $changed || ! crmb_progress_event(
            $task_id, $done ? 'step_done' : 'step_reopened', $step->title
        ) ) { return crmb_task_plan_fail(); }
    } else {
        if ( ! $manager || ! $open ) { return crmb_task_plan_fail( 'denied' ); }
        if ( 'add' === $action ) {
            $kind = isset( $data['step_kind'] ) && is_string( $data['step_kind'] )
                ? sanitize_key( $data['step_kind'] ) : '';
            $title = isset( $data['step_title'] ) && is_string( $data['step_title'] )
                ? trim( sanitize_text_field( $data['step_title'] ) ) : '';
            $assigned = 'check' === $kind ? (int) $parent->assigned_to
                : ( isset( $data['assigned_to'] ) && is_scalar( $data['assigned_to'] )
                    ? absint( $data['assigned_to'] ) : 0 );
            $raw_due = isset( $data['due_day'] ) && is_string( $data['due_day'] )
                ? trim( $data['due_day'] ) : '';
            $due = '' === $raw_due ? null : crmb_leave_parse_day( $raw_due );
            if ( ! in_array( $kind, array( 'check', 'subtask' ), true )
                || '' === $title || strlen( $title ) > 180 || false === $due
                || ! $assigned || ! get_userdata( $assigned ) ) {
                return crmb_task_plan_fail( 'invalid' );
            }
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['steps']} WHERE task_id=%d AND deleted_utc IS NULL",
                $task_id
            ) );
            if ( $wpdb->last_error || (int) $count >= 100 ) { return crmb_task_plan_fail( 'invalid' ); }
            if ( false === $wpdb->insert( $t['steps'], array(
                'task_id' => $task_id, 'kind' => $kind, 'title' => $title,
                'assigned_to' => $assigned, 'due_day' => $due, 'is_done' => 0,
                'completed_by' => 0, 'completed_utc' => null,
                'created_by' => $uid, 'created_utc' => $now, 'updated_utc' => $now,
            ) ) || ! crmb_progress_event( $task_id, 'step_added', $title ) ) {
                return crmb_task_plan_fail();
            }
        } elseif ( 'remove' === $action ) {
            if ( ! $step_id ) { return crmb_task_plan_fail( 'invalid' ); }
            $step = $wpdb->get_row( $wpdb->prepare(
                "SELECT id,title FROM {$t['steps']} WHERE id=%d AND task_id=%d
                   AND deleted_utc IS NULL FOR UPDATE", $step_id, $task_id
            ) );
            if ( ! $step ) { return crmb_task_plan_fail( 'invalid' ); }
            if ( false === $wpdb->update( $t['steps'], array(
                'deleted_utc' => $now, 'updated_utc' => $now,
            ), array( 'id' => $step_id, 'task_id' => $task_id ) )
                || ! crmb_progress_event( $task_id, 'step_removed', $step->title ) ) {
                return crmb_task_plan_fail();
            }
        } elseif ( 'save_template' === $action ) {
            $name = isset( $data['template_name'] ) && is_string( $data['template_name'] )
                ? trim( sanitize_text_field( $data['template_name'] ) ) : '';
            if ( '' === $name || strlen( $name ) > 140 ) {
                return crmb_task_plan_fail( 'invalid' );
            }
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT kind,title FROM {$t['steps']} WHERE task_id=%d
                  AND deleted_utc IS NULL ORDER BY id ASC LIMIT 51", $task_id
            ) );
            if ( $wpdb->last_error || ! $rows || count( $rows ) > 50 ) {
                return crmb_task_plan_fail( 'invalid' );
            }
            $items = array();
            foreach ( $rows as $row ) {
                $items[] = array( 'kind' => $row->kind, 'title' => $row->title );
            }
            if ( false === $wpdb->insert( $t['templates'], array(
                'name' => $name, 'items_json' => wp_json_encode( $items ),
                'created_by' => $uid, 'created_utc' => $now,
            ) ) || ! crmb_progress_event( $task_id, 'template_saved', $name ) ) {
                return crmb_task_plan_fail();
            }
        } else {
            $template_id = isset( $data['template_id'] ) && is_scalar( $data['template_id'] )
                ? absint( $data['template_id'] ) : 0;
            $template = $wpdb->get_row( $wpdb->prepare(
                "SELECT items_json,name FROM {$t['templates']} WHERE id=%d", $template_id
            ) );
            if ( ! $template ) { return crmb_task_plan_fail( 'invalid' ); }
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['steps']} WHERE task_id=%d AND deleted_utc IS NULL", $task_id
            ) );
            if ( $wpdb->last_error || (int) $count > 0 ) {
                return crmb_task_plan_fail( 'changed' );
            }
            $items = json_decode( $template->items_json, true );
            if ( ! is_array( $items ) || ! $items || count( $items ) > 50 ) {
                return crmb_task_plan_fail( 'invalid' );
            }
            foreach ( $items as $item ) {
                if ( ! isset( $item['title'], $item['kind'] )
                    || ! is_string( $item['title'] ) || strlen( $item['title'] ) > 180
                    || ! in_array( $item['kind'], array( 'check', 'subtask' ), true ) ) {
                    return crmb_task_plan_fail( 'invalid' );
                }
                if ( false === $wpdb->insert( $t['steps'], array(
                    'task_id' => $task_id, 'kind' => $item['kind'],
                    'title' => $item['title'],
                    // A reusable template never leaks the original staff member.
                    'assigned_to' => (int) $parent->assigned_to, 'due_day' => null,
                    'is_done' => 0, 'completed_by' => 0, 'completed_utc' => null,
                    'created_by' => $uid, 'created_utc' => $now, 'updated_utc' => $now,
                ) ) ) { return crmb_task_plan_fail(); }
            }
            if ( ! crmb_progress_event( $task_id, 'template_applied', $template->name ) ) {
                return crmb_task_plan_fail();
            }
        }
    }
    if ( false === $wpdb->query( 'COMMIT' ) ) { return crmb_task_plan_fail(); }
    return 'saved';
}
