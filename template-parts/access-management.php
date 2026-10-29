<?php
/**
 * Admin-only CRM role/user management view.
 *
 * @package CRMBarghBazar
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

$crm_tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] )
    ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'roles';
$crm_tab = 'users' === $crm_tab ? 'users' : 'roles';

$crm_notices = array(
    'role_created' => 'نقش جدید ساخته شد.',
    'role_updated' => 'نقش و دسترسی‌های آن به‌روزرسانی شد.',
    'role_deleted' => 'نقش حذف شد.',
    'role_in_use'  => 'این نقش به کاربر اختصاص دارد؛ ابتدا نقش کاربران آن را تغییر دهید.',
    'user_created' => 'کاربر جدید ساخته شد.',
    'user_updated' => 'اطلاعات کاربر به‌روزرسانی شد.',
    'user_deleted' => 'کاربر حذف شد و محتوای او حفظ شد.',
    'email_invalid'=> 'ایمیل نامعتبر یا تکراری است.',
    'invalid'      => 'درخواست معتبر نبود. اطلاعات و مجوزها را بررسی کنید.',
);
$crm_notice = isset( $_GET['crm_notice'] ) && is_string( $_GET['crm_notice'] )
    ? sanitize_key( wp_unslash( $_GET['crm_notice'] ) ) : '';

$crm_roles = wp_roles();
$crm_owned = crmbarghbazar_owned_roles();
$crm_sections = crmbarghbazar_assignable_sections();
$crm_user_roles = crmbarghbazar_allowed_user_roles();
$crm_role_counts = count_users();
$crm_edit_role = isset( $_GET['edit_role'] ) && is_string( $_GET['edit_role'] )
    ? sanitize_key( wp_unslash( $_GET['edit_role'] ) ) : '';
if ( ! in_array( $crm_edit_role, $crm_owned, true ) || ! get_role( $crm_edit_role ) ) {
    $crm_edit_role = '';
}

$crm_edit_user_id = isset( $_GET['edit_user'] ) ? absint( $_GET['edit_user'] ) : 0;
$crm_edit_user = $crm_edit_user_id ? get_userdata( $crm_edit_user_id ) : false;
if ( $crm_edit_user && ( (int) $crm_edit_user->ID === get_current_user_id() || user_can( $crm_edit_user, 'manage_options' ) ) ) {
    $crm_edit_user = false;
}
$crm_user_page = isset( $_GET['crm_page'] ) ? max( 1, absint( $_GET['crm_page'] ) ) : 1;
$crm_user_query = new WP_User_Query(
    array( 'number' => 20, 'paged' => $crm_user_page, 'orderby' => 'registered', 'order' => 'DESC' )
);
$crm_users = $crm_user_query->get_results();
$crm_total_pages = (int) ceil( $crm_user_query->get_total() / 20 );
$crm_base_url = add_query_arg( 'crm_section', 'access', home_url( '/main/' ) );
?>
<section class="crm-access-screen" aria-labelledby="crm-access-title">
    <div class="crm-access-header">
        <div>
            <h1 id="crm-access-title">نقش‌ها / کاربران</h1>
            <p>مدیریت کاربران و سطح دسترسی بخش‌های CRM برق بازار</p>
        </div>
    </div>

    <div class="crm-access-tabs" role="navigation" aria-label="بخش مدیریت دسترسی">
        <a class="<?php echo 'roles' === $crm_tab ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( add_query_arg( 'tab', 'roles', $crm_base_url ) ); ?>">نقش‌ها</a>
        <a class="<?php echo 'users' === $crm_tab ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( add_query_arg( 'tab', 'users', $crm_base_url ) ); ?>">کاربران</a>
    </div>

    <?php if ( isset( $crm_notices[ $crm_notice ] ) ) : ?>
        <p class="crm-access-notice <?php echo 'invalid' === $crm_notice || 'role_in_use' === $crm_notice || 'email_invalid' === $crm_notice ? 'is-error' : 'is-success'; ?>" role="status">
            <?php echo esc_html( $crm_notices[ $crm_notice ] ); ?>
        </p>
    <?php endif; ?>

    <?php if ( 'roles' === $crm_tab ) : ?>
        <div class="crm-access-grid">
            <section class="crm-access-card">
                <h2><?php echo $crm_edit_role ? 'ویرایش نقش' : 'ساخت نقش جدید'; ?></h2>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="crm-access-form">
                    <input type="hidden" name="action" value="crmbarghbazar_access">
                    <input type="hidden" name="crm_task" value="<?php echo $crm_edit_role ? 'edit_role' : 'create_role'; ?>">
                    <input type="hidden" name="crm_role_slug" value="<?php echo esc_attr( $crm_edit_role ); ?>">
                    <?php wp_nonce_field( 'crmbarghbazar_access_action', 'crm_access_nonce' ); ?>

                    <label for="crm-role-name">نام نقش</label>
                    <input id="crm-role-name" type="text" name="crm_role_name" required maxlength="80"
                           value="<?php echo esc_attr( $crm_edit_role ? $crm_roles->roles[ $crm_edit_role ]['name'] : '' ); ?>"
                           placeholder="مثلاً کارشناس فروش">

                    <fieldset class="crm-access-permissions">
                        <legend>دسترسی به گزینه‌های پنل</legend>
                        <?php if ( empty( $crm_sections ) ) : ?>
                            <p class="crm-access-muted">هنوز گزینه دیگری در پنل تعریف نشده است. با اضافه‌شدن هر بخش، مجوز آن اینجا قابل تنظیم خواهد بود.</p>
                        <?php else : ?>
                            <?php foreach ( $crm_sections as $crm_section_key => $crm_section_label ) : ?>
                                <label class="crm-access-check">
                                    <input type="checkbox" name="crm_permissions[]" value="<?php echo esc_attr( $crm_section_key ); ?>"
                                        <?php checked( $crm_edit_role && get_role( $crm_edit_role )->has_cap( 'crm_menu_' . $crm_section_key ) ); ?>>
                                    <span><?php echo esc_html( $crm_section_label ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                    <button type="submit" class="crm-access-primary"><?php echo $crm_edit_role ? 'ذخیره تغییرات نقش' : 'ایجاد نقش'; ?></button>
                    <?php if ( $crm_edit_role ) : ?>
                        <a class="crm-access-cancel" href="<?php echo esc_url( add_query_arg( 'tab', 'roles', $crm_base_url ) ); ?>">انصراف از ویرایش</a>
                    <?php endif; ?>
                </form>
            </section>

            <section class="crm-access-card">
                <h2>نقش‌های ساخته‌شده</h2>
                <div class="crm-access-table-wrap">
                    <table class="crm-access-table">
                        <thead><tr><th>نام نقش</th><th>تعداد کاربر</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php foreach ( $crm_owned as $crm_slug ) : ?>
                            <?php
                            if ( ! get_role( $crm_slug ) ) {
                                continue;
                            }
                            $crm_role_users = isset( $crm_role_counts['avail_roles'][ $crm_slug ] )
                                ? (int) $crm_role_counts['avail_roles'][ $crm_slug ] : 0;
                            ?>
                            <tr>
                                <td><?php echo esc_html( $crm_roles->roles[ $crm_slug ]['name'] ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $crm_role_users ) ); ?></td>
                                <td class="crm-access-actions">
                                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'roles', 'edit_role' => $crm_slug ), $crm_base_url ) ); ?>">ویرایش</a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('این نقش حذف شود؟');">
                                        <input type="hidden" name="action" value="crmbarghbazar_access">
                                        <input type="hidden" name="crm_task" value="delete_role">
                                        <input type="hidden" name="crm_role_slug" value="<?php echo esc_attr( $crm_slug ); ?>">
                                        <?php wp_nonce_field( 'crmbarghbazar_access_action', 'crm_access_nonce' ); ?>
                                        <button type="submit" class="crm-access-danger" <?php disabled( $crm_role_users > 0 ); ?>>حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ( empty( $crm_owned ) ) : ?>
                            <tr><td colspan="3" class="crm-access-empty">هنوز نقشی ساخته نشده است.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="crm-access-muted">برای جلوگیری از اختلال در وردپرس، نقش‌های اصلی وردپرس در این قسمت قابل حذف نیستند.</p>
            </section>
        </div>
    <?php else : ?>
        <div class="crm-access-grid">
            <section class="crm-access-card">
                <h2><?php echo $crm_edit_user ? 'ویرایش کاربر' : 'ساخت کاربر جدید'; ?></h2>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="crm-access-form">
                    <input type="hidden" name="action" value="crmbarghbazar_access">
                    <input type="hidden" name="crm_task" value="<?php echo $crm_edit_user ? 'edit_user' : 'create_user'; ?>">
                    <input type="hidden" name="crm_user_id" value="<?php echo esc_attr( $crm_edit_user ? $crm_edit_user->ID : 0 ); ?>">
                    <?php wp_nonce_field( 'crmbarghbazar_access_action', 'crm_access_nonce' ); ?>
                    <label for="crm-user-login">نام کاربری</label>
                    <input type="text" id="crm-user-login" name="crm_login" required
                        <?php disabled( (bool) $crm_edit_user ); ?>
                        value="<?php echo esc_attr( $crm_edit_user ? $crm_edit_user->user_login : '' ); ?>" autocomplete="off" dir="ltr">
                    <div class="crm-access-two-fields">
                        <div>
                            <label for="crm-first-name">نام</label>
                            <input type="text" id="crm-first-name" name="crm_first_name" required value="<?php echo esc_attr( $crm_edit_user ? $crm_edit_user->first_name : '' ); ?>">
                        </div>
                        <div>
                            <label for="crm-last-name">نام خانوادگی</label>
                            <input type="text" id="crm-last-name" name="crm_last_name" required value="<?php echo esc_attr( $crm_edit_user ? $crm_edit_user->last_name : '' ); ?>">
                        </div>
                    </div>
                    <label for="crm-user-email">ایمیل</label>
                    <input type="email" id="crm-user-email" name="crm_email" required dir="ltr" value="<?php echo esc_attr( $crm_edit_user ? $crm_edit_user->user_email : '' ); ?>">
                    <label for="crm-user-role">نقش</label>
                    <select id="crm-user-role" name="crm_user_role" required>
                        <?php foreach ( $crm_user_roles as $crm_key => $crm_label ) : ?>
                            <option value="<?php echo esc_attr( $crm_key ); ?>" <?php selected( $crm_edit_user && in_array( $crm_key, $crm_edit_user->roles, true ) ); ?>>
                                <?php echo esc_html( $crm_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                    $crm_user_custom_menu = $crm_edit_user && metadata_exists( 'user', $crm_edit_user->ID, 'crmbarghbazar_menu_override' );
                    $crm_user_allowed_menu = $crm_user_custom_menu
                        ? get_user_meta( $crm_edit_user->ID, 'crmbarghbazar_menu_override', true ) : array();
                    $crm_user_allowed_menu = is_array( $crm_user_allowed_menu ) ? $crm_user_allowed_menu : array();
                    ?>
                    <label for="crm-menu-mode">دسترسی به منوهای سمت راست</label>
                    <select id="crm-menu-mode" name="crm_menu_mode">
                        <option value="role" <?php selected( ! $crm_user_custom_menu ); ?>>طبق دسترسی نقش</option>
                        <option value="custom" <?php selected( (bool) $crm_user_custom_menu ); ?>>دسترسی اختصاصی این کاربر</option>
                    </select>
                    <fieldset class="crm-access-permissions">
                        <legend>گزینه‌های مجاز در حالت دسترسی اختصاصی</legend>
                        <?php if ( empty( $crm_sections ) ) : ?>
                            <p class="crm-access-muted">هنوز گزینه دیگری جز مدیریت نقش‌ها و کاربران ساخته نشده است؛ این گزینه فقط برای مدیر کل نمایش داده می‌شود.</p>
                        <?php else : ?>
                            <?php foreach ( $crm_sections as $crm_user_section => $crm_user_section_label ) : ?>
                                <label class="crm-access-check">
                                    <input type="checkbox" name="crm_user_menus[]" value="<?php echo esc_attr( $crm_user_section ); ?>"
                                        <?php checked( in_array( $crm_user_section, $crm_user_allowed_menu, true ) ); ?>>
                                    <span><?php echo esc_html( $crm_user_section_label ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                    <label for="crm-user-password"><?php echo $crm_edit_user ? 'رمز عبور جدید (اختیاری)' : 'رمز عبور'; ?></label>
                    <input type="password" id="crm-user-password" name="crm_password" minlength="12" autocomplete="new-password"
                        <?php echo $crm_edit_user ? '' : 'required'; ?>>
                    <p class="crm-access-muted">رمز عبور باید حداقل ۱۲ کاراکتر باشد. هنگام ویرایش، خالی گذاشتن این فیلد رمز قبلی را حفظ می‌کند.</p>
                    <button type="submit" class="crm-access-primary"><?php echo $crm_edit_user ? 'ذخیره تغییرات کاربر' : 'ایجاد کاربر'; ?></button>
                    <?php if ( $crm_edit_user ) : ?>
                        <a class="crm-access-cancel" href="<?php echo esc_url( add_query_arg( 'tab', 'users', $crm_base_url ) ); ?>">انصراف از ویرایش</a>
                    <?php endif; ?>
                </form>
            </section>

            <section class="crm-access-card">
                <h2>کاربران</h2>
                <div class="crm-access-table-wrap">
                    <table class="crm-access-table">
                        <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php foreach ( $crm_users as $crm_list_user ) : ?>
                            <?php
                            $crm_list_name = trim( $crm_list_user->first_name . ' ' . $crm_list_user->last_name );
                            $crm_list_name = $crm_list_name ?: $crm_list_user->display_name;
                            $crm_list_role_key = ! empty( $crm_list_user->roles ) ? reset( $crm_list_user->roles ) : '';
                            $crm_list_role = isset( $crm_roles->roles[ $crm_list_role_key ]['name'] )
                                ? translate_user_role( $crm_roles->roles[ $crm_list_role_key ]['name'] ) : 'بدون نقش';
                            $crm_is_protected = (int) $crm_list_user->ID === get_current_user_id() || user_can( $crm_list_user, 'manage_options' );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $crm_list_name ); ?></td>
                                <td dir="ltr"><?php echo esc_html( $crm_list_user->user_login ); ?></td>
                                <td><?php echo esc_html( $crm_list_role ); ?></td>
                                <td class="crm-access-actions">
                                <?php if ( $crm_is_protected ) : ?>
                                    <span class="crm-access-muted">محافظت‌شده</span>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'users', 'edit_user' => $crm_list_user->ID ), $crm_base_url ) ); ?>">ویرایش</a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('این کاربر حذف شود؟');">
                                        <input type="hidden" name="action" value="crmbarghbazar_access">
                                        <input type="hidden" name="crm_task" value="delete_user">
                                        <input type="hidden" name="crm_user_id" value="<?php echo esc_attr( $crm_list_user->ID ); ?>">
                                        <?php wp_nonce_field( 'crmbarghbazar_access_action', 'crm_access_nonce' ); ?>
                                        <button type="submit" class="crm-access-danger">حذف</button>
                                    </form>
                                <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ( empty( $crm_users ) ) : ?>
                            <tr><td colspan="4" class="crm-access-empty">کاربری پیدا نشد.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ( $crm_total_pages > 1 ) : ?>
                    <div class="crm-access-pages">
                        <?php if ( $crm_user_page > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'users', 'crm_page' => $crm_user_page - 1 ), $crm_base_url ) ); ?>">صفحه قبل</a>
                        <?php endif; ?>
                        <span><?php echo esc_html( number_format_i18n( $crm_user_page ) ); ?> / <?php echo esc_html( number_format_i18n( $crm_total_pages ) ); ?></span>
                        <?php if ( $crm_user_page < $crm_total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'users', 'crm_page' => $crm_user_page + 1 ), $crm_base_url ) ); ?>">صفحه بعد</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>
