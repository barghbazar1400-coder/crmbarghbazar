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
    $under_review = 'review' === $task->status;
    $review_revisions = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['events']} WHERE task_id=%d AND event_type='review_rejected'", $id
    ) );
    $review_revisions = $wpdb->last_error ? null : (int) $review_revisions;
    $retry = get_transient( 'crmb_task_note_retry_' . get_current_user_id() . '_' . $id );
    $retry = is_array( $retry ) ? $retry : array();
    $event_page = isset( $_GET['history_page'] ) && is_scalar( $_GET['history_page'] ) ? max( 1, min( 100000, absint( $_GET['history_page'] ) ) ) : 1;
    $events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['events']} WHERE task_id = %d ORDER BY id DESC LIMIT 21 OFFSET %d", $id, ( $event_page - 1 ) * 20 ) );
    $history_error = (bool) $wpdb->last_error;
    $more = count( $events ?: array() ) > 20;
    $events = array_slice( $events ?: array(), 0, 20 );
    $gallery = $history_error ? array() : crmb_task_note_gallery( $events );
    $detail = add_query_arg( 'task_id', $id, $base );
    // Unread count is across the entire history; the visible page has its own read receipts.
    $guidance_ready = function_exists( 'crmb_task_guidance_ready' ) && crmb_task_guidance_ready();
    $guidance_state = array( 'count' => 0, 'latest_id' => 0, 'error' => false );
    $guidance_reads = array();
    if ( $guidance_ready ) {
        $guidance_state = crmb_task_guidance_unread( $id, (int) $task->assigned_to );
        $guidance_reads = crmb_task_guidance_reads( $events, (int) $task->assigned_to );
    }
    $guidance_jump = $guidance_state['latest_id']
        ? add_query_arg(
            'history_page',
            crmb_task_guidance_history_page( $id, $guidance_state['latest_id'] ),
            $detail
        ) . '#crmb-task-guidance-' . (int) $guidance_state['latest_id']
        : '';
    $revision_ready = function_exists( 'crmb_task_revision_ready' ) && crmb_task_revision_ready();
    $revision_state = array( 'count' => 0, 'latest_id' => 0, 'latest_reason' => '', 'error' => true );
    $revision_reads = array();
    if ( $revision_ready ) {
        $revision_state = crmb_task_revision_unread( $id, (int) $task->assigned_to );
        $revision_reads = crmb_task_revision_reads( $events, (int) $task->assigned_to );
    }
    $revision_jump = $revision_state['latest_id']
        ? add_query_arg(
            'history_page',
            crmb_task_guidance_history_page( $id, $revision_state['latest_id'] ),
            $detail
        ) . '#crmb-task-revision-' . (int) $revision_state['latest_id']
        : '';
    ?>
    <?php if ( $owner && $revision_ready && ! $revision_state['error'] && $revision_state['count'] > 0 ) : ?>
    <section class="crmb-task-guidance-alert crmb-task-revision-alert" role="status" aria-label="درخواست‌های اصلاح خوانده‌نشده مدیر">
        <span class="crmb-task-guidance-alert-icon"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></span>
        <div class="crmb-task-guidance-alert-content">
            <strong><?php echo esc_html( number_format_i18n( $revision_state['count'] ) ); ?> درخواست اصلاح خوانده‌نشده از مدیر دارید</strong>
            <p>مدیر این کار را برای اصلاح برگردانده است. دلیل درخواست را مطالعه کنید و کنار آن «خواندم» را بزنید.</p>
            <?php if ( $revision_state['latest_reason'] ) : ?>
                <p class="crmb-task-revision-preview"><?php echo esc_html( $revision_state['latest_reason'] ); ?></p>
            <?php endif; ?>
        </div>
        <a class="crmb-task-guidance-alert-link" href="<?php echo esc_url( $revision_jump ); ?>">مشاهده درخواست اصلاح <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
    </section>
    <?php elseif ( $owner && ( ! $revision_ready || $revision_state['error'] ) ) : ?>
        <p class="crm-access-notice" role="status">وضعیت مطالعه درخواست‌های اصلاح در دسترس نیست؛ دلایل اصلاح همچنان در تاریخچه ثبت هستند.</p>
    <?php endif; ?>
    <?php if ( $owner && $guidance_ready && $guidance_state['count'] > 0 ) : ?>
    <section class="crmb-task-guidance-alert" role="status" aria-label="راهنمایی‌های خوانده‌نشده مدیر">
        <span class="crmb-task-guidance-alert-icon"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i></span>
        <div class="crmb-task-guidance-alert-content">
            <strong><?php echo esc_html( number_format_i18n( $guidance_state['count'] ) ); ?> راهنمایی خوانده‌نشده از مدیر دارید</strong>
            <p>راهنمایی‌های مدیر در تاریخچه همین کار ثبت شده‌اند. پس از مطالعه هر مورد، دکمه «خواندم» را بزنید.</p>
        </div>
        <a class="crmb-task-guidance-alert-link" href="<?php echo esc_url( $guidance_jump ); ?>">مشاهده راهنمایی <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
    </section>
    <?php elseif ( $owner && ( ! $guidance_ready || $guidance_state['error'] ) ) : ?>
        <p class="crm-access-notice" role="status">وضعیت مطالعه راهنمایی‌های مدیر فعلاً در دسترس نیست؛ متن راهنمایی‌ها همچنان در تاریخچه قابل مشاهده است.</p>
    <?php endif; ?>
    <?php if ( $under_review ) : ?>
    <section class="crm-access-card crmb-task-review-alert" role="status" aria-label="وضعیت تأیید نهایی کار">
        <h2><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> در انتظار تأیید مدیر</h2>
        <p><?php echo $owner && ! $admin ? 'کار شما برای بازبینی ارسال شده است. تا تأیید مدیر در بایگانی قرار نمی‌گیرد.' : 'این کار برای تصمیم نهایی آماده است؛ پس از مطالعه گزارش‌ها تأیید یا با دلیل برای اصلاح برگردانید.'; ?></p>
        <?php if ( $admin ) : ?>
            <div class="crmb-task-review-controls">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                    <?php crmb_progress_form_fields( 'review_task', $id ); ?>
                    <input type="hidden" name="decision" value="approve">
                    <label for="crmb-review-approve-reason">یادداشت تأیید (اختیاری)</label>
                    <textarea id="crmb-review-approve-reason" name="review_reason" maxlength="3000" rows="2" placeholder="نتیجه بازبینی را می‌توانید ثبت کنید."></textarea>
                    <button type="submit" class="crm-access-primary">تأیید نهایی و بایگانی کار</button>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                    <?php crmb_progress_form_fields( 'review_task', $id ); ?>
                    <input type="hidden" name="decision" value="reject">
                    <label for="crmb-review-reject-reason">دلیل برگشت برای اصلاح (الزامی)</label>
                    <textarea id="crmb-review-reject-reason" name="review_reason" maxlength="3000" rows="3" required minlength="3" placeholder="مشخص کنید چه مواردی باید اصلاح شود."></textarea>
                    <button type="submit" class="crmb-task-review-reject">برگرداندن برای اصلاح</button>
                </form>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="crm-access-card">
        <h2><?php echo esc_html( $task->title ); ?></h2>
        <p class="crmb-work-body"><?php echo esc_html( $task->description ); ?></p>
        <p>مسئول: <?php echo esc_html( crmb_work_user_name( $task->assigned_to ) ); ?> | وضعیت: <?php echo esc_html( crmb_work_statuses()[ $task->status ] ?? 'نامشخص' ); ?></p>
        <p class="crm-access-muted">تعداد دفعات برگشت برای اصلاح: <?php echo null === $review_revisions ? '—' : esc_html( (string) $review_revisions ); ?></p>
        <dl class="crmb-task-times">
            <div><dt>اولین شروع</dt><dd><?php echo esc_html( crmb_work_date( $task->first_started_utc ) ); ?></dd></div>
            <div><dt>پایان</dt><dd><?php echo esc_html( crmb_work_date( $task->completed_utc ) ); ?></dd></div>
            <div><dt>زمان کار ثبت‌شده</dt><dd dir="ltr"><strong id="crmb-task-timer" data-seconds="<?php echo esc_attr( crmb_progress_seconds( $task ) ); ?>" data-running="<?php echo $task->timer_started_utc ? '1' : '0'; ?>"><?php echo esc_html( crmb_progress_duration( crmb_progress_seconds( $task ) ) ); ?></strong></dd></div>
        </dl>
        <p class="crm-access-muted">ساعت‌ها تهران و زمان ثبت براساس سرور است. توقف موقت از مدت کار کم می‌شود. زمان ثبت‌شده با حضور و غیاب یا حقوق یکی نیست.</p>
        <?php if ( $owner && $is_open ) : ?>
            <div class="crmb-task-controls">
            <?php foreach ( $task->timer_started_utc ? array( 'paused' => 'توقف موقت', 'done' => 'ارسال برای تأیید مدیر' ) : array( 'doing' => 'شروع / ادامه کار', 'done' => 'ارسال برای تأیید مدیر' ) as $status => $label ) : ?>
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
                        'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,crmbtasktable,undo,redo',
                        'toolbar2' => 'removeformat,hr',
                        'block_formats' => 'پاراگراف=p;عنوان ۲=h2;عنوان ۳=h3;عنوان ۴=h4',
                    ),
                )
            ); ?>

            <label for="crmb-task-images">تصاویر گزارش پیشرفت</label>
            <input type="file" id="crmb-task-images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
            <p class="crm-access-muted crmb-task-paste-hint"><i class="fa-solid fa-paste" aria-hidden="true"></i> <strong>افزودن سریع اسکرین‌شات:</strong> تصویر را با Win + Shift + S بگیرید، داخل کادر «شرح پیشرفت» کلیک کنید و <kbd>Ctrl</kbd> + <kbd>V</kbd> بزنید. تصویر به پیوست‌های پایین اضافه می‌شود و نیازی به ذخیره دستی فایل نیست.</p>
            <p class="crm-access-muted">تا ۳ تصویر JPG، PNG یا WebP (هرکدام حداکثر ۱ مگابایت). تصاویر بزرگ چسبانده‌شده در صورت امکان کم‌حجم می‌شوند. تصاویر فقط برای افراد مجاز همین کار قابل مشاهده‌اند و همراه گزارش در تاریخچه نمایش داده می‌شوند.</p>
            <div id="crmb-task-image-feedback" class="crmb-task-image-feedback" role="status" aria-live="polite"></div>
            <div id="crmb-task-image-preview" class="crmb-task-image-preview" aria-label="تصاویر آماده ثبت"></div>
            <button type="submit" class="crm-access-primary">ثبت گزارش در تاریخچه</button>
        </form>
    </section>
    <?php endif; ?>
    <?php if ( ! $is_open ) : ?>
        <p class="crm-access-muted"><?php echo $under_review ? 'گزارش جدید تا تصمیم مدیر ثبت نمی‌شود؛ در صورت برگشت برای اصلاح، کار دوباره باز می‌شود.' : 'این کار تکمیل یا لغو شده است. تاریخچه محفوظ است و گزارش جدید تنها پس از بازگشایی کار ثبت می‌شود.'; ?></p>
    <?php endif; ?>
    <section class="crm-access-card"><h2>تاریخچه انجام کار</h2>
        <?php if ( $history_error ) : ?><p role="status">دریافت تاریخچه انجام نشد.</p><?php elseif ( ! $events ) : ?><p class="crm-access-muted">هنوز رویدادی ثبت نشده است. زمان‌های قبل از فعال‌شدن این قابلیت بازسازی نمی‌شوند.</p><?php endif; ?>
        <?php foreach ( $events as $event ) :
            $rich = in_array( $event->event_type, array( 'rich_note', 'rich_blocker', 'manager_note' ), true );
            $from_manager = 'manager_note' === $event->event_type;
            $from_revision = 'review_rejected' === $event->event_type;
            ?>
            <article class="crmb-work-item crmb-task-timeline-entry <?php echo $from_manager ? 'is-manager-note' : ''; ?><?php echo $from_revision ? ' is-revision-request' : ''; ?><?php echo $from_manager && $guidance_ready && ! isset( $guidance_reads[(int)$event->id] ) ? ' is-guidance-unread' : ''; ?><?php echo $from_revision && $revision_ready && ! isset( $revision_reads[(int)$event->id] ) ? ' is-revision-unread' : ''; ?>"
                <?php if ( $from_manager ) : ?>id="crmb-task-guidance-<?php echo esc_attr( $event->id ); ?>"<?php elseif ( $from_revision ) : ?>id="crmb-task-revision-<?php echo esc_attr( $event->id ); ?>"<?php endif; ?>>
                <div class="crmb-task-timeline-head">
                    <span class="crmb-task-timeline-icon">
                        <i class="fa-solid <?php echo $from_manager ? 'fa-lightbulb' : ( $from_revision ? 'fa-rotate-left' : ( $rich ? 'fa-file-lines' : 'fa-clock' ) ); ?>" aria-hidden="true"></i>
                    </span>
                    <div class="crmb-task-timeline-info">
                        <h3><?php echo esc_html( crmb_progress_event_labels()[ $event->event_type ] ?? 'تغییر وضعیت' ); ?></h3>
                        <p class="crm-access-muted"><?php echo esc_html( crmb_work_user_name( $event->actor_id ) . ' — ' . crmb_work_date( $event->created_utc ) ); ?></p>
                    </div>
                    <?php if ( $from_manager && $guidance_ready ) : ?>
                    <div class="crmb-task-guidance-read-action">
                        <?php if ( isset( $guidance_reads[(int)$event->id] ) ) : ?>
                            <span class="crmb-task-guidance-read-label is-read" title="تأیید مطالعه توسط مسئول فعلی کار">
                                <i class="fa-solid fa-check-circle" aria-hidden="true"></i> خوانده شد
                            </span>
                        <?php elseif ( $owner ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php crmb_progress_form_fields( 'ack_guidance', $id ); ?>
                                <input type="hidden" name="event_id" value="<?php echo esc_attr( $event->id ); ?>">
                                <button type="submit" class="crmb-task-guidance-ack">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i> خواندم
                                </button>
                            </form>
                        <?php elseif ( $admin ) : ?>
                            <span class="crmb-task-guidance-read-label is-pending">هنوز توسط مسئول خوانده نشده</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( $from_revision && $revision_ready ) : ?>
                    <div class="crmb-task-guidance-read-action crmb-task-revision-read-action">
                        <?php if ( isset( $revision_reads[(int) $event->id] ) ) : ?>
                            <span class="crmb-task-guidance-read-label is-read" title="تأیید مطالعه درخواست اصلاح توسط مسئول فعلی کار">
                                <i class="fa-solid fa-check-circle" aria-hidden="true"></i> خوانده شد
                            </span>
                        <?php elseif ( $owner ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php crmb_progress_form_fields( 'ack_revision', $id ); ?>
                                <input type="hidden" name="event_id" value="<?php echo esc_attr( $event->id ); ?>">
                                <button type="submit" class="crmb-task-guidance-ack">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i> خواندم
                                </button>
                            </form>
                        <?php elseif ( $admin ) : ?>
                            <span class="crmb-task-guidance-read-label is-pending">درخواست اصلاح هنوز توسط مسئول خوانده نشده</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
    <dialog id="crmb-task-image-modal" class="crmb-task-image-modal" aria-label="نمایش تصویر گزارش پیشرفت">
        <div class="crmb-task-image-modal-content">
            <button type="button" class="crmb-task-image-modal-close" aria-label="بستن تصویر"><i class="fa-solid fa-xmark" aria-hidden="true"></i> بستن</button>
            <img class="crmb-task-image-modal-photo" alt="تصویر گزارش پیشرفت">
        </div>
    </dialog>
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
    const modal = document.getElementById('crmb-task-image-modal');
    const modalImage = modal && modal.querySelector('.crmb-task-image-modal-photo');
    const closeButton = modal && modal.querySelector('.crmb-task-image-modal-close');
    let lastTrigger = null;
    if (modal && modalImage && closeButton && typeof modal.showModal === 'function') {
        document.querySelectorAll('.crmb-task-note-gallery [data-crmb-task-image]').forEach(link => {
            link.addEventListener('click', event => {
                event.preventDefault();
                lastTrigger = link;
                modalImage.src = link.href;
                modalImage.alt = link.querySelector('img')?.alt || 'تصویر گزارش پیشرفت';
                modal.showModal();
                closeButton.focus();
            });
        });
        closeButton.addEventListener('click', () => modal.close());
        modal.addEventListener('click', event => {
            if (event.target === modal) modal.close();
        });
        modal.addEventListener('close', () => {
            modalImage.removeAttribute('src');
            if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
        });
    }
})();
</script>
<?php
// Keep this feature scoped to authorized task-report pages and avoid a
// remote script/CDN dependency or caching conflicts with the CRM shell.
$paste_script = get_template_directory() . '/assets/js/crmb-task-image-paste.js';
if ( is_readable( $paste_script ) ) : ?>
<script id="crmb-task-image-paste-inline"><?php readfile( $paste_script ); ?></script>
<?php endif; ?>

