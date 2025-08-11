<?php
/**
 * Plugin Name: Grampus WC Migrator
 * Description: Миграция произвольных типов записей и таксономий в WooCommerce, маппинг ACF → мета Woo, подключение/копирование шаблонов в тему, поиск/замена в теме и БД, очистка исходных данных.
 * Version: 0.2.1
 * Author: Grampus  
 * License: none
 * url: https://grampus-studio.ru
 */

if (!defined('ABSPATH')) { exit; }

// Constants
define('WCM_SETTINGS_KEY', 'wcm_settings');
define('WCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add WooCommerce theme support when plugin is active
register_activation_hook(__FILE__, function(){
    add_option('wcm_add_woo_support', 1);
});
add_action('after_setup_theme', function(){
    if ((int) get_option('wcm_add_woo_support', 1) === 1) {
        add_theme_support('woocommerce');
    }
}, 11);

// Small utils
function wcm_array_get($array, $key, $default = '') { return isset($array[$key]) ? $array[$key] : $default; }

// WooCommerce presence notice
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WC Migrator: Требуется WooCommerce.</p></div>';
        });
    }
});

// Admin menu + assets
add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'WC Migrator', 'WC Migrator', 'manage_woocommerce', 'wc-migrator', 'wcm_render_admin_page');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'woocommerce_page_wc-migrator') { return; }
    wp_enqueue_style('wcmigrator-admin', WCM_PLUGIN_URL . 'assets/css/admin.css', [], '0.2.0');
    wp_enqueue_script('wcmigrator-admin', WCM_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], '0.2.0', true);
});

