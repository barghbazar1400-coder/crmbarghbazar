<?php
/**
 * Role-scoped executive and operations overview. All numbers come from
 * existing CRM tables; no separate copies, fabricated KPIs or migrations.
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'crmbarghbazar_menu_registry', function ( $items ) {
    return array(
        'ceo' => array( 'label' => 'پیشخوان CEO', 'admin_only' => true ),
        'operations' => array( 'label' => 'مرکز عملیات COO' ),
    ) + $items;
} );

/** This is an explicit CRM permission, not an automatic WordPress editor grant. */
function crmb_leadership_can_operate() {
    return is_user_logged_in() && crmbarghbazar_can_view_section( 'operations' );
}

/** COO can manage ordinary team tasks; private messages and HR remain separate. */
function crmb_leadership_can_manage_tasks() {
    return crmb_leadership_can_operate();
}

function crmb_leadership_count( $sql ) {
    global $wpdb;
    $value = $wpdb->get_var( $sql );
    return $wpdb->last_error ? null : (int) $value;
}

function crmb_leadership_data() {
    $empty = array(
        'tasks_open' => null, 'tasks_overdue' => null, 'tasks_done' => null,
        'timers_active' => null, 'reports_pending' => null, 'leaves_pending' => null,
        'seo_review' => null, 'seo_projects' => null, 'recent' => null,
        'sales_customers' => null, 'sales_quotes' => null, 'sales_followups' => null,
    );
    if ( ! crmb_leadership_can_operate() ) { return $empty; }
    global $wpdb;
    if ( '1' === get_option( 'crmb_work_schema_v1' ) ) {
        $work = crmb_work_tables();
        $not_deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
            ? ' AND deleted_utc IS NULL' : '';
        $empty['tasks_open'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$work['tasks']} WHERE status IN ('todo','doing','paused'){$not_deleted}" );
        $empty['tasks_done'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$work['tasks']} WHERE status = 'done'{$not_deleted}" );
        $empty['tasks_overdue'] = crmb_leadership_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$work['tasks']} WHERE status IN ('todo','doing','paused') AND due_day < %s{$not_deleted}",
            crmb_att_today()
        ) );
        $rows = $wpdb->get_results(
            "SELECT id,title,assigned_to,status,due_day FROM {$work['tasks']}
             WHERE status IN ('todo','doing','paused'){$not_deleted}
             ORDER BY due_day IS NULL,due_day ASC,id DESC LIMIT 8"
        );
        $empty['recent'] = $wpdb->last_error ? null : ( $rows ?: array() );
    }
    if ( crmb_progress_ready() ) {
        $tables = crmb_progress_tables();
        $empty['reports_pending'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$tables['reports']} WHERE status='submitted'" );
        $extra = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
            ? ' AND deleted_utc IS NULL' : '';
        $empty['timers_active'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$tables['tasks']} WHERE timer_started_utc IS NOT NULL{$extra}" );
    }
    if ( '1' === get_option( 'crmb_leave_schema_v1' ) ) {
        $table = crmb_leave_table();
        $empty['leaves_pending'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$table} WHERE status='pending'" );
    }
    // SEO information remains limited to users who already have SEO access.
    if ( crmb_seo_access() && crmb_seo_ready() ) {
        $tables = crmb_seo_tables();
        $projects = crmb_seo_projects();
        $ids = array_map( 'intval', wp_list_pluck( $projects, 'id' ) );
        $empty['seo_projects'] = count( $ids );
        if ( $ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $empty['seo_review'] = crmb_leadership_count( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['items']} WHERE project_id IN ($placeholders) AND status IN ('submitted','reviewed')",
                ...$ids
            ) );
        } else {
            $empty['seo_review'] = 0;
        }
    }
    // Sales figures are operational counts, never invented revenue.
    if ( function_exists( 'crmb_sales_access' ) && crmb_sales_access() && crmb_sales_ready() ) {
        $sales = crmb_sales_tables();
        $scope = crmb_sales_scope();
        $empty['sales_customers'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$sales['customers']} WHERE 1=1{$scope}" );
        $empty['sales_quotes'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$sales['quotes']} WHERE 1=1{$scope}" );
        if ( function_exists( 'crmb_sales_ops_ready' ) && crmb_sales_ops_ready()
            && '1' === get_option( 'crmb_sales_calls_v2' ) ) {
            // Align the headline KPI with the customer-first sales desk:
            // only the latest call for each customer can be outstanding.
            $calls = crmb_sales_ops_tables()['calls'];
            $call_scope = crmb_sales_scope( 'x' );
            $empty['sales_followups'] = crmb_leadership_count( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$calls} x WHERE x.outcome='followup'
                    AND x.next_followup_day<=%s
                    AND x.id=(SELECT MAX(y.id) FROM {$calls} y WHERE y.customer_id=x.customer_id)
                    {$call_scope}",
                crmb_att_today()
            ) );
        } else {
            // Legacy installations that have not migrated calls yet.
            $empty['sales_followups'] = crmb_leadership_count( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sales['opportunities']} WHERE followup_day<=%s
                    AND stage IN ('lead','contacted','quoted'){$scope}",
                crmb_att_today()
            ) );
        }
    }
    return $empty;
}


