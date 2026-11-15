<?php
/**
 * Supplier management: modern, responsive, private RTL directory.
 * Actions are handled by the existing nonce-protected supplier backend.
 */
defined( 'ABSPATH' ) || exit;
if ( ! crmb_suppliers_can_manage() ) { return; }

$base       = add_query_arg( 'crm_section', 'suppliers', home_url( '/main/' ) );
$search     = isset( $_GET['supplier_q'] ) && is_string( $_GET['supplier_q'] )
    ? sanitize_text_field( wp_unslash( $_GET['supplier_q'] ) ) : '';
$status     = isset( $_GET['supplier_status'] ) && is_string( $_GET['supplier_status'] )
    ? sanitize_key( wp_unslash( $_GET['supplier_status'] ) ) : '';
$archived   = isset( $_GET['supplier_archive'] ) && '1' === $_GET['supplier_archive'];
$page       = isset( $_GET['supplier_page'] ) ? max( 1, absint( $_GET['supplier_page'] ) ) : 1;
$edit_id    = isset( $_GET['supplier_id'] ) ? absint( $_GET['supplier_id'] ) : 0;
$create     = isset( $_GET['supplier_new'] ) && '1' === $_GET['supplier_new'];
$profile    = $edit_id ? crmb_suppliers_get( $edit_id, $archived ) : null;
$show_form  = $create || (bool) $profile;
$stats      = crmb_suppliers_stats();
$results    = $show_form ? null : crmb_suppliers_search( $search, $status, $page, $archived );
$statuses   = crmb_suppliers_statuses();
$types      = crmb_suppliers_types();
$fields     = crmb_suppliers_fields();
$notice     = isset( $_GET['supplier_notice'] ) ? sanitize_key( $_GET['supplier_notice'] ) : '';
$import     = get_transient( 'crmb_suppliers_import_' . get_current_user_id() );
if ( $import ) { delete_transient( 'crmb_suppliers_import_' . get_current_user_id() ); }
$notices = array(
    'saved'          => 'اطلاعات تأمین‌کننده با موفقیت ذخیره شد.',
    'archived'       => 'رکورد به بایگانی منتقل شد و در صورت نیاز قابل بازگردانی است.',
    'restored'       => 'رکورد از بایگانی بازگردانده شد.',
    'imported'       => 'فایل تأمین‌کنندگان پردازش و اطلاعات آن ثبت شد.',
    'invalid_csv'    => 'فایل CSV معتبر نیست. نوع فایل و حجم آن را بررسی کنید.',
    'invalid_header' => 'ستون‌های فایل با قالب مورد انتظار مطابقت ندارند.',
    'invalid_row'    => 'یکی از ردیف‌ها نامعتبر است. هیچ ردیفی وارد نشده است.',
    'too_many'       => 'تعداد ردیف‌های فایل بیش از حد مجاز است.',
    'invalid'        => 'اطلاعات واردشده معتبر نیست.',
    'empty'          => 'فایل تأمین‌کننده‌ای برای ثبت ندارد.',
    'error'          => 'ذخیره‌سازی انجام نشد. دوباره تلاش کنید.',
    'denied'         => 'مجوز انجام این عملیات را ندارید.',
    'not_found'      => 'پرونده موردنظر پیدا نشد.',
);
$groups = array(
    'identity' => array(
        'title' => 'مشخصات اصلی', 'hint' => 'نام مجموعه، شخص مرتبط و وضعیت همکاری',
        'icon' => 'fa-id-card', 'fields' => array( 'supplier_name', 'family_name', 'contact_first_name' ),
    ),
    'contact' => array(
        'title' => 'راه‌های ارتباطی', 'hint' => 'اطلاعات تماس و نشانی برای دسترسی سریع تیم فروش',
        'icon' => 'fa-address-book', 'fields' => array( 'mobile', 'landline', 'email', 'website_social', 'address' ),
    ),
    'business' => array(
        'title' => 'زمینه همکاری و یادداشت‌ها', 'hint' => 'محصولات، برچسب‌ها و اطلاعاتی که در پیگیری بعدی کمک می‌کنند',
        'icon' => 'fa-clipboard-list', 'fields' => array( 'activity', 'tags', 'source', 'notes' ),
    ),
);
?>
<section class="crm-access-screen crmb-suppliers" aria-labelledby="crmb-suppliers-heading">
    <header class="crmb-supplier-hero">
        <div class="crmb-supplier-hero-title">
            <span class="crmb-supplier-eyebrow"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> میز فروش / مدیریت تأمین</span>
            <h1 id="crmb-suppliers-heading"><?php echo $show_form ? ( $create ? 'افزودن تأمین‌کننده' : 'پرونده تأمین‌کننده' ) : 'بانک تأمین‌کنندگان'; ?></h1>
            <p><?php echo $show_form
                ? 'اطلاعات شرکت‌ها و اشخاص را به‌صورت ساختارمند و قابل جست‌وجو نگهداری کنید.'
                : 'شرکت‌ها، اشخاص و راه‌های ارتباطی را از یک فضای منظم مدیریت کنید.'; ?></p>
        </div>
        <div class="crmb-supplier-hero-actions">
            <?php if ( $show_form ) : ?>
                <a class="crmb-supplier-btn crmb-supplier-btn--outline" href="<?php echo esc_url( $base ); ?>">
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> بازگشت به فهرست
                </a>
            <?php else : ?>
                <a class="crmb-supplier-btn crmb-supplier-btn--outline" href="#crmb-supplier-import">
                    <i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i> ورود اطلاعات CSV
                </a>
                <a class="crmb-supplier-btn crmb-supplier-btn--primary" href="<?php echo esc_url( add_query_arg( 'supplier_new', '1', $base ) ); ?>">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i> تأمین‌کننده جدید
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ( $notice ) : ?>
        <div class="crmb-supplier-flash <?php echo in_array( $notice, array( 'saved','archived','restored','imported' ), true ) ? 'is-success' : 'is-error'; ?>" role="status">
            <i class="fa-solid <?php echo in_array( $notice, array( 'saved','archived','restored','imported' ), true ) ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>" aria-hidden="true"></i>
            <div>
                <strong><?php echo esc_html( $notices[ $notice ] ?? 'نتیجه عملیات ثبت شد.' ); ?></strong>
                <?php if ( 'imported' === $notice && is_array( $import ) ) : ?>
                    <p><?php echo esc_html( 'کل: ' . ( $import['total'] ?? 0 ) . '  |  جدید: ' . ( $import['created'] ?? 0 ) . '  |  تکراری: ' . ( $import['skipped'] ?? 0 ) ); ?></p>
                <?php elseif ( 'invalid_row' === $notice && is_array( $import ) ) : ?>
                    <p><?php echo esc_html( 'ردیف نیازمند بررسی: ' . ( $import['line'] ?? 'نامشخص' ) ); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if ( $edit_id && ! $profile && ! $create ) : ?>
        <div class="crmb-supplier-flash is-error" role="alert">پرونده‌ای با این شناسه در فهرست فعلی پیدا نشد.</div>
    <?php endif; ?>

    <?php if ( $show_form ) : ?>
        <section class="crmb-supplier-profile-head" aria-label="مشخصات پرونده">
            <div class="crmb-supplier-profile-avatar"><i class="fa-solid <?php echo $profile && 'person' === $profile->supplier_type ? 'fa-user-tie' : 'fa-building'; ?>" aria-hidden="true"></i></div>
            <div class="crmb-supplier-profile-summary">
                <span class="crmb-supplier-profile-kicker"><?php echo $create ? 'پروفایل جدید' : 'شماره پرونده ' . esc_html( $profile->id ); ?></span>
                <h2><?php echo $create ? 'اطلاعات تأمین‌کننده جدید' : esc_html( $profile->supplier_name ); ?></h2>
                <p><?php echo $create ? 'فیلدهای ضروری را وارد کنید؛ جزئیات دیگر را می‌توانید بعداً تکمیل کنید.' : esc_html( $profile->activity ?: 'زمینه فعالیت برای این تأمین‌کننده هنوز ثبت نشده است.' ); ?></p>
                <?php if ( $profile ) : ?>
                    <span class="crmb-supplier-badge crmb-supplier-badge--<?php echo esc_attr( isset( $statuses[ $profile->status ] ) ? $profile->status : 'new' ); ?>">
                        <?php echo esc_html( $statuses[ $profile->status ] ?? 'در حال بررسی' ); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ( $profile ) : ?>
                <div class="crmb-supplier-profile-date">
                    <span>آخرین ویرایش</span>
                    <strong><?php echo esc_html( crmb_work_date( $profile->updated_utc ) ); ?></strong>
                </div>
            <?php endif; ?>
        </section>

        <?php if ( $profile && $profile->deleted_utc ) : ?>
            <div class="crmb-supplier-flash is-error">این پرونده بایگانی شده است. برای ویرایش، ابتدا آن را بازیابی کنید.</div>
        <?php else : ?>
            <form class="crmb-supplier-editor" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php crmb_suppliers_form_fields( 'save', $profile ? (int) $profile->id : 0 ); ?>
                <?php foreach ( $groups as $group_key => $group ) : ?>
                    <section class="crmb-supplier-form-section" aria-labelledby="crmb-supplier-group-<?php echo esc_attr( $group_key ); ?>">
                        <header class="crmb-supplier-form-heading">
                            <span class="crmb-supplier-form-icon"><i class="fa-solid <?php echo esc_attr( $group['icon'] ); ?>" aria-hidden="true"></i></span>
                            <div>
                                <h3 id="crmb-supplier-group-<?php echo esc_attr( $group_key ); ?>"><?php echo esc_html( $group['title'] ); ?></h3>
                                <p><?php echo esc_html( $group['hint'] ); ?></p>
                            </div>
                        </header>
                        <div class="crmb-supplier-fields">
                            <?php foreach ( $group['fields'] as $field ) :
                                $meta = $fields[ $field ];
                                $value = $profile ? (string) $profile->$field : '';
                                $long = ! empty( $meta['multiline'] );
                                $wide = $long || in_array( $field, array( 'supplier_name','mobile','notes' ), true );
                            ?>
                                <div class="crmb-supplier-field <?php echo $wide ? 'is-wide' : ''; ?>">
                                    <label for="crmb-sup-<?php echo esc_attr( $field ); ?>">
                                        <?php echo esc_html( $meta['label'] ); ?><?php if ( 'supplier_name' === $field ) : ?> <span class="crmb-supplier-required">*</span><?php endif; ?>
                                    </label>
                                    <?php if ( $long ) : ?>
                                        <textarea id="crmb-sup-<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
                                            rows="<?php echo 'notes' === $field ? 5 : 3; ?>"
                                            maxlength="<?php echo esc_attr( $meta['max'] ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
                                    <?php else : ?>
                                        <input id="crmb-sup-<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
                                            type="<?php echo 'email' === $field ? 'email' : 'text'; ?>"
                                            maxlength="<?php echo esc_attr( $meta['max'] ); ?>"
                                            value="<?php echo esc_attr( $value ); ?>"
                                            <?php if ( 'mobile' === $field || 'landline' === $field ) : ?>dir="ltr"<?php endif; ?>
                                            <?php if ( 'supplier_name' === $field ) : ?>required<?php endif; ?>>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ( 'identity' === $group_key ) : ?>
                                <div class="crmb-supplier-field">
                                    <label for="crmb-sup-kind">نوع تأمین‌کننده</label>
                                    <select id="crmb-sup-kind" name="supplier_type">
                                        <?php foreach ( $types as $key => $label ) : ?>
                                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile ? $profile->supplier_type : 'unknown', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="crmb-supplier-field">
                                    <label for="crmb-sup-status">وضعیت همکاری</label>
                                    <select id="crmb-sup-status" name="status">
                                        <?php foreach ( $statuses as $key => $label ) : ?>
                                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile ? $profile->status : 'new', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
                <div class="crmb-supplier-editor-actions">
                    <button type="submit" class="crmb-supplier-btn crmb-supplier-btn--primary">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> ذخیره اطلاعات
                    </button>
                    <a class="crmb-supplier-btn crmb-supplier-btn--outline" href="<?php echo esc_url( $base ); ?>">انصراف</a>
                </div>
            </form>
        <?php endif; ?>

        <?php if ( $profile ) : ?>
            <section class="crmb-supplier-archive-action" aria-label="مدیریت بایگانی">
                <div>
                    <strong><?php echo $profile->deleted_utc ? 'بازیابی پرونده' : 'بایگانی کردن پرونده'; ?></strong>
                    <p><?php echo $profile->deleted_utc ? 'این تأمین‌کننده با بازیابی دوباره در فهرست فعال نمایش داده می‌شود.' : 'پرونده حذف دائمی نمی‌شود و در صورت نیاز قابل بازگردانی است.'; ?></p>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                    onsubmit="return confirm('از <?php echo $profile->deleted_utc ? 'بازیابی' : 'بایگانی'; ?> این پرونده مطمئن هستید؟');">
                    <?php crmb_suppliers_form_fields( $profile->deleted_utc ? 'restore' : 'archive', (int) $profile->id ); ?>
                    <button class="crmb-supplier-btn crmb-supplier-btn--<?php echo $profile->deleted_utc ? 'outline' : 'danger'; ?>" type="submit">
                        <i class="fa-solid <?php echo $profile->deleted_utc ? 'fa-arrow-rotate-left' : 'fa-box-archive'; ?>" aria-hidden="true"></i>
                        <?php echo $profile->deleted_utc ? 'بازیابی پرونده' : 'انتقال به بایگانی'; ?>
                    </button>
                </form>
            </section>
        <?php endif; ?>

    <?php else : ?>
        <div class="crmb-supplier-overview" aria-label="خلاصه بانک تأمین‌کنندگان">
            <div class="crmb-supplier-metric">
                <span class="crmb-supplier-metric-icon is-total"><i class="fa-solid fa-address-book" aria-hidden="true"></i></span>
                <div><span class="crmb-supplier-metric-label">همه تأمین‌کنندگان</span><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong></div>
            </div>
            <div class="crmb-supplier-metric">
                <span class="crmb-supplier-metric-icon is-active"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                <div><span class="crmb-supplier-metric-label">همکاری فعال</span><strong><?php echo esc_html( number_format_i18n( $stats['active'] ) ); ?></strong></div>
            </div>
            <div class="crmb-supplier-metric">
                <span class="crmb-supplier-metric-icon is-pending"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></span>
                <div><span class="crmb-supplier-metric-label">در حال بررسی</span><strong><?php echo esc_html( number_format_i18n( $stats['new'] ) ); ?></strong></div>
            </div>
            <div class="crmb-supplier-metric">
                <span class="crmb-supplier-metric-icon is-archived"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></span>
                <div><span class="crmb-supplier-metric-label">بایگانی‌شده</span><strong><?php echo esc_html( number_format_i18n( $stats['archived'] ) ); ?></strong></div>
            </div>
        </div>

        <section class="crmb-supplier-directory" aria-labelledby="crmb-supplier-directory-heading">
            <div class="crmb-supplier-directory-head">
                <div>
                    <span class="crmb-supplier-section-kicker">فهرست مخاطبان تأمین</span>
                    <h2 id="crmb-supplier-directory-heading"><?php echo $archived ? 'پرونده‌های بایگانی‌شده' : 'لیست تأمین‌کنندگان'; ?></h2>
                    <p><?php echo $archived ? 'رکوردهای حذف‌شده را بررسی و در صورت نیاز بازیابی کنید.' : 'با نام، محصول، شهر، تلفن یا کلمه‌ای از توضیحات جست‌وجو کنید.'; ?></p>
                </div>
                <span class="crmb-supplier-results-count"><?php echo esc_html( number_format_i18n( $results['total'] ) ); ?> نتیجه</span>
            </div>
            <nav class="crmb-supplier-switcher" aria-label="نمایش تأمین‌کنندگان">
                <a class="<?php echo ! $archived ? 'is-active' : ''; ?>" href="<?php echo esc_url( $base ); ?>" <?php if ( ! $archived ) : ?>aria-current="page"<?php endif; ?>>
                    <i class="fa-solid fa-address-card" aria-hidden="true"></i> فهرست اصلی
                </a>
                <a class="<?php echo $archived ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'supplier_archive', '1', $base ) ); ?>" <?php if ( $archived ) : ?>aria-current="page"<?php endif; ?>>
                    <i class="fa-solid fa-box-archive" aria-hidden="true"></i> بایگانی
                </a>
            </nav>
            <form class="crmb-supplier-search" method="get" action="<?php echo esc_url( home_url( '/main/' ) ); ?>">
                <input type="hidden" name="crm_section" value="suppliers">
                <?php if ( $archived ) : ?><input type="hidden" name="supplier_archive" value="1"><?php endif; ?>
                <div class="crmb-supplier-search-field">
                    <label class="screen-reader-text" for="crmb-supplier-query">جست‌وجوی تأمین‌کننده</label>
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" id="crmb-supplier-query" name="supplier_q" value="<?php echo esc_attr( $search ); ?>"
                        placeholder="نام، شماره تماس، محصول، شهر یا کلمه کلیدی...">
                </div>
                <div class="crmb-supplier-filter-field">
                    <label class="screen-reader-text" for="crmb-supplier-status">فیلتر وضعیت</label>
                    <select id="crmb-supplier-status" name="supplier_status">
                        <option value="">همه وضعیت‌ها</option>
                        <?php foreach ( $statuses as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="crmb-supplier-btn crmb-supplier-btn--primary" type="submit">جست‌وجو</button>
                <?php if ( $search || $status ) : ?>
                    <a class="crmb-supplier-clear" href="<?php echo esc_url( $archived ? add_query_arg( 'supplier_archive', '1', $base ) : $base ); ?>">پاک کردن</a>
                <?php endif; ?>
            </form>

            <?php if ( ! $results['ready'] ) : ?>
                <div class="crmb-supplier-empty" role="status">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <h3>دریافت اطلاعات ممکن نیست</h3>
                    <p>وضعیت جدول تأمین‌کنندگان در پایگاه‌داده را بررسی کنید.</p>
                </div>
            <?php elseif ( ! $results['rows'] ) : ?>
                <div class="crmb-supplier-empty">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <h3>موردی پیدا نشد</h3>
                    <p><?php echo $search || $status ? 'عبارت جست‌وجو یا فیلتر را تغییر دهید.' : 'هنوز تأمین‌کننده‌ای در این بخش ثبت نشده است.'; ?></p>
                    <?php if ( ! $archived ) : ?>
                        <a class="crmb-supplier-btn crmb-supplier-btn--outline" href="<?php echo esc_url( add_query_arg( 'supplier_new', '1', $base ) ); ?>">افزودن تأمین‌کننده</a>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="crmb-supplier-grid">
                    <?php foreach ( $results['rows'] as $supplier ) :
                        $profile_url = add_query_arg( array( 'supplier_id' => (int) $supplier->id, 'supplier_archive' => $archived ? '1' : '0' ), $base );
                        $kind = isset( $types[ $supplier->supplier_type ] ) ? $supplier->supplier_type : 'unknown';
                        $card_status = isset( $statuses[ $supplier->status ] ) ? $supplier->status : 'new';
                        $has_phone = ! empty( $supplier->mobile ) || ! empty( $supplier->landline );
                    ?>
                        <article class="crmb-supplier-card">
                            <div class="crmb-supplier-card-top">
                                <span class="crmb-supplier-avatar" aria-hidden="true">
                                    <i class="fa-solid <?php echo 'person' === $kind ? 'fa-user-tie' : 'fa-building'; ?>"></i>
                                </span>
                                <span class="crmb-supplier-badge crmb-supplier-badge--<?php echo esc_attr( $card_status ); ?>">
                                    <?php echo esc_html( $statuses[ $supplier->status ] ?? 'در حال بررسی' ); ?>
                                </span>
                            </div>
                            <div class="crmb-supplier-card-identity">
                                <h3><a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $supplier->supplier_name ); ?></a></h3>
                                <p><?php echo esc_html( $supplier->family_name ?: $types[ $kind ] ); ?></p>
                            </div>
                            <div class="crmb-supplier-card-activity">
                                <span class="crmb-supplier-card-caption">زمینه فعالیت</span>
                                <p><?php echo $supplier->activity
                                    ? esc_html( wp_trim_words( $supplier->activity, 24, '…' ) )
                                    : '<span class="crmb-supplier-placeholder">هنوز ثبت نشده است</span>'; ?></p>
                            </div>
                            <dl class="crmb-supplier-contact-list">
                                <?php if ( $supplier->mobile ) : ?>
                                    <div><dt><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> همراه</dt><dd dir="auto" title="<?php echo esc_attr( $supplier->mobile ); ?>"><?php echo esc_html( $supplier->mobile ); ?></dd></div>
                                <?php endif; ?>
                                <?php if ( $supplier->landline ) : ?>
                                    <div><dt><i class="fa-solid fa-phone" aria-hidden="true"></i> ثابت</dt><dd dir="auto" title="<?php echo esc_attr( $supplier->landline ); ?>"><?php echo esc_html( $supplier->landline ); ?></dd></div>
                                <?php endif; ?>
                                <?php if ( ! $has_phone ) : ?>
                                    <div class="crmb-supplier-contact-empty"><dt><i class="fa-solid fa-circle-info" aria-hidden="true"></i> تماس</dt><dd>ثبت نشده</dd></div>
                                <?php endif; ?>
                            </dl>
                            <div class="crmb-supplier-card-footer">
                                <span class="crmb-supplier-source" title="<?php echo esc_attr( $supplier->source ?: 'منبع ثبت نشده' ); ?>">
                                    <i class="fa-solid fa-tag" aria-hidden="true"></i>
                                    <?php echo esc_html( $supplier->source ?: 'بدون منبع' ); ?>
                                </span>
                                <a href="<?php echo esc_url( $profile_url ); ?>" class="crmb-supplier-card-open" aria-label="<?php echo esc_attr( 'مشاهده پروفایل ' . $supplier->supplier_name ); ?>">
                                    مشاهده پرونده <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php
                $pages = max( 1, (int) ceil( $results['total'] / 18 ) );
                $page_params = array( 'supplier_q' => $search, 'supplier_status' => $status );
                if ( $archived ) { $page_params['supplier_archive'] = '1'; }
                ?>
                <?php if ( $pages > 1 ) : ?>
                    <nav class="crmb-supplier-pagination" aria-label="صفحات تأمین‌کنندگان">
                        <?php if ( $page > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $page_params, array( 'supplier_page' => $page - 1 ) ), $base ) ); ?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i> قبلی</a>
                        <?php endif; ?>
                        <span>صفحه <?php echo esc_html( number_format_i18n( $page ) ); ?> از <?php echo esc_html( number_format_i18n( $pages ) ); ?></span>
                        <?php if ( $page < $pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $page_params, array( 'supplier_page' => $page + 1 ) ), $base ) ); ?>">بعدی <i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ( ! $archived ) : ?>
            <section class="crmb-supplier-import" id="crmb-supplier-import" aria-labelledby="crmb-supplier-import-heading">
                <div class="crmb-supplier-import-banner">
                    <span class="crmb-supplier-import-icon"><i class="fa-solid fa-file-csv" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="crmb-supplier-import-heading">ورود گروهی اطلاعات</h2>
                        <p>فایل CSV تأمین‌کنندگان را یک‌جا اضافه کنید؛ اطلاعات تکراری دوباره ثبت نمی‌شوند.</p>
                    </div>
                </div>
                <details class="crmb-supplier-import-details">
                    <summary>انتخاب فایل CSV <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
                    <form method="post" enctype="multipart/form-data" class="crmb-supplier-import-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php crmb_suppliers_form_fields( 'import' ); ?>
                        <div class="crmb-supplier-import-fields">
                            <div class="crmb-supplier-field">
                                <label for="crmb-supplier-source">عنوان منبع اطلاعات</label>
                                <input id="crmb-supplier-source" name="source" maxlength="140" value="نمایشگاه — 2026-11-15">
                            </div>
                            <div class="crmb-supplier-field">
                                <label for="crmb-supplier-csv">انتخاب فایل CSV با کدگذاری UTF-8</label>
                                <input id="crmb-supplier-csv" type="file" name="supplier_csv" accept=".csv,text/csv" required>
                            </div>
                        </div>
                        <p class="crmb-supplier-import-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> حداکثر ۳ مگابایت و ۵۰۰۰ ردیف؛ اطلاعات موجود جایگزین نمی‌شوند. هشت ستون اصلی فایل نمایشگاه شناسایی می‌شوند.</p>
                        <button type="submit" class="crmb-supplier-btn crmb-supplier-btn--primary" onclick="return confirm('اطلاعات این فایل به بانک تأمین‌کنندگان افزوده شود؟');">
                            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> ثبت اطلاعات فایل
                        </button>
                    </form>
                </details>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