// Admin UI
function wcm_render_admin_page() {
    if (!current_user_can('manage_woocommerce')) { return; }

    $settings = get_option(WCM_SETTINGS_KEY, []);
    $post_types = get_post_types(['public' => true], 'objects'); unset($post_types['product']);
    $taxonomies = get_taxonomies(['public' => true], 'objects');

    if (isset($_GET['wcm_saved'])) echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>';
    if (isset($_GET['wcm_migrated'])) echo '<div class="notice notice-success is-dismissible"><p>Миграция завершена.</p></div>';
    if (isset($_GET['wcm_tpl'])) echo '<div class="notice notice-' . (($_GET['wcm_tpl']==='1')?'success':'error') . ' is-dismissible"><p>' . (($_GET['wcm_tpl']==='1')?'Шаблоны WooCommerce скопированы.':'Ошибка копирования шаблонов.') . '</p></div>';
    if (isset($_GET['wcm_tpl_custom'])) echo '<div class="notice notice-' . (($_GET['wcm_tpl_custom']==='1')?'success':'error') . ' is-dismissible"><p>' . (($_GET['wcm_tpl_custom']==='1')?'Файлы темы скопированы в папку woocommerce.':'Ошибка копирования файлов темы.') . '</p></div>';
    if (isset($_GET['wcm_srr'])) {
        $files = (int) $_GET['wcm_srr']; $db = isset($_GET['wcm_srr_db'])?(int)$_GET['wcm_srr_db']:0;
        echo '<div class="notice notice-success is-dismissible"><p>Поиск/замена: файлов ' . $files . ', записей в БД ' . $db . '.</p></div>';
    }
    if (isset($_GET['wcm_cleanup'])) {
        $dp = isset($_GET['deleted_posts']) ? (int) $_GET['deleted_posts'] : 0;
        $dt = isset($_GET['deleted_terms']) ? (int) $_GET['deleted_terms'] : 0;
        echo '<div class="notice notice-success is-dismissible"><p>Очистка: удалено записей ' . $dp . ', терминов ' . $dt . '.</p></div>';
    }

    echo '<div class="wrap wcm-wrap"><h1>WC Migrator</h1>';
    echo '<div class="wcm-tabs">'
        .'<a class="wcm-tab is-active" href="#tab-migration">Миграция</a>'
        .'<a class="wcm-tab" href="#tab-templates">Шаблоны WooCommerce</a>'
        .'<a class="wcm-tab" href="#tab-theme">Правки темы</a>'
        .'<a class="wcm-tab" href="#tab-cleanup">Очистка</a>'
        .'</div>';
    echo '<div class="wcm-panels">';

    // Migration tab
    echo '<div class="wcm-panel is-active" id="tab-migration">';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="wcm_save_settings" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_save_settings')).'" />';
    echo '<h2 class="title">Источник данных</h2><table class="form-table"><tbody>';
    echo '<tr><th><label for="source_post_type">Исходный тип записей</label></th><td><select name="settings[source_post_type]" id="source_post_type">';
    foreach ($post_types as $pt) { $sel = selected(wcm_array_get($settings,'source_post_type'), $pt->name, false);
        echo '<option value="'.esc_attr($pt->name).'" '.$sel.'>'.esc_html($pt->labels->singular_name.' ('.$pt->name.')').'</option>';}
    echo '</select></td></tr>';
    echo '<tr><th><label for="source_taxonomy">Исходная таксономия</label></th><td><select name="settings[source_taxonomy]" id="source_taxonomy">'
        .'<option value="">— не переносить —</option>';
    foreach ($taxonomies as $tx) { $sel = selected(wcm_array_get($settings,'source_taxonomy'), $tx->name, false);
        echo '<option value="'.esc_attr($tx->name).'" '.$sel.'>'.esc_html($tx->labels->singular_name.' ('.$tx->name.')').'</option>';}
    echo '</select><p class="description">Будет перенесено в product_cat.</p></td></tr></tbody></table>';

    echo '<h2 class="title">Маппинг ACF → Woo</h2><table class="form-table"><tbody>';
    $fields=[
        'price_field'=>'Цена (regular_price)','sale_price_field'=>'Скидочная цена (sale_price)','sku_field'=>'Артикул (sku)',
        'stock_qty_field'=>'Количество на складе (stock)','stock_status_field'=>'Статус склада (in_stock/on_backorder/out_of_stock)',
        'image_field'=>'Главное изображение','gallery_field'=>'Галерея','short_desc_field'=>'Короткое описание','desc_field'=>'Полное описание'
    ];
    foreach($fields as $k=>$label){$val=esc_attr(wcm_array_get($settings,$k));
        echo '<tr><th><label for="'.$k.'">'.esc_html($label).'</label></th><td><input class="regular-text" type="text" name="settings['.$k.']" id="'.$k.'" value="'.$val.'"/></td></tr>';}
    $copy_all_meta = !empty($settings['copy_all_meta']);
    echo '<tr><th><label for="copy_all_meta">Копировать всю мету</label></th><td><label><input type="checkbox" name="settings[copy_all_meta]" id="copy_all_meta" value="1" '.checked($copy_all_meta,true,false).'/> Включить</label></td></tr>';
    echo '</tbody></table>';
    submit_button('Сохранить настройки'); echo '</form>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:16px;">'
        .'<input type="hidden" name="action" value="wcm_run_migration" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_run_migration')).'" />';
    submit_button('Запустить миграцию','primary'); echo '</form>';
    echo '</div>';

    // Templates tab
    echo '<div class="wcm-panel" id="tab-templates">';
    $src_tpl = trailingslashit(WP_PLUGIN_DIR) . 'woocommerce/templates';
    $dst_tpl = trailingslashit(get_stylesheet_directory()) . 'woocommerce';
    echo '<h2 class="title">Копирование шаблонов WooCommerce</h2><p>Источник: <code>'.esc_html($src_tpl).'</code><br/>Назначение: <code>'.esc_html($dst_tpl).'</code></p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="wcm_copy_wc_templates" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_copy_wc_templates')).'" />'
        .'<label><input type="checkbox" name="overwrite" value="1"/> Перезаписать существующие</label><br/>';
    submit_button('Скопировать все шаблоны WooCommerce в тему','secondary');
    echo '</form>';

    echo '<hr/>';
    echo '<h2 class="title">Копировать файлы темы → в папку woocommerce (переименование)</h2>';
    echo '<p class="description">Укажи пары "источник в теме" → "файл назначения в theme/woocommerce". Например: <code>single.php → single-product.php</code>, <code>archive.php → archive-product.php</code>, <code>taxonomy.php → taxonomy-product_cat.php</code>.</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="wcm_copy_custom_templates" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_copy_custom_templates')).'" />';
    echo '<table class="widefat striped" style="max-width:840px"><thead><tr><th>Источник (относительно темы)</th><th>Назначение (относительно theme/woocommerce)</th></tr></thead><tbody>';
    $defaults = [
        ['single.php','single-product.php'],
        ['archive.php','archive-product.php'],
        ['taxonomy.php','taxonomy-product_cat.php'],
        ['page.php','single-product.php'],
        ['',''],
    ];
    $idx=0; foreach($defaults as $row){
        echo '<tr><td><input type="text" name="map_src['.$idx.']" class="regular-text code" placeholder="single.php" value="'.esc_attr($row[0]).'"/></td>'
            .'<td><input type="text" name="map_dst['.$idx.']" class="regular-text code" placeholder="single-product.php" value="'.esc_attr($row[1]).'"/></td></tr>'; $idx++; }
    echo '</tbody></table>';
    echo '<p><label><input type="checkbox" name="create_dirs" value="1" checked/> Создавать недостающие папки назначения</label></p>';
    submit_button('Скопировать выбранные','secondary');
    echo '</form>';

    echo '<hr/>';
    echo '<h2 class="title">Точечное копирование: только single и taxonomy</h2>';
    $src_tx = esc_attr(wcm_array_get($settings,'source_taxonomy'));
    $default_tax_src = $src_tx ? ('taxonomy-'.$src_tx.'.php') : 'taxonomy.php';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="wcm_copy_single_tax" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_copy_single_tax')).'" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Источник Single (в теме)</th><td><input type="text" name="single_src" class="regular-text code" value="single.php" placeholder="single.php" /></td></tr>';
    echo '<tr><th>Назначение Single (в woocommerce)</th><td><input type="text" name="single_dst" class="regular-text code" value="single-product.php" placeholder="single-product.php" /></td></tr>';
    echo '<tr><th>Источник Taxonomy (в теме)</th><td><input type="text" name="tax_src" class="regular-text code" value="'.$default_tax_src.'" placeholder="taxonomy.php или taxonomy-xxxxx.php" /></td></tr>';
    echo '<tr><th>Назначение Taxonomy (в woocommerce)</th><td><input type="text" name="tax_dst" class="regular-text code" value="taxonomy-product_cat.php" placeholder="taxonomy-product_cat.php" /></td></tr>';
    echo '<tr><th></th><td><label><input type="checkbox" name="create_dirs" value="1" checked/> Создавать папки</label> &nbsp; <label><input type="checkbox" name="overwrite" value="1"/> Перезаписывать</label></td></tr>';
    echo '</tbody></table>';
    submit_button('Скопировать single и taxonomy','secondary');
    echo '</form>';
    echo '</div>';

    // Theme tab
    echo '<div class="wcm-panel" id="tab-theme">';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="wcm_theme_search_replace" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_theme_search_replace')).'" />';
    $src_pt = esc_attr(wcm_array_get($settings,'source_post_type'));
    $src_tx = esc_attr(wcm_array_get($settings,'source_taxonomy'));
    echo '<table class="form-table"><tbody>'
        .'<tr><th>Старый тип записей</th><td><input class="regular-text" name="old_post_type" value="'.$src_pt.'" placeholder="catalog"></td></tr>'
        .'<tr><th>Новый тип записей</th><td><input class="regular-text" name="new_post_type" value="product"></td></tr>'
        .'<tr><th>Старая таксономия</th><td><input class="regular-text" name="old_taxonomy" value="'.$src_tx.'" placeholder="product_category"></td></tr>'
        .'<tr><th>Новая таксономия</th><td><input class="regular-text" name="new_taxonomy" value="product_cat"></td></tr>'
        .'<tr><th>Доп. замены</th><td><textarea class="large-text code" rows="5" name="custom_replacements">product_category=>product_cat</textarea></td></tr>'
        .'<tr><th></th><td><label><input type="checkbox" name="backup" value="1" checked/> Бэкап темы</label> &nbsp; <label><input type="checkbox" name="also_db" value="1"/> Замены в БД</label></td></tr>'
        .'</tbody></table>';
    submit_button('Выполнить поиск и замену','secondary'); echo '</form>';
    echo '</div>';

    // Cleanup tab
    echo '<div class="wcm-panel" id="tab-cleanup">';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="wcm_cleanup_source" />'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('wcm_cleanup_source')).'" />';
    echo '<table class="form-table"><tbody>'
        .'<tr><th>Исходный тип записей</th><td><code>'.esc_html(wcm_array_get($settings,'source_post_type')).'</code></td></tr>'
        .'<tr><th>Исходная таксономия</th><td><code>'.esc_html(wcm_array_get($settings,'source_taxonomy')).'</code></td></tr>'
        .'<tr><th></th><td><label><input type="checkbox" name="delete_posts" value="1"/> Удалить все записи исходного типа</label></td></tr>'
        .'<tr><th></th><td><label><input type="checkbox" name="delete_terms" value="1"/> Удалить все термины исходной таксономии</label></td></tr>'
        .'<tr><th></th><td><label><input type="checkbox" name="confirm" value="1"/> Подтверждаю удаление</label></td></tr>'
        .'</tbody></table>';
    submit_button('Очистить исходные данные','delete'); echo '</form>';
    echo '</div>';

    echo '</div></div>';
}

