<?php
/**
 * Unified operational sales desk. The legacy detail forms remain intact,
 * while the initial view is now a compact actionable pipeline.
 */
defined( 'ABSPATH' ) || exit;
if ( ! crmb_sales_access() ) { return; }
if ( isset( $_GET['sales_view'] ) && 'quote' === $_GET['sales_view'] ) {
    get_template_part( 'template-parts/sales' );
    return;
}
get_template_part( 'template-parts/sales' );
if ( ! crmb_sales_ready() ) { return; }

global $wpdb;
$sales = crmb_sales_tables();
$ops = crmb_sales_ops_tables();
$scope = crmb_sales_scope( 'o' );
$stages = crmb_sales_stages();
$pipeline = $wpdb->get_results(
    "SELECT o.id,o.customer_id,o.owner_id,o.title,o.stage,o.followup_day,o.notes,
            c.name AS customer_name,c.phone AS customer_phone
     FROM {$sales['opportunities']} o INNER JOIN {$sales['customers']} c ON c.id=o.customer_id
     WHERE 1=1{$scope} ORDER BY o.followup_day IS NULL,o.followup_day,o.id DESC LIMIT 120"
);
$pipeline_error = (bool) $wpdb->last_error;
$groups = array_fill_keys( array_keys( $stages ), array() );
foreach ( $pipeline ?: array() as $op ) {
    if ( isset( $groups[$op->stage] ) ) { $groups[$op->stage][] = $op; }
}
$followups = $wpdb->get_results( $wpdb->prepare(
    "SELECT o.id,o.title,o.followup_day,c.name AS customer_name
     FROM {$sales['opportunities']} o INNER JOIN {$sales['customers']} c ON c.id=o.customer_id
     WHERE o.stage IN ('lead','contacted','quoted') AND o.followup_day<=%s{$scope}
     ORDER BY o.followup_day ASC,o.id DESC LIMIT 10", crmb_att_today()
) );
$followup_error = (bool) $wpdb->last_error;
$customers = $wpdb->get_results(
    "SELECT id,name,phone FROM {$sales['customers']} WHERE 1=1" . crmb_sales_scope() . " ORDER BY id DESC LIMIT 200"
);
$customer_error = (bool) $wpdb->last_error;
$calls = array(); $calls_error = false;
if ( crmb_sales_ops_ready() ) {
    $calls = $wpdb->get_results(
        "SELECT x.id,x.owner_id,x.note,x.outcome,x.created_utc,c.name AS customer_name
         FROM {$ops['calls']} x INNER JOIN {$sales['customers']} c ON x.customer_id=c.id
         WHERE 1=1" . crmb_sales_scope( 'x' ) . " ORDER BY x.id DESC LIMIT 6"
    );
    $calls_error = (bool) $wpdb->last_error;
}
$base = add_query_arg( 'crm_section', 'sales', home_url( '/main/' ) );
?>
<section id="crmb-sales-v2-overview" class="crm-access-screen crmb-sales-v2-overview" aria-label="عملیات فروش">
    <div class="crmb-sales-v2-toolbar">
        <div><span class="crmb-sales-v2-eyebrow">عملیات فروش / برق‌بازار</span><h2>امروز روی چه چیزی تمرکز کنیم؟</h2></div>
        <div class="crmb-sales-v2-buttons">
            <button type="button" class="crm-access-primary" data-sales-open="customers">+ مشتری</button>
            <button type="button" class="crm-access-primary" data-sales-open="opportunities">+ فرصت فروش</button>
            <button type="button" class="crm-access-primary" data-sales-open="quotes">+ پیش‌فاکتور</button>
        </div>
    </div>
    <?php if ( ! crmb_sales_ops_ready() ) : ?>
        <p class="crm-access-notice" role="status">راه‌اندازی تاریخچه تماس و تأیید تخفیف کامل نشده است؛ مدیر وضعیت پایگاه داده را بررسی کند.</p>
    <?php endif; ?>
    <div class="crmb-sales-v2-grid">
        <div class="crmb-sales-v2-main">
            <section class="crm-access-card">
                <div class="crmb-leadership-heading">
                    <div><h2>قیف فروش</h2><p class="crm-access-muted">هر کارت یک فرصت واقعی است؛ مرحله آن را از همین‌جا تغییر دهید.</p></div>
                    <button type="button" class="crmb-sales-v2-link" data-sales-open="opportunities">نمای جدولی و ویرایش</button>
                </div>
                <?php if ( $pipeline_error ) : ?><p role="status">اطلاعات قیف فروش دریافت نشد.</p>
                <?php else : ?>
                <div class="crmb-sales-v2-kanban" role="region" aria-label="قیف فروش" tabindex="0">
                    <?php foreach ( $stages as $stage => $label ) : ?>
                    <section class="crmb-sales-v2-lane" data-stage="<?php echo esc_attr( $stage ); ?>">
                        <header class="crmb-sales-v2-lane-header"><h3><?php echo esc_html( $label ); ?></h3><span><?php echo esc_html( count( $groups[$stage] ) ); ?></span></header>
                        <div class="crmb-sales-v2-cards">
                            <?php if ( ! $groups[$stage] ) : ?><p class="crmb-sales-v2-empty">فرصتی ثبت نشده</p><?php endif; ?>
                            <?php foreach ( $groups[$stage] as $op ) :
                                $due = $op->followup_day && $op->followup_day <= crmb_att_today() && ! in_array( $op->stage, array( 'won','lost' ), true ); ?>
                            <article class="crmb-sales-v2-deal">
                                <p class="crmb-sales-v2-deal-title"><?php echo esc_html( $op->title ); ?></p>
                                <p class="crmb-sales-v2-deal-customer"><?php echo esc_html( $op->customer_name ); ?></p>
                                <p class="<?php echo $due ? 'crmb-work-overdue' : 'crm-access-muted'; ?>"><?php echo esc_html( $op->followup_day ? 'پیگیری: ' . crmb_att_jdate( $op->followup_day ) : 'بدون زمان پیگیری' ); ?></p>
                                <form method="post" class="crmb-sales-v2-stage" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php crmb_sales_ops_form_fields( 'move_opportunity' ); ?>
                                    <input type="hidden" name="opportunity_id" value="<?php echo esc_attr( $op->id ); ?>">
                                    <label class="screen-reader-text" for="crmb-stage-<?php echo esc_attr( $op->id ); ?>">تغییر مرحله فرصت</label>
                                    <select id="crmb-stage-<?php echo esc_attr( $op->id ); ?>" name="stage">
                                        <?php foreach ( $stages as $key => $text ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $op->stage, $key ); ?>><?php echo esc_html( $text ); ?></option><?php endforeach; ?>
                                    </select>
                                    <button type="submit" title="ثبت مرحله">ثبت</button>
                                </form>
                                <a class="crmb-sales-v2-link" href="<?php echo esc_url( add_query_arg( 'edit_opportunity', $op->id, $base ) . '#crmb-sales-opportunities' ); ?>" data-sales-open="opportunities">جزئیات فرصت ←</a>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endforeach; ?>
                </div>
                <?php if ( 120 <= count( $pipeline ?: array() ) ) : ?><p class="crm-access-muted">۱۲۰ فرصت اول نمایش داده شده‌اند؛ برای بقیه از نمای جدولی استفاده کنید.</p><?php endif;
                endif; ?>
            </section>
            <section class="crm-access-card crmb-sales-v2-report">
                <div class="crmb-leadership-heading"><h2>آخرین فعالیت‌ها</h2><span class="crm-access-muted">سوابق واقعی تماس‌های ثبت‌شده</span></div>
                <?php if ( $calls_error ) : ?><p role="status">تاریخچه تماس در دسترس نیست.</p>
                <?php elseif ( ! $calls ) : ?><p class="crm-access-muted">هنوز تماسی ثبت نشده است.</p>
                <?php else : ?><div class="crmb-sales-v2-activity"><?php foreach ( $calls as $call ) : ?>
                    <div><strong><?php echo esc_html( $call->customer_name ); ?></strong>
                        <span><?php echo esc_html( crmb_work_date( $call->created_utc ) ); ?></span>
                        <p><?php echo esc_html( $call->note ); ?></p></div>
                <?php endforeach; ?></div><?php endif; ?>
            </section>
        </div>
        <aside class="crmb-sales-v2-aside" aria-label="پیگیری و تماس">
            <section class="crm-access-card">
                <div class="crmb-leadership-heading"><h2>پیگیری‌های سررسید</h2><span class="crmb-sales-v2-count"><?php echo esc_html( count( $followups ?: array() ) ); ?></span></div>
                <?php if ( $followup_error ) : ?><p role="status">پیگیری‌ها دریافت نشد.</p>
                <?php elseif ( ! $followups ) : ?><p class="crm-access-muted">مورد سررسیدشده‌ای ندارید.</p>
                <?php else : ?><ul class="crmb-sales-v2-followups"><?php foreach ( $followups as $item ) : ?>
                    <li><strong><?php echo esc_html( $item->customer_name ); ?></strong><span><?php echo esc_html( $item->title ); ?></span>
                        <small class="crmb-work-overdue"><?php echo esc_html( crmb_att_jdate( $item->followup_day ) ); ?></small></li>
                <?php endforeach; ?></ul><?php endif; ?>
            </section>
            <section class="crm-access-card">
                <h2>ثبت سریع تماس</h2>
                <p class="crm-access-muted">تماس را ثبت کنید تا در سابقه مشتری ذخیره شود.</p>
                <?php if ( crmb_sales_ops_ready() && ! $customer_error ) : ?>
                <form class="crm-access-form crmb-sales-v2-call" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php crmb_sales_ops_form_fields( 'log_call' ); ?>
                    <label for="crmb-call-customer-search">جست‌وجوی مشتری</label><input id="crmb-call-customer-search" type="search" autocomplete="off" placeholder="نام یا شماره مشتری">
                    <div id="crmb-call-customer-results" role="status"></div>
                    <label for="crmb-call-customer">مشتری</label><select id="crmb-call-customer" name="customer_id" required><option value="">انتخاب مشتری</option>
                        <?php foreach ( $customers as $c ) : ?><option value="<?php echo esc_attr( $c->id ); ?>"><?php echo esc_html( $c->name . ' · ' . $c->phone ); ?></option><?php endforeach; ?>
                    </select>
                    <label for="crmb-call-opportunity">فرصت مرتبط (اختیاری)</label><select id="crmb-call-opportunity" name="opportunity_id"><option value="0">بدون فرصت</option>
                        <?php foreach ( $pipeline ?: array() as $o ) : ?><option value="<?php echo esc_attr( $o->id ); ?>" data-customer="<?php echo esc_attr( $o->customer_id ); ?>"><?php echo esc_html( $o->title ); ?></option><?php endforeach; ?>
                    </select>
                    <label for="crmb-call-outcome">نتیجه تماس</label><select id="crmb-call-outcome" name="outcome">
                        <option value="answered">پاسخ داده شد</option><option value="unanswered">بدون پاسخ</option>
                        <option value="interested">علاقه‌مند</option><option value="not_interested">عدم تمایل</option>
                        <option value="followup">نیازمند پیگیری</option>
                    </select>
                    <label for="crmb-call-note">توضیح تماس</label><textarea id="crmb-call-note" name="note" rows="3" maxlength="3000" required></textarea>
                    <label for="crmb-call-day">پیگیری بعدی (شمسی؛ اختیاری)</label><input id="crmb-call-day" name="next_followup_day" dir="ltr" placeholder="1405-08-15">
                    <button type="submit" class="crm-access-primary">ثبت تماس و پیگیری</button>
                </form>
                <?php else : ?><p role="status">فرم تماس آماده نیست؛ مدیر وضعیت نصب را بررسی کند.</p><?php endif; ?>
            </section>
            <?php if ( crmb_sales_admin() ) :
                $pending_discounts = crmb_sales_ops_ready() ? $wpdb->get_results(
                    "SELECT d.quote_id,d.percent,c.name AS customer_name
                     FROM {$ops['discounts']} d INNER JOIN {$sales['quotes']} q ON q.id=d.quote_id
                     INNER JOIN {$sales['customers']} c ON c.id=q.customer_id
                     WHERE d.status='pending' ORDER BY d.quote_id DESC LIMIT 10"
                ) : array();
                $discounts_error = (bool) $wpdb->last_error;
                $team = $wpdb->get_results(
                    "SELECT owner_id,COUNT(*) AS total FROM {$sales['opportunities']} WHERE stage NOT IN ('won','lost') GROUP BY owner_id ORDER BY total DESC LIMIT 15"
                ); $team_error = (bool) $wpdb->last_error; ?>
            <section class="crm-access-card">
                <div class="crmb-leadership-heading"><h2>درخواست‌های تخفیف مدیر</h2></div>
                <?php if ( $discounts_error ) : ?><p role="status">درخواست‌های تخفیف دریافت نشد.</p>
                <?php elseif ( ! $pending_discounts ) : ?><p class="crm-access-muted">درخواست تخفیف منتظر تأیید ندارید.</p>
                <?php else : ?><ul class="crmb-sales-v2-team">
                    <?php foreach ( $pending_discounts as $d ) : ?><li>
                        <span><?php echo esc_html( $d->customer_name . ' · ' . $d->percent . '٪' ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( array( 'sales_view'=>'quote','quote'=>$d->quote_id ), $base ) ); ?>">بررسی</a>
                    </li><?php endforeach; ?>
                </ul><?php endif; ?>
            </section>
            <section class="crm-access-card">
                <h2>بار کاری تیم فروش</h2>
                <?php if ( $team_error ) : ?><p role="status">گزارش تیم در دسترس نیست.</p>
                <?php elseif ( ! $team ) : ?><p class="crm-access-muted">فرصتی برای تیم ثبت نشده است.</p>
                <?php else : ?><ul class="crmb-sales-v2-team"><?php foreach ( $team as $person ) : ?>
                    <li><span><?php echo esc_html( crmb_work_user_name( $person->owner_id ) ); ?></span><strong><?php echo esc_html( $person->total ); ?> فرصت باز</strong></li>
                <?php endforeach; ?></ul><?php endif; ?>
            </section>
            <?php endif; ?>
        </aside>
    </div>
