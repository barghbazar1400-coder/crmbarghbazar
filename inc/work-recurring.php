<?php
/**
 * Daily CRM tasks: one independent instance per local calendar day.
 * Keeps progress/events for past days and soft-deletes on managerial request.
 */
defined( 'ABSPATH' ) || exit;

function crmb_task_repeat_table() {
    global $wpdb;
    return $wpdb->prefix . 'crmb_task_repeat_series';
}

function crmb_task_repeat_ready() {
    return '1' === get_option( 'crmb_task_repeat_schema_v1' );
}

/** Additive migration; old tasks and all progress/image events remain intact. */
function crmb_task_repeat_install() {
    if ( crmb_task_repeat_ready() || '1' !== get_option( 'crmb_work_schema_v1' )
        || ( function_exists( 'crmb_progress_ready' ) && ! crmb_progress_ready() ) ) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $series = crmb_task_repeat_table();
    $tasks = crmb_work_tables()['tasks'];
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$series} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(180) NOT NULL,
        description text NOT NULL,
        assigned_to bigint(20) unsigned NOT NULL,
        created_by bigint(20) unsigned NOT NULL,
        priority varchar(12) NOT NULL DEFAULT 'normal',
        start_day date NOT NULL,
        active tinyint(1) NOT NULL DEFAULT 1,
        created_utc datetime NOT NULL,
        updated_utc datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY recurring_staff (active,assigned_to,start_day)
    ) ENGINE=InnoDB {$charset};" );
    $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $series ) ) );
    if ( $series !== $found ) { return; }

    $columns = array(
        'repeat_series_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
        'repeat_day'       => 'date DEFAULT NULL',
        'deleted_utc'      => 'datetime DEFAULT NULL',
    );
    foreach ( $columns as $name => $type ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$tasks} LIKE %s", $name ) );
        if ( $wpdb->last_error ) { return; }
        if ( $name !== $exists && false === $wpdb->query( "ALTER TABLE {$tasks} ADD COLUMN {$name} {$type}" ) ) { return; }
    }
    // Null repeat_day permits arbitrarily many ordinary, non-repeating tasks.
    $index = $wpdb->get_var( "SHOW INDEX FROM {$tasks} WHERE Key_name='crmb_repeat_instance'" );
    if ( $wpdb->last_error ) { return; }
    if ( ! $index && false === $wpdb->query(
        "ALTER TABLE {$tasks} ADD UNIQUE KEY crmb_repeat_instance (repeat_series_id,repeat_day)"
    ) ) { return; }
    update_option( 'crmb_task_repeat_schema_v1', '1', false );
}
add_action( 'init', 'crmb_task_repeat_install', 18 );

function crmb_task_repeat_rollback( $code = 'error' ) {
    global $wpdb;
    $wpdb->query( 'ROLLBACK' );
    return $code;
}

