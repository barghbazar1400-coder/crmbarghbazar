<?php
/** Standalone routing/access regression checks for the unified HR view. */
define('ABSPATH', __DIR__);
$admin = true;
function current_user_can($cap) { global $admin; return $admin; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', $s); }
function wp_unslash($s) { return $s; }
function home_url($s) { return $s; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function esc_url($s) { return htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars($s); }
function get_template_part($path) { echo '[VIEW:' . $path . ']'; }
foreach (array('units' => 'departments', 'users' => 'access-management', 'roles' => 'access-management', 'invalid' => 'departments') as $tab => $view) {
    $_GET['tab'] = $tab;
    ob_start(); include __DIR__ . '/../template-parts/human-resources.php'; $html = ob_get_clean();
    if (!str_contains($html, '[VIEW:template-parts/' . $view . ']') || substr_count($html, 'aria-current="page"') !== 1) { throw new RuntimeException('HR tab routing failed'); }
}
$admin = false;
ob_start(); include __DIR__ . '/../template-parts/human-resources.php'; $html = ob_get_clean();
if ('' !== $html) { throw new RuntimeException('Unauthorized HR content'); }
echo "5 HR routing/access checks passed\n";
