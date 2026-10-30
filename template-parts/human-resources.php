<?php
/** Unified HR workspace; all child views retain their own authorization checks. */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { return; }
$hr_tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'units';
if ( ! in_array( $hr_tab, array( 'units', 'users', 'roles' ), true ) ) { $hr_tab = 'units'; }
$hr_base = add_query_arg( 'crm_section', 'departments', home_url( '/main/' ) );
?>
<header class="crm-access-screen crm-access-header">
    <h1>منابع انسانی</h1>
    <p>مدیریت یکپارچه واحدها، کارکنان و دسترسی‌های پنل</p>
    <nav class="crm-access-tabs" aria-label="بخش‌های منابع انسانی">
        <?php foreach ( array( 'units' => 'واحدهای سازمانی', 'users' => 'کارکنان', 'roles' => 'نقش‌ها و دسترسی‌ها' ) as $hr_key => $hr_label ) : ?>
            <a class="<?php echo $hr_tab === $hr_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $hr_key, $hr_base ) ); ?>" <?php echo $hr_tab === $hr_key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $hr_label ); ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<?php
get_template_part( 'units' === $hr_tab ? 'template-parts/departments' : 'template-parts/access-management' );