// Save settings
add_action('admin_post_wcm_save_settings', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_save_settings');
    $settings = isset($_POST['settings']) ? (array) $_POST['settings'] : [];
    foreach ($settings as $k=>$v) { if (is_string($v)) $settings[$k]=trim(wp_unslash($v)); }
    update_option(WCM_SETTINGS_KEY,$settings);
    wp_redirect(add_query_arg('wcm_saved','1',admin_url('admin.php?page=wc-migrator'))); exit;
});

// Run migration
add_action('admin_post_wcm_run_migration', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_run_migration');
    $settings = get_option(WCM_SETTINGS_KEY, []);
    $src_pt = wcm_array_get($settings,'source_post_type'); if(!$src_pt){ wp_redirect(add_query_arg('wcm_migrated','0',admin_url('admin.php?page=wc-migrator'))); exit; }
    $term_map = [];
    $src_tx = wcm_array_get($settings,'source_taxonomy'); if ($src_tx) $term_map = wcm_sync_terms_to_product_cat($src_tx);
    $ids = get_posts(['post_type'=>$src_pt,'post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1]);
    foreach($ids as $source_id){ wcm_migrate_single_post($source_id,$settings,$src_tx,$term_map); }
    wp_redirect(add_query_arg('wcm_migrated','1',admin_url('admin.php?page=wc-migrator'))); exit;
});

// Copy all Woo templates
add_action('admin_post_wcm_copy_wc_templates', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_copy_wc_templates');
    $overwrite = !empty($_POST['overwrite']);
    $ok = wcm_copy_woocommerce_templates($overwrite);
    wp_redirect(add_query_arg(['wcm_tpl' => is_wp_error($ok)?'0':'1'], admin_url('admin.php?page=wc-migrator'))); exit;
});

