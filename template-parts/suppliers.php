<?php
/** RTL supplier cards and profile forms for CEO / granted sales managers only. */
defined('ABSPATH') || exit;
if(!crmb_suppliers_can_manage()) return;
$base=add_query_arg('crm_section','suppliers',home_url('/main/'));
$search=isset($_GET['supplier_q'])&&is_string($_GET['supplier_q'])
    ?sanitize_text_field(wp_unslash($_GET['supplier_q'])):'';
$status=isset($_GET['supplier_status'])&&is_string($_GET['supplier_status'])
    ?sanitize_key(wp_unslash($_GET['supplier_status'])):'';
$archived=isset($_GET['supplier_archive'])&&'1'===$_GET['supplier_archive'];
$page=isset($_GET['supplier_page'])?max(1,absint($_GET['supplier_page'])):1;
$edit_id=isset($_GET['supplier_id'])?absint($_GET['supplier_id']):0;
$create=isset($_GET['supplier_new'])&&'1'===$_GET['supplier_new'];
$profile=$edit_id?crmb_suppliers_get($edit_id,$archived):null;
$data=$profile?:null;
$notice=isset($_GET['supplier_notice'])?sanitize_key($_GET['supplier_notice']):'';
$import_result=get_transient('crmb_suppliers_import_'.get_current_user_id());
if($import_result) delete_transient('crmb_suppliers_import_'.get_current_user_id());
$results=crmb_suppliers_search($search,$status,$page,$archived);
$labels=array(
    'saved'=>'پروفایل تأمین‌کننده ذخیره شد.',
    'archived'=>'تأمین‌کننده به بایگانی منتقل شد؛ اطلاعات حذف دائمی نشده است.',
    'restored'=>'تأمین‌کننده از بایگانی بازگردانده شد.',
    'imported'=>'اطلاعات فایل وارد شدند.',
    'invalid_csv'=>'فایل CSV معتبر نیست؛ حجم، پسوند و ساختار را بررسی کنید.',
    'invalid_header'=>'ستون‌های فایل با قالب مورد انتظار تطابق ندارند.',
    'invalid_row'=>'یک ردیف فایل ناقص یا نامعتبر است؛ هیچ ردیفی وارد نشده است.',
    'too_many'=>'فایل بیش از سقف مجاز ردیف دارد.',
    'invalid'=>'اطلاعات واردشده معتبر نیست.',
    'empty'=>'فایل حاوی تأمین‌کننده نیست.',
    'error'=>'ذخیره‌سازی انجام نشد؛ دوباره تلاش کنید.',
    'denied'=>'به این بخش دسترسی ندارید.',
    'not_found'=>'رکورد موردنظر پیدا نشد.',
);
?>
<section class="crm-access-screen crmb-suppliers" aria-labelledby="crmb-suppliers-heading">
    <header class="crm-access-header">
        <h1 id="crmb-suppliers-heading">بانک تأمین‌کنندگان</h1>
        <p>اطلاعات ارتباطی، زمینه فعالیت و سوابق شرکت‌ها و اشخاص مرتبط با تأمین کالا</p>
    </header>
    <?php if($notice): ?>
        <p class="crm-access-notice" role="status"><?php echo esc_html($labels[$notice]??'عملیات ثبت شد.'); ?>
        <?php if('imported'===$notice && is_array($import_result)): ?>
            <?php echo esc_html('تعداد فایل: '.($import_result['total']??0).' | جدید: '.($import_result['created']??0).' | تکراری: '.($import_result['skipped']??0)); ?>
        <?php elseif('invalid_row'===$notice && is_array($import_result)): ?>
            <?php echo esc_html('ردیف مشکل‌دار: '.($import_result['line']??'?')); ?>
        <?php endif; ?></p>
    <?php endif; ?>

    <?php if($create || $profile): ?>
        <section class="crm-access-card crmb-supplier-profile">
            <div class="crmb-supplier-page-header">
                <div>
                    <h2><?php echo $create?'افزودن تأمین‌کننده':esc_html($profile->supplier_name); ?></h2>
                    <p class="crm-access-muted"><?php echo $create?'پروفایل جدید بسازید و در صورت نیاز بعداً تکمیل کنید.':'پروفایل و سوابق موجود را بررسی و ویرایش کنید.'; ?></p>
                </div>
                <a class="crmb-supplier-back" href="<?php echo esc_url($base); ?>">بازگشت به فهرست</a>
            </div>
            <?php if($profile && $profile->deleted_utc): ?>
                <p class="crm-access-muted">این رکورد در بایگانی قرار دارد. برای ویرایش، ابتدا آن را بازگردانید.</p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crm-access-form crmb-supplier-form">
                    <?php crmb_suppliers_form_fields('save',$profile?(int)$profile->id:0); ?>
                    <div class="crmb-supplier-fields">
                        <?php foreach(crmb_suppliers_fields() as $field=>$meta):
                            $value=$data?(string)$data->$field:'';
                            $long=!empty($meta['multiline']);
                        ?>
                            <div class="crmb-supplier-field <?php echo $long?'is-long':''; ?>">
                                <label for="crmb-sup-<?php echo esc_attr($field); ?>"><?php echo esc_html($meta['label']); ?></label>
                                <?php if($long): ?>
                                    <textarea id="crmb-sup-<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>"
                                        rows="<?php echo 'notes'===$field?6:3; ?>" maxlength="<?php echo esc_attr($meta['max']); ?>"><?php echo esc_textarea($value); ?></textarea>
                                <?php else: ?>
                                    <input id="crmb-sup-<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>"
                                        maxlength="<?php echo esc_attr($meta['max']); ?>" value="<?php echo esc_attr($value); ?>"
                                        <?php echo 'supplier_name'===$field?'required':''; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="crmb-supplier-field">
                            <label for="crmb-sup-kind">نوع تأمین‌کننده</label>
                            <select id="crmb-sup-kind" name="supplier_type">
                                <?php foreach(crmb_suppliers_types() as $value=>$text): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($data?$data->supplier_type:'unknown',$value); ?>><?php echo esc_html($text); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="crmb-supplier-field">
                            <label for="crmb-sup-status">وضعیت همکاری</label>
                            <select id="crmb-sup-status" name="status">
                                <?php foreach(crmb_suppliers_statuses() as $value=>$text): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($data?$data->status:'new',$value); ?>><?php echo esc_html($text); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="crm-access-primary">ذخیره پروفایل</button>
                </form>
            <?php endif; ?>
            <?php if($profile): ?>
                <div class="crmb-supplier-meta">
                    <span>شناسه #<?php echo esc_html($profile->id); ?></span>
                    <span>آخرین تغییر: <?php echo esc_html(crmb_work_date($profile->updated_utc)); ?></span>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    onsubmit="return confirm('آیا از <?php echo $profile->deleted_utc?'بازگرداندن':'بایگانی کردن'; ?> این تأمین‌کننده مطمئن هستید؟');">
                    <?php crmb_suppliers_form_fields($profile->deleted_utc?'restore':'archive',(int)$profile->id); ?>
                    <button type="submit" class="crmb-supplier-danger"><?php echo $profile->deleted_utc?'بازگرداندن از بایگانی':'حذف از فهرست (بایگانی)'; ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <div class="crmb-supplier-heading-actions">
            <div class="crmb-supplier-stats">
                <span><strong><?php echo esc_html(number_format_i18n($results['total'])); ?></strong> مورد <?php echo $archived?'بایگانی‌شده':'در فیلتر فعلی'; ?></span>
            </div>
            <a class="crm-access-primary crmb-supplier-new" href="<?php echo esc_url(add_query_arg('supplier_new','1',$base)); ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> افزودن تأمین‌کننده</a>
        </div>
        <section class="crm-access-card">
            <form class="crmb-supplier-search" method="get" action="<?php echo esc_url(home_url('/main/')); ?>">
                <input type="hidden" name="crm_section" value="suppliers">
                <?php if($archived): ?><input type="hidden" name="supplier_archive" value="1"><?php endif; ?>
                <label for="crmb-supplier-query">جست‌وجوی تأمین‌کننده</label>
                <div class="crmb-supplier-filters">
                    <input type="search" id="crmb-supplier-query" name="supplier_q" value="<?php echo esc_attr($search); ?>"
                        placeholder="نام شرکت، شخص، شماره تماس، محصول، شهر یا توضیحات…">
                    <select name="supplier_status" aria-label="وضعیت تأمین‌کننده">
                        <option value="">همه وضعیت‌ها</option>
                        <?php foreach(crmb_suppliers_statuses() as $value=>$label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($status,$value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="crm-access-primary" type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> جست‌وجو</button>
                </div>
            </form>
            <nav class="crm-access-tabs" aria-label="وضعیت بایگانی">
                <a class="<?php echo !$archived?'is-active':''; ?>" href="<?php echo esc_url($base); ?>">تأمین‌کنندگان</a>
                <a class="<?php echo $archived?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg('supplier_archive','1',$base)); ?>">بایگانی</a>
            </nav>
        </section>
        <?php if(!$results['ready']): ?>
            <p class="crm-access-notice" role="status">اطلاعات تأمین‌کنندگان آماده نیست. جدول پایگاه‌داده را بررسی کنید.</p>
        <?php elseif(!$results['rows']): ?>
            <section class="crm-access-card"><p class="crm-access-muted">تأمین‌کننده‌ای برای این جست‌وجو پیدا نشد.</p></section>
        <?php else: ?>
            <div class="crmb-supplier-grid">
                <?php foreach($results['rows'] as $supplier):
                    $profile_url=add_query_arg(array('supplier_id'=>(int)$supplier->id,'supplier_archive'=>$archived?'1':'0'),$base);
                ?>
                    <article class="crmb-supplier-card">
                        <div class="crmb-supplier-card-top">
                            <span class="crmb-supplier-avatar"><i class="fa-solid <?php echo 'person'===$supplier->supplier_type?'fa-user':'fa-building'; ?>" aria-hidden="true"></i></span>
                            <span class="crmb-supplier-state"><?php echo esc_html(crmb_suppliers_statuses()[$supplier->status]??'نامشخص'); ?></span>
                        </div>
                        <h3><a href="<?php echo esc_url($profile_url); ?>"><?php echo esc_html($supplier->supplier_name); ?></a></h3>
                        <?php if($supplier->family_name): ?><p class="crmb-supplier-contact"><?php echo esc_html($supplier->family_name); ?></p><?php endif; ?>
                        <?php if($supplier->activity): ?><p class="crmb-supplier-activity"><?php echo esc_html(wp_trim_words($supplier->activity,23,'…')); ?></p><?php endif; ?>
                        <div class="crmb-supplier-card-bottom">
                            <?php if($supplier->mobile): ?><div><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i><span dir="ltr"><?php echo esc_html($supplier->mobile); ?></span></div><?php endif; ?>
                            <?php if($supplier->landline): ?><div><i class="fa-solid fa-phone" aria-hidden="true"></i><span dir="ltr"><?php echo esc_html($supplier->landline); ?></span></div><?php endif; ?>
                            <?php if($supplier->source): ?><div><i class="fa-solid fa-tag" aria-hidden="true"></i><span><?php echo esc_html($supplier->source); ?></span></div><?php endif; ?>
                            <a class="crmb-supplier-card-open" href="<?php echo esc_url($profile_url); ?>">مشاهده و ویرایش پروفایل <i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php
                $pages=max(1,(int)ceil($results['total']/18));
                $page_params=array('supplier_q'=>$search,'supplier_status'=>$status);
                if($archived) $page_params['supplier_archive']='1';
            ?>
            <nav class="crm-access-pages" aria-label="صفحات تأمین‌کنندگان">
                <?php if($page>1): ?><a href="<?php echo esc_url(add_query_arg(array_merge($page_params,array('supplier_page'=>$page-1)),$base)); ?>">قبلی</a><?php endif; ?>
                <span><?php echo esc_html($page.' / '.$pages); ?></span>
                <?php if($page<$pages): ?><a href="<?php echo esc_url(add_query_arg(array_merge($page_params,array('supplier_page'=>$page+1)),$base)); ?>">بعدی</a><?php endif; ?>
            </nav>
        <?php endif; ?>
        <?php if(!$archived): ?>
            <section class="crm-access-card crmb-supplier-import">
                <details>
                    <summary><i class="fa-solid fa-file-csv" aria-hidden="true"></i> ورود گروهی تأمین‌کنندگان از CSV</summary>
                    <p class="crm-access-muted">۸ ستون «نام، نام خانوادگی، زمینه فعالیت، تلفن همراه، وب‌سایت / شبکه‌های اجتماعی، تلفن ثابت، آدرس و توضیحات» شناسایی می‌شوند. ستون‌های خالی انتهایی نادیده گرفته می‌شوند.</p>
                    <form class="crm-access-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php crmb_suppliers_form_fields('import'); ?>
                        <label for="crmb-supplier-source">منبع اطلاعات</label>
                        <input id="crmb-supplier-source" name="source" maxlength="140" value="نمایشگاه — 2026-11-15">
                        <label for="crmb-supplier-csv">فایل CSV با کدگذاری UTF-8</label>
                        <input id="crmb-supplier-csv" type="file" name="supplier_csv" accept=".csv,text/csv" required>
                        <p class="crm-access-muted">حداکثر ۳ مگابایت و ۵۰۰۰ ردیف. آپلود دوباره فایل مشابه رکورد تکراری ایجاد نمی‌کند؛ اطلاعات قبلی و ویرایش‌های دستی جایگزین نمی‌شوند.</p>
                        <button class="crm-access-primary" type="submit" onclick="return confirm('اطلاعات این فایل به بانک تأمین‌کنندگان افزوده شود؟');">بررسی و ورود اطلاعات CSV</button>
                    </form>
                </details>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
