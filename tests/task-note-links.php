<?php
/** Rendering regression for employee-provided URLs, including old reports. */
define( 'CRMB_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/progress.php';
// WordPress make_clickable() is present in production. This small isolated
// approximation verifies that the CRM invokes it AFTER markup sanitation,
// and that a rendered link survives the final permitted-tag filter.
function make_clickable( $html ) {
    $GLOBALS['autolink_called_with'][] = $html;
    $chunks = preg_split( '/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
    $in_link = false;
    $output = '';
    foreach ( $chunks as $chunk ) {
        if ( preg_match( '/^<a\b/i', $chunk ) ) { $in_link = true; }
        if ( preg_match( '/^<\/a/i', $chunk ) ) { $in_link = false; }
        if ( ! $in_link && ! str_starts_with( $chunk, '<' ) ) {
            $chunk = preg_replace_callback( '~https?://[^\s<]+~iu', static function ( $match ) {
                $url = htmlspecialchars( $match[0], ENT_QUOTES, 'UTF-8' );
                return '<a href="' . $url . '">' . $url . '</a>';
            }, $chunk );
        }
        $output .= $chunk;
    }
    return $output;
}
$url = 'https://barghbazar.com/product/%d8%b3%db%8c%d9%85-%d8%a7%d9%81%d8%b4%d8%a7%d9%86/';
$legacy = '<p>گزارش بررسی محصول:</p><p>' . $url . '</p>';
$rendered = crmb_task_note_render_html( $legacy );
check( true, str_contains( $rendered, '<a href="' . $url . '">' . $url . '</a>' ) );
check( true, str_contains( $rendered, 'گزارش بررسی محصول:' ) );
check( 1, substr_count( $rendered, '<a ' ) );
// Sanitation is applied both before and after the URL transformer.
$dangerous = crmb_task_note_render_html(
    '<p>مستندات:</p><script>bad()</script> https://example.com/specs?id=2'
);
check( false, str_contains( $dangerous, '<script' ) );
check( true, str_contains( $dangerous, 'href="https://example.com/specs?id=2"' ) );
check( false, str_contains( $GLOBALS['autolink_called_with'][1], '<script' ) );
// Already-linked content must not acquire nested anchors.
$ready = crmb_task_note_render_html(
    '<p><a href="https://example.com/product">مشاهده محصول</a></p>'
);
check( 1, substr_count( $ready, '<a ' ) );
check( false, str_contains( $ready, '<a href="https://example.com/product"><a' ) );
// The actual task history page calls the render helper, rather than the
// old sanitizer-only function that left percent-encoded URLs inert.
$template = file_get_contents( __DIR__ . '/../template-parts/task-progress.php' );
check( true, str_contains( $template, 'crmb_task_note_render_html( $event->body )' ) );
echo "$checks task progress linkification and double-sanitization checks passed\n";
