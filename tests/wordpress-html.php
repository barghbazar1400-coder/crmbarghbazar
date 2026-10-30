<?php
/** Optional integration test against an extracted WordPress wp-includes directory.
 * php tests/wordpress-html.php /path/to/wordpress/wp-includes
 */
$root = $argv[1] ?? (__DIR__ . '/../.checks/wp');
foreach (array('plugin.php','class-wp-hook.php','formatting.php','kses.php') as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Pass the path to WordPress wp-includes.\n"); exit(1); }
}
define('ABSPATH', rtrim($root, '/\\') . '/');
require $root . '/plugin.php';
require $root . '/formatting.php';
require $root . '/kses.php';
require __DIR__ . '/../inc/daily-reports.php';
$html = crmb_report_html('<h3>Result</h3><p><strong>Good</strong> <a href="javascript:alert(1)">link</a><img src="https://example.com/tracker" onerror="alert(1)"><script>alert(1)</script><iframe src="https://example.com"></iframe><p onclick="alert(1)">text</p>');
foreach (array('<img','<script','<iframe','onclick=','javascript:','onerror=') as $bad) { if(str_contains($html,$bad)) throw new RuntimeException('Unsafe HTML survived'); }
if(!str_contains($html,'<h3>Result</h3>') || !str_contains($html,'<strong>Good</strong>')) throw new RuntimeException('Safe formatting lost');
echo "7 real WordPress KSES checks passed\n";