// Copy custom theme files into theme/woocommerce with rename
add_action('admin_post_wcm_copy_custom_templates', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_copy_custom_templates');
    $map_src = isset($_POST['map_src']) ? (array) $_POST['map_src'] : [];
    $map_dst = isset($_POST['map_dst']) ? (array) $_POST['map_dst'] : [];
    $create_dirs = !empty($_POST['create_dirs']);
    $ok = wcm_copy_theme_files_to_woocommerce($map_src, $map_dst, $create_dirs);
    wp_redirect(add_query_arg(['wcm_tpl_custom' => $ok ? '1' : '0'], admin_url('admin.php?page=wc-migrator'))); exit;
});

// Copy only single.php and taxonomy*.php into theme/woocommerce with rename
add_action('admin_post_wcm_copy_single_tax', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_copy_single_tax');

    $single_src = isset($_POST['single_src']) ? (string) wp_unslash($_POST['single_src']) : '';
    $single_dst = isset($_POST['single_dst']) ? (string) wp_unslash($_POST['single_dst']) : '';
    $tax_src    = isset($_POST['tax_src']) ? (string) wp_unslash($_POST['tax_src']) : '';
    $tax_dst    = isset($_POST['tax_dst']) ? (string) wp_unslash($_POST['tax_dst']) : '';
    $create_dirs = !empty($_POST['create_dirs']);
    $overwrite   = !empty($_POST['overwrite']);

    $ok = wcm_copy_selected_templates([
        ['src' => $single_src, 'dst' => $single_dst],
        ['src' => $tax_src,    'dst' => $tax_dst],
    ], $create_dirs, $overwrite);

    wp_redirect(add_query_arg(['wcm_tpl_custom' => $ok ? '1' : '0'], admin_url('admin.php?page=wc-migrator'))); exit;
});

