<?php
/** CRM sales unit shared B2B partner profiles and invoice/note timeline. */
defined('ABSPATH') || exit;
if(!crmb_partner_access())return;
$base=add_query_arg('crm_section','sales_partners',home_url('/main/'));
$id=isset($_GET['partner_id'])?crmb_work_id($_GET,'partner_id'):0;
$archived=isset($_GET['archived'])&&$_GET['archived']==='1';
$create=isset($_GET['new_partner'])&&$_GET['new_partner']==='1';
$partner=$id?crmb_partner_get($id,$archived):null;
$editing=$create||!!$partner;
$search=isset($_GET['partner_q'])&&is_string($_GET['partner_q'])?sanitize_text_field(wp_unslash($_GET['partner_q'])):'';
$status=isset($_GET['partner_status'])&&is_string($_GET['partner_status'])?sanitize_key($_GET['partner_status']):'';
$page=isset($_GET['partner_page'])?max(1,absint($_GET['partner_page'])):1;
$results=$editing?null:crmb_partner_search($search,$status,$page,$archived);
$stats=crmb_partner_stats();
$types=crmb_partner_types();$states=crmb_partner_statuses();$invoice_states=crmb_partner_invoice_statuses();
$alert=isset($_GET['partner_notice'])?sanitize_key($_GET['partner_notice']):'';
$alerts=array('saved'=>'پروفایل همکار ذخیره شد.','archived'=>'پرونده بایگانی شد.','restored'=>'پرونده بازیابی شد.',
'noted'=>'یادداشت مشترک برای تیم فروش ثبت شد.','invoice_added'=>'مشخصات فاکتور ثبت شد.',
'invoice_updated'=>'وضعیت فاکتور به‌روزرسانی شد.','duplicate'=>'شماره این فاکتور قبلاً برای این همکار ثبت شده است.',
'invalid'=>'اطلاعات واردشده معتبر نیست.','not_found'=>'پرونده موردنظر پیدا نشد.',
'error'=>'ذخیره‌سازی ناموفق بود. لطفاً دوباره تلاش کنید.','denied'=>'اجازه دسترسی ندارید.');
?>
<section class="crmb-partners" aria-labelledby="crmb-partners-h1">
    <header class="crmb-partner-hero">
        <div>
            <span class="crmb-partner-overline"><i class="fa-solid fa-people-arrows-left-right" aria-hidden="true"></i> واحد فروش / همکاران تجاری</span>
            <h1 id="crmb-partners-h1">شبکه فروش همکاران</h1>
            <p>پروفایل همکاران فروش، فاکتورها و یادداشت‌های قابل مشاهده برای تیم فروش را در یک پرونده نگه دارید.</p>
        </div>
        <div class="crmb-partner-hero-actions">
            <?php if($editing): ?><a class="crmb-partner-btn is-secondary" href="<?php echo esc_url($base); ?>">بازگشت به فهرست</a>
            <?php else: ?><a class="crmb-partner-btn is-primary" href="<?php echo esc_url(add_query_arg('new_partner','1',$base)); ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> ثبت همکار جدید</a><?php endif; ?>
        </div>
    </header>
    <?php if($alert): ?>
        <p class="crmb-partner-alert <?php echo in_array($alert,array('saved','archived','restored','noted','invoice_added','invoice_updated'),true)?'is-ok':'is-error'; ?>" role="status">
            <?php echo esc_html($alerts[$alert]??'نتیجه عملیات ثبت شد.'); ?>
        </p>
    <?php endif; ?>
    <?php if($id&&!$partner&&!$create): ?><p class="crmb-partner-alert is-error">پرونده انتخاب‌شده یافت نشد یا بایگانی شده است.</p><?php endif; ?>

    <?php if($editing): ?>
        <section class="crmb-partner-profile-header">
            <span class="crmb-partner-avatar"><i class="fa-solid fa-store" aria-hidden="true"></i></span>
            <div>
                <small><?php echo $create?'پرونده جدید':'شناسه پرونده: '.esc_html($partner->id); ?></small>
                <h2><?php echo $create?'ایجاد پروفایل همکار':esc_html($partner->name); ?></h2>
                <p><?php echo $create?'اطلاعات اصلی را ثبت کنید؛ سایر فیلدها را بعداً تکمیل کنید.':esc_html(($partner->city?:'شهر ثبت نشده').' · '.($types[$partner->partner_type]??'همکار')); ?></p>
            </div>
            <?php if($partner): ?><span class="crmb-partner-status"><?php echo esc_html($states[$partner->status]??'نامشخص'); ?></span><?php endif; ?>
        </section>
        <?php if($partner&&$partner->archived_utc): ?>
            <section class="crmb-partner-panel"><p>این همکار در بایگانی است. برای ثبت فاکتور یا یادداشت جدید ابتدا بازیابی کنید.</p></section>
        <?php else: ?>
            <section class="crmb-partner-panel">
                <header class="crmb-partner-panel-title"><i class="fa-solid fa-address-card" aria-hidden="true"></i><div><h2>اطلاعات همکار</h2><p>مشخصات ارتباطی و شرایط همکاری</p></div></header>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crmb-partner-form">
                    <?php crmb_partner_form_hidden('save_partner',$partner?(int)$partner->id:0); ?>
                    <div class="crmb-partner-fields">
                        <?php foreach(crmb_partner_fields() as $key=>$field):
                            $value=$partner?(string)$partner->$key:'';
                            $is_long=!empty($field['multiline']); ?>
                            <div class="crmb-partner-field <?php echo $is_long?'is-wide':''; ?>">
                                <label for="crmb-partner-<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?><?php if(!empty($field['required']))echo ' *'; ?></label>
                                <?php if($is_long): ?>
                                    <textarea id="crmb-partner-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="3" maxlength="<?php echo esc_attr($field['max']); ?>"><?php echo esc_textarea($value); ?></textarea>
                                <?php else: ?>
                                    <input id="crmb-partner-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                                        value="<?php echo esc_attr($value); ?>" maxlength="<?php echo esc_attr($field['max']); ?>"
                                        <?php if(!empty($field['required']))echo 'required'; ?>
                                        <?php if(in_array($key,array('mobile','phone','whatsapp'),true))echo ' dir="ltr"'; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="crmb-partner-field">
                            <label for="crmb-partner-type">نوع همکار</label>
                            <select id="crmb-partner-type" name="partner_type">
                                <?php foreach($types as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($partner?$partner->partner_type:'retailer',$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="crmb-partner-field">
                            <label for="crmb-partner-status">وضعیت همکاری</label>
                            <select id="crmb-partner-status" name="status">
                                <?php foreach($states as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($partner?$partner->status:'prospect',$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="crmb-partner-field">
                            <label for="crmb-partner-followup">تاریخ پیگیری بعدی (میلادی)</label>
                            <input type="date" id="crmb-partner-followup" name="followup_day" value="<?php echo esc_attr($partner?$partner->followup_day:''); ?>">
                        </div>
                    </div>
                    <button class="crmb-partner-btn is-primary" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> ذخیره پروفایل</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if($partner&&!$partner->archived_utc): ?>
            <div class="crmb-partner-two-panels">
                <section class="crmb-partner-panel" aria-labelledby="crmb-partner-invoices-heading">
                    <header class="crmb-partner-panel-title"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i><div>
                        <h2 id="crmb-partner-invoices-heading">سوابق فاکتور</h2>
                        <p>شماره، مبلغ، تاریخ، سررسید و وضعیت تسویه</p>
                    </div></header>
                    <?php $invoices=crmb_partner_invoices((int)$partner->id); ?>
                    <div class="crmb-partner-invoices">
                        <?php if(!$invoices): ?><p class="crmb-partner-muted">هنوز فاکتوری برای این همکار ثبت نشده است.</p><?php endif; ?>
                        <?php foreach($invoices as $invoice): ?>
                            <article class="crmb-partner-invoice">
                                <div class="crmb-partner-invoice-info">
                                    <strong>فاکتور <?php echo esc_html($invoice->invoice_no); ?></strong>
                                    <span><?php echo esc_html(number_format_i18n((int)$invoice->amount_toman)); ?> تومان</span>
                                    <small>تاریخ: <?php echo esc_html($invoice->invoice_day); ?><?php if($invoice->due_day): ?> · سررسید: <?php echo esc_html($invoice->due_day); ?><?php endif; ?></small>
                                    <?php if($invoice->description): ?><p><?php echo nl2br(esc_html($invoice->description)); ?></p><?php endif; ?>
                                </div>
                                <div class="crmb-partner-invoice-actions">
                                    <span><?php echo esc_html($invoice_states[$invoice->status]??'نامشخص'); ?></span>
                                    <?php
                                    $next_states=array(
                                        'draft'=>array('issued','cancelled'),'issued'=>array('overdue','paid','cancelled'),
                                        'overdue'=>array('paid','cancelled')
                                    );
                                    if(!empty($next_states[$invoice->status])): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php crmb_partner_form_hidden('invoice_status',(int)$partner->id); ?>
                                            <input type="hidden" name="invoice_id" value="<?php echo esc_attr($invoice->id); ?>">
                                            <label class="screen-reader-text" for="partner-invoice-state-<?php echo esc_attr($invoice->id); ?>">تغییر وضعیت</label>
                                            <select id="partner-invoice-state-<?php echo esc_attr($invoice->id); ?>" name="status">
                                                <?php foreach($next_states[$invoice->status] as $next): ?><option value="<?php echo esc_attr($next); ?>"><?php echo esc_html($invoice_states[$next]); ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="crmb-partner-btn is-secondary">ثبت وضعیت</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <details class="crmb-partner-details">
                        <summary><i class="fa-solid fa-plus" aria-hidden="true"></i> ثبت مشخصات فاکتور جدید</summary>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crmb-partner-form">
                            <?php crmb_partner_form_hidden('add_invoice',(int)$partner->id); ?>
                            <div class="crmb-partner-fields">
                                <div class="crmb-partner-field"><label for="partner-invoice-no">شماره فاکتور *</label><input required maxlength="100" name="invoice_no" id="partner-invoice-no"></div>
                                <div class="crmb-partner-field"><label for="partner-invoice-amount">مبلغ (تومان) *</label><input required type="number" min="1" max="999999999999" step="1" name="amount_toman" id="partner-invoice-amount"></div>
                                <div class="crmb-partner-field"><label for="partner-invoice-day">تاریخ فاکتور *</label><input required type="date" name="invoice_day" id="partner-invoice-day"></div>
                                <div class="crmb-partner-field"><label for="partner-invoice-due">سررسید (اختیاری)</label><input type="date" name="due_day" id="partner-invoice-due"></div>
                                <div class="crmb-partner-field is-wide"><label for="partner-invoice-description">شرح فاکتور / کالاها</label><textarea maxlength="4000" rows="3" name="description" id="partner-invoice-description"></textarea></div>
                            </div>
                            <button type="submit" class="crmb-partner-btn is-primary">ثبت فاکتور</button>
                        </form>
                    </details>
                </section>
                <section class="crmb-partner-panel" aria-labelledby="crmb-partner-notes-heading">
                    <header class="crmb-partner-panel-title"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i><div>
                        <h2 id="crmb-partner-notes-heading">یادداشت‌های مشترک</h2>
                        <p>برای هماهنگی تمام اعضای مجاز تیم فروش</p>
                    </div></header>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crmb-partner-form crmb-partner-note-composer">
                        <?php crmb_partner_form_hidden('add_note',(int)$partner->id); ?>
                        <label class="screen-reader-text" for="crmb-partner-note-input">یادداشت جدید</label>
                        <textarea required maxlength="4000" rows="4" name="note_text" id="crmb-partner-note-input" placeholder="نتیجه تماس، تجربه همکاری، شرایط توافق‌شده یا نکته‌ای برای سایر همکاران..."></textarea>
                        <button class="crmb-partner-btn is-primary" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> ثبت یادداشت برای تیم فروش</button>
                    </form>
                    <div class="crmb-partner-note-timeline">
                        <?php $notes=crmb_partner_notes((int)$partner->id); ?>
                        <?php if(!$notes): ?><p class="crmb-partner-muted">هنوز یادداشتی ثبت نشده است.</p><?php endif; ?>
                        <?php foreach($notes as $note): ?>
                            <article class="crmb-partner-note <?php echo $note->kind==='event'?'is-event':''; ?>">
                                <div><strong><?php echo esc_html(crmb_work_user_name((int)$note->author_id)); ?></strong>
                                    <time><?php echo esc_html(crmb_work_date($note->created_utc)); ?></time></div>
                                <p><?php echo nl2br(esc_html($note->note_text)); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        <?php if($partner): ?>
            <div class="crmb-partner-archive">
                <div><strong><?php echo $partner->archived_utc?'بازیابی همکار':'بایگانی پرونده'; ?></strong>
                    <p>بایگانی اطلاعات و سوابق فاکتور را حذف نمی‌کند.</p></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    onsubmit="return confirm('از انجام این عملیات مطمئن هستید؟');">
                    <?php crmb_partner_form_hidden($partner->archived_utc?'restore_partner':'archive_partner',(int)$partner->id); ?>
                    <button class="crmb-partner-btn is-secondary" type="submit"><?php echo $partner->archived_utc?'بازیابی همکار':'انتقال به بایگانی'; ?></button>
                </form>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="crmb-partner-stats">
            <?php foreach(array('total'=>array('همکار ثبت‌شده','fa-users'),'active'=>array('همکاری فعال','fa-circle-check'),
            'prospect'=>array('در حال مذاکره','fa-handshake'),'archived'=>array('بایگانی','fa-box-archive')) as $key=>$meta): ?>
                <div class="crmb-partner-stat"><i class="fa-solid <?php echo esc_attr($meta[1]); ?>" aria-hidden="true"></i>
                    <div><span><?php echo esc_html($meta[0]); ?></span><strong><?php echo esc_html(number_format_i18n($stats[$key])); ?></strong></div>
                </div>
            <?php endforeach; ?>
        </div>
        <section class="crmb-partner-panel">
            <header class="crmb-partner-panel-title"><i class="fa-solid fa-address-book" aria-hidden="true"></i><div>
                <h2>دفتر همکاران فروش</h2><p>جست‌وجوی نام، شهر، شماره تماس و زمینه همکاری</p>
            </div></header>
            <nav class="crmb-partner-tabs" aria-label="پرونده‌های همکاران">
                <a class="<?php echo !$archived?'is-active':''; ?>" href="<?php echo esc_url($base); ?>">فعال و در حال مذاکره</a>
                <a class="<?php echo $archived?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg('archived','1',$base)); ?>">بایگانی</a>
            </nav>
            <form method="get" action="<?php echo esc_url(home_url('/main/')); ?>" class="crmb-partner-search">
                <input type="hidden" name="crm_section" value="sales_partners">
                <?php if($archived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
                <input type="search" name="partner_q" aria-label="جست‌وجوی همکار" placeholder="نام فروشگاه، تلفن، شهر، کالا..." value="<?php echo esc_attr($search); ?>">
                <select name="partner_status" aria-label="وضعیت همکاری">
                    <option value="">همه وضعیت‌ها</option><?php foreach($states as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($status,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select>
                <button class="crmb-partner-btn is-primary" type="submit">جست‌وجو</button>
            </form>
            <?php if(!$results['ready']): ?>
                <p class="crmb-partner-muted">جدول همکاران هنوز آماده نیست؛ وضعیت پایگاه‌داده را بررسی کنید.</p>
            <?php elseif(!$results['rows']): ?>
                <div class="crmb-partner-empty"><i class="fa-solid fa-users" aria-hidden="true"></i>
                    <h3>همکاری در این فهرست پیدا نشد</h3><p>برای شروع، یک همکار تجاری جدید ثبت کنید.</p></div>
            <?php else: ?>
                <div class="crmb-partner-cards">
                    <?php foreach($results['rows'] as $row): ?>
                        <a class="crmb-partner-card" href="<?php echo esc_url(add_query_arg(array('partner_id'=>(int)$row->id,'archived'=>$archived?'1':'0'),$base)); ?>">
                            <div class="crmb-partner-card-top">
                                <span class="crmb-partner-avatar"><i class="fa-solid fa-store" aria-hidden="true"></i></span>
                                <span class="crmb-partner-status"><?php echo esc_html($states[$row->status]??'نامشخص'); ?></span>
                            </div>
                            <strong><?php echo esc_html($row->name); ?></strong>
                            <small><?php echo esc_html(($row->city?:'شهر مشخص نشده').' · '.($types[$row->partner_type]??'سایر')); ?></small>
                            <p><?php echo esc_html($row->products?wp_trim_words($row->products,16,'…'):'هنوز کالایی ثبت نشده است.'); ?></p>
                            <div class="crmb-partner-card-bottom"><span><?php echo esc_html($row->mobile?:$row->phone?:'تلفن ثبت نشده'); ?></span><b>مشاهده پرونده <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></b></div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php $pages=max(1,(int)ceil($results['total']/18));if($pages>1): ?>
                <nav class="crmb-partner-pagination">
                    <?php if($page>1): ?><a href="<?php echo esc_url(add_query_arg(array('partner_q'=>$search,'partner_status'=>$status,'archived'=>$archived?'1':'0','partner_page'=>$page-1),$base)); ?>">قبلی</a><?php endif; ?>
                    <span>صفحه <?php echo esc_html($page.' از '.$pages); ?></span>
                    <?php if($page<$pages): ?><a href="<?php echo esc_url(add_query_arg(array('partner_q'=>$search,'partner_status'=>$status,'archived'=>$archived?'1':'0','partner_page'=>$page+1),$base)); ?>">بعدی</a><?php endif; ?>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
