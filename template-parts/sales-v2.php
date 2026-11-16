<?php
/**
 * Customer-first sales desk. Existing customer forms and quotes live in sales.php.
 * Historical opportunity rows stay in the database but are no longer a workflow.
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
$tables = crmb_sales_tables();
$ops = crmb_sales_ops_tables();
$ready = crmb_sales_ops_ready() && '1' === get_option( 'crmb_sales_calls_v2' );
$base = add_query_arg( 'crm_section', 'sales', home_url( '/main/' ) );
$today = crmb_att_today();
$local_midnight = new DateTimeImmutable( $today . ' 00:00:00', crmb_att_timezone() );
$today_utc = $local_midnight->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$tomorrow_utc = $local_midnight->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$scope = crmb_sales_scope( 'x' );
$calls = array(); $followups = array(); $totals = array( 'all' => 0, 'purchased' => 0, 'not_purchased' => 0, 'followup' => 0 );
$call_error = false;
if ( $ready ) {
    $table = $ops['calls'];
    $calls = $wpdb->get_results( $wpdb->prepare(
        "SELECT x.id,x.customer_id,x.owner_id,x.outcome,x.note,x.requested_products,x.loss_reason,
             x.next_followup_day,x.created_utc,c.name AS customer_name
         FROM {$table} x JOIN {$tables['customers']} c ON c.id=x.customer_id
         WHERE x.created_utc >= %s AND x.created_utc < %s" . $scope . "
         ORDER BY x.id DESC LIMIT 40",
        $today_utc, $tomorrow_utc
    ) );
    $call_error = (bool) $wpdb->last_error;
    $today_total = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS all_calls,
             SUM(CASE WHEN outcome='purchased' THEN 1 ELSE 0 END) AS purchased,
             SUM(CASE WHEN outcome='not_purchased' THEN 1 ELSE 0 END) AS not_purchased,
             SUM(CASE WHEN outcome='followup' THEN 1 ELSE 0 END) AS followup
         FROM {$table} x WHERE x.created_utc >= %s AND x.created_utc < %s" . $scope,
        $today_utc, $tomorrow_utc
    ) );
    $call_error = $call_error || (bool) $wpdb->last_error;
    if ( $today_total ) {
        $totals = array(
            'all' => (int) $today_total->all_calls, 'purchased' => (int) $today_total->purchased,
            'not_purchased' => (int) $today_total->not_purchased, 'followup' => (int) $today_total->followup
        );
    }
    $followups = $wpdb->get_results( $wpdb->prepare(
        "SELECT x.id,x.customer_id,x.owner_id,x.next_followup_day,x.requested_products,
                c.name AS customer_name,c.phone
         FROM {$table} x JOIN {$tables['customers']} c ON c.id=x.customer_id
         WHERE x.outcome='followup' AND x.next_followup_day <= %s
           AND x.id=(SELECT MAX(y.id) FROM {$table} y WHERE y.customer_id=x.customer_id)"
           . $scope . " ORDER BY x.next_followup_day,x.id LIMIT 30", $today
    ) );
    $call_error = $call_error || (bool) $wpdb->last_error;
}
$customers = $wpdb->get_results(
    "SELECT id,name,phone FROM {$tables['customers']} WHERE 1=1" . crmb_sales_scope()
    . " ORDER BY id DESC LIMIT 200"
);
$customer_error = (bool) $wpdb->last_error;
$team = array(); $team_error = false;
if ( $ready && crmb_sales_admin() ) {
    $team = $wpdb->get_results( $wpdb->prepare(
        "SELECT owner_id, COUNT(*) AS total,
             SUM(CASE WHEN outcome='purchased' THEN 1 ELSE 0 END) AS purchased,
             SUM(CASE WHEN outcome='not_purchased' THEN 1 ELSE 0 END) AS not_purchased
         FROM {$ops['calls']} WHERE created_utc >= %s AND created_utc < %s
         GROUP BY owner_id ORDER BY total DESC LIMIT 30",
        $today_utc, $tomorrow_utc
    ) );
    $team_error = (bool) $wpdb->last_error;
}
?>
<section id="crmb-sales-contact-center" class="crm-access-screen crmb-sales-contact-center" aria-labelledby="crmb-contact-center-heading">
    <header class="crmb-contact-center-heading">
        <div><span class="crmb-contact-center-eyebrow">واحد فروش برق‌بازار</span>
            <h2 id="crmb-contact-center-heading">تماس‌ها و پیگیری مشتریان</h2>
            <p>ثبت تماس، وضعیت خرید و اقدام بعدی؛ هر مشتری فقط یک پرونده دارد.</p>
        </div>
        <div class="crmb-contact-center-actions">
            <button type="button" data-sales-open="customers" class="crm-access-primary">+ مشتری جدید</button>
            <button type="button" data-sales-open="quotes" class="crm-access-primary">+ پیش‌فاکتور</button>
        </div>
    </header>
    <?php if ( ! $ready ) : ?>
        <p class="crm-access-notice" role="status">جداول تماس هنوز به نسخه جدید ارتقا نیافته‌اند. مدیر باید نصب دیتابیس را بررسی کند؛ سوابق قبلی حذف نشده‌اند.</p>
    <?php else : ?>
        <div class="crmb-contact-center-metrics">
            <?php foreach ( array(
                'all' => array( 'تماس‌های امروز', 'fa-phone-volume' ),
                'purchased' => array( 'منجر به خرید', 'fa-circle-check' ),
                'not_purchased' => array( 'بدون خرید', 'fa-circle-xmark' ),
                'followup' => array( 'نیازمند پیگیری', 'fa-clock' ),
            ) as $key => $meta ) : ?>
            <div class="crmb-contact-center-metric">
                <i class="fa-solid <?php echo esc_attr( $meta[1] ); ?>" aria-hidden="true"></i>
                <div><small><?php echo esc_html( $meta[0] ); ?></small><strong><?php echo esc_html( number_format_i18n( $totals[$key] ) ); ?></strong></div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="crmb-contact-center-layout">
        <div class="crmb-contact-center-main">
            <section class="crm-access-card crmb-contact-center-panel" id="crmb-sales-calls">
                <div class="crmb-leadership-heading"><div><h3>ثبت تماس مشتری</h3>
                    <p class="crm-access-muted">اطلاعات هر تماس در پرونده همان مشتری ذخیره می‌شود.</p>
                </div></div>
                <?php if ( $ready && ! $customer_error ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      class="crm-access-form crmb-contact-center-form" id="crmb-sales-call-form">
                    <?php crmb_sales_ops_form_fields( 'log_call' ); ?>
                    <label for="crmb-call-customer-search">جست‌وجوی مشتری با نام یا تلفن</label>
                    <input id="crmb-call-customer-search" type="search" placeholder="نام یا شماره مشتری..." autocomplete="off">
                    <div id="crmb-call-customer-results" class="crmb-sales-customer-results" role="status"></div>
                    <label for="crmb-call-customer">مشتری *</label>
                    <select id="crmb-call-customer" name="customer_id" required>
                        <option value="">انتخاب مشتری</option>
                        <?php foreach ( $customers ?: array() as $customer ) : ?>
                            <option value="<?php echo esc_attr( $customer->id ); ?>"><?php echo esc_html( $customer->name . ' · ' . $customer->phone ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="crmb-call-products">محصول یا درخواست مشتری</label>
                    <textarea id="crmb-call-products" name="requested_products" maxlength="2000" rows="2" placeholder="مثلاً ۵ حلقه سیم افشان ۲.۵ خراسان"></textarea>
                    <label for="crmb-call-outcome">نتیجه تماس *</label>
                    <select id="crmb-call-outcome" name="outcome" required>
                        <option value="followup">نیازمند پیگیری</option>
                        <option value="purchased">خرید انجام شد</option>
                        <option value="not_purchased">خرید انجام نشد</option>
                    </select>
                    <div id="crmb-call-loss-wrap" hidden>
                        <label for="crmb-call-loss-reason">علت خرید نکردن *</label>
                        <select id="crmb-call-loss-reason" name="loss_reason">
                            <option value="">علت را انتخاب کنید</option>
                            <?php foreach ( crmb_sales_loss_reasons() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label for="crmb-call-note">شرح مکالمه و اقدام انجام‌شده *</label>
                    <textarea id="crmb-call-note" name="note" maxlength="3000" rows="3" required placeholder="مشتری چه خواست و چه پاسخی دریافت کرد؟"></textarea>
                    <div id="crmb-call-followup-wrap">
                        <label for="crmb-call-day">تاریخ تماس بعدی (شمسی) *</label>
                        <input id="crmb-call-day" name="next_followup_day" dir="ltr" placeholder="1405-08-25">
                    </div>
                    <button type="submit" class="crm-access-primary">ثبت تماس در پرونده مشتری</button>
                </form>
                <?php else : ?><p class="crm-access-muted">ثبت تماس تا آماده‌شدن بانک اطلاعاتی در دسترس نیست.</p><?php endif; ?>
            </section>
            <section class="crm-access-card crmb-contact-center-panel">
                <div class="crmb-leadership-heading"><h3>تماس‌های ثبت‌شده امروز</h3></div>
                <?php if ( $call_error ) : ?><p role="status">دریافت تماس‌ها ناموفق بود.</p>
                <?php elseif ( ! $calls ) : ?><p class="crm-access-muted">هنوز تماسی برای امروز ثبت نشده است.</p>
                <?php else : ?>
                <div class="crmb-contact-center-history">
                    <?php foreach ( $calls as $call ) : ?>
                    <article class="crmb-contact-center-activity">
                        <div><a href="<?php echo esc_url( add_query_arg( array( 'sales_tab'=>'customers','customer'=>$call->customer_id ), $base ) ); ?>">
                            <?php echo esc_html( $call->customer_name ); ?></a>
                            <span><?php echo esc_html( crmb_sales_call_outcomes()[$call->outcome] ?? 'تماس ثبت‌شده' ); ?></span>
                        </div>
                        <p><?php echo esc_html( $call->requested_products ? $call->requested_products . ' — ' . $call->note : $call->note ); ?></p>
                        <?php if ( $call->loss_reason ) : ?>
                        <small>علت عدم خرید: <?php echo esc_html( crmb_sales_loss_reasons()[$call->loss_reason] ?? 'سایر موارد' ); ?></small>
                        <?php endif; ?>
                        <small><?php echo esc_html( crmb_work_date( $call->created_utc ) ); ?></small>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>
        <aside class="crmb-contact-center-side">
            <section class="crm-access-card crmb-contact-center-panel">
                <div class="crmb-leadership-heading"><h3>پیگیری‌های سررسیدشده</h3>
                    <strong><?php echo esc_html( count( $followups ?: array() ) ); ?></strong>
                </div>
                <?php if ( $call_error ) : ?><p role="status">دریافت پیگیری‌ها ناموفق بود.</p>
                <?php elseif ( ! $followups ) : ?><p class="crm-access-muted">پیگیری عقب‌افتاده‌ای ثبت نشده است.</p>
                <?php else : ?>
                <ul class="crmb-contact-center-followups">
                    <?php foreach ( $followups as $followup ) : ?>
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( array( 'sales_tab'=>'customers','customer'=>$followup->customer_id ), $base ) ); ?>"><?php echo esc_html( $followup->customer_name ); ?></a>
                        <span dir="ltr"><?php echo esc_html( $followup->phone ); ?></span>
                        <small>موعد: <?php echo esc_html( crmb_att_jdate( $followup->next_followup_day ) ); ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
            <?php if ( crmb_sales_admin() ) : ?>
            <section class="crm-access-card crmb-contact-center-panel">
                <div class="crmb-leadership-heading"><h3>گزارش امروز کارکنان فروش</h3></div>
                <?php if ( $team_error ) : ?><p role="status">دریافت آمار ناموفق بود.</p>
                <?php elseif ( ! $team ) : ?><p class="crm-access-muted">هنوز آماری برای امروز ثبت نشده است.</p>
                <?php else : ?>
                <div class="crm-access-table-wrap"><table class="crm-access-table"><thead><tr><th>کارمند</th><th>تماس</th><th>خرید</th><th>بدون خرید</th></tr></thead><tbody>
                    <?php foreach ( $team as $member ) : ?><tr>
                    <td><?php echo esc_html( crmb_work_user_name( $member->owner_id ) ); ?></td>
                    <td><?php echo esc_html( $member->total ); ?></td>
                    <td><?php echo esc_html( $member->purchased ); ?></td>
                    <td><?php echo esc_html( $member->not_purchased ); ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </aside>
    </div>
</section>
<script>
(function () {
    "use strict";
    const overview = document.getElementById("crmb-sales-contact-center");
    const legacy = document.querySelector(".crmb-sales");
    if (!overview || !legacy) return;
    const panels = {
        overview,
        customers:document.getElementById("crmb-sales-customers"),
        quotes:document.getElementById("crmb-sales-quotes"),
        catalog:document.getElementById("crmb-sales-catalog")
    };
    const labels = {overview:"تماس‌ها و پیگیری‌ها",customers:"مشتریان",quotes:"پیش‌فاکتورها",catalog:"کاتالوگ محصولات"};
    const tabs = document.createElement("nav");
    tabs.className = "crmb-contact-center-tabs";
    tabs.setAttribute("aria-label","بخش‌های میز فروش");
    function open(name, changeUrl) {
        if (!panels[name]) name="overview";
        Object.keys(panels).forEach(key => {
            panels[key].hidden = key !== name;
        });
        Array.from(tabs.children).forEach(button => {
            button.classList.toggle("is-active",button.dataset.tab===name);
            button.setAttribute("aria-current",button.dataset.tab===name?"page":"false");
        });
        if (changeUrl) {
            const url=new URL(location.href);
            url.searchParams.set("sales_tab",name);
            url.searchParams.delete("sales_anchor");
            window.history.replaceState(null,"",url.toString());
        }
    }
    Object.keys(labels).forEach(key=>{
        const button=document.createElement("button");
        button.type="button";
        button.textContent=labels[key];
        button.dataset.tab=key;
        button.addEventListener("click",()=>open(key,true));
        tabs.append(button);
    });
    const jump=legacy.querySelector(".crmb-sales-jump");
    if (jump) { jump.hidden=true; legacy.parentNode.insertBefore(tabs,legacy); }
    else { overview.parentNode.insertBefore(tabs,overview); }
    const metrics=legacy.querySelector(".crmb-sales-metrics");
    if (metrics) metrics.hidden=true; // Contact summary is in the primary dashboard.
    const params=new URLSearchParams(location.search);
    const initial=params.get("sales_tab") || params.get("sales_anchor") ||
        (params.has("edit_customer")||params.has("customer")?"customers":params.has("quote_customer")?"quotes":params.has("sales_search")?"catalog":"overview");
    open(initial,false);
    document.querySelectorAll("[data-sales-open]").forEach(button => button.addEventListener("click",()=>{
        const name=button.getAttribute("data-sales-open");
        open(name,true);
        if(name==="customers"){
            const el=document.querySelector("#crmb-sales-customers details.crmb-sales-expand");
            if(el){el.open=true;el.scrollIntoView({behavior:"smooth",block:"start"});}
        } else if(name==="quotes"){
            const el=document.getElementById("crmb-sales-new-quote");
            if(el){el.open=true;el.scrollIntoView({behavior:"smooth",block:"start"});}
        }
    }));
    const result=document.getElementById("crmb-call-outcome");
    const reason=document.getElementById("crmb-call-loss-wrap");
    const reasonSelect=document.getElementById("crmb-call-loss-reason");
    const followup=document.getElementById("crmb-call-followup-wrap");
    const date=document.getElementById("crmb-call-day");
    if (result && reason && followup) {
        function change(){
            const noSale=result.value==="not_purchased",pending=result.value==="followup";
            reason.hidden=!noSale;
            reasonSelect.required=noSale;
            followup.hidden=!pending;
            date.required=pending;
            if(!noSale)reasonSelect.value="";
            if(!pending)date.value="";
        }
        result.addEventListener("change",change);
        change();
    }
    const search=document.getElementById("crmb-call-customer-search");
    const customer=document.getElementById("crmb-call-customer");
    const searchResult=document.getElementById("crmb-call-customer-results");
    if(search && customer && searchResult) {
        let request=0, timer=null;
        search.addEventListener("input",()=>{
            clearTimeout(timer);
            const q=search.value.trim(),current=++request;
            searchResult.replaceChildren();
            if(q.length<2)return;
            timer=setTimeout(async()=>{
                try{
                    const url=new URL(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,location.href);
                    url.searchParams.set("action","crmb_sales_customer");
                    url.searchParams.set("nonce",<?php echo wp_json_encode( wp_create_nonce( 'crmb_sales_catalog' ) ); ?>);
                    url.searchParams.set("q",q);
                    const response=await fetch(url.toString(),{credentials:"same-origin"});
                    const json=await response.json();
                    if(current!==request)return;
                    if(!response.ok||!json.success||!Array.isArray(json.data))throw new Error();
                    searchResult.replaceChildren();
                    if(!json.data.length){searchResult.textContent="مشتری پیدا نشد.";return;}
                    json.data.forEach(person=>{
                        const button=document.createElement("button");
                        button.type="button";button.className="crmb-sales-result";
                        button.textContent=person.name+" · "+person.phone;
                        button.addEventListener("click",()=>{
                            let option=Array.from(customer.options).find(x=>x.value===String(person.id));
                            if(!option){option=new Option(person.name+" · "+person.phone,String(person.id));customer.add(option);}
                            customer.value=String(person.id); search.value="";searchResult.replaceChildren();
                        });
                        searchResult.append(button);
                    });
                }catch(e){if(current===request)searchResult.textContent="جست‌وجو در دسترس نیست.";}
            },250);
        });
    }
})();
</script>
