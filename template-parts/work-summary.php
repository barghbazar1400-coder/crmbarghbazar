<?php
/** Current user's active work and unread messages only. */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$my_tasks = crmb_work_list( 'tasks', 1, 5, false, false, true );
$my_messages = crmb_work_list( 'messages', 1, 5, false, false, true );
$task_url = add_query_arg( 'crm_section', 'tasks', home_url( '/main/' ) );
$message_url = add_query_arg( 'crm_section', 'messages', home_url( '/main/' ) );
?>
<div class="crmb-dashboard-grid">
    <section class="crm-access-card"><h2>وظایف من</h2>
        <?php if ( ! $my_tasks['ready'] ) : ?><p role="status">دریافت وظایف انجام نشد.</p>
        <?php else : ?><p><strong class="crmb-dashboard-number"><?php echo esc_html( $my_tasks['total'] ); ?></strong>وظیفه باز</p>
            <?php if ( ! $my_tasks['rows'] ) : ?><p class="crm-access-muted">هم‌اکنون وظیفه بازی ندارید.</p><?php endif; ?>
            <ul class="crmb-work-summary"><?php foreach ( $my_tasks['rows'] as $item ) : ?><li><a href="<?php echo esc_url( $task_url . '#task-' . $item->id ); ?>"><?php echo esc_html( $item->title ); ?></a><p class="crm-access-muted"><?php echo esc_html( crmb_work_statuses()[ $item->status ] ?? 'نامشخص' ); ?> | <?php echo esc_html( crmb_work_priorities()[ $item->priority ] ?? 'عادی' ); ?> | <?php echo esc_html( $item->due_day ? crmb_att_jdate( $item->due_day ) : 'بدون مهلت' ); ?><?php if ( $item->due_day && $item->due_day < crmb_att_today() ) : ?> <strong class="crmb-work-overdue">مهلت گذشته</strong><?php endif; ?></p></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $task_url ); ?>">مشاهده همه وظایف</a>
    </section>
    <section class="crm-access-card"><h2>پیام‌های خوانده‌نشده</h2>
        <?php if ( ! $my_messages['ready'] ) : ?><p role="status">دریافت پیام‌ها انجام نشد.</p>
        <?php else : ?><p><strong class="crmb-dashboard-number"><?php echo esc_html( $my_messages['total'] ); ?></strong>پیام خوانده‌نشده</p>
            <?php if ( ! $my_messages['rows'] ) : ?><p class="crm-access-muted">پیام خوانده‌نشده‌ای ندارید.</p><?php endif; ?>
            <ul class="crmb-work-summary"><?php foreach ( $my_messages['rows'] as $item ) : ?><li><a href="<?php echo esc_url( add_query_arg( 'message', $item->id, $message_url ) . '#message-' . $item->id ); ?>"><?php echo esc_html( $item->subject ); ?></a><p class="crm-access-muted"><?php echo esc_html( crmb_work_user_name( $item->sender_id ) . ' — ' . crmb_work_date( $item->created_utc ) ); ?></p></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <a class="crm-access-primary crmb-dashboard-link" href="<?php echo esc_url( $message_url ); ?>">ورود به پیام‌ها</a>
    </section>
</div>
