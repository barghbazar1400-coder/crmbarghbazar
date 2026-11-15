<?php
/**
 * Telegram-style two-way messaging with lazy-loaded 30-message histories.
 * No private message content is embedded in this document: only authorized
 * AJAX requests fetch one thread at a time.
 */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }
$contacts = crmb_chat_contacts();
$selected = isset( $_GET['peer'] ) ? crmb_work_id( $_GET, 'peer' ) : 0;
if ( ! $selected && isset( $_GET['message'] ) ) {
    $selected = crmb_chat_peer_from_message( crmb_work_id( $_GET, 'message' ) );
}
// Do not automatically select a coworker or expose arbitrary user IDs in URLs.
if ( ! $selected || ! crmb_chat_has_history( $selected ) ) {
    $selected = 0;
}
$selected_name = $selected ? crmb_work_user_name( $selected ) : '';
$members_by_id = array();
foreach ( $contacts as $contact ) { $members_by_id[ (int) $contact['id'] ] = $contact; }
if ( $selected && ! isset( $members_by_id[ $selected ] ) ) {
    $old_user = get_userdata( $selected );
    $members_by_id[ $selected ] = array( 'id' => $selected, 'name' => $selected_name,
        'username' => $old_user ? $old_user->user_login : '', 'unread' => 0, 'last_id' => 1 );
    array_unshift( $contacts, $members_by_id[ $selected ] );
}
?>
<section class="crmb-messenger" id="crmb-messenger"
    data-selected-peer="<?php echo esc_attr( $selected ); ?>"
    data-my-user="<?php echo esc_attr( get_current_user_id() ); ?>"
    aria-label="پیام‌رسان داخلی CRM">
    <div class="crmb-messenger-layout">
        <aside class="crmb-messenger-sidebar" id="crmb-chat-sidebar" aria-label="گفتگوهای همکاران">
            <div class="crmb-messenger-sidebar-header">
                <span class="crmb-messenger-sidebar-icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                <div>
                    <h1>پیام‌ها</h1>
                    <p>فقط گفتگوهای قبلی شما</p>
                </div>
                <span class="crmb-messenger-contact-total"><?php echo esc_html( number_format_i18n( count( $contacts ) ) ); ?></span>
            </div>
            <div class="crmb-messenger-search">
                <label class="screen-reader-text" for="crmb-messenger-search-input">جست‌وجوی گفتگو یا نام کاربری دقیق با @</label>
                <i class="fa-solid fa-at" aria-hidden="true"></i>
                <input id="crmb-messenger-search-input" type="search" placeholder="جست‌وجو یا @username" autocomplete="off" dir="auto">
                <button type="button" id="crmb-chat-find" aria-label="پیدا کردن نام کاربری دقیق">پیدا کن</button>
            </div>
            <p class="crmb-messenger-search-help">شروع گفتگو فقط با نام کاربری دقیق مثل <code>@username</code></p>
            <div id="crmb-chat-lookup-result" class="crmb-chat-lookup-result" role="status" aria-live="polite" hidden></div>
            <div class="crmb-messenger-contact-list" id="crmb-messenger-contacts" role="list">
                <?php foreach ( $contacts as $contact ) :
                    $person_id = (int) $contact['id'];
                    $label = (string) $contact['name'];
                    $unread = (int) $contact['unread'];
                ?>
                    <button type="button" role="listitem"
                        class="crmb-messenger-contact <?php echo $selected === $person_id ? 'is-active' : ''; ?>"
                        data-peer="<?php echo esc_attr( $person_id ); ?>"
                        data-name="<?php echo esc_attr( $label ); ?>"
                        aria-current="<?php echo $selected === $person_id ? 'true' : 'false'; ?>">
                        <span class="crmb-messenger-person-avatar" aria-hidden="true"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                        <span class="crmb-messenger-contact-details">
                            <strong><?php echo esc_html( $label ); ?></strong>
                            <small><?php echo $unread ? 'پیام جدید دارید' : esc_html( '@' . ( $contact['username'] ?? '' ) ); ?></small>
                        </span>
                        <?php if ( $unread ) : ?>
                            <span class="crmb-messenger-contact-unread"><?php echo esc_html( min( 99, $unread ) ); ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if ( ! $contacts ) : ?>
                <div class="crmb-messenger-list-empty" id="crmb-chat-list-empty">
                    <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
                    <strong>هنوز گفتگویی ندارید</strong>
                    <p>نام کاربری طرف مقابل را با @ وارد و اولین پیام را ارسال کنید.</p>
                </div>
            <?php endif; ?>
            <div class="crmb-messenger-sidebar-foot">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                پیام‌ها فقط برای شرکت‌کنندگان گفتگو قابل مشاهده هستند.
            </div>
        </aside>

        <div class="crmb-messenger-conversation" aria-label="پنجره گفتگو">
            <header class="crmb-messenger-conversation-header">
                <button type="button" class="crmb-messenger-back" id="crmb-chat-back" aria-label="بازگشت به فهرست گفتگوها">
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
                <span class="crmb-messenger-thread-avatar" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                <div class="crmb-messenger-thread-person">
                    <strong id="crmb-messenger-partner"><?php echo esc_html( $selected_name ?: 'یک همکار را انتخاب کنید' ); ?></strong>
                    <span>گفتگوی داخلی برق‌بازار</span>
                </div>
                <span class="crmb-messenger-private-pill"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> خصوصی</span>
            </header>
            <div class="crmb-messenger-history" id="crmb-messenger-history" tabindex="0"
                role="log" aria-live="off" aria-label="سابقه پیام‌ها">
                <div class="crmb-messenger-load-older" id="crmb-chat-older" hidden>
                    <button type="button" id="crmb-chat-older-button"><i class="fa-solid fa-angles-up" aria-hidden="true"></i> پیام‌های قدیمی‌تر</button>
                </div>
                <div class="crmb-messenger-bubbles" id="crmb-chat-bubbles"></div>
                <div class="crmb-messenger-empty" id="crmb-chat-empty">
                    <span><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></span>
                    <strong><?php echo $selected ? 'در حال بارگذاری گفتگو…' : 'برای شروع، @username را جست‌وجو کنید'; ?></strong>
                    <p>تنها گفتگوهایی که پیام ردوبدل کرده‌اید در فهرست می‌مانند؛ پیام‌های قدیمی‌تر هر بار ۳۰تایی بارگذاری می‌شوند.</p>
                </div>
            </div>
            <button type="button" class="crmb-messenger-jump" id="crmb-chat-jump" hidden>
                <i class="fa-solid fa-arrow-down" aria-hidden="true"></i> جدیدترین پیام‌ها
            </button>
            <div class="crmb-chat-drop-overlay" id="crmb-chat-drop-overlay" hidden aria-hidden="true">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <strong>فایل یا تصویر را اینجا رها کنید</strong>
                <span>حداکثر ۳ فایل در هر پیام</span>
            </div>
            <form class="crmb-messenger-composer" id="crmb-chat-compose" autocomplete="off" novalidate enctype="multipart/form-data">
                <div class="crmb-chat-attachment-tray" id="crmb-chat-attachment-tray" hidden aria-live="polite"></div>
                <div class="crmb-messenger-compose-row">
                    <label class="screen-reader-text" for="crmb-chat-input">نوشتن پیام</label>
                    <textarea id="crmb-chat-input" name="message" rows="1" maxlength="3000"
                        placeholder="پیام خود را بنویسید… (Enter برای ارسال)" <?php echo $selected ? '' : 'disabled'; ?>></textarea>
                    <button type="button" class="crmb-chat-attach-button" id="crmb-chat-attach-label"
                        title="انتخاب فایل از ویندوز" aria-label="پیوست فایل یا تصویر" aria-controls="crmb-chat-attach-input"
                        <?php echo $selected ? '' : 'disabled'; ?>>
                        <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" focusable="false" aria-hidden="true">
                            <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49L14.37 1.14a4 4 0 0 1 5.66 5.66L9.41 17.41a2 2 0 0 1-2.83-2.83l9.9-9.9"></path>
                        </svg>
                    </button>
                    <input type="file" id="crmb-chat-attach-input" name="attachments[]" multiple
                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.docx,.xlsx,.pptx"
                        class="crmb-chat-file-hidden" aria-label="فایل‌های پیوست گفتگو"
                        <?php echo $selected ? '' : 'disabled'; ?>>
                    <button type="submit" id="crmb-chat-send" <?php echo $selected ? '' : 'disabled'; ?> aria-label="ارسال پیام">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span>ارسال</span>
                    </button>
                </div>
                <p class="crmb-chat-attachment-help" id="crmb-chat-attachment-help">
                    <i class="fa-solid fa-image" aria-hidden="true"></i>
                    تصویر را با Ctrl+V بچسبانید، در چت رها کنید یا با گیره انتخاب کنید · هر فایل حداکثر ۸ مگابایت
                </p>
            </form>
            <div class="crmb-messenger-status" id="crmb-chat-status" role="status" aria-live="polite"></div>
        </div>
    </div>
</section>
<?php
$chat_script = get_template_directory() . '/assets/js/crmb-chat.js';
if ( is_readable( $chat_script ) ) :
    $chat_config = array(
        'ajax' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'crmb_chat' ),
        'baseUrl' => add_query_arg( 'crm_section', 'messages', home_url( '/main/' ) ),
    );
?>
<script>window.crmbChatConfig = <?php echo wp_json_encode( $chat_config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); ?>;</script>
<script id="crmb-chat-inline"><?php readfile( $chat_script ); ?></script>
<?php endif; ?>