/** Manager saves an ordinary task, converts it to daily, or edits a daily series. */
function crmb_task_repeat_save( $id, $values, $rule ) {
    if ( ! crmb_work_manage_team() ) { return 'denied'; }
    if ( ! crmb_task_repeat_ready() ) { return 'error'; }
    if ( ! in_array( $rule, array( 'none', 'daily' ), true ) ) { return 'invalid'; }
    global $wpdb;
    $tasks = crmb_work_tables()['tasks'];
    $series = crmb_task_repeat_table();
    $today = crmb_att_today();
    $now = gmdate( 'Y-m-d H:i:s' );
    $old = null;
    $old_assignee = 0;

    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    if ( $id ) {
        $old = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tasks} WHERE id=%d AND deleted_utc IS NULL FOR UPDATE", $id
        ) );
        if ( ! $old ) { return crmb_task_repeat_rollback( $wpdb->last_error ? 'error' : 'invalid' ); }
        $old_assignee = (int) $old->assigned_to;
        // Never transfer a running timer, or discard its tracked time.
        if ( ! empty( $old->timer_started_utc ) && $old_assignee !== (int) $values['assigned_to'] ) {
            return crmb_task_repeat_rollback( 'running' );
        }
    }
    $series_id = $old ? (int) $old->repeat_series_id : 0;
    $start = $values['due_day'] ?: $today;
    if ( 'daily' === $rule && ! $series_id && $start < $today ) {
        return crmb_task_repeat_rollback( 'invalid' );
    }

    if ( $series_id ) {
        // Serializing the template with generation avoids stale daily copies.
        $template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$series} WHERE id=%d FOR UPDATE", $series_id ) );
        if ( ! $template ) { return crmb_task_repeat_rollback(); }
        $changes = array( 'updated_utc' => $now );
        if ( 'daily' === $rule ) {
            $changes += array(
                'title' => $values['title'], 'description' => $values['description'],
                'assigned_to' => $values['assigned_to'], 'priority' => $values['priority'],
                'active' => 1,
            );
        } else {
            $changes['active'] = 0; // Stop future instances; old history stays.
            // The edited task becomes an ordinary task. Past daily instances
            // retain their historical series relationship.
            $values['repeat_series_id'] = 0;
            $values['repeat_day'] = null;
        }
        if ( false === $wpdb->update( $series, $changes, array( 'id' => $series_id ) ) ) {
            return crmb_task_repeat_rollback();
        }
    } elseif ( 'daily' === $rule ) {
        if ( false === $wpdb->insert( $series, array(
            'title' => $values['title'], 'description' => $values['description'],
            'assigned_to' => $values['assigned_to'], 'created_by' => get_current_user_id(),
            'priority' => $values['priority'], 'start_day' => $start, 'active' => 1,
            'created_utc' => $now, 'updated_utc' => $now,
        ) ) ) { return crmb_task_repeat_rollback(); }
        $series_id = (int) $wpdb->insert_id;
        if ( ! $series_id ) { return crmb_task_repeat_rollback(); }
        // Existing single task becomes the first occurrence; new task is inserted below.
        if ( $id ) {
            $values['repeat_series_id'] = $series_id;
            $values['repeat_day'] = $start;
            $values['due_day'] = $start;
        }
    }

    $new_id = 0;
    if ( $id ) {
        if ( false === $wpdb->update( $tasks, $values, array( 'id' => $id, 'deleted_utc' => null ) ) ) {
            return crmb_task_repeat_rollback();
        }
        if ( function_exists( 'crmb_progress_event' )
            && ! crmb_progress_event( $id, 'edited', 'ویرایش وظیفه توسط مدیر' ) ) {
            return crmb_task_repeat_rollback();
        }
    } else {
        $values += array(
            'created_by' => get_current_user_id(), 'status' => 'todo',
            'created_utc' => $now,
        );
        if ( 'daily' === $rule ) {
            $values['repeat_series_id'] = $series_id;
            $values['repeat_day'] = $start;
            $values['due_day'] = $start;
        }
        if ( false === $wpdb->insert( $tasks, $values ) ) { return crmb_task_repeat_rollback(); }
        $new_id = (int) $wpdb->insert_id;
        if ( ! $new_id ) { return crmb_task_repeat_rollback(); }
    }
    if ( false === $wpdb->query( 'COMMIT' ) ) { return crmb_task_repeat_rollback(); }
    if ( function_exists( 'do_action' ) ) {
        if ( $new_id ) {
            do_action( 'crmb_task_assigned', $new_id, (int) $values['assigned_to'],
                get_current_user_id(), 0 );
        } elseif ( $old_assignee !== (int) $values['assigned_to'] ) {
            do_action( 'crmb_task_assigned', $id, (int) $values['assigned_to'],
                get_current_user_id(), $old_assignee );
        }
    }
    return 'saved';
}

/**
 * Deletion means soft archive. It hides the task from all ordinary views,
 * without erasing time tracking, staff progress notes, images, or audit events.
 */
