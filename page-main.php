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
            <?php // Menu items will be added only when specified. ?>
        </nav>
    </aside>

    <main class="crm-main-workspace" id="crm-main-content" aria-label="محتوای اصلی CRM">
        <?php // Content will be added for each requested menu option. ?>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