// Search & Replace
add_action('admin_post_wcm_theme_search_replace', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_theme_search_replace');
    $old_pt = isset($_POST['old_post_type']) ? sanitize_key(wp_unslash($_POST['old_post_type'])) : '';
    $new_pt = isset($_POST['new_post_type']) ? sanitize_key(wp_unslash($_POST['new_post_type'])) : '';
    $old_tx = isset($_POST['old_taxonomy']) ? sanitize_key(wp_unslash($_POST['old_taxonomy'])) : '';
    $new_tx = isset($_POST['new_taxonomy']) ? sanitize_key(wp_unslash($_POST['new_taxonomy'])) : '';
    $custom = isset($_POST['custom_replacements']) ? (string) wp_unslash($_POST['custom_replacements']) : '';
    $do_backup = !empty($_POST['backup']); $also_db = !empty($_POST['also_db']);
    $replacements = [];
    if ($old_pt && $new_pt && $old_pt!==$new_pt) $replacements[$old_pt]=$new_pt;
    if ($old_tx && $new_tx && $old_tx!==$new_tx) $replacements[$old_tx]=$new_tx;
    if ($custom){ foreach(preg_split('/\r?\n/',$custom) as $l){ if(strpos($l,'=>')!==false){ list($f,$t)=array_map('trim',explode('=>',$l,2)); if($f!=='') $replacements[$f]=$t; } } }
    $files=0; $db=0; if(!empty($replacements)){ if($do_backup) wcm_backup_active_theme(); $files=wcm_theme_search_replace($replacements); if($also_db) $db=wcm_db_search_replace($replacements);}    
    wp_redirect(add_query_arg(['wcm_srr'=>(string)intval($files),'wcm_srr_db'=>(string)intval($db)], admin_url('admin.php?page=wc-migrator'))); exit;
});

// Cleanup
add_action('admin_post_wcm_cleanup_source', function(){
    if(!current_user_can('manage_woocommerce')) wp_die('Недостаточно прав.');
    check_admin_referer('wcm_cleanup_source');
    $settings = get_option(WCM_SETTINGS_KEY, []);
    $src_pt = wcm_array_get($settings,'source_post_type');
    $src_tx = wcm_array_get($settings,'source_taxonomy');
    $deleted_posts=0; $deleted_terms=0;
    if(!empty($_POST['delete_posts']) && $src_pt){ $ids=get_posts(['post_type'=>$src_pt,'post_status'=>'any','fields'=>'ids','posts_per_page'=>-1]); foreach($ids as $id){ if(wp_delete_post((int)$id,true)) $deleted_posts++; } }
    if(!empty($_POST['delete_terms']) && $src_tx){ $terms=get_terms(['taxonomy'=>$src_tx,'hide_empty'=>false]); if(!is_wp_error($terms)){ foreach($terms as $term){ $ok=wp_delete_term($term->term_id,$src_tx); if(!is_wp_error($ok)) $deleted_terms++; } } }
    wp_redirect(add_query_arg(['wcm_cleanup'=>'1','deleted_posts'=>(string)$deleted_posts,'deleted_terms'=>(string)$deleted_terms], admin_url('admin.php?page=wc-migrator'))); exit;
});

