<?php
/**
 * CRM sign-in screen.
 *
 * @package CRMBarghBazar
 */

defined( 'ABSPATH' ) || exit;

$login_error = isset( $GLOBALS['crmbarghbazar_login_error'] )
    ? (string) $GLOBALS['crmbarghbazar_login_error']
    : '';
?>
<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <?php
    // Print theme styles from disk as a fallback when the public CSS URL is blocked
    // or WordPress is configured with a different content URL.
    $crmbarghbazar_css_file = get_stylesheet_directory() . '/style.css';
    if ( is_readable( $crmbarghbazar_css_file ) ) :
    ?>
    <style id="crmbarghbazar-local-css">
        <?php readfile( $crmbarghbazar_css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Trusted theme stylesheet. ?>
    </style>
    <?php endif; ?>
</head>
<body <?php body_class( 'crm-login-page' ); ?>>
<?php wp_body_open(); ?>
<main class="crm-viewport">
    <div class="crm-ambient crm-ambient--one" aria-hidden="true"></div>
    <div class="crm-ambient crm-ambient--two" aria-hidden="true"></div>

    <section class="crm-shell" aria-label="ورود به سامانه برق بازار">
        <div class="crm-brand-panel">
            <div class="crm-brand-decoration crm-brand-decoration--top" aria-hidden="true"></div>
            <div class="crm-brand-decoration crm-brand-decoration--bottom" aria-hidden="true"></div>

            <div class="crm-brand-content">
                <div class="crm-logo-orbit">
                    <span class="crm-orbit-ring" aria-hidden="true"></span>
                    <div class="crm-logo-card">
                        <img
                            class="crm-logo-fallback"
                            src="<?php echo esc_url( get_template_directory_uri() . '/assets/logo-mark.svg' ); ?>"
                            width="104"
                            height="104"
                            alt="لوگوی برق بازار"
                        >
                    </div>
                </div>

                <div class="crm-brand-identity">
                    <h2 class="crm-brand-title">برق بازار</h2>
                    <span class="crm-brand-rule" aria-hidden="true"></span>
                    <p class="crm-brand-subtitle">سامانه مدیریت ارتباط با مشتریان</p>
                </div>
            </div>
            <span class="crm-brand-footer">BARGHBAZAR CRM</span>
        </div>

        <div class="crm-form-panel">
            <div class="crm-form-content">
                <div class="crm-form-heading">
                    
                    <h1>خوش آمدید <span class="crm-title-accent">!</span></h1>
                    <p>برای ورود به حساب کاربری، اطلاعات خود را وارد کنید.</p>
                </div>

                <?php if ( '' !== $login_error ) : ?>
                    <div class="crm-alert" role="alert"><?php echo esc_html( $login_error ); ?></div>
                <?php endif; ?>

                <form class="crm-login-form" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <?php wp_nonce_field( 'crmbarghbazar_login', 'crm_login_nonce' ); ?>

                    <div class="crm-field">
                        <label for="crm_username">نام کاربری یا ایمیل</label>
                        <div class="crm-input-wrap">
                            <svg class="crm-field-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M19 20a7 7 0 0 0-14 0M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <input
                                id="crm_username"
                                name="crm_username"
                                type="text"
                                placeholder="نام کاربری یا ایمیل خود را وارد کنید"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                required
                                value="<?php echo isset( $_POST['crm_username'] ) && is_string( $_POST['crm_username'] ) ? esc_attr( wp_unslash( $_POST['crm_username'] ) ) : ''; ?>"
                            >
                        </div>
                    </div>

                    <div class="crm-field">
                        <label for="crm_password">رمز عبور</label>
                        <div class="crm-input-wrap">
                            <svg class="crm-field-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="5" y="10" width="14" height="11" rx="2.5" stroke="currentColor" stroke-width="1.7"/>
                                <path d="M8 10V7a4 4 0 0 1 8 0v3M12 14.5v2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                            </svg>
                            <input
                                id="crm_password"
                                name="crm_password"
                                type="password"
                                placeholder="رمز عبور خود را وارد کنید"
                                autocomplete="current-password"
                                required
                            >
                        </div>
                    </div>

                    <label class="crm-remember">
                        <input type="checkbox" name="crm_remember" value="1">
                        <span>مرا به خاطر بسپار</span>
                    </label>

                    <button class="crm-login-button" type="submit" name="crm_login_submit" value="1">
                        <span>ورود به حساب کاربری</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M19 12H5m0 0 5-5m-5 5 5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </form>
            </div>
            <div class="crm-form-footer">© <?php echo esc_html( wp_date( 'Y' ) ); ?> برق بازار</div>
        </div>
    </section>
</main>
<?php wp_footer(); ?>
</body>
</html>
