<?php
/** Task detail and timeline, authorized by the same owner/manager scope as timers. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$id = crmb_work_id( $_GET, 'task_id' );
$task = crmb_progress_task( $id );
$base = add_query_arg( 'crm_section', 'tasks', home_url( '/main/' ) );
?>
<section class="crm-access-screen crmb-work" aria-labelledby="crmb-progress-title">
    <header class="crm-access-header"><h1 id="crmb-progress-title">روند انجام وظیفه</h1><p><a href="<?php echo esc_url( $base ); ?>">بازگشت به وظایف</a></p></header>
    <?php crmb_work_notice(); crmb_progress_notice(); ?>
    <?php if ( ! $task ) : ?><p role="status">وظیفه در دسترس نیست یا اجازه مشاهده آن را ندارید.</p></section><?php return; endif;
    global $wpdb; $tables = crmb_progress_tables();
    $owner = (int) $task->assigned_to === get_current_user_id();
    $admin = current_user_can( 'manage_options' ) || ( function_exists( 'crmb_seo_can_manage_task' ) && crmb_seo_can_manage_task( $id ) );
    $event_page = isset( $_GET['history_page'] ) && is_scalar( $_GET['history_page'] ) ? max( 1, min( 100000, absint( $_GET['history_page'] ) ) ) : 1;
    $events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['events']} WHERE task_id = %d ORDER BY id DESC LIMIT 21 OFFSET %d", $id, ( $event_page - 1 ) * 20 ) );
    $history_error = (bool) $wpdb->last_error;
    $more = count( $events ?: array() ) > 20;
    $events = array_slice( $events ?: array(), 0, 20 );
    $detail = add_query_arg( 'task_id', $id, $base ); ?>
    <section class="crm-access-card">
        <h2><?php echo esc_html( $task->title ); ?></h2>
        <p class="crmb-work-body"><?php echo esc_html( $task->description ); ?></p>
        <p>مسئول: <?php echo esc_html( crmb_work_user_name( $task->assigned_to ) ); ?> | وضعیت: <?php echo esc_html( crmb_work_statuses()[ $task->status ] ?? 'نامشخص' ); ?></p>
        <dl class="crmb-task-times">
            <div><dt>اولین شروع</dt><dd><?php echo esc_html( crmb_work_date( $task->first_started_utc ) ); ?></dd></div>
            <div><dt>پایان</dt><dd><?php echo esc_html( crmb_work_date( $task->completed_utc ) ); ?></dd></div>
            <div><dt>زمان کار ثبت‌شده</dt><dd dir="ltr"><strong id="crmb-task-timer" data-seconds="<?php echo esc_attr( crmb_progress_seconds( $task ) ); ?>" data-running="<?php echo $task->timer_started_utc ? '1' : '0'; ?>"><?php echo esc_html( crmb_progress_duration( crmb_progress_seconds( $task ) ) ); ?></strong></dd></div>
        </dl>
        <p class="crm-access-muted">ساعت‌ها تهران و زمان ثبت براساس سرور است. توقف موقت از مدت کار کم می‌شود. زمان ثبت‌شده با حضور و غیاب یا حقوق یکی نیست.</p>
        <?php if ( $owner && ! in_array( $task->status, array( 'done', 'cancelled' ), true ) ) : ?>
            <div class="crmb-task-controls">
            <?php foreach ( $task->timer_started_utc ? array( 'paused' => 'توقف موقت', 'done' => 'پایان کار' ) : array( 'doing' => 'شروع / ادامه کار', 'done' => 'پایان کار' ) as $status => $label ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php crmb_work_form_fields( 'task_status', $id ); ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><button type="submit" class="crm-access-primary crmb-work-button"><?php echo esc_html( $label ); ?></button></form>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ( $owner || $admin ) : ?><p class="crm-access-muted">برای بازگشایی یا تغییر دیگر وضعیت‌ها از فهرست وظایف استفاده کنید؛ شروع زمان‌سنج فقط توسط مسئول انجام می‌شود.</p><?php endif; ?>
    </section>
    <section class="crm-access-card"><h2>ثبت پیشرفت یا مانع</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
            <?php crmb_progress_form_fields( 'task_note', $id ); ?>
            <label for="note-type">نوع یادداشت</label><select id="note-type" name="note_type"><option value="note">پیشرفت / توضیح</option><option value="blocker">مانع / مشکل</option></select>
            <label for="note-body">توضیحات</label><textarea id="note-body" name="note_body" rows="4" maxlength="3000" required></textarea><button type="submit" class="crm-access-primary">ثبت در تاریخچه</button>
        </form>
    </section>
    <section class="crm-access-card"><h2>تاریخچه انجام کار</h2>
        <?php if ( $history_error ) : ?><p role="status">دریافت تاریخچه انجام نشد.</p><?php elseif ( ! $events ) : ?><p class="crm-access-muted">هنوز رویدادی ثبت نشده است. زمان‌های قبل از فعال‌شدن این قابلیت بازسازی نمی‌شوند.</p><?php endif; ?>
        <?php foreach ( $events as $event ) : ?><article class="crmb-work-item"><h3><?php echo esc_html( crmb_progress_event_labels()[ $event->event_type ] ?? 'تغییر وضعیت' ); ?></h3><p class="crm-access-muted"><?php echo esc_html( crmb_work_user_name( $event->actor_id ) . ' — ' . crmb_work_date( $event->created_utc ) ); ?></p><p class="crmb-work-body"><?php echo esc_html( $event->body ); ?></p></article><?php endforeach; ?>
        <nav class="crm-access-pages" aria-label="صفحات تاریخچه"><?php if ( $event_page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'history_page', $event_page - 1, $detail ) ); ?>">قبلی</a><?php endif; ?><?php if ( $more ) : ?><a href="<?php echo esc_url( add_query_arg( 'history_page', $event_page + 1, $detail ) ); ?>">بعدی</a><?php endif; ?></nav>
    </section>
</section>
<script>
(function () {
    const timer = document.getElementById('crmb-task-timer');
    if (!timer || timer.dataset.running !== '1') return;
    const seconds = Number(timer.dataset.seconds), start = performance.now();
    function update() {
        const value = seconds + Math.floor((performance.now() - start) / 1000);
        timer.textContent = [Math.floor(value / 3600), Math.floor(value / 60) % 60, value % 60].map(n => String(n).padStart(2, '0')).join(':');
    }
    window.setInterval(update, 1000);
})();
</script>
