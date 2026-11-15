<?php
/** Internal messages; even administrators only see their own inbox and sent items. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$sent = isset( $_GET['box'] ) && 'sent' === $_GET['box'];
$page = isset( $_GET['work_page'] ) && is_scalar( $_GET['work_page'] ) ? max( 1, absint( $_GET['work_page'] ) ) : 1;
$focus = crmb_work_id( $_GET, 'message' );
$messages = crmb_work_list( 'messages', $page, 20, false, $sent, false, $focus );
$base = add_query_arg( array( 'crm_section' => 'messages', 'box' => $sent ? 'sent' : 'inbox' ), home_url( '/main/' ) );
?>
<section class="crm-access-screen crmb-work" aria-labelledby="crmb-messages-title">
    <header class="crm-access-header"><h1 id="crmb-messages-title">پیام‌های داخلی</h1><p>ارسال پیام به همکاران و پیگیری خوانده‌شدن پیام‌های ارسالی</p></header>
    <?php crmb_work_notice(); ?>
    <?php if ( $messages['ready'] ) : ?>
        <section class="crm-access-card"><h2>ارسال پیام جدید</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <?php crmb_work_form_fields( 'send_message' ); ?>
                <label for="message-recipient">گیرنده</label><select id="message-recipient" name="recipient_id" required><option value="">انتخاب همکار</option><?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $member ) : if ( (int) $member->ID === get_current_user_id() ) { continue; } ?><option value="<?php echo esc_attr( $member->ID ); ?>"><?php echo esc_html( $member->display_name ); ?></option><?php endforeach; ?></select>
                <label for="message-subject">موضوع</label><input id="message-subject" name="subject" maxlength="60" required>
                <label for="message-body">متن پیام</label><textarea id="message-body" name="body" rows="5" maxlength="3000" required></textarea>
                <button class="crm-access-primary" type="submit">ارسال پیام</button>
            </form>
        </section>
    <?php endif; ?>
    <nav class="crm-access-tabs" aria-label="پوشه پیام‌ها"><a class="<?php echo $sent ? '' : 'is-active'; ?>" href="<?php echo esc_url( add_query_arg( 'box', 'inbox', $base ) ); ?>">دریافتی</a><a class="<?php echo $sent ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'box', 'sent', $base ) ); ?>">ارسالی</a></nav>
    <?php include __DIR__ . '/messages-list.php'; ?>
</section>
