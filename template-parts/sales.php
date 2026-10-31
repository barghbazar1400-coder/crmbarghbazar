<?php
/** Single sales desk: lead -> follow-up -> quote, with a local product catalog. */
defined( 'ABSPATH' ) || exit;
if ( ! crmb_sales_access() ) { return; }
$t = crmb_sales_tables();
$base = add_query_arg( 'crm_section', 'sales', home_url( '/main/' ) );
$quote_id = crmb_sales_id( $_GET, 'quote' );
$quote_view = isset( $_GET['sales_view'] ) && 'quote' === $_GET['sales_view'];
$notices = array(
    'saved' => 'اطلاعات با موفقیت ثبت شد.',
    'imported' => 'فایل محصولات وارد شد و کاتالوگ به‌روزرسانی شد.',
    'invalid' => 'اطلاعات فرم معتبر نیست؛ فیلدها و محصولات را بررسی کنید.',
    'invalid_file' => 'فایل معتبر CSV با حجم حداکثر ۸ مگابایت انتخاب کنید.',
    'bad_columns' => 'ستون‌های ضروری CSV پیدا نشد. فایل خروجی استاندارد ووکامرس را انتخاب کنید.',
    'too_many' => 'در هر فایل حداکثر ۵۰۰۰ ردیف مجاز است.',
    'empty_catalog' => 'محصول قیمت‌دار یا محصول قابل شناسایی در فایل نبود.',
    'error' => 'ثبت اطلاعات ناموفق بود؛ داده‌ها را بررسی و دوباره تلاش کنید.',
    'denied' => 'برای این عملیات دسترسی ندارید.',
);
$notice = isset( $_GET['sales_notice'] ) && is_string( $_GET['sales_notice'] ) ? sanitize_key( $_GET['sales_notice'] ) : '';
?>
<section class="crm-access-screen crmb-sales" aria-labelledby="crmb-sales-title">
    <header class="crm-access-header crmb-sales-head">
        <h1 id="crmb-sales-title">میز فروش برق‌بازار</h1>
        <p>یک مسیر برای ثبت مشتری، پیگیری، انتخاب محصول از فایل ووکامرس و صدور پیش‌فاکتور.</p>
    </header>
    <?php if ( isset( $notices[$notice] ) ) : ?><p class="crm-access-notice" role="status"><?php echo esc_html( $notices[$notice] ); ?></p><?php endif; ?>
    <?php if ( ! crmb_sales_ready() ) : ?><p role="status">پایگاه داده بخش فروش هنوز آماده نیست. مدیر وضعیت نصب را بررسی کند.</p></section><?php return; endif; ?>