/**
 * Read-only manager queues. Respect the existing team-task and sales scopes.
 * Missing tables/query failures must never look like an empty queue.
 */
function crmb_leadership_control_rows( $sql ) {
    global $wpdb;
    $rows = $wpdb->get_results( $sql );
    return $wpdb->last_error || ! is_array( $rows ) ? null : $rows;
}

function crmb_leadership_control_day_bounds() {
    $midnight = new DateTimeImmutable( crmb_att_today() . ' 00:00:00', crmb_att_timezone() );
    return array(
        $midnight->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        $midnight->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
    );
}

function crmb_leadership_control_data() {
    if ( ! crmb_leadership_can_operate() ) { return null; }
    $result = array(
        'overdue' => null, 'stalled' => null, 'activity' => null,
        'sales_access' => false, 'sales_ready' => false,
        'calls_today' => null, 'calls' => null, 'followups' => null,
    );
    global $wpdb;
    $bounds = crmb_leadership_control_day_bounds();

    if ( '1' === get_option( 'crmb_work_schema_v1' ) ) {
        $tasks = crmb_work_tables()['tasks'];
        $deleted = function_exists( 'crmb_task_repeat_ready' ) && crmb_task_repeat_ready()
            ? ' AND t.deleted_utc IS NULL' : '';
        $result['overdue'] = crmb_leadership_control_rows( $wpdb->prepare(
            "SELECT t.id,t.title,t.assigned_to,t.status,t.due_day
               FROM {$tasks} t WHERE t.status IN ('todo','doing','paused')
                 AND t.due_day < %s{$deleted}
              ORDER BY t.due_day ASC,t.id DESC LIMIT 10",
            crmb_att_today()
        ) );
        if ( crmb_progress_ready() ) {
            $events = crmb_progress_tables()['events'];
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - 48 * 3600 );
            // An active or paused task older than 48h, with no employee
            // progress/blocker report in the last 48h. A manager note is
            // deliberately NOT counted as the employee's progress.
            $result['stalled'] = crmb_leadership_control_rows( $wpdb->prepare(
                "SELECT t.id,t.title,t.assigned_to,t.status,t.due_day
                   FROM {$tasks} t
                  WHERE t.status IN ('doing','paused') AND t.created_utc < %s{$deleted}
                    AND NOT EXISTS (
                        SELECT 1 FROM {$events} e
                         WHERE e.task_id=t.id
                           AND e.actor_id=t.assigned_to
                           AND e.event_type IN ('note','blocker','rich_note','rich_blocker')
                           AND e.created_utc >= %s
                    )
                  ORDER BY t.updated_utc ASC,t.id DESC LIMIT 10",
                $cutoff, $cutoff
            ) );
            $result['activity'] = crmb_leadership_control_rows( $wpdb->prepare(
                "SELECT e.id,e.task_id,e.actor_id,e.event_type,e.created_utc,t.title
                   FROM {$events} e JOIN {$tasks} t ON t.id=e.task_id
                  WHERE e.created_utc >= %s AND e.created_utc < %s
                    AND e.actor_id=t.assigned_to
                    AND e.event_type IN ('doing','paused','done','todo','note','blocker','rich_note','rich_blocker')
                    {$deleted}
                  ORDER BY e.id DESC LIMIT 12",
                $bounds[0], $bounds[1]
            ) );
        }
    }

    $result['sales_access'] = function_exists( 'crmb_sales_access' ) && crmb_sales_access();
    if ( ! $result['sales_access'] || ! crmb_sales_ready()
        || ! function_exists( 'crmb_sales_ops_ready' ) || ! crmb_sales_ops_ready()
        || '1' !== get_option( 'crmb_sales_calls_v2' ) ) {
        return $result;
    }
    $result['sales_ready'] = true;
    $sales = crmb_sales_tables();
    $calls = crmb_sales_ops_tables()['calls'];
    // Non-admin COO accounts are scoped to their own sales records.
    $scope = crmb_sales_scope( 'x' );
    $result['calls_today'] = crmb_leadership_count( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$calls} x WHERE x.created_utc >= %s AND x.created_utc < %s{$scope}",
        $bounds[0], $bounds[1]
    ) );
    $result['calls'] = crmb_leadership_control_rows( $wpdb->prepare(
        "SELECT x.id,x.customer_id,x.owner_id,x.outcome,x.created_utc,c.name AS customer_name
           FROM {$calls} x JOIN {$sales['customers']} c ON c.id=x.customer_id
          WHERE x.created_utc >= %s AND x.created_utc < %s{$scope}
          ORDER BY x.id DESC LIMIT 8",
        $bounds[0], $bounds[1]
    ) );
    // Match the latest-call-per-customer logic already used by sales-v2.
    $result['followups'] = crmb_leadership_control_rows( $wpdb->prepare(
        "SELECT x.id,x.customer_id,x.owner_id,x.next_followup_day,c.name AS customer_name
           FROM {$calls} x JOIN {$sales['customers']} c ON c.id=x.customer_id
          WHERE x.outcome='followup' AND x.next_followup_day<=%s
            AND x.id=(SELECT MAX(y.id) FROM {$calls} y WHERE y.customer_id=x.customer_id)
            {$scope}
          ORDER BY x.next_followup_day ASC,x.id DESC LIMIT 10",
        crmb_att_today()
    ) );
    return $result;
}