// --- Migration core ---
function wcm_migrate_single_post($source_id, $settings, $source_taxonomy, $term_map){
    $existing=get_posts(['post_type'=>'product','meta_key'=>'_wcm_source_post_id','meta_value'=>$source_id,'fields'=>'ids','posts_per_page'=>1]);
    $product_id = $existing ? (int)$existing[0] : 0;
    $source_post = get_post($source_id); if(!$source_post) return;
    $desc_field=wcm_array_get($settings,'desc_field'); $short_desc_field=wcm_array_get($settings,'short_desc_field');
    $content=$desc_field? wcm_get_field($source_id,$desc_field) : ''; if(!$content) $content=$source_post->post_content; $excerpt=$short_desc_field? wcm_get_field($source_id,$short_desc_field) : $source_post->post_excerpt;
    $postarr=['post_title'=>$source_post->post_title,'post_name'=>$source_post->post_name,'post_content'=>$content,'post_excerpt'=>$excerpt,'post_status'=>'publish','post_type'=>'product'];
    if($product_id){ $postarr['ID']=$product_id; $product_id=wp_update_post($postarr); } else { $product_id=wp_insert_post($postarr); }
    if(is_wp_error($product_id) || !$product_id) return;
    update_post_meta($product_id,'_wcm_source_post_id',$source_id);
    wp_set_object_terms($product_id,'simple','product_type',false);
    $regular_price=wcm_array_get_meta_from_mapping($source_id,$settings,'price_field'); $sale_price=wcm_array_get_meta_from_mapping($source_id,$settings,'sale_price_field'); $sku=wcm_array_get_meta_from_mapping($source_id,$settings,'sku_field'); $stock_qty=wcm_array_get_meta_from_mapping($source_id,$settings,'stock_qty_field'); $stock_status=wcm_array_get_meta_from_mapping($source_id,$settings,'stock_status_field');
    if($regular_price!==''){ update_post_meta($product_id,'_regular_price',$regular_price); update_post_meta($product_id,'_price', $sale_price!==''? $sale_price : $regular_price); }
    if($sale_price!==''){ update_post_meta($product_id,'_sale_price',$sale_price); update_post_meta($product_id,'_price',$sale_price); }
    if($sku!==''){ update_post_meta($product_id,'_sku',$sku); }
    if($stock_qty!==''){ update_post_meta($product_id,'_manage_stock','yes'); update_post_meta($product_id,'_stock',$stock_qty); }
    if($stock_status!==''){ update_post_meta($product_id,'_stock_status',$stock_status); }
    $image_field=wcm_array_get($settings,'image_field'); if($image_field){ $thumb_id=wcm_coerce_attachment_id(wcm_get_field($source_id,$image_field)); if($thumb_id) set_post_thumbnail($product_id,$thumb_id); }
    $gallery_field=wcm_array_get($settings,'gallery_field'); if($gallery_field){ $ids=wcm_normalize_gallery_ids(wcm_get_field($source_id,$gallery_field)); if(!empty($ids)) update_post_meta($product_id,'_product_image_gallery',implode(',',array_map('intval',$ids))); }
    if($source_taxonomy){ $src_terms=wp_get_object_terms($source_id,$source_taxonomy,['fields'=>'ids']); $cat_ids=[]; foreach($src_terms as $sid){ if(isset($term_map[$sid])) $cat_ids[]=(int)$term_map[$sid]; } if(!empty($cat_ids)) wp_set_object_terms($product_id,$cat_ids,'product_cat',false); }
    if(!empty($settings['copy_all_meta'])){ $all_meta=get_post_meta($source_id); $skip=['_thumbnail_id','_product_image_gallery','_regular_price','_price','_sale_price','_sku','_manage_stock','_stock','_stock_status','_wcm_source_post_id']; foreach($all_meta as $k=>$vals){ if(in_array($k,$skip,true)) continue; if(in_array($k,[wcm_array_get($settings,'price_field'),wcm_array_get($settings,'sale_price_field'),wcm_array_get($settings,'sku_field'),wcm_array_get($settings,'stock_qty_field'),wcm_array_get($settings,'stock_status_field'),wcm_array_get($settings,'image_field'),wcm_array_get($settings,'gallery_field'),wcm_array_get($settings,'short_desc_field'),wcm_array_get($settings,'desc_field')],true)) continue; foreach($vals as $v){ add_post_meta($product_id,$k,maybe_unserialize($v)); } } }
}