</section>
<script>
(function () {
    "use strict";
    const old = document.querySelector(".crmb-sales");
    const overview = document.getElementById("crmb-sales-v2-overview");
    if (!old || !overview) return;
    old.classList.add("crmb-sales-v2-active");
    const ids = {overview:overview,customers:document.getElementById("crmb-sales-customers"),
        opportunities:document.getElementById("crmb-sales-opportunities"),
        quotes:document.getElementById("crmb-sales-quotes"),catalog:document.getElementById("crmb-sales-catalog")};
    const labels = {overview:"میز کار و قیف فروش",customers:"مشتریان",opportunities:"فرصت‌ها",
        quotes:"پیش‌فاکتورها",catalog:"کاتالوگ"};
    const navbar = document.createElement("nav"); navbar.className="crmb-sales-v2-tabs"; navbar.setAttribute("aria-label","میز فروش");
    const buttons = {};
    Object.keys(ids).forEach(key => {
        const b=document.createElement("button"); b.type="button"; b.textContent=labels[key];
        b.addEventListener("click",()=>activate(key));buttons[key]=b;navbar.append(b);
        ids[key].classList.add("crmb-sales-v2-panel");
    });
    old.insertBefore(navbar,document.querySelector(".crmb-sales-metrics"));
    const params = new URLSearchParams(location.search);
    function activate(key,updateUrl=true) {
        if (!ids[key]) key="overview";
        Object.entries(ids).forEach(([k,p])=>{p.hidden=k!==key;buttons[k].classList.toggle("is-active",k===key);buttons[k].setAttribute("aria-current",k===key?"page":"false");});
        if (updateUrl) {
            const url=new URL(location.href);url.searchParams.set("sales_tab",key);
            history.replaceState(null,"",url.toString());
        }
        if (key!=="overview") ids[key].scrollIntoView({block:"nearest"});
    }
    const current=params.get("sales_tab") || params.get("sales_anchor") || (params.has("edit_customer")?"customers":params.has("edit_opportunity")?"opportunities":params.has("sales_search")?"catalog":"overview");
    activate(current,false);
    document.querySelectorAll("[data-sales-open]").forEach(button=>{
        button.addEventListener("click",event=>{
            event.preventDefault();const key=button.dataset.salesOpen;
            if(button.tagName==="A" && button.search) {
                const url=new URL(button.href);url.searchParams.set("sales_tab",key);
                location.href=url.toString();return;
            }
            activate(key);
            const detail=ids[key].querySelector(".crmb-sales-expand");
            if(detail) detail.open=true;
        });
    });
    const customer=document.getElementById("crmb-call-customer");
    const opportunity=document.getElementById("crmb-call-opportunity");
    if(customer && opportunity) {
        const sync=()=>{opportunity.value="0";Array.from(opportunity.options).forEach(o=>{
            o.hidden=o.value!=="0" && o.dataset.customer!==customer.value;
        });};
        customer.addEventListener("change",sync);sync();
        const search=document.getElementById("crmb-call-customer-search");
        const results=document.getElementById("crmb-call-customer-results");
        let timer=0,seq=0;
        search.addEventListener("input",()=>{
            clearTimeout(timer);const term=search.value.trim(),id=++seq;results.replaceChildren();
            if(term.length<2)return;
            timer=setTimeout(async()=>{
                const url=new URL(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,location.href);
                url.searchParams.set("action","crmb_sales_customer");
                url.searchParams.set("nonce",<?php echo wp_json_encode( wp_create_nonce( 'crmb_sales_catalog' ) ); ?>);
                url.searchParams.set("q",term);
                try {
                    const response=await fetch(url.toString(),{credentials:"same-origin"}),json=await response.json();
                    if(id!==seq)return;
                    if(!response.ok||!json.success||!Array.isArray(json.data))throw new Error("failed");
                    if(!json.data.length){results.textContent="مشتری پیدا نشد";return;}
                    json.data.forEach(c=>{
                        const b=document.createElement("button");b.type="button";b.textContent=c.name+" · "+c.phone;
                        b.addEventListener("click",()=>{
                            let o=Array.from(customer.options).find(item=>item.value===String(c.id));
                            if(!o){o=new Option(c.name+" · "+c.phone,String(c.id));customer.add(o);}
                            customer.value=String(c.id);customer.dispatchEvent(new Event("change"));
                            search.value="";results.replaceChildren();++seq;
                        });results.append(b);
                    });
                } catch(e){if(id===seq)results.textContent="خطا در جست‌وجوی مشتری";}
            },250);
        });
    }
})();
</script>
