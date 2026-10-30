<?php
/** SEO attachments stay behind project and record authorization, never public media URLs. */
defined('ABSPATH') || exit;
function crmb_seo_media_editable($item) { return $item && in_array($item->status,array('draft','changes'),true) && ((int)$item->owner_id===get_current_user_id() || crmb_seo_manage($item->project_id)); }
function crmb_seo_images_save($item_id,$files) {
    $item=crmb_seo_item($item_id);
    if(!crmb_seo_media_editable($item)) return 'denied';
    $images=crmb_report_uploads($files);
    if(is_wp_error($images) || !$images) return 'image_error';
    global $wpdb; $t=crmb_seo_tables();
    if(false===$wpdb->query('START TRANSACTION')) return 'error';
    $item=crmb_seo_item($item_id,true);
    if(!crmb_seo_media_editable($item)) return crmb_progress_fail('locked');
    $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['images']} WHERE item_id=%d",$item_id));
    if($wpdb->last_error) return crmb_progress_fail();
    if((int)$count+count($images)>3) return crmb_progress_fail('image_error');
    foreach($images as $image) if(false===$wpdb->insert($t['images'],$image+array('item_id'=>$item_id))) return crmb_progress_fail();
    if(false===$wpdb->update($t['items'],array('version'=>(int)$item->version+1,'updated_utc'=>gmdate('Y-m-d H:i:s')),array('id'=>$item_id)) || !crmb_seo_history($item_id,'attachment','تصویر پیوست اضافه شد.',array())) return crmb_progress_fail();
    return crmb_progress_commit();
}
function crmb_seo_image_delete($image_id) {
    global $wpdb; $t=crmb_seo_tables();
    if(!crmb_seo_access() || !crmb_seo_ready()) return 'denied';
    $item_id=$wpdb->get_var($wpdb->prepare("SELECT item_id FROM {$t['images']} WHERE id=%d",$image_id));
    if(!$item_id || false===$wpdb->query('START TRANSACTION')) return 'invalid';
    $item=crmb_seo_item($item_id,true);
    if(!crmb_seo_media_editable($item)) return crmb_progress_fail('denied');
    if(false===$wpdb->delete($t['images'],array('id'=>$image_id,'item_id'=>$item_id)) || false===$wpdb->update($t['items'],array('version'=>(int)$item->version+1,'updated_utc'=>gmdate('Y-m-d H:i:s')),array('id'=>$item_id)) || !crmb_seo_history($item_id,'attachment','تصویر پیوست حذف شد.',array())) return crmb_progress_fail();
    return crmb_progress_commit();
}
function crmb_seo_image_serve() {
    if(!crmb_seo_access() || !crmb_seo_ready()) wp_die('دسترسی مجاز نیست.','',array('response'=>403));
    global $wpdb; $t=crmb_seo_tables();
    $id=crmb_work_id($_GET,'image');
    $image=$wpdb->get_row($wpdb->prepare("SELECT item_id,mime FROM {$t['images']} WHERE id=%d",$id));
    if(!$image || !crmb_seo_item($image->item_id) || !in_array($image->mime,array('image/jpeg','image/png','image/webp'),true)) wp_die('تصویر در دسترس نیست.','',array('response'=>404));
    $encoded=$wpdb->get_var($wpdb->prepare("SELECT image_data FROM {$t['images']} WHERE id=%d",$id));
    $bytes=is_string($encoded)?base64_decode($encoded,true):false;
    if(false===$bytes) wp_die('تصویر در دسترس نیست.','',array('response'=>404));
    nocache_headers(); header('Cache-Control: private, no-store, max-age=0'); header('Content-Type: '.$image->mime); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: inline'); header("Content-Security-Policy: default-src 'none'; sandbox"); echo $bytes; exit;
}
add_action('admin_post_crmb_seo_image','crmb_seo_image_serve');
function crmb_seo_image_gallery($item,$tab) {
    global $wpdb; $t=crmb_seo_tables();
    $images=$wpdb->get_results($wpdb->prepare("SELECT id FROM {$t['images']} WHERE item_id=%d ORDER BY id",$item->id));
    echo '<div class="crmb-report-gallery">';
    foreach($images?:array() as $image) {
        $url=add_query_arg(array('action'=>'crmb_seo_image','image'=>$image->id),admin_url('admin-post.php'));
        echo '<figure class="crmb-report-photo"><a href="'.esc_url($url).'" target="_blank" rel="noopener"><img loading="lazy" src="'.esc_url($url).'" alt="پیوست رکورد سئو"></a>';
        if(crmb_seo_media_editable($item)) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; crmb_seo_form_fields('delete_seo_image',$item->project_id,$tab);
            echo '<input type="hidden" name="item" value="'.esc_attr($item->id).'"><input type="hidden" name="image" value="'.esc_attr($image->id).'"><button class="crm-access-danger" type="submit">حذف تصویر</button></form>';
        }
        echo '</figure>';
    }
    echo '</div>';
}
