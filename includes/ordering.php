<?php
/**
 * Adminino: Post ordering tab.
 *
 * Drag and drop rows on the admin list screens (posts, pages, custom post types) to set their order.
 * The order is stored in WordPress's own menu_order field and, unless "Don't apply on the website" is ticked,
 * also used on the website for queries that use the default (date) order.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_AME_Order', false)) {

final class MK_AME_Order {
  const OPT = 'mk_ame_order';

  public static function init() {
    add_action('admin_post_mk_ame_save_order', [__CLASS__, 'save']);
    add_action('admin_post_mk_ame_reset_order', [__CLASS__, 'reset']);
    add_action('wp_ajax_mk_ame_order', [__CLASS__, 'ajax']);
    add_action('load-edit.php', [__CLASS__, 'list_screen']);
    add_action('pre_get_posts', [__CLASS__, 'query']);
  }

  private static function t($s) { return MK_Admin_Menu::tr($s); }

  // ---------- Settings ----------
  public static function settings() {
    $o = get_option(self::OPT, []);
    $o = is_array($o) ? $o : [];
    return [
      'all' => isset($o['all']) ? (!empty($o['all']) ? 1 : 0) : 1,
      'types' => (isset($o['types']) && is_array($o['types'])) ? array_values(array_map('sanitize_key', $o['types'])) : [],
      'admin_only' => !empty($o['admin_only']) ? 1 : 0,
    ];
  }
  // Post types that can be ordered: everything with a list screen, minus WordPress and builder internals.
  public static function types() {
    $skip = ['attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'elementor_library', 'e-floating-buttons', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page'];
    $out = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
      if (!in_array($pt->name, $skip, true)) { $out[$pt->name] = $pt; }
    }
    return $out;
  }
  public static function enabled($type) {
    if (!is_string($type) || $type === '') { return false; }
    $types = self::types();
    if (!isset($types[$type])) { return false; }
    $s = self::settings();
    return $s['all'] ? true : in_array($type, $s['types'], true);
  }
  private static function orderby($type) {
    return is_post_type_hierarchical($type) ? ['menu_order' => 'ASC', 'title' => 'ASC'] : ['menu_order' => 'ASC', 'date' => 'DESC'];
  }

  // ---------- Queries ----------
  public static function query($q) {
    if (is_admin()) {
      // Admin list screens: show the custom order unless the list is sorted by a column.
      if (!$q->is_main_query() || !function_exists('get_current_screen')) { return; }
      $sc = get_current_screen();
      if (!$sc || $sc->base !== 'edit' || !self::enabled($sc->post_type) || !empty($_GET['orderby'])) { return; }
      $q->set('orderby', self::orderby($sc->post_type));
      $q->set('order', 'ASC');
      return;
    }
    // Website: only queries that use the default (date) order, and never search results or feeds.
    if (self::settings()['admin_only'] || $q->is_search() || $q->is_feed()) { return; }
    $ob = $q->get('orderby');
    if (is_array($ob) || !in_array((string) $ob, ['', 'date', 'post_date'], true)) { return; }
    $pt = $q->get('post_type');
    if (empty($pt)) { $pt = $q->is_page() ? 'page' : 'post'; }
    $types = (array) $pt;
    if (in_array('any', $types, true)) { return; }
    foreach ($types as $t) { if (!self::enabled($t)) { return; } }
    $q->set('orderby', self::orderby($types[0]));
    $q->set('order', 'ASC');
  }

  // ---------- Admin list screen ----------
  public static function list_screen() {
    $type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';
    if (!self::enabled($type)) { return; }
    // Dragging only makes sense in the custom order, not while sorted by a column or searching.
    if (!empty($_GET['orderby']) || (isset($_GET['s']) && $_GET['s'] !== '')) { return; }
    $pto = get_post_type_object($type);
    if (!$pto || !current_user_can($pto->cap->edit_others_posts)) { return; }
    add_filter('manage_' . $type . '_posts_columns', [__CLASS__, 'column']);
    add_action('manage_' . $type . '_posts_custom_column', [__CLASS__, 'cell'], 10, 2);
    add_action('admin_enqueue_scripts', function () { wp_enqueue_script('jquery-ui-sortable'); });
    add_action('admin_footer', function () use ($type) { self::footer($type); });
  }
  public static function column($cols) {
    $new = [];
    foreach ($cols as $k => $v) {
      $new[$k] = $v;
      if ($k === 'cb') { $new['mk_ame_order'] = '<span class="screen-reader-text">' . esc_html(self::t('Order')) . '</span>'; }
    }
    if (!isset($new['mk_ame_order'])) { $new = ['mk_ame_order' => ''] + $new; }
    return $new;
  }
  public static function cell($col, $id) {
    if ($col === 'mk_ame_order') { echo '<span class="mk-ord-handle" title="' . esc_attr(self::t('Drag to reorder')) . '"></span>'; }
  }
  private static function footer($type) {
    $data = ['nonce' => wp_create_nonce('mk_ame_order'), 'type' => $type, 'error' => self::t('The new order could not be saved. Reload the page and try again.')];
    echo '<style>#the-list .column-mk_ame_order,.wp-list-table .column-mk_ame_order{width:14px;padding-left:0!important;padding-right:0!important;text-align:center}.mk-ord-handle{display:inline-block;vertical-align:middle;width:8px;height:15px;cursor:grab;--mk-dc:#8c8f94;background:radial-gradient(circle,var(--mk-dc) 1.3px,transparent 1.6px) 0 0/4px 5px}.mk-ord-handle:hover{--mk-dc:var(--wp-admin-theme-color,#2271b1)}#the-list tr.mk-ord-ph td{height:40px;background:#f6f7f7;box-shadow:inset 0 0 0 1px var(--wp-admin-theme-color,#2271b1)}#the-list.mk-ord-saving{opacity:.6;pointer-events:none}tr.ui-sortable-helper{background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.12)}</style>';
    echo '<script>jQuery(function($){var D=' . wp_json_encode($data) . ',l=$("#the-list");if(!l.length||!l.children("tr[id^=post-]").length){return;}l.sortable({items:"> tr[id^=post-]",handle:".mk-ord-handle",axis:"y",placeholder:"mk-ord-ph",helper:function(e,tr){var o=tr.children(),h=tr.clone();h.children().each(function(i){$(this).width(o.eq(i).outerWidth());});return h;},start:function(e,ui){ui.placeholder.html("<td colspan=\\""+ui.item.children().length+"\\"></td>");},update:function(){var ids=l.children("tr[id^=post-]").map(function(){return this.id.replace("post-","");}).get();l.addClass("mk-ord-saving");$.post(ajaxurl,{action:"mk_ame_order",nonce:D.nonce,post_type:D.type,ids:ids}).done(function(r){if(!r||!r.success){l.before($("<div class=\\"notice notice-error\\"><p></p></div>").find("p").text(D.error).end());}}).fail(function(){l.before($("<div class=\\"notice notice-error\\"><p></p></div>").find("p").text(D.error).end());}).always(function(){l.removeClass("mk-ord-saving");});}});});</script>';
  }

  // Saves the order of the rows on the current list page. The rows swap their existing positions, so it
  // works with pagination and status filters. The first time (or when posts share a position) every post
  // of the type is numbered in its current order.
  public static function ajax() {
    check_ajax_referer('mk_ame_order', 'nonce');
    $type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
    $pto = get_post_type_object($type);
    if (!$pto || !self::enabled($type) || !current_user_can($pto->cap->edit_others_posts)) { wp_send_json_error('denied', 403); }
    $ids = array_values(array_filter(array_unique(array_map('absint', isset($_POST['ids']) ? (array) $_POST['ids'] : []))));
    if (!$ids) { wp_send_json_error('empty', 400); }
    global $wpdb;
    $st = "post_status NOT IN ('trash','auto-draft','inherit')";
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND $st", $type));
    $distinct = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT menu_order) FROM {$wpdb->posts} WHERE post_type = %s AND $st", $type));
    if ($distinct < $total) {
      $sec = is_post_type_hierarchical($type) ? 'post_title ASC' : 'post_date DESC';
      $all = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND $st ORDER BY menu_order ASC, $sec, ID ASC", $type));
      foreach ($all as $i => $pid) { $wpdb->update($wpdb->posts, ['menu_order' => $i], ['ID' => (int) $pid]); clean_post_cache((int) $pid); }
    }
    $in = implode(',', array_map('intval', $ids));
    $cur = $wpdb->get_results($wpdb->prepare("SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ($in)", $type), OBJECT_K);
    $ids = array_values(array_filter($ids, function ($i) use ($cur) { return isset($cur[$i]); }));
    $slots = array_map(function ($i) use ($cur) { return (int) $cur[$i]->menu_order; }, $ids);
    sort($slots);
    foreach ($ids as $k => $pid) {
      if ((int) $cur[$pid]->menu_order !== $slots[$k]) { $wpdb->update($wpdb->posts, ['menu_order' => $slots[$k]], ['ID' => $pid]); }
      clean_post_cache($pid);
    }
    wp_send_json_success(['saved' => count($ids)]);
  }

  // ---------- Settings tab ----------
  public static function save() {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('Access denied.'))); }
    check_admin_referer('mk_ame_save_order');
    $types = isset($_POST['types']) ? array_map('sanitize_key', (array) wp_unslash($_POST['types'])) : [];
    update_option(self::OPT, [
      'all' => !empty($_POST['all']) ? 1 : 0,
      'types' => array_values(array_intersect($types, array_keys(self::types()))),
      'admin_only' => !empty($_POST['admin_only']) ? 1 : 0,
    ]);
    wp_safe_redirect(add_query_arg(['page' => MK_Admin_Menu::SLUG, 'tab' => 'order', 'saved' => 1], admin_url('admin.php')));
    exit;
  }
  public static function reset() {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('Access denied.'))); }
    check_admin_referer('mk_ame_reset_order');
    delete_option(self::OPT);
    wp_safe_redirect(add_query_arg(['page' => MK_Admin_Menu::SLUG, 'tab' => 'order', 'reset' => 1], admin_url('admin.php')));
    exit;
  }

  public static function tab() {
    $s = self::settings();
    $post = admin_url('admin-post.php');
    $h = '<style>.amo-types{margin:10px 0 0;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;max-width:520px;columns:2;column-gap:24px}.amo-types label{display:block;margin:0 0 8px;break-inside:avoid}.amo-types code{font-size:11px;color:#646970;background:none;padding:0}.amo-how{max-width:760px;background:#fff;border-left:4px solid var(--wp-admin-theme-color,#2271b1);padding:12px 16px;margin:8px 0 0}</style>';
    $h .= '<h2 class="amx-t">' . esc_html(self::t('Post ordering')) . '</h2><p class="amd-intro" style="max-width:760px">' . esc_html(self::t('Put posts, pages and custom post types in your own order by dragging them on their lists in the admin.')) . '</p>';
    $h .= '<div class="amx-cmp" style="max-width:760px;margin:6px 0 18px;' . MK_AME_Extras::vars() . '"><div><div class="amx-cap">' . esc_html(self::t('Without post ordering')) . '</div><div class="amx-th">' . MK_AME_Extras::thumb('order_before') . '</div></div><div><div class="amx-cap">' . esc_html(self::t('With post ordering')) . '</div><div class="amx-th">' . MK_AME_Extras::thumb('order') . '</div></div></div>';
    $h .= '<form method="post" action="' . esc_url($post) . '"><input type="hidden" name="action" value="mk_ame_save_order">' . wp_nonce_field('mk_ame_save_order', '_wpnonce', true, false);
    $h .= '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html(self::t('Post types')) . '</th><td><label><input type="checkbox" name="all" value="1" id="amo-all"' . checked($s['all'], 1, false) . '> ' . esc_html(self::t('All post types')) . '</label><p class="description">' . esc_html(self::t('Pages, posts and every custom post type, including ones added later. Untick to choose specific post types.')) . '</p>';
    $h .= '<div class="amo-types" id="amo-types"' . ($s['all'] ? ' style="display:none"' : '') . '>';
    foreach (self::types() as $name => $pt) {
      $h .= '<label><input type="checkbox" name="types[]" value="' . esc_attr($name) . '"' . checked(in_array($name, $s['types'], true), true, false) . '> ' . esc_html($pt->labels->name) . ' <code>' . esc_html($name) . '</code></label>';
    }
    $h .= '</div></td></tr>';
    $h .= '<tr><th scope="row">' . esc_html(self::t('Website')) . '</th><td><label><input type="checkbox" name="admin_only" value="1"' . checked($s['admin_only'], 1, false) . '> ' . esc_html(self::t("Don't apply on the website")) . '</label><p class="description">' . esc_html(self::t('By default your order is used on the website too, wherever items are listed by date (blog pages, archives, query loops). Tick this to use your order only in the admin lists and leave the website as it is.')) . '</p></td></tr></table>';
    $h .= '<div class="amo-how"><strong>' . esc_html(self::t('How it works')) . '</strong><p style="margin:6px 0 0">' . esc_html(self::t('Open a list such as Pages and drag a row by its handle. The new order is saved straight away. Dragging is available when the list is not sorted by a column and no search is active.')) . '</p></div>';
    $h .= '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(self::t('Save changes')) . '</button></p></form>';
    $h .= '<form method="post" action="' . esc_url($post) . '" onsubmit="return confirm(&quot;' . esc_attr(self::t('Reset everything to the defaults?')) . '&quot;)"><input type="hidden" name="action" value="mk_ame_reset_order">' . wp_nonce_field('mk_ame_reset_order', '_wpnonce', true, false) . '<button type="submit" class="button-link button-link-delete">' . esc_html(self::t('Reset to defaults')) . '</button></form>';
    $h .= '<script>jQuery(function($){$("#amo-all").on("change",function(){$("#amo-types").toggle(!this.checked);});});</script>';
    return $h;
  }
}

}