function wcm_sync_terms_to_product_cat($source_taxonomy){ $map=[]; $terms=get_terms(['taxonomy'=>$source_taxonomy,'hide_empty'=>false]); if(is_wp_error($terms)) return $map; $remaining=$terms; $guard=0; while(!empty($remaining) && $guard<10){ $next=[]; foreach($remaining as $t){ $parent_id=0; if($t->parent){ if(!isset($map[$t->parent])){ $next[]=$t; continue; } $parent_id=(int)$map[$t->parent]; } $exists=term_exists($t->slug,'product_cat'); if($exists && is_array($exists)){ $target_id=(int)$exists['term_id']; wp_update_term($target_id,'product_cat',['name'=>$t->name,'slug'=>$t->slug,'parent'=>$parent_id,'description'=>$t->description]); } else { $created=wp_insert_term($t->name,'product_cat',['slug'=>$t->slug,'parent'=>$parent_id,'description'=>$t->description]); if(is_wp_error($created)) continue; $target_id=(int)$created['term_id']; } $map[$t->term_id]=$target_id; } $remaining=$next; $guard++; } return $map; }

function wcm_get_field($post_id,$key){ if(function_exists('get_field')){ $val=get_field($key,$post_id); if($val!==null && $val!=='') return $val; } return get_post_meta($post_id,$key,true); }
function wcm_array_get_meta_from_mapping($post_id,$settings,$map_key){ $f=wcm_array_get($settings,$map_key); if(!$f) return ''; $val=wcm_get_field($post_id,$f); if(is_array($val)){ if(isset($val['value'])) return (string)$val['value']; if(isset($val['ID'])) return (string)$val['ID']; return implode(',',array_map('strval',$val)); } return (string)$val; }
function wcm_coerce_attachment_id($value){ if(!$value) return 0; if(is_numeric($value)) return (int)$value; if(is_array($value) && isset($value['ID'])) return (int)$value['ID']; if(is_string($value)){ $id=attachment_url_to_postid($value); if($id) return (int)$id; } return 0; }
function wcm_normalize_gallery_ids($value){ if(!$value) return []; if(is_array($value)){ $ids=[]; foreach($value as $it){ $ids[]=wcm_coerce_attachment_id($it);} return array_values(array_filter(array_map('intval',$ids))); } if(is_string($value)){ $parts=array_map('trim',explode(',',$value)); $ids=[]; foreach($parts as $p){ $ids[]=wcm_coerce_attachment_id($p);} return array_values(array_filter(array_map('intval',$ids))); } return []; }

// Template overrides (theme-defined)
add_filter('template_include', function($template){ $settings=get_option(WCM_SETTINGS_KEY,[]); $rel=wcm_array_get($settings,'template_single'); if(is_singular('product') && $rel){ $p=trailingslashit(get_template_directory()).ltrim($rel,'/'); if(file_exists($p)) return $p; } return $template; }, 99);
add_filter('taxonomy_template', function($template){ $settings=get_option(WCM_SETTINGS_KEY,[]); $rel=wcm_array_get($settings,'template_taxonomy'); if(is_tax('product_cat') && $rel){ $p=trailingslashit(get_template_directory()).ltrim($rel,'/'); if(file_exists($p)) return $p; } return $template; }, 99);

// --- Filesystem helpers ---
function wcm_copy_woocommerce_templates($overwrite=false){ $src=trailingslashit(WP_PLUGIN_DIR).'woocommerce/templates'; if(!is_dir($src)) return new WP_Error('no_src','Не найден путь WooCommerce templates'); $dst=trailingslashit(get_stylesheet_directory()).'woocommerce'; require_once ABSPATH.'wp-admin/includes/file.php'; WP_Filesystem(); global $wp_filesystem; if(!$wp_filesystem) return new WP_Error('fs','FS недоступна'); if($wp_filesystem->is_dir($dst)){ if($overwrite){ $wp_filesystem->delete($dst,true);} else { return new WP_Error('exists','Папка назначения существует'); } } $parent=dirname($dst); if(!$wp_filesystem->is_dir($parent)) $wp_filesystem->mkdir($parent); $res=copy_dir($src,$dst); if(is_wp_error($res)) return $res; return true; }