function crmb_task_repeat_delete( $id, $stop_future = false ) {
    if ( ! crmb_work_manage_team() ) { return 'denied'; }
    if ( ! crmb_task_repeat_ready() ) { return 'error'; }
    if ( ! $id ) { return 'invalid'; }
    global $wpdb;
    $tasks = crmb_work_tables()['tasks'];
    $series = crmb_task_repeat_table();
    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return 'error'; }
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$tasks} WHERE id=%d AND deleted_utc IS NULL FOR UPDATE", $id
    ) );
    if ( ! $row ) { return crmb_task_repeat_rollback( $wpdb->last_error ? 'error' : 'changed' ); }
    if ( ! empty( $row->timer_started_utc ) ) { return crmb_task_repeat_rollback( 'running' ); }
    if ( $stop_future && (int) $row->repeat_series_id ) {
        if ( false === $wpdb->update( $series, array(
            'active' => 0, 'updated_utc' => gmdate( 'Y-m-d H:i:s' )
        ), array( 'id' => (int) $row->repeat_series_id ) ) ) { return crmb_task_repeat_rollback(); }
    }
    if ( false === $wpdb->update( $tasks, array(
        'deleted_utc' => gmdate( 'Y-m-d H:i:s'), 'status' => 'cancelled',
        'updated_utc' => gmdate( 'Y-m-d H:i:s'),
    ), array( 'id' => $id, 'deleted_utc' => null ) ) ) { return crmb_task_repeat_rollback(); }
    if ( function_exists( 'crmb_progress_event' )
        && ! crmb_progress_event( $id, 'deleted',
            $stop_future ? 'مدیر وظیفه و تکرار آینده را بایگانی کرد.' : 'مدیر وظیفه را بایگانی کرد.' ) ) {
        return crmb_task_repeat_rollback();
    }
    return false === $wpdb->query( 'COMMIT' ) ? crmb_task_repeat_rollback() : 'deleted';
}

/** Produce one fresh daily task per series. UNIQUE index makes retries idempotent. */
function crmb_task_repeat_generate( $day = null ) {
    if ( ! crmb_task_repeat_ready() ) { return false; }
    if ( null === $day ) { $day = crmb_att_today(); }
    if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) { return false; }
    global $wpdb;
    $series = crmb_task_repeat_table();
    $tasks = crmb_work_tables()['tasks'];
    $templates = $wpdb->get_results( $wpdb->prepare(
        "SELECT id FROM {$series} WHERE active=1 AND start_day<=%s ORDER BY id", $day
    ) );
    if ( $wpdb->last_error || ! is_array( $templates ) ) { return false; }
    foreach ( $templates as $item ) {
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        $template = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$series} WHERE id=%d AND active=1 AND start_day<=%s FOR UPDATE",
            (int) $item->id, $day
        ) );
        if ( $wpdb->last_error ) { crmb_task_repeat_rollback(); return false; }
        if ( ! $template ) { crmb_task_repeat_rollback(); continue; }
        $now = gmdate( 'Y-m-d H:i:s' );
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$tasks}
              (title,description,assigned_to,created_by,priority,due_day,status,
               created_utc,updated_utc,repeat_series_id,repeat_day)
             VALUES (%s,%s,%d,%d,%s,%s,'todo',%s,%s,%d,%s)",
            $template->title, $template->description, (int) $template->assigned_to,
            (int) $template->created_by, $template->priority, $day, $now, $now,
            (int) $template->id, $day
        );
        $inserted = $wpdb->query( $sql );
        $new_id = $inserted === 1 ? (int) $wpdb->insert_id : 0;
        if ( false === $inserted || false === $wpdb->query( 'COMMIT' ) ) {
            crmb_task_repeat_rollback(); return false;
        }
        if ( $new_id && function_exists( 'do_action' ) ) {
            do_action( 'crmb_task_assigned', $new_id, (int) $template->assigned_to,
                (int) $template->created_by, 0 );
        }
    }
    return true;
}

function crmb_task_repeat_next_run() {
    $now = new DateTimeImmutable( 'now', crmb_att_timezone() );
    return $now->modify( '+1 day' )->setTime( 0, 5, 0 )->getTimestamp();
}

function crmb_task_repeat_schedule() {
    if ( ! crmb_task_repeat_ready() ) { return; }
    if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' )
        && ! wp_next_scheduled( 'crmb_task_repeat_daily' ) ) {
        wp_schedule_single_event( crmb_task_repeat_next_run(), 'crmb_task_repeat_daily' );
    }
}

/** Cron + one first-request fallback for hosts where WordPress cron runs late. */
function crmb_task_repeat_maybe_generate() {
    if ( ! crmb_task_repeat_ready() ) { return; }
    crmb_task_repeat_schedule();
    $today = crmb_att_today();
    if ( get_option( 'crmb_task_repeat_last_day' ) === $today ) { return; }
    if ( crmb_task_repeat_generate( $today ) ) {
        update_option( 'crmb_task_repeat_last_day', $today, false );
    }
}
add_action( 'init', 'crmb_task_repeat_maybe_generate', 24 );
add_action( 'crmb_task_repeat_daily', function () {
    crmb_task_repeat_maybe_generate();
} );
