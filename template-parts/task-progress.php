<?php
/** Task detail and timeline, authorized by the same owner/manager scope as timers. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$id = crmb_work_id( $_GET, 'task_id' );
$task = crmb_progress_task( $id );
$base = add_query_arg( 'crm_section', 'tasks', home_url( '/main/' ) );
?>
<section class="crm-access-screen crmb-work crmb-task-detail-page" aria-labelledby="crmb-progress-title">
    <header class="crm-access-header"><h1 id="crmb-progress-title">روند انجام کار</h1><p><a href="<?php echo esc_url( $base ); ?>">بازگشت به کارهای من</a></p></header>
    <?php crmb_work_notice(); crmb_progress_notice(); ?>
    <?php if ( ! $task ) : ?><p role="status">وظیفه در دسترس نیست یا اجازه مشاهده آن را ندارید.</p></section><?php return; endif;
    global $wpdb; $tables = crmb_progress_tables();
    $owner = (int) $task->assigned_to === get_current_user_id();
    $admin = crmb_work_manage_team() || ( function_exists( 'crmb_seo_can_manage_task' ) && crmb_seo_can_manage_task( $id ) );
    $is_open = in_array( $task->status, array( 'todo', 'doing', 'paused' ), true );
    $retry = get_transient( 'crmb_task_note_retry_' . get_current_user_id() . '_' . $id );
    $retry = is_array( $retry ) ? $retry : array();
    $event_page = isset( $_GET['history_page'] ) && is_scalar( $_GET['history_page'] ) ? max( 1, min( 100000, absint( $_GET['history_page'] ) ) ) : 1;
    $events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['events']} WHERE task_id = %d ORDER BY id DESC LIMIT 21 OFFSET %d", $id, ( $event_page - 1 ) * 20 ) );
    $history_error = (bool) $wpdb->last_error;
    $more = count( $events ?: array() ) > 20;
    $events = array_slice( $events ?: array(), 0, 20 );
    $gallery = $history_error ? array() : crmb_task_note_gallery( $events );
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
        <?php if ( $owner || $admin ) : ?><p class="crm-access-muted">برای بازگشایی یا تغییر دیگر وضعیت‌ها از فهرست کارهای من استفاده کنید؛ شروع زمان‌سنج فقط توسط مسئول انجام می‌شود.</p><?php endif; ?>
    </section>
    <?php if ( $admin && $is_open ) : ?>
    <section class="crm-access-card crmb-task-manager-note">
        <div class="crmb-task-note-heading">
            <span class="crmb-task-note-heading-icon"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i></span>
            <div><h2>راهنمایی مدیر برای انجام این کار</h2>
                <p>نکته یا مسیر پیشنهادی خود را ثبت کنید تا مسئول کار در تاریخچه ببیند.</p></div>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
            <?php crmb_progress_form_fields( 'manager_note', $id ); ?>
            <label for="crmb-manager-note">یادداشت و راهنمایی</label>
            <textarea id="crmb-manager-note" name="note_body" rows="3" maxlength="3000"
                placeholder="مثلاً ابتدا فهرست محصولات را بررسی کنید، بعد نتیجه مقایسه را در گزارش ثبت کنید."
                required><?php echo esc_textarea( ( $retry['type'] ?? '' ) === 'manager_note' ? ( $retry['body'] ?? '' ) : '' ); ?></textarea>
            <button type="submit" class="crm-access-primary crmb-work-button">ثبت راهنمایی در تاریخچه</button>
        </form>
    </section>
    <?php endif; ?>
    <?php if ( $owner && $is_open ) : ?>
    <section class="crm-access-card crmb-task-rich-section">
        <div class="crmb-task-note-heading">
            <span class="crmb-task-note-heading-icon is-progress"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span>
            <div><h2>ثبت گزارش پیشرفت یا مانع</h2>
                <p>می‌توانید متن را قالب‌بندی کنید، جدول بسازید و تصویر پیوست کنید.</p></div>
        </div>
        <?php if ( $retry && ( $retry['type'] ?? '' ) !== 'manager_note' ) : ?>
            <p class="crm-access-notice" role="status">متن گزارش پس از خطا بازیابی شد. تصاویر را دوباره انتخاب کنید.</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              class="crm-access-form crmb-task-rich-form" id="crmb-task-progress-form">
            <?php crmb_progress_form_fields( 'task_note', $id ); ?>
            <label for="note-type">نوع گزارش</label>
            <select id="note-type" name="note_type">
                <option value="note" <?php selected( ( $retry['type'] ?? '' ), 'rich_note' ); ?>>پیشرفت / توضیح</option>
                <option value="blocker" <?php selected( ( $retry['type'] ?? '' ), 'rich_blocker' ); ?>>مانع / مشکل</option>
            </select>
            <label for="crmbtaskeditor">شرح پیشرفت</label>
            <?php wp_editor(
                ( $retry['type'] ?? '' ) === 'rich_note' || ( $retry['type'] ?? '' ) === 'rich_blocker' ? ( $retry['body'] ?? '' ) : '',
                'crmbtaskeditor',
                array(
                    'textarea_name' => 'note_body',
                    'textarea_rows' => 11,
                    'media_buttons' => false,
                    'quicktags' => true,
                    'tinymce' => array(
                        'directionality' => 'rtl',
                        'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,undo,redo',
                        'toolbar2' => 'removeformat,hr',
                        'block_formats' => 'پاراگراف=p;عنوان ۲=h2;عنوان ۳=h3;عنوان ۴=h4',
                    ),
                )
            ); ?>
            <div class="crmb-task-editor-tools">
                <button type="button" id="crmb-task-insert-table" class="crmb-task-insert-table">
                    <i class="fa-solid fa-table" aria-hidden="true"></i> درج جدول ۲×۲
                </button>
                <span>بعد از درج، متن سلول‌ها را در ویرایشگر تغییر دهید.</span>
            </div>
            <label for="crmb-task-images">تصاویر گزارش پیشرفت</label>
            <input type="file" id="crmb-task-images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
            <p class="crm-access-muted">تا ۳ تصویر JPG، PNG یا WebP (هرکدام حداکثر ۱ مگابایت). تصاویر فقط برای افراد مجاز همین کار قابل مشاهده‌اند و همراه گزارش در تاریخچه نمایش داده می‌شوند.</p>
            <div id="crmb-task-image-preview" class="crmb-task-image-preview" aria-live="polite"></div>
            <button type="submit" class="crm-access-primary">ثبت گزارش در تاریخچه</button>
        </form>
    </section>
    <?php endif; ?>
    <?php if ( ! $is_open ) : ?>
        <p class="crm-access-muted">این کار تکمیل یا لغو شده است. تاریخچه محفوظ است و گزارش جدید تنها پس از بازگشایی کار ثبت می‌شود.</p>
    <?php endif; ?>
    <section class="crm-access-card"><h2>تاریخچه انجام کار</h2>
        <?php if ( $history_error ) : ?><p role="status">دریافت تاریخچه انجام نشد.</p><?php elseif ( ! $events ) : ?><p class="crm-access-muted">هنوز رویدادی ثبت نشده است. زمان‌های قبل از فعال‌شدن این قابلیت بازسازی نمی‌شوند.</p><?php endif; ?>
        <?php foreach ( $events as $event ) :
            $rich = in_array( $event->event_type, array( 'rich_note', 'rich_blocker', 'manager_note' ), true );
            $from_manager = 'manager_note' === $event->event_type;
            ?>
            <article class="crmb-work-item crmb-task-timeline-entry <?php echo $from_manager ? 'is-manager-note' : ''; ?>">
                <div class="crmb-task-timeline-head">
                    <span class="crmb-task-timeline-icon">
                        <i class="fa-solid <?php echo $from_manager ? 'fa-lightbulb' : ( $rich ? 'fa-file-lines' : 'fa-clock' ); ?>" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h3><?php echo esc_html( crmb_progress_event_labels()[ $event->event_type ] ?? 'تغییر وضعیت' ); ?></h3>
                        <p class="crm-access-muted"><?php echo esc_html( crmb_work_user_name( $event->actor_id ) . ' — ' . crmb_work_date( $event->created_utc ) ); ?></p>
                    </div>
                </div>
                <?php if ( $rich ) : ?>
                    <div class="crmb-task-rich-body"><?php echo crmb_task_note_html( $event->body ); ?></div>
                <?php else : ?>
                    <p class="crmb-work-body"><?php echo esc_html( $event->body ); ?></p>
                <?php endif; ?>
                <?php crmb_task_note_images_markup( $gallery[(int) $event->id] ?? array() ); ?>
            </article>
        <?php endforeach; ?>
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
(function () {
    const insert = document.getElementById('crmb-task-insert-table');
    if (insert) {
        insert.addEventListener('click', () => {
            const html = '<table><thead><tr><th>عنوان اول</th><th>عنوان دوم</th></tr></thead>' +
                '<tbody><tr><td>مورد اول</td><td>توضیح</td></tr>' +
                '<tr><td>مورد دوم</td><td>توضیح</td></tr></tbody></table><p></p>';
            const editor = window.tinymce && window.tinymce.get('crmbtaskeditor');
            if (editor && !editor.isHidden()) {
                editor.focus();
                editor.insertContent(html);
                return;
            }
            const field = document.getElementById('crmbtaskeditor');
            if (field) {
                field.setRangeText(html, field.selectionStart, field.selectionEnd, 'end');
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.focus();
            }
        });
    }
    const files = document.getElementById('crmb-task-images');
    const preview = document.getElementById('crmb-task-image-preview');
    if (files && preview) {
        files.addEventListener('change', () => {
            preview.replaceChildren();
            const chosen = Array.from(files.files || []);
            if (chosen.length > 3) {
                preview.textContent = 'حداکثر ۳ تصویر انتخاب کنید.';
                return;
            }
            chosen.forEach(file => {
                const item = document.createElement('span');
                item.textContent = file.name + ' (' + Math.ceil(file.size / 1024) + ' KB)';
                preview.append(item);
            });
        });
    }
})();
</script>
