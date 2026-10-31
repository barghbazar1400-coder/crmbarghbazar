<?php
/**
 * SEO project workbench: one list for target pages, keywords, content,
 * technical issues and reports. Existing editors, workflow and URLs remain.
 */
defined( 'ABSPATH' ) || exit;

$seo_workspace_url = add_query_arg(
    array( 'crm_section' => 'seo', 'seo_tab' => 'workspace', 'project' => $seo_project_id ),
    home_url( '/main/' )
);
$scope = crmb_seo_item_scope();
$kinds = crmb_seo_kinds();
$states = crmb_seo_states();
$selected_kind = crmb_work_text( $_GET, 'seo_kind' );
$selected_state = crmb_work_text( $_GET, 'seo_state' );
if ( ! isset( $kinds[ $selected_kind ] ) ) { $selected_kind = ''; }
if ( ! isset( $states[ $selected_state ] ) ) { $selected_state = ''; }
$page = isset( $_GET['seo_page'] ) && is_scalar( $_GET['seo_page'] ) ? max( 1, min( 100000, absint( $_GET['seo_page'] ) ) ) : 1;

$stat_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT i.status,COUNT(*) AS total FROM {$seo_tables['items']} i WHERE i.project_id=%d AND {$scope} GROUP BY i.status",
    $seo_project_id
) );
$stats_error = (bool) $wpdb->last_error;
$status_counts = array();
foreach ( $stat_rows ?: array() as $row ) { $status_counts[ $row->status ] = (int) $row->total; }

$where = "i.project_id=%d AND {$scope}";
$args = array( $seo_project_id );
if ( $selected_kind ) { $where .= ' AND i.kind=%s'; $args[] = $selected_kind; }
if ( $selected_state ) { $where .= ' AND i.status=%s'; $args[] = $selected_state; }
$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$seo_tables['items']} i WHERE {$where}", ...$args ) );
$count_error = (bool) $wpdb->last_error;
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT i.id,i.title,i.kind,i.status,i.owner_id,i.due_day
     FROM {$seo_tables['items']} i WHERE {$where}
     ORDER BY i.updated_utc DESC,i.id DESC LIMIT %d OFFSET %d",
    ...array_merge( $args, array( 16, ( $page - 1 ) * 15 ) )
) );
$list_error = $count_error || (bool) $wpdb->last_error;
$more = count( $rows ?: array() ) > 15;
$filtered_url = add_query_arg(
    array( 'seo_kind' => $selected_kind, 'seo_state' => $selected_state ),
    $seo_workspace_url
);
?>
<div class="crmb-seo-project-top">
    <div>
        <h2><?php echo esc_html( $seo_project->name ); ?></h2>
        <p class="crm-access-muted" dir="ltr"><?php echo esc_html( $seo_project->site_url ); ?></p>
    </div>
    <div class="crmb-seo-project-actions">
        <?php if ( $seo_manage ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'seo_tab', 'projects', $seo_workspace_url ) ); ?>">تنظیمات پروژه</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_tab', 'team', $seo_workspace_url ) ); ?>">اعضای پروژه</a>
        <?php endif; ?>
        <a href="<?php echo esc_url( add_query_arg( 'seo_tab', 'tasks', $seo_workspace_url ) ); ?>">تسک‌های پروژه</a>
    </div>
</div>

<div class="crmb-seo-workflow" aria-label="وضعیت گردش کار پروژه">
    <?php if ( $stats_error ) : ?>
        <p role="status">آمار پروژه در دسترس نیست.</p>
    <?php else :
        $stage_labels = array(
            'draft' => 'آماده‌سازی',
            'submitted' => 'منتظر بازبینی',
            'changes' => 'نیازمند اصلاح',
            'reviewed' => 'بازبینی کارشناس',
            'approved' => 'تأیید نهایی',
            'published' => 'ثبت انتشار',
        );
        foreach ( $stage_labels as $stage => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'seo_state', $stage, $seo_workspace_url ) ); ?>">
                <strong><?php echo esc_html( (string) ( $status_counts[ $stage ] ?? 0 ) ); ?></strong>
                <span><?php echo esc_html( $label ); ?></span>
            </a>
        <?php endforeach;
    endif; ?>
</div>

