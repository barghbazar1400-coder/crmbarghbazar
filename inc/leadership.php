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
        $empty['tasks_open'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$work['tasks']} WHERE status IN ('todo','doing','paused')" );
        $empty['tasks_done'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$work['tasks']} WHERE status = 'done'" );
        $empty['tasks_overdue'] = crmb_leadership_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$work['tasks']} WHERE status IN ('todo','doing','paused') AND due_day < %s",
            crmb_att_today()
        ) );
        $rows = $wpdb->get_results(
            "SELECT id,title,assigned_to,status,due_day FROM {$work['tasks']}
             WHERE status IN ('todo','doing','paused')
             ORDER BY due_day IS NULL,due_day ASC,id DESC LIMIT 8"
        );
        $empty['recent'] = $wpdb->last_error ? null : ( $rows ?: array() );
    }
    if ( crmb_progress_ready() ) {
        $tables = crmb_progress_tables();
        $empty['reports_pending'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$tables['reports']} WHERE status='submitted'" );
        $empty['timers_active'] = crmb_leadership_count( "SELECT COUNT(*) FROM {$tables['tasks']} WHERE timer_started_utc IS NOT NULL" );
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
        $empty['sales_followups'] = crmb_leadership_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$sales['opportunities']} WHERE followup_day<=%s AND stage IN ('lead','contacted','quoted'){$scope}",
            crmb_att_today()
        ) );
    }
    return $empty;
}
