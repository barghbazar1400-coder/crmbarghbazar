<?php
/** Daily authoring and manager review. Drafts stay private to their author. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$base = add_query_arg( 'crm_section', 'reports', home_url( '/main/' ) );
$admin = current_user_can( 'manage_options' ); $uid = get_current_user_id();
$team = $admin && isset( $_GET['scope'] ) && 'team' === $_GET['scope'];
?>
<section class="crm-access-screen crmb-work crmb-reports" aria-labelledby="crmb-reports-title">
    <header class="crm-access-header"><h1 id="crmb-reports-title">گزارش روزانه</h1><p>نتیجه کار، موانع و برنامه بعدی؛ همراه با تصویر و متن قالب‌بندی‌شده</p></header>
    <?php crmb_progress_notice(); ?>
    <nav class="crm-access-tabs" aria-label="گزارش‌های روزانه"><a class="<?php echo $team ? '' : 'is-active'; ?>" href="<?php echo esc_url( $base ); ?>">گزارش‌های من</a><?php if ( $admin ) : ?><a class="<?php echo $team ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'scope', 'team', $base ) ); ?>">گزارش‌های تیم</a><?php endif; ?></nav>
    <?php if ( ! crmb_progress_ready() ) : ?><p role="status">بخش گزارش‌ها فعلاً در دسترس نیست؛ دوباره تلاش کنید.</p></section><?php return; endif;
    global $wpdb; $tables = crmb_progress_tables();
    $day_label = crmb_work_text( $_GET, 'day' );
    $day = '' !== $day_label ? crmb_leave_parse_day( $day_label ) : crmb_att_today();
    if ( ! $day ) { $day = crmb_att_today(); }
    $day_label = crmb_att_jdate( $day );
    $view_id = crmb_work_id( $_GET, 'report' );
    $report = $view_id ? crmb_report_get( $view_id ) : null;
    $lookup_failed = false;
    if ( ! $team && ! $view_id ) {
        $report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['reports']} WHERE user_id = %d AND report_day = %s", $uid, $day ) );
        $lookup_failed = (bool) $wpdb->last_error;
    }
    if ( $view_id && ! $report ) : ?><p role="status">گزارش در دسترس نیست یا مجوز مشاهده ندارید.</p><?php endif;
    $can_edit = ! $team && ! $lookup_failed && ( ! $report || ( (int) $report->user_id === $uid && 'draft' === $report->status ) ) && ! ( $view_id && ! $report );
    if ( $report ) { $day = $report->report_day; $day_label = crmb_att_jdate( $day ); }
    if ( ! $team && ! $view_id ) : ?>
        <form method="get" action="<?php echo esc_url( home_url( '/main/' ) ); ?>" class="crm-access-form crmb-work-inline"><input type="hidden" name="crm_section" value="reports"><label for="report-select-day">روز گزارش (شمسی)</label><input id="report-select-day" name="day" dir="ltr" value="<?php echo esc_attr( $day_label ); ?>"><button type="submit" class="crm-access-primary crmb-work-button">بازکردن روز</button></form>
    <?php endif; ?>
    <?php if ( $lookup_failed ) : ?><p role="status">دریافت گزارش انجام نشد. برای جلوگیری از بازنویسی، فرم فعلاً نمایش داده نمی‌شود.</p><?php endif; ?>
    <?php if ( $can_edit ) :
        $retry = get_transient( 'crmb_report_retry_' . $uid );
        $restore = is_array( $retry ) && ( $retry['day'] ?? '' ) === $day_label;
        $body = $restore ? $retry['body'] : ( $report ? $report->body : '' ); ?>
        <section class="crm-access-card"><h2>گزارش <?php echo esc_html( $day_label ); ?></h2>
            <?php if ( $restore ) : ?><p role="status">متن واردشده پس از خطا بازیابی شد. تصاویر جدید را دوباره انتخاب کنید.</p><?php endif; ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form" id="crmb-report-form">
                <?php crmb_progress_form_fields( 'save_report' ); ?>
                <input type="hidden" name="report_day" value="<?php echo esc_attr( $day_label ); ?>">
                <p class="crm-access-muted">هر روز یک گزارش؛ امروز یا حداکثر ۳۱ روز گذشته. پیش‌نویس فقط برای شماست. با ارسال نهایی، مدیر گزارش را می‌بیند و متن قفل می‌شود.</p>
                <label for="crmbreporteditor">شرح فعالیت و نتیجه</label>
                <?php wp_editor( $body, 'crmbreporteditor', array( 'textarea_name' => 'report_body', 'textarea_rows' => 14, 'media_buttons' => false, 'quicktags' => false, 'tinymce' => array( 'directionality' => 'rtl', 'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,undo,redo', 'toolbar2' => 'removeformat,hr', 'block_formats' => 'پاراگراف=p;عنوان ۲=h2;عنوان ۳=h3;عنوان ۴=h4' ) ) ); ?>
                <label for="report-blockers">موانع و نیاز به کمک</label><textarea id="report-blockers" name="blockers" rows="3" maxlength="3000"><?php echo esc_textarea( $restore ? $retry['blockers'] : ( $report ? $report->blockers : '' ) ); ?></textarea>
                <label for="report-plan">برنامه روز بعد</label><textarea id="report-plan" name="next_plan" rows="3" maxlength="3000"><?php echo esc_textarea( $restore ? $retry['next_plan'] : ( $report ? $report->next_plan : '' ) ); ?></textarea>
                <label for="report-images">تصاویر گزارش</label><input id="report-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple><p class="crm-access-muted">مجموعاً حداکثر ۳ تصویر، هر تصویر تا ۱ مگابایت. عکس‌ها پیوست خصوصی گزارش هستند و پایین متن نمایش داده می‌شوند.</p>
                <div class="crmb-task-controls"><button type="submit" name="mode" value="draft" class="crm-access-primary crmb-work-button">ذخیره پیش‌نویس</button><button type="submit" name="mode" value="submit" class="crm-access-primary crmb-work-button" onclick="return confirm('گزارش نهایی ارسال و برای ویرایش قفل شود؟');">ارسال نهایی برای مدیر</button></div>
            </form>
            <?php if ( $report ) : ?><div class="crmb-report-gallery"><?php crmb_report_image_list( $report ); ?></div><?php endif; ?>
        </section>
    <?php elseif ( $report ) : ?>
        <section class="crm-access-card"><h2>گزارش <?php echo esc_html( crmb_work_user_name( $report->user_id ) . ' — ' . $day_label ); ?></h2>
            <p>وضعیت: <?php echo 'reviewed' === $report->status ? 'بررسی‌شده' : ( 'draft' === $report->status ? 'پیش‌نویس' : 'ارسال‌شده' ); ?> | آخرین تغییر: <?php echo esc_html( crmb_work_date( $report->updated_utc ) ); ?></p>
            <div class="crmb-report-content"><?php echo crmb_report_html( wpautop( $report->body ) ); // Already restricted HTML, sanitized again on output. ?></div>
            <h3>موانع و نیاز به کمک</h3><p class="crmb-work-body"><?php echo esc_html( $report->blockers ?: 'ثبت نشده' ); ?></p>
            <h3>برنامه روز بعد</h3><p class="crmb-work-body"><?php echo esc_html( $report->next_plan ?: 'ثبت نشده' ); ?></p>
            <div class="crmb-report-gallery"><?php crmb_report_image_list( $report ); ?></div>
            <?php if ( $report->reviewed_utc ) : ?><p class="crm-access-muted">بررسی توسط <?php echo esc_html( crmb_work_user_name( $report->reviewed_by ) . ' — ' . crmb_work_date( $report->reviewed_utc ) ); ?></p><?php endif; ?>
            <?php if ( $admin && 'submitted' === $report->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php crmb_progress_form_fields( 'review_report', $report->id ); ?><button class="crm-access-primary crmb-work-button" type="submit">بررسی کردم</button></form><?php endif; ?>
        </section>
    <?php endif;
    $page = isset( $_GET['report_page'] ) && is_scalar( $_GET['report_page'] ) ? max( 1, min( 100000, absint( $_GET['report_page'] ) ) ) : 1;
    $where = $team ? "status <> 'draft'" : $wpdb->prepare( 'user_id = %d', $uid );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,user_id,report_day,status FROM {$tables['reports']} WHERE {$where} ORDER BY report_day DESC,id DESC LIMIT 21 OFFSET %d", ( $page - 1 ) * 20 ) );
    $list_error = (bool) $wpdb->last_error; $more = count( $rows ?: array() ) > 20; $rows = array_slice( $rows ?: array(), 0, 20 );
    $list_base = $team ? add_query_arg( 'scope', 'team', $base ) : $base; ?>
    <section class="crm-access-card"><h2><?php echo $team ? 'گزارش‌های ارسالی تیم' : 'سوابق گزارش‌های من'; ?></h2>
        <?php if ( $list_error ) : ?><p role="status">دریافت فهرست انجام نشد.</p><?php elseif ( ! $rows ) : ?><p class="crm-access-muted">هنوز گزارشی برای نمایش وجود ندارد.</p><?php endif; ?>
        <div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>روز</th><th>کارمند</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>
        <?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( crmb_att_jdate( $row->report_day ) ); ?></td><td><?php echo esc_html( crmb_work_user_name( $row->user_id ) ); ?></td><td><?php echo esc_html( array( 'draft' => 'پیش‌نویس', 'submitted' => 'ارسال‌شده', 'reviewed' => 'بررسی‌شده' )[$row->status] ?? 'نامشخص' ); ?></td><td><a href="<?php echo esc_url( add_query_arg( 'report', $row->id, $list_base ) ); ?>">مشاهده</a></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <nav class="crm-access-pages" aria-label="صفحات گزارش‌ها"><?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'report_page', $page - 1, $list_base ) ); ?>">قبلی</a><?php endif; ?><?php if ( $more ) : ?><a href="<?php echo esc_url( add_query_arg( 'report_page', $page + 1, $list_base ) ); ?>">بعدی</a><?php endif; ?></nav>
    </section>
</section>