<section class="crm-access-card crmb-seo-workbench">
    <div class="crmb-leadership-heading"><h2>پرونده‌های کاری پروژه</h2>
        <?php if ( $seo_manage || 'expert' === crmb_seo_level() ) : ?>
            <details class="crmb-seo-create-menu"><summary>+ ثبت کار جدید</summary>
                <div class="crmb-seo-create-links">
                    <?php foreach ( $kinds as $key => $label ) :
                        if ( 'training' === $key && ! $seo_manage ) { continue; } ?>
                        <a href="<?php echo esc_url( add_query_arg( 'seo_tab', $key, $seo_workspace_url ) ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                    <?php if ( $seo_manage ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'seo_tab', 'tasks', $seo_workspace_url ) ); ?>">واگذاری تسک</a>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
    <form method="get" action="<?php echo esc_url( home_url( '/main/' ) ); ?>" class="crmb-seo-filter">
        <input type="hidden" name="crm_section" value="seo">
        <input type="hidden" name="seo_tab" value="workspace">
        <input type="hidden" name="project" value="<?php echo esc_attr( $seo_project_id ); ?>">
        <label for="seo-work-kind">نوع کار</label>
        <select id="seo-work-kind" name="seo_kind"><option value="">همه نوع‌ها</option>
            <?php foreach ( $kinds as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_kind, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
        </select>
        <label for="seo-work-state">وضعیت</label>
        <select id="seo-work-state" name="seo_state"><option value="">همه وضعیت‌ها</option>
            <?php foreach ( $states as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
        </select>
        <button class="crm-access-primary crmb-work-button" type="submit">اعمال فیلتر</button>
        <a href="<?php echo esc_url( $seo_workspace_url ); ?>">پاک‌کردن فیلتر</a>
    </form>
    <?php if ( $list_error ) : ?>
        <p role="status">فهرست پروژه دریافت نشد؛ دوباره تلاش کنید.</p>
    <?php elseif ( ! $rows ) : ?>
        <p class="crm-access-muted">در این فیلتر موردی ثبت نشده است. برای شروع یک کار جدید بسازید.</p>
    <?php else : ?>
        <div class="crm-access-table-wrap">
            <table class="crm-access-table crmb-seo-project-table">
                <thead><tr><th scope="col">کار / خروجی</th><th scope="col">نوع</th><th scope="col">مسئول</th><th scope="col">وضعیت</th><th scope="col">مهلت</th></tr></thead>
                <tbody><?php foreach ( array_slice( $rows, 0, 15 ) as $row ) :
                    $detail_url = add_query_arg( array( 'seo_tab' => $row->kind, 'item' => $row->id ), $seo_workspace_url );
                    $is_overdue = $row->due_day && $row->due_day < crmb_att_today() && ! in_array( $row->status, array( 'approved', 'published' ), true ); ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $row->title ); ?></a></td>
                        <td><?php echo esc_html( $kinds[ $row->kind ] ?? 'نامشخص' ); ?></td>
                        <td><?php echo esc_html( crmb_work_user_name( $row->owner_id ) ); ?></td>
                        <td><span class="crmb-dashboard-status"><?php echo esc_html( $states[ $row->status ] ?? 'نامشخص' ); ?></span></td>
                        <td class="<?php echo $is_overdue ? 'crmb-work-overdue' : ''; ?>"><?php echo esc_html( $row->due_day ? crmb_att_jdate( $row->due_day ) : '—' ); ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
        <nav class="crm-access-pages" aria-label="صفحه‌های پرونده‌های سئو">
            <?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'seo_page', $page - 1, $filtered_url ) ); ?>">قبلی</a><?php endif; ?>
            <span><?php echo esc_html( $page . ' / ' . max( 1, (int) ceil( (int) $total / 15 ) ) ); ?></span>
            <?php if ( $more ) : ?><a href="<?php echo esc_url( add_query_arg( 'seo_page', $page + 1, $filtered_url ) ); ?>">بعدی</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php
// The task engine is shared: showing an existing task never creates a copy.
$work_tables = crmb_work_tables();
$task_scope = $seo_manage ? '' : $wpdb->prepare( ' AND w.assigned_to=%d', get_current_user_id() );
$task_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT w.id,w.title,w.status,w.assigned_to,w.due_day FROM {$work_tables['tasks']} w
     INNER JOIN {$seo_tables['tasks']} l ON l.task_id=w.id
     WHERE l.project_id=%d{$task_scope} AND w.status IN ('todo','doing','paused')
     ORDER BY w.due_day IS NULL,w.due_day ASC,w.id DESC LIMIT 8",
    $seo_project_id
) );
$task_error = (bool) $wpdb->last_error;
?>
<section class="crm-access-card crmb-seo-workbench">
    <div class="crmb-leadership-heading"><h2>وظایف اجرایی پروژه</h2>
        <a href="<?php echo esc_url( add_query_arg( 'seo_tab', 'tasks', $seo_workspace_url ) ); ?>">مدیریت و واگذاری وظایف</a>
    </div>
    <?php if ( $task_error ) : ?><p role="status">وظایف پروژه دریافت نشد.</p>
    <?php elseif ( ! $task_rows ) : ?><p class="crm-access-muted">وظیفه بازی در این پروژه ندارید.</p>
    <?php else : ?>
        <div class="crmb-leadership-worklist">
            <?php foreach ( $task_rows as $task ) : ?>
                <div class="crmb-leadership-workrow">
                    <div><a href="<?php echo esc_url( add_query_arg( array( 'crm_section' => 'tasks', 'task_id' => $task->id ), home_url( '/main/' ) ) ); ?>"><?php echo esc_html( $task->title ); ?></a>
                        <small><?php echo esc_html( crmb_work_user_name( $task->assigned_to ) . ' · ' . ( crmb_work_statuses()[ $task->status ] ?? 'نامشخص' ) ); ?></small></div>
                    <span><?php echo esc_html( $task->due_day ? crmb_att_jdate( $task->due_day ) : 'بدون مهلت' ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
