<?php
/** Administrator-only organizational units. */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { return; }
$units = get_terms( array( 'taxonomy' => 'crmb_department', 'hide_empty' => false ) );
if ( is_wp_error( $units ) ) { echo '<p role="alert">دریافت واحدها انجام نشد.</p>'; return; }
$employees = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
$edit_id = isset( $_GET['unit'] ) && is_scalar( $_GET['unit'] ) ? absint( $_GET['unit'] ) : 0;
$editing = $edit_id ? crmb_department( $edit_id ) : false;
$manager_id = $editing ? (int) get_term_meta( $editing->term_id, 'crmb_manager_id', true ) : 0;
$base = add_query_arg( 'crm_section', 'departments', home_url( '/main/' ) );
$messages = array( 'saved' => 'اطلاعات ذخیره شد.', 'deleted' => 'واحد حذف شد.', 'invalid' => 'اطلاعات معتبر نیست؛ دوباره بررسی کنید.', 'duplicate' => 'واحدی با این نام وجود دارد.', 'in_use' => 'ابتدا اعضا را منتقل و مدیر واحد را حذف کنید.', 'error' => 'ذخیره انجام نشد؛ دوباره تلاش کنید.' );
$notice = isset( $_GET['department_notice'] ) && is_string( $_GET['department_notice'] ) ? sanitize_key( $_GET['department_notice'] ) : '';
?>
<section class="crm-access-screen" aria-labelledby="crmb-department-heading">
    <header class="crm-access-header"><h1 id="crmb-department-heading">منابع انسانی — واحدهای سازمانی</h1><p>هر کارمند یک واحد اصلی دارد. مدیر واحد صرفاً مسئول سازمانی است و مجوزهای حساب او تغییری نمی‌کند.</p></header>
    <?php if ( isset( $messages[ $notice ] ) ) : ?>
        <p role="status" class="crm-access-notice <?php echo in_array( $notice, array( 'saved', 'deleted' ), true ) ? '' : 'is-error'; ?>"><?php echo esc_html( $messages[ $notice ] ); ?></p>
    <?php endif; ?>
    <div class="crm-access-grid">
        <section class="crm-access-card">
            <h2><?php echo $editing ? 'ویرایش واحد' : 'ایجاد واحد'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <input type="hidden" name="action" value="crmb_departments"><input type="hidden" name="task" value="save">
                <input type="hidden" name="department_id" value="<?php echo esc_attr( $editing ? $editing->term_id : 0 ); ?>">
                <?php wp_nonce_field( 'crmb_departments', 'crmb_nonce' ); ?>
                <label for="unit-name">نام واحد</label><input id="unit-name" name="name" required maxlength="60" value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>">
                <label for="unit-manager">مدیر واحد</label><select id="unit-manager" name="manager"><option value="0">بدون مدیر</option>
                    <?php foreach ( $employees as $employee ) : ?><option value="<?php echo esc_attr( $employee->ID ); ?>" <?php selected( $manager_id, $employee->ID ); ?>><?php echo esc_html( $employee->display_name ); ?></option><?php endforeach; ?>
                </select>
                <button class="crm-access-primary" type="submit">ذخیره واحد</button>
                <?php if ( $editing ) : ?><a class="crm-access-cancel" href="<?php echo esc_url( $base ); ?>">انصراف از ویرایش</a><?php endif; ?>
            </form>
        </section>
        <section class="crm-access-card"><h2>تخصیص یا انتقال کارمند</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form">
                <input type="hidden" name="action" value="crmb_departments"><input type="hidden" name="task" value="assign">
                <?php wp_nonce_field( 'crmb_departments', 'crmb_nonce' ); ?>
                <label for="unit-employee">کارمند</label><select id="unit-employee" name="employee" required><option value="">انتخاب کارمند</option>
                    <?php foreach ( $employees as $employee ) : ?><option value="<?php echo esc_attr( $employee->ID ); ?>"><?php echo esc_html( $employee->display_name ); ?></option><?php endforeach; ?>
                </select>
                <label for="employee-unit">واحد مقصد</label><select id="employee-unit" name="department_id"><option value="0">بدون واحد / حذف عضویت</option>
                    <?php foreach ( $units as $unit ) : ?><option value="<?php echo esc_attr( $unit->term_id ); ?>"><?php echo esc_html( $unit->name ); ?></option><?php endforeach; ?>
                </select><button class="crm-access-primary" type="submit">ثبت عضویت</button>
            </form>
        </section>
    </div>
    <section class="crm-access-card"><h2>فهرست واحدها (<?php echo esc_html( count( $units ) ); ?>)</h2>
        <div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>واحد</th><th>مدیر</th><th>تعداد اعضا</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach ( $units as $unit ) :
            $manager = get_userdata( (int) get_term_meta( $unit->term_id, 'crmb_manager_id', true ) );
            $members = crmb_department_members( $unit->term_id ); ?>
            <tr><td><?php echo esc_html( $unit->name ); ?></td><td><?php echo esc_html( $manager ? $manager->display_name : 'تعیین نشده' ); ?></td><td><?php echo esc_html( count( $members ) ); ?></td><td class="crm-access-actions">
                <a href="<?php echo esc_url( add_query_arg( 'unit', $unit->term_id, $base ) ); ?>">ویرایش</a>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('این واحد حذف شود؟');">
                    <input type="hidden" name="action" value="crmb_departments"><input type="hidden" name="task" value="delete"><input type="hidden" name="department_id" value="<?php echo esc_attr( $unit->term_id ); ?>">
                    <?php wp_nonce_field( 'crmb_departments', 'crmb_nonce' ); ?>
                    <button class="crm-access-danger" type="submit" <?php disabled( ! empty( $members ) || (bool) $manager ); ?>>حذف</button>
                </form>
            </td></tr>
        <?php endforeach; ?>
        <?php if ( ! $units ) : ?><tr><td colspan="4" class="crm-access-empty">هنوز واحدی ایجاد نشده است.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="crm-access-card"><h2>فهرست کارکنان و واحد فعلی</h2>
        <div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>کارمند</th><th>واحد اصلی</th></tr></thead><tbody>
        <?php foreach ( $employees as $employee ) :
            $unit_id = (int) get_user_meta( $employee->ID, 'crmb_department_id', true );
            $unit = $unit_id ? crmb_department( $unit_id ) : false; ?>
            <tr><td><?php echo esc_html( $employee->display_name ); ?></td><td><?php echo esc_html( $unit ? $unit->name : 'بدون واحد' ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
</section>
