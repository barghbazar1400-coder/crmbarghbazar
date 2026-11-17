<?php
/** Render/privacy regression for the manager control center: php tests/manager-control-view.php */
define( 'ABSPATH', __DIR__ );
$allowed = true;
$checks = 0;
function crmb_leadership_can_operate() { global $allowed; return $allowed; }
function crmb_work_user_name( $id ) { return 'همکار نمونه'; }
function crmb_work_statuses() { return array( 'paused'=>'توقف موقت', 'doing'=>'در حال انجام' ); }
function crmb_progress_event_labels() { return array( 'rich_note'=>'گزارش پیشرفت' ); }
function crmb_sales_call_outcomes() { return array( 'followup'=>'نیازمند پیگیری' ); }
function crmb_att_jdate( $day ) { return '۱۴۰۵/۰۸/۲۴'; }
function crmb_work_date( $date ) { return '۱۴۰۵/۰۸/۲۴، ۱۴:۰۰'; }
function home_url( $path ) { return $path; }
function add_query_arg( $key, $value, $url = '' ) {
    if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
    return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
$control = array(
    'overdue'=>array( (object) array( 'id'=>17, 'title'=>'کار <script>bad</script>', 'assigned_to'=>7, 'due_day'=>'2026-11-16' ) ),
    'reviews'=>array( (object) array( 'id'=>19, 'title'=>'تأیید پروژه', 'assigned_to'=>7 ) ),
    'stalled'=>array( (object) array( 'id'=>18, 'title'=>'گزارش مانده', 'assigned_to'=>7, 'status'=>'paused' ) ),
    'activity'=>array( (object) array( 'task_id'=>17, 'actor_id'=>7, 'event_type'=>'rich_note', 'created_utc'=>'2026-11-17 09:30:00', 'title'=>'گزارش جدید' ) ),
    'sales_access'=>false, 'sales_ready'=>false, 'calls_today'=>null, 'calls'=>null, 'followups'=>null,
);
function crmb_leadership_control_data() { global $control; return $control; }
function view() { ob_start(); include __DIR__ . '/../template-parts/manager-control.php'; return ob_get_clean(); }
function check( $ok, $message ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( "Manager control check {$checks}: {$message}" ); } }

$html = view();
check( str_contains( $html, 'مرکز کنترل و پیگیری مدیر' ), 'Heading' );
check( str_contains( $html, 'کارهای' ) === false || str_contains( $html, 'وظایف بدون گزارش تازه' ), 'No-progress list' );
check( str_contains( $html, 'فعالیت امروز کارکنان' ), 'Team activity' );
check( str_contains( $html, 'queue=overdue' ), 'Overdue panel links to real filtered task queue' );
check( str_contains( $html, 'queue=stalled' ), 'Stalled panel links to real filtered task queue' );
check( str_contains( $html, 'queue=activity' ), 'Activity panel links to today's matching tasks' );
check( str_contains( $html, 'view=review' ), 'Review panel retains review status' );
check( str_contains( $html, 'sales_queue=followups' ), 'Sales followup panel uses a filtered queue' );

check( str_contains( $html, 'کارهای در انتظار تأیید شما' ), 'Review queue' );
check( str_contains( $html, 'تأیید یا برگشت برای اصلاح' ), 'Review action' );
check( str_contains( $html, '&lt;script&gt;bad&lt;/script&gt;' ), 'Task title escaped' );
check( ! str_contains( $html, '<script>bad</script>' ), 'No raw HTML from title' );
check( str_contains( $html, 'task_id=17' ), 'Manager task drilldown' );
check( ! str_contains( $html, 'sales_tab=customers' ), 'No sales customer links without access' );
$control['sales_access'] = true;
$control['sales_ready'] = true;
$control['calls_today'] = 1;
$control['calls'] = array( (object) array( 'customer_id'=>21, 'customer_name'=>'مشتری <img>', 'owner_id'=>7, 'outcome'=>'followup', 'created_utc'=>'2026-11-17 09:30:00' ) );
$control['followups'] = array( (object) array( 'customer_id'=>21, 'customer_name'=>'مشتری <img>', 'owner_id'=>7, 'next_followup_day'=>'2026-11-17' ) );
$html = view();
check( str_contains( $html, 'sales_tab=customers' ), 'Only authorized sales links' );
check( str_contains( $html, '&lt;img&gt;' ), 'Customer name escaped' );
check( ! str_contains( $html, 'مشتری <img>' ), 'No raw customer markup' );
check( str_contains( $html, 'تماس‌های ثبت‌شده امروز' ), 'Calls summary' );
$control['followups'] = null;
$html = view();
check( str_contains( $html, 'اطلاعات تماس‌ها در دسترس نیست' ), 'DB failure is not empty list' );
$allowed = false;
check( '' === view(), 'COO permission enforced' );
echo "$checks manager-control render/privacy checks passed\n";
