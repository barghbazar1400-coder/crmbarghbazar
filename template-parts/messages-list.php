<?php
/** Shared HTML for paginated private messages, including AJAX updates. */
defined('ABSPATH') || exit;
?>
    <section class="crm-access-card" id="crmb-messages-live-list" aria-live="off"><h2><?php echo $sent ? 'پیام‌های ارسالی' : 'صندوق دریافت'; ?></h2>
        <?php if ( ! $messages['ready'] ) : ?><p role="status">دریافت پیام‌ها انجام نشد؛ دوباره تلاش کنید.</p>
        <?php elseif ( ! $messages['rows'] ) : ?><p class="crm-access-muted">هنوز پیامی در این پوشه ندارید.</p>
        <?php else : foreach ( $messages['rows'] as $message ) : ?>
            <article class="crmb-work-item <?php echo ! $sent && ! $message->read_utc ? 'is-unread' : ''; ?>" id="message-<?php echo esc_attr( $message->id ); ?>">
                <h3><?php echo esc_html( $message->subject ); ?></h3>
                <p class="crm-access-muted"><?php echo $sent ? 'گیرنده: ' : 'فرستنده: '; ?><?php echo esc_html( crmb_work_user_name( $sent ? $message->recipient_id : $message->sender_id ) ); ?> | <?php echo esc_html( crmb_work_date( $message->created_utc ) ); ?></p>
                <p class="crmb-work-body"><?php echo esc_html( $message->body ); ?></p>
                <p><?php echo $message->read_utc ? esc_html( 'خوانده‌شده — ' . crmb_work_date( $message->read_utc ) ) : 'خوانده‌نشده'; ?></p>
                <?php if ( ! $sent && ! $message->read_utc ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php crmb_work_form_fields( 'read_message', $message->id ); ?><button class="crm-access-primary crmb-work-button" type="submit">خواندم</button></form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
            <nav class="crm-access-pages" aria-label="صفحات پیام‌ها"><?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'work_page', $page - 1, $base ) ); ?>">قبلی</a><?php endif; ?><span><?php echo esc_html( $page ); ?> / <?php echo esc_html( max( 1, ceil( $messages['total'] / 20 ) ) ); ?></span><?php if ( $page * 20 < $messages['total'] ) : ?><a href="<?php echo esc_url( add_query_arg( 'work_page', $page + 1, $base ) ); ?>">بعدی</a><?php endif; ?></nav>
        <?php endif; ?>
    </section>