function wcm_copy_theme_files_to_woocommerce(array $map_src, array $map_dst, $create_dirs=true){ $theme=trailingslashit(get_stylesheet_directory()); $dst_root=trailingslashit($theme.'woocommerce'); if(!is_dir($dst_root) && $create_dirs){ wp_mkdir_p($dst_root); }
    $ok=true; $pairs=0; foreach($map_src as $i=>$src_rel){ $src_rel=trim((string)$src_rel); $dst_rel=isset($map_dst[$i])? trim((string)$map_dst[$i]) : ''; if($src_rel===''||$dst_rel==='') continue; $pairs++; $src=$theme.ltrim($src_rel,'/'); $dst=$dst_root.ltrim($dst_rel,'/'); $dst_dir=dirname($dst); if(!is_dir($dst_dir) && $create_dirs){ wp_mkdir_p($dst_dir);} $data=@file_get_contents($src); if($data===false){ $ok=false; continue; } $w=@file_put_contents($dst,$data); if($w===false){ $ok=false; }
    }
    return $ok && $pairs>0; }

// Safer targeted copy with sanitization and .php extension enforcement
function wcm_secure_relpath($rel){
    $rel = trim((string) $rel);
    $rel = str_replace('\\\\', '/', $rel);
    $rel = str_replace('..', '', $rel);
    $rel = ltrim($rel, '/');
    return $rel;
}
function wcm_ensure_php_ext($rel){
    $rel = (string) $rel;
    if (substr($rel, -4) !== '.php') { $rel .= '.php'; }
    return $rel;
}
function wcm_copy_selected_templates(array $pairs, $create_dirs=true, $overwrite=false){
    $theme   = trailingslashit(get_stylesheet_directory());
    $dstRoot = trailingslashit($theme.'woocommerce');
    if (!is_dir($dstRoot) && $create_dirs) { wp_mkdir_p($dstRoot); }
    $ok = true; $copied = 0;
    foreach ($pairs as $pair) {
        $srcRel = isset($pair['src']) ? wcm_secure_relpath($pair['src']) : '';
        $dstRel = isset($pair['dst']) ? wcm_secure_relpath($pair['dst']) : '';
        if ($srcRel === '' || $dstRel === '') { continue; }
        $srcRel = wcm_ensure_php_ext($srcRel);
        $dstRel = wcm_ensure_php_ext($dstRel);
        $src = $theme . $srcRel;
        $dst = $dstRoot . $dstRel;
        $dstDir = dirname($dst);
        if (!is_dir($dstDir) && $create_dirs) { wp_mkdir_p($dstDir); }
        if (file_exists($dst) && !$overwrite) { continue; }
        $data = @file_get_contents($src);
        if ($data === false) { $ok = false; continue; }
        $w = @file_put_contents($dst, $data);
        if ($w === false) { $ok = false; } else { $copied++; }
    }
    return $ok && $copied > 0;
}

function wcm_backup_active_theme(){ $theme_dir=get_stylesheet_directory(); $uploads=wp_upload_dir(); $backup=trailingslashit($uploads['basedir']).'wc-migrator/theme-backup-'.date('Ymd-His'); require_once ABSPATH.'wp-admin/includes/file.php'; WP_Filesystem(); if(!is_dir(dirname($backup))) wp_mkdir_p(dirname($backup)); copy_dir($theme_dir,$backup); }

function wcm_theme_search_replace($replacements){ $dir=get_stylesheet_directory(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)); $changed=0; foreach($it as $file){ if(!$file->isFile()) continue; $path=$file->getPathname(); $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION)); if(!in_array($ext,['php','html','twig'],true)) continue; $c=@file_get_contents($path); if($c===false) continue; $n=strtr($c,$replacements); if($n!==$c){ @file_put_contents($path,$n); $changed++; } } return $changed; }

function wcm_db_search_replace($replacements){ global $wpdb; $tables=[$wpdb->postmeta,$wpdb->termmeta,$wpdb->options]; $affected=0; foreach($tables as $table){ $id_col='id'; $val_col='val'; if($table===$wpdb->postmeta||$table===$wpdb->termmeta){ $id_col='meta_id'; $val_col='meta_value'; } else { $id_col='option_id'; $val_col='option_value'; } $rows=$wpdb->get_results("SELECT {$id_col} id, {$val_col} val FROM {$table}"); foreach($rows as $row){ $old=$row->val; $is_ser=is_serialized($old); $value=$is_ser? maybe_unserialize($old) : $old; if(is_string($value)){ $new=strtr($value,$replacements); if($new!==$value){ $stored=$is_ser? maybe_serialize($new):$new; $wpdb->update($table,[$val_col=>$stored],[$id_col=>$row->id]); $affected++; } } } } return $affected; }


