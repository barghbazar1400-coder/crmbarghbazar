<?php
/**
 * Private CRM main screen.
 *
 * @package CRMBarghBazar
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

$crm_user = wp_get_current_user();

$crm_full_name = trim(
    (string) $crm_user->first_name . ' ' . (string) $crm_user->last_name
);
if ( '' === $crm_full_name ) {
    $crm_full_name = trim( (string) $crm_user->display_name );
}
if ( '' === $crm_full_name ) {
    $crm_full_name = (string) $crm_user->user_login;
}

$crm_role = 'کاربر';
if ( ! empty( $crm_user->roles ) ) {
    $crm_role_slug = reset( $crm_user->roles );
    $crm_roles     = wp_roles();
    if ( is_string( $crm_role_slug ) && isset( $crm_roles->roles[ $crm_role_slug ]['name'] ) ) {
        $crm_role = translate_user_role( $crm_roles->roles[ $crm_role_slug ]['name'] );
    }
}
?>
<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <?php
    // Keep the layout styled even if the public stylesheet URL is unavailable.
    $crmbarghbazar_css_file = get_stylesheet_directory() . '/style.css';
    if ( is_readable( $crmbarghbazar_css_file ) ) :
        ?>
        <style id="crmbarghbazar-local-css">
            <?php readfile( $crmbarghbazar_css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Trusted theme stylesheet. ?>
        </style>
    <?php endif; ?>
</head>
<body <?php body_class( 'crm-main-page' ); ?>>
<?php wp_body_open(); ?>
<div class="crm-main-layout">
    <aside class="crm-main-sidebar" aria-label="نوار کناری CRM">
        <div class="crm-main-profile">
            <div class="crm-main-avatar">
                <?php
                echo get_avatar(
                    $crm_user->ID,
                    112,
                    '',
                    $crm_full_name,
                    array( 'class' => 'crm-main-avatar-image' )
                );
                ?>
            </div>
            <div class="crm-main-profile-text">
                <div class="crm-main-user-name"><?php echo esc_html( $crm_full_name ); ?></div>
                <div class="crm-main-user-role"><?php echo esc_html( $crm_role ); ?></div>
            </div>
        </div>

        <nav class="crm-main-navigation" aria-label="گزینه‌های CRM">
            <?php
            $crm_current_section = isset( $_GET['crm_section'] ) && is_string( $_GET['crm_section'] )
                ? sanitize_key( wp_unslash( $_GET['crm_section'] ) ) : '';
            if ( '' === $crm_current_section ) {
                // Old clock endpoints return to /main/ with a result message.
                $crm_current_section = isset( $_GET['clock_notice'] ) || isset( $_GET['leave_notice'] ) ? 'clock' : 'dashboard';
            }
            // Preserve old bookmarks without exposing another sidebar item.
            if ( 'access' === $crm_current_section ) {
                $crm_current_section = 'departments';
                if ( ! isset( $_GET['tab'] ) ) { $_GET['tab'] = 'roles'; }
            }
            // Group only implemented, permitted pages. Unimplemented modules never
            // appear as dead links, and future registered pages get a safe fallback.
            $crm_menu_items = crmbarghbazar_menu_registry();
            $crm_nav_groups = array(
                'میز کار من' => array( 'dashboard', 'clock' ),
                'منابع انسانی' => array( 'departments', 'attendance' ),
                'مدیریت فعالیت‌ها' => array( 'tasks', 'messages', 'reports' ),
                'فروش و مشتریان' => array( 'customers', 'sales', 'orders' ),
                'سئو و بازاریابی' => array( 'seo' ),
            );
            $crm_rendered_items = array();
            foreach ( $crm_nav_groups as $crm_group_label => $crm_group_slugs ) :
                $crm_group_items = array();
                foreach ( $crm_group_slugs as $crm_group_slug ) {
                    if ( isset( $crm_menu_items[ $crm_group_slug ] )
                        && crmbarghbazar_can_view_section( $crm_group_slug ) ) {
                        $crm_group_items[ $crm_group_slug ] = $crm_menu_items[ $crm_group_slug ];
                        $crm_rendered_items[ $crm_group_slug ] = true;
                    }
                }
                if ( ! $crm_group_items ) { continue; }
                ?>
                <div class="crm-nav-group">
                    <h2 class="crm-nav-group-title"><?php echo esc_html( $crm_group_label ); ?></h2>
                    <?php foreach ( $crm_group_items as $crm_menu_slug => $crm_menu_item ) :
                        if ( ! empty( $crm_menu_item['children'] ) ) :
                            $crm_child_tab = isset( $_GET['seo_tab'] ) && is_string( $_GET['seo_tab'] )
                                ? sanitize_key( wp_unslash( $_GET['seo_tab'] ) ) : 'overview';
                            ?>
                            <details class="crm-department-menu" <?php echo $crm_current_section === $crm_menu_slug ? 'open' : ''; ?>>
                                <summary class="crm-main-nav-link"><?php echo esc_html( $crm_menu_item['label'] ); ?></summary>
                                <nav aria-label="<?php echo esc_attr( $crm_menu_item['label'] ); ?>">
                                    <?php foreach ( $crm_menu_item['children'] as $crm_child_key => $crm_child_label ) :
                                        $crm_child_active = $crm_current_section === $crm_menu_slug && $crm_child_tab === $crm_child_key;
                                        ?>
                                        <a class="crm-main-nav-link crm-department-child <?php echo $crm_child_active ? 'is-active' : ''; ?>"
                                           href="<?php echo esc_url( add_query_arg( array( 'crm_section' => $crm_menu_slug, 'seo_tab' => $crm_child_key ), home_url( '/main/' ) ) ); ?>"
                                           <?php echo $crm_child_active ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $crm_child_label ); ?></a>
                                    <?php endforeach; ?>
                                </nav>
                            </details>
                        <?php else : ?>
                            <a class="crm-main-nav-link <?php echo $crm_current_section === $crm_menu_slug ? 'is-active' : ''; ?>"
                               href="<?php echo esc_url( add_query_arg( 'crm_section', $crm_menu_slug, home_url( '/main/' ) ) ); ?>"
                               <?php echo $crm_current_section === $crm_menu_slug ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html( $crm_menu_item['label'] ); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php
            // Extensions can register new pages without needing a theme update.
            $crm_other_items = array();
            foreach ( $crm_menu_items as $crm_other_key => $crm_other_item ) {
                if ( ! isset( $crm_rendered_items[ $crm_other_key ] )
                    && crmbarghbazar_can_view_section( $crm_other_key )
                    && empty( $crm_other_item['children'] ) ) {
                    $crm_other_items[ $crm_other_key ] = $crm_other_item;
                }
            }
            if ( $crm_other_items ) :
                ?>
                <div class="crm-nav-group">
                    <h2 class="crm-nav-group-title">سایر بخش‌ها</h2>
                    <?php foreach ( $crm_other_items as $crm_other_slug => $crm_other_item ) : ?>
                        <a class="crm-main-nav-link <?php echo $crm_current_section === $crm_other_slug ? 'is-active' : ''; ?>"
                           href="<?php echo esc_url( add_query_arg( 'crm_section', $crm_other_slug, home_url( '/main/' ) ) ); ?>"
                           <?php echo $crm_current_section === $crm_other_slug ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $crm_other_item['label'] ); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

    </aside>

    <main class="crm-main-workspace" id="crm-main-content" aria-label="محتوای اصلی CRM">
        <header class="crm-topbar" aria-label="نوار اعلان‌های پنل">
            <div class="crm-topbar-group">
                <?php $crm_unread = crmb_work_unread_count(); ?>
                <a class="crm-topbar-bell" href="<?php echo esc_url( add_query_arg( 'crm_section', 'messages', home_url( '/main/' ) ) ); ?>"
                   aria-label="<?php echo esc_attr( null === $crm_unread ? 'پیام‌های داخلی' : $crm_unread . ' پیام خوانده‌نشده' ); ?>" title="پیام‌های داخلی">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/>
                        <path d="M10 21h4"/>
                    </svg>
                    <?php if ( $crm_unread ) : ?><span class="crmb-unread-badge"><?php echo esc_html( $crm_unread > 99 ? '99+' : $crm_unread ); ?></span><?php endif; ?>
                </a>
                <div class="crm-topbar-datetime"
                     data-clock-timezone="<?php echo esc_attr( crmb_att_timezone()->getName() ); ?>"
                     data-clock-offset="<?php echo esc_attr( crmb_att_now_local()->getOffset() ); ?>"
                     data-server-epoch="<?php echo esc_attr( time() ); ?>">
                    <time class="crm-topbar-date" id="crmb-live-date"><?php echo esc_html( crmb_att_jdate( crmb_att_today() ) ); ?></time>
                    <span class="crm-topbar-time" id="crmb-live-time" dir="ltr"><?php echo esc_html( crmb_att_now_local()->format( 'H:i:s' ) ); ?></span>
                </div>
            </div>
        </header>
        <?php
        if ( 'dashboard' === $crm_current_section && crmbarghbazar_can_view_section( 'dashboard' ) ) {
            get_template_part( 'template-parts/dashboard' );
        } elseif ( 'attendance' === $crm_current_section && crmbarghbazar_can_view_section( 'attendance' ) ) {
            get_template_part( 'template-parts/attendance-admin' );
        } elseif ( 'clock' === $crm_current_section ) {
            get_template_part( 'template-parts/attendance-clock' );
        }
        elseif ( 'departments' === $crm_current_section && crmbarghbazar_can_view_section( 'departments' ) ) {
            get_template_part( 'template-parts/human-resources' );
        } elseif ( 'tasks' === $crm_current_section && crmbarghbazar_can_view_section( 'tasks' ) ) {
            get_template_part( 'template-parts/tasks' );
        } elseif ( 'messages' === $crm_current_section && crmbarghbazar_can_view_section( 'messages' ) ) {
            get_template_part( 'template-parts/messages' );
        } elseif ( 'reports' === $crm_current_section && crmbarghbazar_can_view_section( 'reports' ) ) {
            get_template_part( 'template-parts/daily-reports' );
        } elseif ( 'seo' === $crm_current_section && crmbarghbazar_can_view_section( 'seo' ) ) {
            get_template_part( 'template-parts/seo' );
        }
        ?>
    </main>
</div>
<script>
(function () {
    "use strict";
    const root = document.querySelector(".crm-topbar-datetime");
    if (!root) return;
    const dateElement = document.getElementById("crmb-live-date");
    const timeElement = document.getElementById("crmb-live-time");
    const zone = root.dataset.clockTimezone || "Asia/Tehran";
    const offsetMs = (Number(root.dataset.clockOffset) || 0) * 1000;
    // Anchor the live clock to the server's UTC timestamp, not the browser's
    // potentially incorrect system clock. performance.now() is monotonic.
    const initialEpochMs = Number(root.dataset.serverEpoch) * 1000 || Date.now();
    const monotonicStart = window.performance && typeof performance.now === "function"
        ? performance.now() : 0;
    const browserStart = Date.now();
    function elapsedMs() {
        return window.performance && typeof performance.now === "function"
            ? performance.now() - monotonicStart : Date.now() - browserStart;
    }
    let useOffset = false;
    let dateFormatter, timeFormatter;
    function makeFormatters(timeZone) {
        dateFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", {
            timeZone: timeZone, weekday: "long", day: "numeric", month: "long", year: "numeric"
        });
        timeFormatter = new Intl.DateTimeFormat("en-GB", {
            timeZone: timeZone, hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23"
        });
        dateFormatter.format(new Date());
        timeFormatter.format(new Date());
    }
    try {
        makeFormatters(zone);
    } catch (error) {
        try {
            useOffset = true;
            makeFormatters("UTC");
        } catch (unsupported) {
            return; // Keep the initial server-rendered date/time.
        }
    }
    function updateClock() {
        const now = new Date(initialEpochMs + elapsedMs() + (useOffset ? offsetMs : 0));
        dateElement.textContent = dateFormatter.format(now);
        timeElement.textContent = timeFormatter.format(now);
    }
    updateClock();
    window.setInterval(updateClock, 1000);
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
