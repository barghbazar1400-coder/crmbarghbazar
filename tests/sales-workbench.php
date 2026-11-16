<?php
/** Guard the customer-first sales UX and the historical-data migration design. */
$root = dirname( __DIR__ ) . '/';
$checks = 0;
function assert_sales_view( $result, $meaning ) {
    global $checks;
    ++$checks;
    if ( ! $result ) { throw new RuntimeException( "Sales view check {$checks}: {$meaning}" ); }
}
$desk = file_get_contents( $root . 'template-parts/sales-v2.php' );
$forms = file_get_contents( $root . 'template-parts/sales.php' );
$customer = file_get_contents( $root . 'inc/sales.php' );
$ops = file_get_contents( $root . 'inc/sales-operations.php' );
$css = file_get_contents( $root . 'style.css' );
assert_sales_view( str_contains( $desk, 'crmb-sales-call-form' ), 'call form exists' );
assert_sales_view( str_contains( $desk, 'name="requested_products"' ), 'requested products are recorded' );
assert_sales_view( str_contains( $desk, 'name="loss_reason"' ), 'no-sale reason is recorded' );
assert_sales_view( str_contains( $desk, 'name="next_followup_day"' ), 'followup field' );
assert_sales_view( str_contains( $desk, 'crmb-sales-calls' ), 'calls are a primary tab' );
assert_sales_view( str_contains( $desk, 'خرید انجام شد' ), 'purchase outcome label' );
assert_sales_view( str_contains( $desk, 'خرید انجام نشد' ), 'lost purchase outcome label' );
assert_sales_view( str_contains( $desk, 'تماس‌های ثبت‌شده امروز' ), 'daily contact history' );
assert_sales_view( str_contains( $desk, 'گزارش امروز کارکنان فروش' ), 'manager summary' );
assert_sales_view( ! str_contains( $desk, 'data-sales-open="opportunities"' ), 'no opportunity button' );
assert_sales_view( ! str_contains( $desk, 'crmb-sales-v2-kanban' ), 'no opportunity pipeline' );
assert_sales_view( ! str_contains( $forms, 'id="crmb-sales-opportunities"' ), 'no old opportunity form' );
assert_sales_view( ! str_contains( $forms, 'فرصت فروش' ), 'no opportunity-facing labels' );
assert_sales_view( str_contains( $forms, 'id="crmb-sales-new-customer"' ), 'new customer control' );
assert_sales_view( str_contains( $forms, 'name="customer_type"' ), 'customer category' );
assert_sales_view( str_contains( $forms, 'name="address"' ), 'customer address' );
assert_sales_view( str_contains( $forms, 'name="opportunity_id" value="0"' ), 'quotes without opportunities' );
assert_sales_view( str_contains( $forms, "window.print()" ), 'printable quotes retained' );
assert_sales_view( str_contains( $forms, 'crmb-sales-customer-file' ), 'customer file retained' );
assert_sales_view( str_contains( $forms, 'crmb_sales_call_outcomes()' ), 'customer last call status available' );
assert_sales_view( str_contains( $customer, 'crmb_sales_upgrade_customers' ), 'non-destructive customer upgrade' );
assert_sales_view( str_contains( $ops, 'crmb_sales_ops_upgrade_calls' ), 'non-destructive calls upgrade' );
assert_sales_view( str_contains( $ops, "'not_purchased' === \$outcome" ), 'reason required for no-sale' );
assert_sales_view( str_contains( $ops, "'followup' === \$outcome" ), 'followup date required' );
assert_sales_view( str_contains( $css, '.crmb-contact-center-tabs' ), 'responsive tabs styled' );
echo "{$checks} customer-first sales desk UI and migration checks passed\n";
