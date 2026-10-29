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
?>
<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <style>
        body.crm-main-page {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #f8f6fe;
            color: #211a35;
            font-family: Tahoma, sans-serif;
            text-align: center;
        }
        .crm-main-content {
            width: min(560px, calc(100% - 36px));
            padding: 46px 26px;
            border: 1px solid #eee7fa;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 18px 56px rgba(48, 21, 108, .09);
        }
        .crm-main-content h1 {
            margin: 0 0 12px;
            color: #6034d0;
            font-size: clamp(24px, 5vw, 34px);
        }
        .crm-main-content p { margin: 0; font-size: 15px; line-height: 2; }
    </style>
</head>
<body <?php body_class( 'crm-main-page' ); ?>>
<?php wp_body_open(); ?>
<main class="crm-main-content">
    <h1>صفحه اصلی CRM برق بازار</h1>
    <p>ورود شما با موفقیت انجام شد.</p>
</main>
<?php wp_footer(); ?>
</body>
</html>
