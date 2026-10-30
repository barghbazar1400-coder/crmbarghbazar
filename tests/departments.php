<?php
// Run with: php tests/departments.php (no WordPress installation needed).
define('ABSPATH', __DIR__);
$hooks = $terms = $meta = $members = array();
function add_action($name, $callback) { global $hooks; $hooks[$name] = $callback; }
function add_filter($name, $callback) { add_action($name, $callback); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return strip_tags($value); }
function is_wp_error($value) { return $value instanceof Exception; }
function get_userdata($id) { return in_array($id, array(1, 2), true) ? (object) array('ID' => $id) : false; }
function get_term($id, $taxonomy) { global $terms; return $terms[$id] ?? false; }
function get_term_by($field, $name, $taxonomy) { global $terms; foreach ($terms as $term) { if ($term->name === $name) return $term; } return false; }
function wp_insert_term($name, $taxonomy) { global $terms; $id = count($terms) + 1; $terms[$id] = (object) array('term_id' => $id, 'name' => $name); return array('term_id' => $id); }
function wp_update_term($id, $taxonomy, $args) { global $terms; $terms[$id]->name = $args['name']; return array('term_id' => $id); }
function update_term_meta($id, $key, $value) { global $meta; $meta[$id] = $value; return true; }
function get_term_meta($id, $key, $single) { global $meta; return $meta[$id] ?? 0; }
function get_user_meta($id, $key, $single) { global $members; return $members[$id] ?? 0; }
function update_user_meta($id, $key, $value) { global $members; $members[$id] = $value; return true; }
function delete_user_meta($id, $key) { global $members; unset($members[$id]); return true; }
function get_users($args) { global $members; return array_keys($members, (int) $args['meta_value']); }
function wp_delete_term($id, $taxonomy) { global $terms; unset($terms[$id]); return true; }
function current_user_can($cap) { global $admin; return !empty($admin); }
function wp_die($message, $title, $args) { throw new RuntimeException((string) $args['response']); }
require __DIR__ . '/../inc/departments.php';
$count = 0;
function expect($expected, $actual) { global $count; if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } $count++; }
expect('invalid', crmb_department_save(array('task' => 'save', 'name' => array())));
expect('invalid', crmb_department_save(array('task' => 'save', 'name' => 'Sales', 'manager' => 99)));
expect('saved', crmb_department_save(array('task' => 'save', 'name' => 'Sales', 'manager' => 1)));
expect('duplicate', crmb_department_save(array('task' => 'save', 'name' => 'Sales')));
expect('in_use', crmb_department_save(array('task' => 'delete', 'department_id' => 1)));
expect('saved', crmb_department_save(array('task' => 'save', 'name' => 'Sales team', 'department_id' => 1)));
expect('invalid', crmb_department_save(array('task' => 'assign', 'employee' => 99, 'department_id' => 1)));
expect('invalid', crmb_department_save(array('task' => 'assign', 'employee' => 2, 'department_id' => 99)));
expect('saved', crmb_department_save(array('task' => 'assign', 'employee' => 2, 'department_id' => 1)));
expect('in_use', crmb_department_save(array('task' => 'delete', 'department_id' => 1)));
expect('saved', crmb_department_save(array('task' => 'save', 'name' => 'Content')));
expect('saved', crmb_department_save(array('task' => 'assign', 'employee' => 2, 'department_id' => 2)));
expect(2, get_user_meta(2, '', true));
expect('deleted', crmb_department_save(array('task' => 'delete', 'department_id' => 1)));
expect('saved', crmb_department_save(array('task' => 'assign', 'employee' => 2, 'department_id' => 0)));
expect('deleted', crmb_department_save(array('task' => 'delete', 'department_id' => 2)));
try { $hooks['admin_post_crmb_departments'](); throw new RuntimeException('Access allowed'); } catch (RuntimeException $error) { expect('403', $error->getMessage()); }
$admin = false;
$options = array();
function get_option($key) { global $options; return $options[$key] ?? false; }
function update_option($key, $value, $autoload = false) { global $options; $options[$key] = $value; }
$terms = $meta = $members = array();
crmb_department_seed_defaults();
expect(0, count($terms));
$admin = true;
wp_insert_term('فروش', 'crmb_department');
update_term_meta(1, 'crmb_manager_id', 1);
crmb_department_seed_defaults();
expect(6, count($terms));
expect(1, get_term_meta(1, 'crmb_manager_id', true));
expect(6, count($options));
crmb_department_seed_defaults();
expect(6, count($terms));
wp_update_term(1, 'crmb_department', array('name' => 'فروش سازمانی'));
wp_delete_term(2, 'crmb_department');
crmb_department_seed_defaults();
expect(5, count($terms));
expect('فروش سازمانی', get_term(1, 'crmb_department')->name);
expect(false, get_term_by('name', 'سئو', 'crmb_department'));
echo "$count checks passed\n";
