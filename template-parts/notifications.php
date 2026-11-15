<?php
/** Personal notifications and manager-issued announcements. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$items = crmb_notice_list();
$types = array(
    'message' => 'پیام داخلی', 'task' => 'وظیفه', 'manager' => 'راهنمایی مدیر',
    'task_done' => 'تکمیل وظیفه', 'announcement' => 'اطلاعیه مدیریت',
);
$result = isset( $_GET['notice_result'] ) ? sanitize_key( $_GET['notice_result'] ) : '';
$setup = isset( $_GET['push_setup'] ) ? sanitize_key( $_GET['push_setup'] ) : '';
?>
<section class="crm-access-screen crmb-notifications-page" aria-labelledby="crmb-notices-heading">
    <header class="crm-access-header"><h1 id="crmb-notices-heading">اعلان‌ها و اطلاعیه‌ها</h1>
        <p>پیام‌های جدید، راهنمایی مدیر، تغییرات وظایف و اطلاعیه‌های سازمانی</p>
    </header>
    <?php if ( $result ) : ?>
        <p class="crm-access-notice" role="status"><?php echo esc_html( array(
            'saved' => 'اطلاعیه برای مخاطبان ثبت شد.', 'read' => 'اعلان خوانده شد.',
            'empty' => 'مخاطبی برای اطلاعیه پیدا نشد.', 'invalid' => 'اطلاعات اطلاعیه معتبر نیست.',
            'denied' => 'اجازه انجام این کار را ندارید.', 'error' => 'ثبت انجام نشد.',
        )[ $result ] ?? 'نتیجه عملیات مشخص نیست.' ); ?></p>
    <?php endif; ?>
    <?php if ( $setup ) : ?>
        <p class="crm-access-notice" role="status"><?php echo esc_html( array(
            'enabled' => 'کلیدهای ارسال پوش روی سرور ساخته و فعال شدند.',
            'already' => 'ارسال پوش قبلاً فعال شده است.',
            'unavailable' => 'ابتدا کتابخانه ارسال پوش را روی هاست نصب کنید.',
            'error' => 'ایجاد کلیدهای پوش ناموفق بود.',
        )[ $setup ] ?? 'نتیجه راه‌اندازی مشخص نیست.' ); ?></p>
    <?php endif; ?>

    <section class="crm-access-card crmb-push-panel" aria-labelledby="crmb-push-heading">
        <div class="crmb-task-note-heading">
            <span class="crmb-task-note-heading-icon"><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
            <div><h2 id="crmb-push-heading">اعلان روی کامپیوتر</h2>
                <p>با اجازه خودتان، پیام‌های جدید حتی هنگام بسته بودن تب CRM از طریق مرورگر نمایش داده می‌شوند.</p>
            </div>
        </div>
        <p class="crm-access-muted" id="crmb-push-status" role="status">در حال بررسی پشتیبانی مرورگر...</p>
        <div class="crmb-notices-actions">
            <button type="button" class="crm-access-primary" id="crmb-push-enable">فعال‌سازی پوش روی این سیستم</button>
            <button type="button" class="crmb-notices-secondary" id="crmb-push-disable" hidden>غیرفعال‌سازی روی این سیستم</button>
        </div>
        <p class="crm-access-muted">نمایش اعلان به مجوز مرورگر، HTTPS و سرویس پوش فعال وابسته است. متن خصوصی پیام در اعلان دسکتاپ نشان داده نمی‌شود.</p>
        <?php if ( current_user_can( 'manage_options' ) && ! crmb_push_public_key() ) : ?>
            <p class="crm-access-muted">راه‌اندازی سرور: ابتدا وابستگی Composer نصب شود، سپس کلیدهای VAPID از همین صفحه ایجاد شوند.</p>
            <?php if ( crmb_push_library() ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="crmb_push_setup">
                    <?php wp_nonce_field( 'crmb_push_setup', 'crmb_push_setup_nonce' ); ?>
                    <button type="submit" class="crmb-notices-secondary">ایجاد کلیدهای پوش سرور</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <section class="crm-access-card" aria-labelledby="crmb-announce-heading">
            <h2 id="crmb-announce-heading">انتشار اطلاعیه مدیریتی</h2>
            <p class="crm-access-muted">اطلاعیه را برای همه کارکنان، یک نفر یا اعضای یک واحد سازمانی ارسال کنید.</p>
            <form class="crm-access-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="crmb_notices">
                <input type="hidden" name="mode" value="announcement">
                <?php wp_nonce_field( 'crmb_notices', 'crmb_notice_nonce' ); ?>
                <label for="crmb-notice-title">عنوان اطلاعیه</label>
                <input id="crmb-notice-title" name="title" maxlength="160" required>
                <label for="crmb-notice-body">متن اطلاعیه</label>
                <textarea id="crmb-notice-body" name="body" rows="4" maxlength="2000" required></textarea>
                <label for="crmb-notice-scope">مخاطب</label>
                <select id="crmb-notice-scope" name="scope">
                    <option value="all">تمام کارکنان</option>
                    <option value="user">یک کارمند</option>
                    <option value="department">یک واحد سازمانی</option>
                </select>
                <div id="crmb-notice-person" hidden>
                    <label for="crmb-notice-user">انتخاب کارمند</label>
                    <select id="crmb-notice-user" name="target_user" disabled>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) : ?>
                            <option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->display_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="crmb-notice-department" hidden>
                    <label for="crmb-notice-dept">انتخاب واحد</label>
                    <select id="crmb-notice-dept" name="target_department" disabled>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ( get_terms( array( 'taxonomy' => 'crmb_department', 'hide_empty' => false ) ) ?: array() as $department ) : ?>
                            <?php if ( is_wp_error( $department ) ) { continue; } ?>
                            <option value="<?php echo esc_attr( $department->term_id ); ?>"><?php echo esc_html( $department->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="crm-access-primary">انتشار اطلاعیه</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="crm-access-card">
        <div class="crmb-notices-title-row"><h2>اعلان‌های من</h2>
            <?php if ( crmb_notice_count() ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="crmb_notices">
                    <input type="hidden" name="mode" value="read_all">
                    <?php wp_nonce_field( 'crmb_notices', 'crmb_notice_nonce' ); ?>
                    <button type="submit" class="crmb-notices-secondary">همه را خواندم</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ( ! crmb_notice_ready() ) : ?>
            <p role="status">جدول اعلان‌ها آماده نیست. وضعیت پایگاه‌داده را بررسی کنید.</p>
        <?php elseif ( ! $items ) : ?>
            <p class="crm-access-muted">هنوز اعلانی ندارید.</p>
        <?php else : foreach ( $items as $item ) : ?>
            <article class="crmb-notice-entry <?php echo $item->read_utc ? '' : 'is-unread'; ?>">
                <div class="crmb-notice-entry-main">
                    <span class="crmb-notice-kind"><?php echo esc_html( $types[ $item->kind ] ?? 'اعلان' ); ?></span>
                    <h3><?php echo esc_html( $item->title ); ?></h3>
                    <?php if ( $item->summary ) : ?><p><?php echo nl2br( esc_html( $item->summary ) ); ?></p><?php endif; ?>
                    <?php if ( isset( $item->detail ) && $item->detail && 'announcement' === $item->kind ) : ?>
                        <p class="crmb-notice-detail"><?php echo nl2br( esc_html( $item->detail ) ); ?></p>
                    <?php endif; ?>
                    <small class="crm-access-muted"><?php echo esc_html( crmb_work_date( $item->created_utc ) ); ?></small>
                </div>
                <div class="crmb-notice-entry-actions">
                    <?php if ( 'announcement' !== $item->kind ) : ?>
                        <a href="<?php echo esc_url( $item->href ); ?>">مشاهده</a>
                    <?php endif; ?>
                    <?php if ( ! $item->read_utc ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="crmb_notices">
                            <input type="hidden" name="mode" value="read">
                            <input type="hidden" name="id" value="<?php echo esc_attr( $item->id ); ?>">
                            <?php wp_nonce_field( 'crmb_notices', 'crmb_notice_nonce' ); ?>
                            <button type="submit" class="crmb-notices-secondary">خواندم</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; endif; ?>
    </section>
</section>
<script>
(function(){
    var scope = document.getElementById('crmb-notice-scope');
    var person = document.getElementById('crmb-notice-person');
    var dept = document.getElementById('crmb-notice-department');
    if (!scope) return;
    function toggle() {
        var personal = scope.value === 'user', department = scope.value === 'department';
        person.hidden = !personal;
        dept.hidden = !department;
        document.getElementById('crmb-notice-user').disabled = !personal;
        document.getElementById('crmb-notice-dept').disabled = !department;
    }
    scope.addEventListener('change', toggle);
    toggle();
})();
</script>
