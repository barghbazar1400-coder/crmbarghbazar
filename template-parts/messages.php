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
if ( ! crmb_chat_peer( $selected ) ) {
    $selected = count( $contacts ) ? (int) $contacts[0]['id'] : 0;
}
$selected_name = $selected ? crmb_work_user_name( $selected ) : '';
$members_by_id = array();
foreach ( $contacts as $contact ) { $members_by_id[ (int) $contact['id'] ] = $contact; }
if ( $selected && ! isset( $members_by_id[ $selected ] ) ) {
    $members_by_id[ $selected ] = array( 'id' => $selected, 'name' => $selected_name, 'unread' => 0, 'last_id' => 0 );
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
                    <p>گفتگوهای داخلی تیم</p>
                </div>
                <span class="crmb-messenger-contact-total"><?php echo esc_html( number_format_i18n( count( $contacts ) ) ); ?></span>
            </div>
            <div class="crmb-messenger-search">
                <label class="screen-reader-text" for="crmb-messenger-search-input">جست‌وجوی همکار</label>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input id="crmb-messenger-search-input" type="search" placeholder="جست‌وجوی همکار..." autocomplete="off">
            </div>
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
                            <small><?php echo $unread ? 'پیام جدید دارید' : ( $contact['last_id'] ? 'گفتگوی قبلی' : 'شروع گفتگو' ); ?></small>
                        </span>
                        <?php if ( $unread ) : ?>
                            <span class="crmb-messenger-contact-unread"><?php echo esc_html( min( 99, $unread ) ); ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
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
                    <strong><?php echo $selected ? 'در حال بارگذاری گفتگو…' : 'یک گفتگو را انتخاب کنید'; ?></strong>
                    <p>آخرین ۳۰ پیام نمایش داده می‌شوند. برای دیدن پیام‌های قدیمی‌تر به بالا اسکرول کنید.</p>
                </div>
            </div>
            <button type="button" class="crmb-messenger-jump" id="crmb-chat-jump" hidden>
                <i class="fa-solid fa-arrow-down" aria-hidden="true"></i> جدیدترین پیام‌ها
            </button>
            <form class="crmb-messenger-composer" id="crmb-chat-compose" autocomplete="off" novalidate>
                <label class="screen-reader-text" for="crmb-chat-input">نوشتن پیام</label>
                <textarea id="crmb-chat-input" name="message" rows="1" maxlength="3000"
                    placeholder="پیام خود را بنویسید… (Enter برای ارسال)" <?php echo $selected ? '' : 'disabled'; ?>></textarea>
                <button type="submit" id="crmb-chat-send" <?php echo $selected ? '' : 'disabled'; ?> aria-label="ارسال پیام">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span>ارسال</span>
                </button>
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