<?php if ( $quote_view ) :
    $quote = crmb_sales_quote( $quote_id );
    if ( ! $quote ) : ?>
        <p role="status">پیش‌فاکتور پیدا نشد یا اجازه مشاهده آن را ندارید.</p>
    <?php else :
        $customer = crmb_sales_customer( (int) $quote->customer_id );
        $lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['lines']} WHERE quote_id=%d ORDER BY id", $quote->id ) );
        $line_error = (bool) $wpdb->last_error;
        $history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['history']} WHERE quote_id=%d ORDER BY id DESC LIMIT 20", $quote->id ) );
        $status_labels = crmb_sales_quote_states(); ?>
        <div class="crmb-sales-actions"><a href="<?php echo esc_url( $base . '#crmb-sales-quotes' ); ?>">← بازگشت به میز فروش</a><button type="button" class="crm-access-primary" onclick="window.print()">چاپ پیش‌فاکتور</button></div>
        <article class="crm-access-card crmb-sales-invoice">
            <div class="crmb-sales-invoice-head"><div><p class="crmb-sales-kicker">پیش‌فاکتور فروش</p><h2>برق‌بازار · CRM</h2></div>
                <div><strong dir="ltr"><?php echo esc_html( 'PF-' . str_pad( (string) $quote->id, 6, '0', STR_PAD_LEFT ) ); ?></strong>
                <p><?php echo esc_html( crmb_sales_quote_states()[$quote->status] ?? 'نامشخص' ); ?></p></div>
            </div>
            <dl class="crmb-sales-details">
                <div><dt>مشتری</dt><dd><?php echo esc_html( $customer ? $customer->name : 'نامشخص' ); ?></dd></div>
                <div><dt>شماره تماس</dt><dd dir="ltr"><?php echo esc_html( $customer ? $customer->phone : '—' ); ?></dd></div>
                <div><dt>شرکت</dt><dd><?php echo esc_html( $customer && $customer->company ? $customer->company : '—' ); ?></dd></div>
                <div><dt>تاریخ صدور</dt><dd><?php echo esc_html( crmb_work_date( $quote->created_utc ) ); ?></dd></div>
            </dl>
            <?php if ( $line_error ) : ?><p role="alert">اقلام پیش‌فاکتور دریافت نشد؛ چاپ نکنید.</p><?php else : ?>
                <div class="crm-access-table-wrap"><table class="crm-access-table crmb-sales-lines"><thead><tr><th>محصول</th><th>کد کالا</th><th>تعداد</th><th>قیمت واحد (تومان)</th><th>جمع (تومان)</th></tr></thead>
                    <tbody><?php foreach ( $lines ?: array() as $line ) : ?><tr>
                        <td><?php echo esc_html( $line->product_title ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $line->sku ?: '—' ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $line->quantity ); ?></td>
                        <td><?php echo esc_html( number_format( (int) $line->unit_price_toman ) ); ?></td>
                        <td><?php echo esc_html( number_format( (int) $line->total_toman ) ); ?></td>
                    </tr><?php endforeach; ?></tbody></table></div>
                <p class="crmb-sales-total"><span>جمع اقلام (تومان)</span><strong><?php echo esc_html( number_format( (int) $quote->subtotal_toman ) ); ?></strong></p>
            <?php endif; ?>
            <?php if ( $quote->notes ) : ?><p class="crmb-sales-note"><strong>توضیحات:</strong> <?php echo nl2br( esc_html( $quote->notes ) ); ?></p><?php endif; ?>
            <p class="crm-access-muted">قیمت‌ها مربوط به زمان صدور پیش‌فاکتور است. مالیات، هزینه حمل و موجودی قطعی کالا در این نسخه محاسبه یا تضمین نمی‌شود؛ این سند فاکتور رسمی یا سفارش ووکامرس نیست.</p>
        </article>
        <section class="crm-access-card crmb-sales-actions"><h2>پیگیری وضعیت</h2>
            <?php $next_states = array( 'draft' => array( 'sent' ), 'sent' => array( 'accepted', 'rejected' ) ); ?>
            <?php if ( isset( $next_states[$quote->status] ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crmb-sales-inline">
                    <?php crmb_sales_form_fields( 'quote_status' ); ?>
                    <input type="hidden" name="quote_id" value="<?php echo esc_attr( $quote->id ); ?>">
                    <label for="crmb-quote-next">وضعیت جدید</label><select id="crmb-quote-next" name="status">
                        <?php foreach ( $next_states[$quote->status] as $state ) : ?><option value="<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $status_labels[$state] ); ?></option><?php endforeach; ?>
                    </select><button class="crm-access-primary" type="submit">ثبت وضعیت</button>
                </form>
            <?php else : ?><p>این پیش‌فاکتور تعیین تکلیف شده است.</p><?php endif; ?>
            <h3>تاریخچه</h3>
            <?php foreach ( $history ?: array() as $event ) : ?><p class="crmb-sales-history"><?php echo esc_html( crmb_work_user_name( $event->actor_id ) . ' · ' . ( $status_labels[$event->event_type] ?? 'ایجاد پیش‌فاکتور' ) . ' · ' . crmb_work_date( $event->created_utc ) ); ?></p><?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php else :
    $scope = crmb_sales_scope();
    $total_customers = $wpdb->get_var( "SELECT COUNT(*) FROM {$t['customers']} WHERE 1=1{$scope}" );
    $customers_ok = ! $wpdb->last_error;
    $total_quotes = $wpdb->get_var( "SELECT COUNT(*) FROM {$t['quotes']} WHERE 1=1{$scope}" );
    $quotes_ok = ! $wpdb->last_error;
    $due_followups = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['opportunities']} WHERE stage IN ('lead','contacted','quoted') AND followup_day<=%s{$scope}",
        crmb_att_today()
    ) );
    $followups_ok = ! $wpdb->last_error;
    $total_products = $wpdb->get_var( "SELECT COUNT(*) FROM {$t['products']}" );
    $products_ok = ! $wpdb->last_error;
    $customers = $wpdb->get_results( "SELECT * FROM {$t['customers']} WHERE 1=1{$scope} ORDER BY id DESC LIMIT 200" );
    $customer_error = (bool) $wpdb->last_error;
    $opportunities = $wpdb->get_results( "SELECT o.*,c.name AS customer_name FROM {$t['opportunities']} o INNER JOIN {$t['customers']} c ON c.id=o.customer_id WHERE 1=1" . crmb_sales_scope( 'o' ) . " ORDER BY o.followup_day IS NULL,o.followup_day,o.id DESC LIMIT 25" );
    $opportunity_error = (bool) $wpdb->last_error;
    $quotes = $wpdb->get_results( "SELECT q.*,c.name AS customer_name FROM {$t['quotes']} q INNER JOIN {$t['customers']} c ON c.id=q.customer_id WHERE 1=1" . crmb_sales_scope( 'q' ) . " ORDER BY q.id DESC LIMIT 25" );
    $quotes_error = (bool) $wpdb->last_error;
    $search = isset( $_GET['sales_search'] ) && is_string( $_GET['sales_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sales_search'] ) ) : '';
    $product_where = '';
    if ( $search ) {
        $like = '%' . $wpdb->esc_like( substr( $search, 0, 240 ) ) . '%';
        $product_where = $wpdb->prepare( ' WHERE title LIKE %s OR sku LIKE %s', $like, $like );
    }
    $catalog = $wpdb->get_results( "SELECT id,title,sku,price_toman,stock_status FROM {$t['products']}{$product_where} ORDER BY id DESC LIMIT 30" );
    $catalog_error = (bool) $wpdb->last_error;
    $edit_customer = crmb_sales_customer( crmb_sales_id( $_GET, 'edit_customer' ) );
    $edit_opportunity = null;
    $edit_opportunity_id = crmb_sales_id( $_GET, 'edit_opportunity' );
    if ( $edit_opportunity_id ) {
        $edit_opportunity = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['opportunities']} WHERE id=%d{$scope}", $edit_opportunity_id
        ) );
    }
    $last_import = get_option( 'crmb_sales_last_import', array() );
    ?>
    <nav class="crmb-sales-jump" aria-label="بخش‌های میز فروش">
        <a href="#crmb-sales-customers">مشتریان</a>
        <a href="#crmb-sales-opportunities">فرصت‌ها و پیگیری‌ها</a>
        <a href="#crmb-sales-quotes">پیش‌فاکتورها</a>
        <a href="#crmb-sales-catalog">کاتالوگ محصولات</a>
    </nav>
    <div class="crmb-leadership-metrics crmb-sales-metrics">
        <?php foreach ( array(
            array( 'مشتریان', $customers_ok ? $total_customers : null ),
            array( 'پیگیری‌های سررسید', $followups_ok ? $due_followups : null ),
            array( 'پیش‌فاکتورها', $quotes_ok ? $total_quotes : null ),
            array( 'محصولات واردشده', $products_ok ? $total_products : null ),
        ) as $metric ) : ?>
            <div class="crmb-leadership-metric"><span><?php echo esc_html( $metric[0] ); ?></span>
                <strong><?php echo null === $metric[1] ? '—' : esc_html( (string) $metric[1] ); ?></strong></div>
        <?php endforeach; ?>
    </div>

    <section class="crm-access-card crmb-sales-panel" id="crmb-sales-customers">
        <div class="crmb-leadership-heading"><h2>مشتریان</h2><span class="crm-access-muted">پرونده هر مشتری فقط برای فروشنده ثبت‌کننده و مدیر کل قابل مشاهده است.</span></div>
        <details class="crmb-sales-expand" <?php echo $edit_customer ? 'open' : ''; ?>><summary><?php echo $edit_customer ? 'ویرایش پرونده مشتری' : '+ ثبت مشتری جدید'; ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form crmb-sales-form">
                <?php crmb_sales_form_fields( 'save_customer' ); ?>
                <?php if ( $edit_customer ) : ?><input type="hidden" name="customer_id" value="<?php echo esc_attr( $edit_customer->id ); ?>"><?php endif; ?>
                <label for="sales-name">نام مشتری *</label><input id="sales-name" name="name" maxlength="180" required value="<?php echo esc_attr( $edit_customer->name ?? '' ); ?>">
                <label for="sales-phone">شماره تماس</label><input id="sales-phone" name="phone" dir="ltr" maxlength="40" value="<?php echo esc_attr( $edit_customer->phone ?? '' ); ?>">
                <label for="sales-email">ایمیل</label><input id="sales-email" name="email" type="email" dir="ltr" value="<?php echo esc_attr( $edit_customer->email ?? '' ); ?>">
                <label for="sales-company">نام شرکت</label><input id="sales-company" name="company" value="<?php echo esc_attr( $edit_customer->company ?? '' ); ?>">
                <label for="sales-notes">توضیحات تماس و نیاز مشتری</label><textarea id="sales-notes" name="notes" rows="3"><?php echo esc_textarea( $edit_customer->notes ?? '' ); ?></textarea>
                <button class="crm-access-primary" type="submit">ذخیره مشتری</button>
            </form>
        </details>
        <?php if ( $customer_error ) : ?><p role="status">مشتریان دریافت نشدند.</p>
        <?php elseif ( ! $customers ) : ?><p class="crm-access-muted">هنوز مشتری ثبت نشده است.</p>
        <?php else : ?><div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>نام</th><th>شرکت</th><th>تلفن</th><th>عملیات</th></tr></thead><tbody>
            <?php foreach ( $customers as $customer ) : ?><tr><td><?php echo esc_html( $customer->name ); ?></td><td><?php echo esc_html( $customer->company ); ?></td>
                <td dir="ltr"><?php echo esc_html( $customer->phone ); ?></td>
                <td><a href="<?php echo esc_url( add_query_arg( 'edit_customer', $customer->id, $base ) . '#crmb-sales-customers' ); ?>">ویرایش</a></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php if ( $customers_ok && (int) $total_customers > 200 ) : ?><p class="crm-access-muted">۲۰۰ پرونده جدید نمایش داده شده است؛ جست‌وجو و صفحه‌بندی کامل در مرحله بعد افزوده می‌شود.</p><?php endif; ?>
    </section>

    <section class="crm-access-card crmb-sales-panel" id="crmb-sales-opportunities">
        <div class="crmb-leadership-heading"><h2>فرصت‌ها و پیگیری فروش</h2><p class="crm-access-muted">موعد پیگیری شمسی، وضعیت و شرح اقدام بعدی</p></div>
        <details class="crmb-sales-expand" <?php echo $edit_opportunity ? 'open' : ''; ?>><summary><?php echo $edit_opportunity ? 'ویرایش فرصت فروش' : '+ فرصت فروش جدید'; ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form crmb-sales-form">
                <?php crmb_sales_form_fields( 'save_opportunity' ); ?>
                <?php if ( $edit_opportunity ) : ?><input type="hidden" name="opportunity_id" value="<?php echo esc_attr( $edit_opportunity->id ); ?>"><?php endif; ?>
                <label for="sales-op-customer">مشتری</label><select id="sales-op-customer" name="customer_id" required><option value="">انتخاب کنید</option>
                    <?php foreach ( $customers ?: array() as $c ) : ?><option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $edit_opportunity->customer_id ?? '', $c->id ); ?>><?php echo esc_html( $c->name . ' · ' . $c->phone ); ?></option><?php endforeach; ?>
                </select>
                <label for="sales-op-title">موضوع درخواست</label><input id="sales-op-title" name="title" maxlength="180" required value="<?php echo esc_attr( $edit_opportunity->title ?? '' ); ?>">
                <label for="sales-op-stage">مرحله فروش</label><select id="sales-op-stage" name="stage">
                    <?php foreach ( crmb_sales_stages() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $edit_opportunity->stage ?? 'lead', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                </select>
                <label for="sales-op-day">تاریخ پیگیری بعدی (شمسی)</label><input id="sales-op-day" name="followup_day" dir="ltr" placeholder="1405-08-15" value="<?php echo esc_attr( $edit_opportunity && $edit_opportunity->followup_day ? crmb_att_jdate( $edit_opportunity->followup_day ) : '' ); ?>">
                <label for="sales-op-notes">شرح تماس و اقدام بعدی</label><textarea id="sales-op-notes" name="notes" rows="3"><?php echo esc_textarea( $edit_opportunity->notes ?? '' ); ?></textarea>
                <button class="crm-access-primary" type="submit">ذخیره پیگیری</button>
            </form>
        </details>
        <?php if ( $opportunity_error ) : ?><p role="status">پیگیری‌های فروش در دسترس نیست.</p>
        <?php elseif ( ! $opportunities ) : ?><p class="crm-access-muted">هنوز فرصت فروشی ثبت نشده است.</p>
        <?php else : ?><div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>موضوع</th><th>مشتری</th><th>مرحله</th><th>پیگیری بعدی</th><th>عملیات</th></tr></thead><tbody>
            <?php foreach ( $opportunities as $o ) :
                $overdue = $o->followup_day && $o->followup_day <= crmb_att_today() && ! in_array( $o->stage, array( 'won', 'lost' ), true ); ?>
                <tr><td><?php echo esc_html( $o->title ); ?></td><td><?php echo esc_html( $o->customer_name ); ?></td>
                    <td><?php echo esc_html( crmb_sales_stages()[$o->stage] ?? '—' ); ?></td>
                    <td class="<?php echo $overdue ? 'crmb-work-overdue' : ''; ?>"><?php echo esc_html( $o->followup_day ? crmb_att_jdate( $o->followup_day ) : '—' ); ?></td>
                    <td><a href="<?php echo esc_url( add_query_arg( 'edit_opportunity', $o->id, $base ) . '#crmb-sales-opportunities' ); ?>">جزئیات / ویرایش</a></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="crm-access-card crmb-sales-panel" id="crmb-sales-quotes">
        <div class="crmb-leadership-heading"><h2>پیش‌فاکتورها</h2><p class="crm-access-muted">اقلام و قیمت‌ها هنگام صدور ثابت می‌شوند.</p></div>
        <details class="crmb-sales-expand" id="crmb-sales-new-quote"><summary>+ صدور پیش‌فاکتور جدید</summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form crmb-sales-form" id="crmb-sales-quote-form">
                <?php crmb_sales_form_fields( 'create_quote' ); ?>
                <label for="sales-quote-customer">مشتری *</label><select id="sales-quote-customer" name="customer_id" required><option value="">انتخاب مشتری</option>
                    <?php foreach ( $customers ?: array() as $c ) : ?><option value="<?php echo esc_attr( $c->id ); ?>"><?php echo esc_html( $c->name . ' · ' . $c->phone ); ?></option><?php endforeach; ?>
                </select>
                <label for="sales-product-search">جست‌وجوی محصول با نام یا SKU (حداقل دو حرف)</label>
                <input id="sales-product-search" type="search" autocomplete="off" placeholder="مثلاً سیم افشان ۲.۵ یا کد کالا">
                <p class="crm-access-muted">محصول را جست‌وجو و انتخاب کنید؛ سپس مقدار را تنظیم کنید. قیمت واحد از CSV واردشده خوانده می‌شود.</p>
                <div id="crmb-sales-search-results" class="crmb-sales-search-results" role="status" aria-live="polite"></div>
                <div id="crmb-sales-selected" class="crmb-sales-selected" aria-live="polite"></div>
                <label for="sales-quote-notes">شرایط و توضیحات پیش‌فاکتور</label><textarea id="sales-quote-notes" name="notes" rows="3" placeholder="مدت اعتبار، نحوه تحویل و شرایط پرداخت را اینجا بنویسید."></textarea>
                <p class="crmb-sales-total">جمع اقلام: <strong id="crmb-sales-preview-total">۰ تومان</strong></p>
                <button class="crm-access-primary" type="submit" id="crmb-sales-submit-quote" disabled>ثبت و مشاهده پیش‌فاکتور</button>
            </form>
        </details>
        <?php if ( $quotes_error ) : ?><p role="status">پیش‌فاکتورها دریافت نشدند.</p>
        <?php elseif ( ! $quotes ) : ?><p class="crm-access-muted">هنوز پیش‌فاکتوری صادر نشده است.</p>
        <?php else : ?><div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>شماره</th><th>مشتری</th><th>مبلغ (تومان)</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody>
            <?php foreach ( $quotes as $q ) : ?><tr>
                <td><a href="<?php echo esc_url( add_query_arg( array( 'sales_view' => 'quote', 'quote' => $q->id ), $base ) ); ?>" dir="ltr"><?php echo esc_html( 'PF-' . str_pad( (string) $q->id, 6, '0', STR_PAD_LEFT ) ); ?></a></td>
                <td><?php echo esc_html( $q->customer_name ); ?></td><td><?php echo esc_html( number_format( (int) $q->subtotal_toman ) ); ?></td>
                <td><?php echo esc_html( crmb_sales_quote_states()[$q->status] ?? '—' ); ?></td><td><?php echo esc_html( crmb_work_date( $q->created_utc ) ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>

    <section class="crm-access-card crmb-sales-panel" id="crmb-sales-catalog">
        <div class="crmb-leadership-heading"><h2>کاتالوگ محصولات ووکامرس</h2><span class="crm-access-muted">کاتالوگ مستقل؛ بدون اتصال خودکار به فروشگاه.</span></div>
        <?php if ( crmb_sales_admin() ) : ?>
            <details class="crmb-sales-expand"><summary>+ ورود یا به‌روزرسانی فایل CSV</summary>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-access-form crmb-sales-form">
                    <?php crmb_sales_form_fields( 'import_catalog' ); ?>
                    <label for="sales-csv">فایل CSV خروجی محصولات ووکامرس</label><input id="sales-csv" type="file" name="catalog" accept=".csv,text/csv" required>
                    <label for="sales-unit">واحد قیمت در فایل خروجی</label><select id="sales-unit" name="price_unit" required>
                        <option value="toman">تومان</option><option value="rial">ریال (تبدیل به تومان)</option>
                    </select>
                    <p class="crm-access-muted">ستون‌های ID یا SKU، نام و قیمت عادی/ویژه لازم است. سقف ۵۰۰۰ ردیف و ۸ مگابایت. محصولات قدیمی حذف نمی‌شوند؛ قیمت‌های جدید فقط بر پیش‌فاکتورهای بعدی اثر دارند.</p>
                    <button class="crm-access-primary" type="submit">بارگذاری و به‌روزرسانی کاتالوگ</button>
                </form>
            </details>
            <?php if ( is_array( $last_import ) && isset( $last_import['created'] ) ) : ?>
                <p class="crm-access-muted">آخرین ورود: <?php echo esc_html( crmb_work_date( $last_import['at_utc'] ?? '' ) ); ?> |
                    جدید: <?php echo esc_html( $last_import['created'] ); ?> |
                    به‌روزرسانی: <?php echo esc_html( $last_import['updated'] ); ?> |
                    ردشده: <?php echo esc_html( $last_import['skipped'] ); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <form method="get" action="<?php echo esc_url( home_url( '/main/' ) ); ?>" class="crmb-sales-inline">
            <input type="hidden" name="crm_section" value="sales"><label for="sales-catalog-filter">جست‌وجو</label>
            <input id="sales-catalog-filter" name="sales_search" value="<?php echo esc_attr( $search ); ?>" placeholder="نام محصول یا کد کالا">
            <button class="crm-access-primary" type="submit">نمایش</button>
        </form>
        <?php if ( $catalog_error ) : ?><p role="status">محصولات در دسترس نیستند.</p>
        <?php elseif ( ! $catalog ) : ?><p class="crm-access-muted">محصولی پیدا نشد. مدیر کل می‌تواند CSV ووکامرس را وارد کند.</p>
        <?php else : ?><div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>نام محصول</th><th>SKU</th><th>قیمت (تومان)</th><th>وضعیت موجودی فایل</th></tr></thead><tbody>
            <?php foreach ( $catalog as $p ) : ?><tr><td><?php echo esc_html( $p->title ); ?></td><td dir="ltr"><?php echo esc_html( $p->sku ); ?></td>
                <td><?php echo esc_html( number_format( (int) $p->price_toman ) ); ?></td>
                <td><?php echo esc_html( array( 'instock' => 'موجود', 'outofstock' => 'ناموجود', 'unknown' => 'نامشخص' )[$p->stock_status] ?? 'نامشخص' ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <p class="crm-access-muted">وضعیت موجودی فقط مربوط به لحظه دریافت فایل است؛ پیش از تأیید سفارش، موجودی فروشگاه اصلی را بررسی کنید.</p>
    </section>
<?php endif; ?>
</section>
<?php if ( ! $quote_view ) : ?>
<script>
(function () {
    "use strict";
    const form = document.getElementById("crmb-sales-quote-form");
    if (!form) return;
    const search = document.getElementById("sales-product-search");
    const results = document.getElementById("crmb-sales-search-results");
    const selected = document.getElementById("crmb-sales-selected");
    const submit = document.getElementById("crmb-sales-submit-quote");
    const total = document.getElementById("crmb-sales-preview-total");
    const endpoint = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    const nonce = <?php echo wp_json_encode( wp_create_nonce( 'crmb_sales_catalog' ) ); ?>;
    let timer = null, sequence = 0, products = [];
    const money = value => new Intl.NumberFormat("fa-IR").format(Math.round(value));
    function update() {
        const sum = products.reduce((s,p) => s + p.price * p.quantity, 0);
        total.textContent = money(sum) + " تومان";
        submit.disabled = products.length === 0 || products.some(p => !p.quantity || p.quantity > 99999) || sum <= 0;
    }
    function redraw() {
        selected.replaceChildren();
        products.forEach((p,i) => {
            const row = document.createElement("div"); row.className = "crmb-sales-product-row";
            const title = document.createElement("span"); title.textContent = p.name + " · " + money(p.price) + " تومان";
            const id = document.createElement("input"); id.type = "hidden"; id.name = "product_ids[]"; id.value = p.id;
            const qty = document.createElement("input"); qty.type = "number"; qty.name = "quantities[]"; qty.min = "0.01"; qty.step = "0.01"; qty.max = "99999"; qty.value = p.quantity; qty.required = true;
            qty.setAttribute("aria-label", "تعداد " + p.name);
            qty.addEventListener("input", () => { p.quantity = Number(qty.value); update(); });
            const del = document.createElement("button"); del.type = "button"; del.textContent = "حذف"; del.className = "crmb-sales-remove";
            del.addEventListener("click", () => { products.splice(i,1); redraw(); });
            row.append(title,id,qty,del); selected.append(row);
        });
        update();
    }
    search.addEventListener("input", () => {
        clearTimeout(timer);
        const q = search.value.trim(); ++sequence;
        results.replaceChildren();
        if (q.length < 2) return;
        const current = sequence;
        timer = setTimeout(async () => {
            results.textContent = "در حال جست‌وجو…";
            try {
                const url = new URL(endpoint,window.location.href);
                url.searchParams.set("action","crmb_sales_catalog");
                url.searchParams.set("nonce",nonce);
                url.searchParams.set("q",q);
                const response = await fetch(url.toString(), {credentials:"same-origin"});
                const json = await response.json();
                if (current !== sequence) return;
                results.replaceChildren();
                if (!response.ok || !json.success || !Array.isArray(json.data)) throw new Error("خطای دریافت کالا");
                if (!json.data.length) { results.textContent = "محصولی پیدا نشد."; return; }
                json.data.forEach(item => {
                    const button = document.createElement("button"); button.type = "button";
                    button.className = "crmb-sales-result";
                    button.textContent = item.title + " · " + (item.sku || "بدون SKU") + " · " + money(Number(item.price_toman)) + " تومان" + (item.stock_status === "outofstock" ? " · ناموجود در فایل" : "");
                    button.addEventListener("click", () => {
                        const id = Number(item.id);
                        if (!products.some(p => p.id === id)) {
                            if (products.length >= 30) { results.textContent = "حداکثر ۳۰ قلم در هر پیش‌فاکتور مجاز است."; return; }
                            products.push({id,name:item.title,price:Number(item.price_toman),quantity:1});
                            redraw();
                        }
                        search.value = ""; results.replaceChildren(); ++sequence; search.focus();
                    });
                    results.append(button);
                });
            } catch (error) {
                if (current === sequence) results.textContent = "جست‌وجوی محصولات ناموفق بود.";
            }
        },250);
    });
    redraw();
})();
</script>
<?php endif; ?>
