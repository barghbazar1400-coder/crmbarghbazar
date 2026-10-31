<?php
/** Project-scoped SEO workflows exercised against a real SQLite database.
 * Run with PDO SQLite: php tests/seo.php
 */
define('CRMB_TEST_BOOTSTRAP_ONLY',true);
require __DIR__.'/progress.php';
$levels=array(2=>'manager',3=>'expert',4=>'intern',5=>'manager',6=>'expert',7=>'intern');
function get_user_meta($user,$key,$single) { global $levels; return $levels[$user]??''; }
function user_can($user,$cap) { return (int)(is_object($user)?$user->ID:$user)===1; }
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
function esc_url_raw($v,$protocols=array()) { return filter_var($v,FILTER_VALIDATE_URL)?$v:''; }
function wp_http_validate_url($v) { return preg_match('#^https?://#',$v)?$v:false; }
function apply_filters($filter,$value) { if('crmbarghbazar_menu_registry'===$filter) $value['seo']=array('label'=>'SEO','access_callback'=>'crmb_seo_access'); return $value; }
class SEOTestDB extends ProgressDB { function get_col($sql) { $s=$this->run($sql); return $s?$s->fetchAll(PDO::FETCH_COLUMN):array(); } }
$wpdb=new SEOTestDB();
$wpdb->db->exec("CREATE TABLE wp_crmb_seo_projects(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,site_url TEXT,created_by INTEGER,created_utc TEXT);
CREATE TABLE wp_crmb_seo_members(project_id INTEGER,user_id INTEGER,PRIMARY KEY(project_id,user_id));
CREATE TABLE wp_crmb_seo_items(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,kind TEXT,title TEXT,body TEXT,meta_json TEXT,owner_id INTEGER,reviewer_id INTEGER DEFAULT 0,due_day TEXT,status TEXT DEFAULT 'draft',version INTEGER DEFAULT 1,created_utc TEXT,updated_utc TEXT);
CREATE TABLE wp_crmb_seo_history(id INTEGER PRIMARY KEY AUTOINCREMENT,item_id INTEGER,actor_id INTEGER,action TEXT,note TEXT,snapshot TEXT,created_utc TEXT);
CREATE TABLE wp_crmb_seo_images(id INTEGER PRIMARY KEY AUTOINCREMENT,item_id INTEGER,mime TEXT,image_data TEXT);
CREATE TABLE wp_crmb_seo_task_links(task_id INTEGER PRIMARY KEY,project_id INTEGER,item_id INTEGER DEFAULT 0);");
require __DIR__.'/../inc/seo.php';
require __DIR__.'/../inc/seo-media.php';
require __DIR__.'/../inc/access-management.php';
$uid=7; $admin=false;
check(true,crmb_seo_access()); check(false,crmbarghbazar_can_view_section('attendance')); check(true,crmbarghbazar_can_view_section('seo')); check(false,isset(crmbarghbazar_assignable_sections()['seo']));
$uid=8; check(false,crmb_seo_access()); check(false,crmbarghbazar_can_view_section('seo'));
$uid=2;
check('saved',crmb_seo_project_save(array('name'=>'Store A','site_url'=>'https://example.com'))); $project=1;
check(true,crmb_seo_is_member(1,2)); check(1,count(crmb_seo_projects()));
$uid=5; check(null,crmb_seo_project(1)); check('denied',crmb_seo_membership(array('project'=>1,'member'=>3)));
check('saved',crmb_seo_project_save(array('name'=>'Store B','site_url'=>'https://example.org')));
$uid=2; check(null,crmb_seo_project(2)); check('saved',crmb_seo_membership(array('project'=>1,'member'=>3))); check('saved',crmb_seo_membership(array('project'=>1,'member'=>4)));
$uid=3; check(false,(bool)crmb_seo_manage(1)); check('denied',crmb_seo_membership(array('project'=>1,'member'=>6)));
$uid=2;
$item=array('project'=>1,'kind'=>'content','title'=>'Cable content','body'=>'<p>Result</p>','owner'=>4,'reviewer'=>3,'target_url'=>'https://example.com/cable','brief'=>'Write the product brief','priority'=>'high');
check('saved',crmb_seo_item_save($item)); $iid=(int)$wpdb->get_var('SELECT MAX(id) FROM wp_crmb_seo_items');
check('draft',crmb_seo_item($iid)->status);
check('invalid',crmb_seo_item_save(array_replace($item,array('reviewer'=>0)))); check('invalid',crmb_seo_item_save(array_replace($item,array('owner'=>7))));
$uid=4; check(true,(bool)crmb_seo_item($iid)); check('denied',crmb_seo_item_save($item));
$version=(int)crmb_seo_item($iid)->version;
check('changed',crmb_seo_item_save($item+array('item'=>$iid,'version'=>99)));
$edit=array_replace($item,array('item'=>$iid,'version'=>$version,'body'=>'<h3>Intern deliverable</h3>','brief'=>'Tamper','owner'=>3));
check('saved',crmb_seo_item_save($edit)); $row=crmb_seo_item($iid); check(4,(int)$row->owner_id); check('Write the product brief',json_decode($row->meta_json,true)['brief']);
$state=array('item'=>$iid,'version'=>(int)$row->version,'state'=>'submitted'); check('saved',crmb_seo_review($state));
check('locked',crmb_seo_item_save(array_replace($edit,array('version'=>(int)crmb_seo_item($iid)->version))));
$uid=2; check('denied',crmb_seo_review(array_replace($state,array('version'=>(int)crmb_seo_item($iid)->version,'state'=>'approved'))));
$uid=3; check('saved',crmb_seo_review(array('item'=>$iid,'version'=>(int)crmb_seo_item($iid)->version,'state'=>'reviewed')));
$uid=2; check('saved',crmb_seo_review(array('item'=>$iid,'version'=>(int)crmb_seo_item($iid)->version,'state'=>'approved')));
check('saved',crmb_seo_review(array('item'=>$iid,'version'=>(int)crmb_seo_item($iid)->version,'state'=>'published')));
check('published',crmb_seo_item($iid)->status);
check('denied',crmb_seo_review(array('item'=>$iid,'version'=>(int)crmb_seo_item($iid)->version,'state'=>'changes','feedback'=>'')));
check('saved',crmb_seo_review(array('item'=>$iid,'version'=>(int)crmb_seo_item($iid)->version,'state'=>'changes','feedback'=>'Improve the introduction')));
check(true,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_seo_history WHERE item_id=1')>=5);
$uid=5; check(null,crmb_seo_item($iid)); check('denied',crmb_seo_review(array('item'=>$iid,'version'=>1,'state'=>'approved')));
$uid=2; check('in_use',crmb_seo_membership(array('project'=>1,'member'=>4,'task'=>'remove_member')));
$uid=3; $report=array('project'=>1,'kind'=>'report','title'=>'Weekly SEO','body'=>'<p>Analysis</p>','date_from'=>'2026-10-01','date_to'=>'2026-10-30','source'=>'Search Console web','clicks'=>'10','impressions'=>'100','position'=>'4.5');
check('saved',crmb_seo_item_save($report)); $reportid=(int)$wpdb->get_var('SELECT MAX(id) FROM wp_crmb_seo_items');
check(false,crmb_seo_meta(array_replace($report,array('clicks'=>'101')),'report')); check(false,crmb_seo_meta(array_replace($report,array('date_to'=>'2026-11-01')),'report')); check(false,crmb_seo_meta(array_replace($report,array('source'=>'')),'report'));
$empty=crmb_seo_meta(array_replace($report,array('clicks'=>'','impressions'=>'')),'report'); check(null,$empty['clicks']); check(4.5,$empty['position']);
$uid=4; check(null,crmb_seo_item($reportid));
$uid=2; $seo_task=array('project'=>1,'owner'=>4,'title'=>'Optimize cable','body'=>'Checklist','priority'=>'high','item'=>$iid);
check('saved',crmb_seo_task_save($seo_task)); $tid=(int)$wpdb->get_var('SELECT MAX(id) FROM wp_crmb_tasks'); check(true,(bool)crmb_seo_can_manage_task($tid)); check(true,(bool)crmb_progress_task($tid));
$uid=5; check(false,crmb_seo_can_manage_task($tid)); check(null,crmb_progress_task($tid)); check('denied',crmb_progress_note($tid,'note','Foreign manager'));
$uid=4; check('saved',crmb_work_mutate(array('task'=>'task_status','id'=>$tid,'status'=>'doing')));
$uid=2; check('denied',crmb_progress_transition($tid,'doing')); check('running',crmb_seo_task_save(array_replace($seo_task,array('work_id'=>$tid,'owner'=>3))));
check('saved',crmb_progress_transition($tid,'paused')); check('saved',crmb_seo_task_save(array_replace($seo_task,array('work_id'=>$tid,'owner'=>3)))); check(3,(int)crmb_progress_task($tid)->assigned_to);
$uid=5; check('denied',crmb_seo_task_save(array_replace($seo_task,array('project'=>2,'work_id'=>$tid,'owner'=>5,'item'=>0))));
$uid=4; check(null,crmb_progress_task($tid));
$uid=2; $wpdb->fail_table='wp_crmb_seo_history'; $before=(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_seo_items'); check('error',crmb_seo_item_save($item)); check($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_crmb_seo_items')); $wpdb->fail_table='';
$uid=7; check(0,count(crmb_seo_projects())); $logged=false; check(false,crmb_seo_access()); $logged=true;

function update_user_meta($user,$key,$tier) { global $levels; $levels[$user]=$tier; }
function delete_user_meta($user,$key) { global $levels; unset($levels[$user]); }
$uid=2; check('denied',crmb_seo_set_tier(7,'manager')); check('intern',crmb_seo_level(7));
$uid=1; $admin=true; check('invalid',crmb_seo_set_tier(7,'administrator')); check('saved',crmb_seo_set_tier(7,'expert')); check('expert',crmb_seo_level(7)); check('saved',crmb_seo_set_tier(7,'')); check('',crmb_seo_level(7));
$uid=2; $admin=false;
$with_checklist=array_replace($item,array('title'=>'Checklist item','checklist'=>"Review title\nReview links"));
check('saved',crmb_seo_item_save($with_checklist)); $cid=(int)$wpdb->get_var('SELECT MAX(id) FROM wp_crmb_seo_items');
$uid=4; $c=crmb_seo_item($cid); check('denied',crmb_seo_review(array('item'=>$cid,'version'=>(int)$c->version,'state'=>'submitted')));
$done=array_replace($with_checklist,array('item'=>$cid,'version'=>(int)$c->version,'checked'=>array('0','1'),'checklist'=>'Tamper'));
check('saved',crmb_seo_item_save($done)); $c=crmb_seo_item($cid); check(2,count(json_decode($c->meta_json,true)['checklist'])); check('saved',crmb_seo_review(array('item'=>$cid,'version'=>(int)$c->version,'state'=>'submitted')));
$uid=3; check('saved',crmb_seo_review(array('item'=>$cid,'version'=>(int)crmb_seo_item($cid)->version,'state'=>'reviewed')));
$uid=2; check('saved',crmb_seo_review(array('item'=>$cid,'version'=>(int)crmb_seo_item($cid)->version,'state'=>'approved')));
$uid=3; check('denied',crmb_seo_review(array('item'=>$cid,'version'=>(int)crmb_seo_item($cid)->version,'state'=>'changes','feedback'=>'Override manager')));
$uid=2; $wpdb->insert('wp_crmb_seo_images',array('item_id'=>$iid,'mime'=>'image/png','image_data'=>'abc')); $img=(int)$wpdb->insert_id;
$uid=5; check('denied',crmb_seo_image_delete($img)); $uid=3; check('denied',crmb_seo_image_delete($img)); $uid=4; check('saved',crmb_seo_image_delete($img));
check('image_error',crmb_seo_images_save($iid,array()));
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/','',$s); }
function selected($a,$b) { if((string)$a===(string)$b) echo 'selected'; }
function checked($a) { if($a) echo 'checked'; }
function get_users($args) { return array((object)array('ID'=>2,'display_name'=>'Manager'),(object)array('ID'=>3,'display_name'=>'Expert'),(object)array('ID'=>4,'display_name'=>'Intern')); }
$uid=4; $_GET=array('seo_tab'=>'content','project'=>1,'item'=>$iid);
ob_start(); include __DIR__.'/../template-parts/seo.php'; $html=ob_get_clean(); check(true,str_contains($html,'Intern deliverable')); check(true,str_contains($html,'name="body"')); check(false,str_contains($html,'name="owner"'));
$uid=5; $_GET=array('seo_tab'=>'content','project'=>1,'item'=>$iid); ob_start(); include __DIR__.'/../template-parts/seo.php'; $html=ob_get_clean(); check(false,str_contains($html,'Intern deliverable'));
$uid=3; $_GET=array('seo_tab'=>'report','project'=>1,'item'=>$reportid); ob_start(); include __DIR__.'/../template-parts/seo.php'; $html=ob_get_clean(); check(true,str_contains($html,'Search Console web')); check(true,str_contains($html,'10.00%'));
$uid=2;
$uid=2; $_GET=array('project'=>1);
ob_start(); include __DIR__.'/../template-parts/seo.php'; $workspace=ob_get_clean();
check(true,str_contains($workspace,'پرونده‌های کاری پروژه'));
check(true,str_contains($workspace,'name="seo_kind"'));
check(true,str_contains($workspace,'Cable content'));
check(true,str_contains($workspace,'seo_tab=content') || str_contains($workspace,'seo_tab%3Dcontent'));
$uid=4; $_GET=array('seo_tab'=>'workspace','project'=>1);
ob_start(); include __DIR__.'/../template-parts/seo.php'; $workspace=ob_get_clean();
check(false,str_contains($workspace,'Weekly SEO'));
$uid=2;
foreach(array('overview','projects','page','keyword','content','tasks','technical','report','training','team') as $tab) { $_GET=array('seo_tab'=>$tab,'project'=>1); ob_start(); include __DIR__.'/../template-parts/seo.php'; $html=ob_get_clean(); check(true,str_contains($html,'دپارتمان سئو')); }
$uid=8; try { crmb_seo_handle_post(); } catch(RuntimeException $e) { check('403',$e->getMessage()); }
$uid=2; $_SERVER['REQUEST_METHOD']='GET'; try { crmb_seo_handle_post(); } catch(RuntimeException $e) { check('405',$e->getMessage()); }
$_SERVER['REQUEST_METHOD']='POST'; try { crmb_seo_handle_post(); } catch(RuntimeException $e) { check('nonce_required',$e->getMessage()); }
echo "$checks SEO scope/workflow/database/rendering checks passed\n";
