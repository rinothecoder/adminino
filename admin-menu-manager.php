<?php
/**
 * Plugin Name: Admin Menu Manager
 * Description: Controls the order, names and visibility of the WordPress admin menu (left) and the admin bar (top). Settings under Settings > Menu Editor.
 * Version: 1.0.0
 * Author: Rino de Boer
 * Requires PHP: 7.4
 * Update URI: https://github.com/rinothecoder/wp-admin-manager
 */

if (!defined('ABSPATH')) { exit; }

if (class_exists('MK_Admin_Menu', false)) {
  // The old snippet version is still active. Turn it off in Fluent Snippets first.
  add_action('admin_notices', function () {
    echo '<div class="notice notice-warning"><p><strong>Admin Menu Manager:</strong> the Fluent Snippets version is still active. Deactivate that snippet so the plugin can run.</p></div>';
  });
} else {
final class MK_Admin_Menu {
  const OPT = 'mk_ame_settings';
  const BAR = 'mk_ame_bar_settings';
  const SEEN = 'mk_ame_bar_seen';
  const CUS = 'mk_ame_bar_custom';
  const SLUG = 'mk-admin-menu';
  private static $orig = null;
  private static $gb = []; private static $sim = false; private static $ctxk = false;

  // Language: Dutch when the WordPress locale is Dutch, otherwise English. Filter mk_ame_locale to override.
  public static function lang() {
    $loc = (string) apply_filters('mk_ame_locale', determine_locale());
    return strpos($loc, 'nl') === 0 ? 'nl' : 'en';
  }
  private static function t($s) {
    static $nl = null;
    if (self::lang() !== 'nl') { return $s; }
    if ($nl === null) { $f = __DIR__ . '/languages/nl.php'; $nl = is_readable($f) ? (array) include $f : []; }
    return isset($nl[$s]) ? $nl[$s] : $s;
  }
  private static function t_html($s) {
    return wp_kses(self::t($s), ['strong' => []]);
  }

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'add_page']);
    add_action('admin_menu', [__CLASS__, 'capture'], 9998);
    add_action('admin_menu', [__CLASS__, 'apply'], 9999);
    add_filter('custom_menu_order', [__CLASS__, 'use_order']);
    add_filter('menu_order', [__CLASS__, 'order']);
    add_action('admin_head', [__CLASS__, 'css']);
    add_action('wp_before_admin_bar_render', [__CLASS__, 'bar_seen'], 9998);
    add_action('wp_before_admin_bar_render', [__CLASS__, 'bar_apply'], 9999);
    add_action('admin_bar_menu', [__CLASS__, 'bar_custom'], 500);
    add_action('admin_post_mk_ame_save', [__CLASS__, 'save']);
    add_action('admin_post_mk_ame_reset', [__CLASS__, 'reset']);
    add_action('admin_post_mk_ame_save_bar', [__CLASS__, 'save_bar']);
    add_action('admin_post_mk_ame_reset_bar', [__CLASS__, 'reset_bar']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
  }

  private static function settings() {
    $s = get_option(self::OPT, []);
    return is_array($s) ? $s : [];
  }
  private static function locked($slug) {
    return in_array($slug, ['options-general.php', self::SLUG, 'my-account', 'menu-toggle'], true);
  }
  private static function plain($t) {
    return trim(wp_strip_all_tags(preg_replace('/<span.*$/s', '', (string) $t)));
  }
  private static function plain_bar($t) {
    $t = preg_replace('#<span[^>]*screen-reader-text[^>]*>.*?</span>#s', '', (string) $t);
    return trim(html_entity_decode(wp_strip_all_tags($t)));
  }
  // Readable name for a top bar item. Icon-only items (no text, or only a counter) get a known or screen reader name and are flagged.
  private static function bar_label($id, $title) {
    $text = self::plain_bar($title);
    if ($text !== '' && !is_numeric($text)) { return [$text, 0]; }
    $map = array (
  'updates' => self::t('Updates'),
  'comments' => self::t('Comments'),
  'wp-logo' => self::t('WordPress logo'),
  'menu-toggle' => self::t('Menu toggle'),
  'search' => self::t('Search'),
  'command-palette' => self::t('Command palette'),
);
    if (isset($map[$id])) { return [$map[$id], 1]; }
    if (preg_match('#<span[^>]*screen-reader-text[^>]*>(.*?)</span>#s', (string) $title, $sr)) {
      $sr = trim(preg_replace('/^\d+\s*/', '', html_entity_decode(wp_strip_all_tags($sr[1]))));
      if ($sr !== '') { return [ucfirst($sr), 1]; }
    }
    return [ucwords(str_replace(['-', '_'], ' ', $id)), 1];
  }
  private static function retitle($old, $new) {
    $suffix = '';
    if (preg_match('/<span.*$/s', (string) $old, $m)) { $suffix = ' ' . $m[0]; }
    return esc_html($new) . $suffix;
  }
  private static function bar_retitle($old, $new) {
    $new = esc_html($new);
    $old = (string) $old;
    $swap = function ($cls) use ($old, $new) {
      return preg_replace_callback('#(<span[^>]*' . $cls . '[^>]*>).*?(</span>)#s', function ($m) use ($new) { return $m[1] . $new . $m[2]; }, $old, 1);
    };
    if (strpos($old, 'display-name') !== false) { return $swap('display-name'); }
    if (preg_match('#<span[^>]*ab-label[^>]*>\s*\d+\s*</span>#', $old)) {
      return preg_replace_callback('#<span[^>]*ab-label[^>]*>\s*\d+\s*</span>#', function ($m) use ($new) { return '<span class=' . chr(34) . 'ab-label mk-ame-label' . chr(34) . '>' . $new . '</span> ' . $m[0]; }, $old, 1);
    }
    if (strpos($old, 'ab-label') !== false) { return $swap('ab-label'); }
    if (preg_match('#^(<span[^>]*ab-icon[^>]*></span>)#', $old, $m)) { return $m[1] . '<span class=' . chr(34) . 'ab-label' . chr(34) . '>' . $new . '</span>'; }
    return $new;
  }
  private static function clean_slug($s) {
    return substr(trim(wp_strip_all_tags((string) $s)), 0, 300);
  }
  private static function cmp_pos($a, $b) {
    return (int) (isset($a['pos']) ? $a['pos'] : 0) - (int) (isset($b['pos']) ? $b['pos'] : 0);
  }

  // Column headings above a list.
  private static function cols($top) {
    $c = $top ? ['', '', self::t('Name'), '', self::t('Shown in'), self::t('Hide')] : ['', '', self::t('Name'), '', self::t('Hide')];
    return '<div class=~ame-cols~ aria-hidden=~true~><span>' . implode('</span><span>', array_map('esc_html', $c)) . '</span></div>';
  }
  public static function add_page() {
    add_options_page(self::t('Menu Editor'), self::t('Menu Editor'), 'manage_options', self::SLUG, [__CLASS__, 'page']);
  }
  public static function capture() {
    global $menu, $submenu;
    self::$orig = ['menu' => $menu, 'submenu' => $submenu];
  }

  // ---------- Left menu: apply ----------
  public static function apply() {
    global $menu, $submenu;
    $s = self::settings();
    if (!$s) { return; }
    $map = [];
    foreach ((array) (isset($s['top']) ? $s['top'] : []) as $t) { $map[$t['slug']] = $t; }
    foreach ($menu as $k => $item) {
      $slug = $item[2];
      if (!isset($map[$slug])) { continue; }
      $t = $map[$slug];
      if ($t['title'] !== '' && $item[0] !== '') { $menu[$k][0] = self::retitle($item[0], $t['title']); }
      if (!empty($t['hidden']) && !self::locked($slug)) { $menu[$k][4] = trim((isset($item[4]) ? $item[4] : '') . ' mk-ame-hidden'); }
    }
    foreach ((array) (isset($s['sub']) ? $s['sub'] : []) as $parent => $list) {
      if (empty($submenu[$parent])) { continue; }
      $m = [];
      foreach ($list as $t) { $m[$t['slug']] = $t; }
      $items = $submenu[$parent];
      ksort($items, SORT_NUMERIC);
      foreach ($items as $k => $it) {
        $slug = $it[2];
        if (!isset($m[$slug])) { continue; }
        $t = $m[$slug];
        if ($t['title'] !== '') { $items[$k][0] = self::retitle($it[0], $t['title']); }
        if (!empty($t['hidden']) && !self::locked($slug) && empty($it[4])) { $items[$k][4] = 'mk-ame-hidden'; }
      }
      $out = []; $used = [];
      foreach ($list as $t) {
        foreach ($items as $k => $it) {
          if ($it[2] === $t['slug'] && !isset($used[$k])) { $out[] = $it; $used[$k] = 1; break; }
        }
      }
      foreach ($items as $k => $it) { if (!isset($used[$k])) { $out[] = $it; } }
      $new = []; $i = 10;
      foreach ($out as $it) { $new[$i] = $it; $i += 10; }
      $submenu[$parent] = $new;
    }
  }
  public static function use_order($b) {
    $s = self::settings();
    return empty($s['top']) ? $b : true;
  }
  public static function order($o) {
    $s = self::settings();
    if (empty($s['top'])) { return $o; }
    $res = [];
    foreach ($s['top'] as $t) { if (in_array($t['slug'], $o, true) && !in_array($t['slug'], $res, true)) { $res[] = $t['slug']; } }
    foreach ($o as $x) { if (!in_array($x, $res, true)) { $res[] = $x; } }
    return $res;
  }
  public static function css() {
    if (self::settings()) { echo '<style>#adminmenu .mk-ame-hidden{display:none!important}</style>'; }
  }
  public static function assets($hook) {
    if ($hook === 'settings_page_' . self::SLUG) { wp_enqueue_script('jquery-ui-sortable'); }
  }

  // ---------- Top bar: remember which items exist ----------
  public static function bar_seen() {
    global $wp_admin_bar;
    if (!is_object($wp_admin_bar) || !current_user_can('manage_options')) { return; }
    $seen = get_option(self::SEEN, []);
    if (!is_array($seen)) { $seen = []; }
    $changed = false;
    foreach ((array) $wp_admin_bar->get_nodes() as $id => $n) {
      $p = (string) $n->parent;
      if (!empty($n->group)) { $row = ['t' => '', 'p' => $p, 'g' => 1]; } else { $lb = self::bar_label($id, $n->title); $row = ['t' => $lb[0], 'p' => $p]; if ($lb[1]) { $row['i'] = 1; } }
      // Remember where the item was seen: a = admin, f = website (frontend).
      $ctx = is_admin() ? 'a' : 'f';
      if (isset($seen[$id])) { foreach (['a', 'f'] as $c) { if (!empty($seen[$id][$c])) { $row[$c] = 1; } } }
      $row[$ctx] = 1;
      if (!isset($seen[$id]) || empty($seen[$id][$ctx]) || $seen[$id]['p'] !== $p || (isset($seen[$id]['g']) !== isset($row['g'])) || $seen[$id]['t'] !== $row['t'] || (isset($seen[$id]['i']) !== isset($row['i']))) { $seen[$id] = $row; $changed = true; }
    }
    if ($changed) { update_option(self::SEEN, $seen, false); }
  }

  // ---------- Top bar: apply ----------
  public static function bar_apply() {
    global $wp_admin_bar;
    $s = get_option(self::BAR, []);
    if (empty($s['lists']) || !is_object($wp_admin_bar)) { return; }
    $nodes = $wp_admin_bar->get_nodes();
    if (!$nodes) { return; }
    foreach ($s['lists'] as $parent => $rows) {
      $order = [];
      foreach ($rows as $r) {
        $id = $r['slug'];
        if (!isset($nodes[$id])) { continue; }
        if (!empty($r['hidden']) && !self::locked($id)) { $wp_admin_bar->remove_node($id); continue; }
        if ($r['title'] !== '') { $wp_admin_bar->add_node(['id' => $id, 'title' => self::bar_retitle($nodes[$id]->title, $r['title'])]); }
        $order[] = $id;
      }
      if (count($order) > 1) {
        foreach ($order as $id) {
          $n = $wp_admin_bar->get_node($id);
          if (!$n) { continue; }
          $args = get_object_vars($n);
          unset($args['children']);
          $wp_admin_bar->remove_node($id);
          $wp_admin_bar->add_node($args);
        }
      }
    }
  }

  // ---------- Save ----------
  public static function save() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_save');
    $in = isset($_POST['top']) ? (array) wp_unslash($_POST['top']) : [];
    $sub = isset($_POST['sub']) ? (array) wp_unslash($_POST['sub']) : [];
    uasort($in, [__CLASS__, 'cmp_pos']);
    $out = ['top' => [], 'sub' => []];
    foreach ($in as $i => $t) {
      $slug = self::clean_slug(isset($t['slug']) ? $t['slug'] : '');
      if ($slug === '') { continue; }
      $out['top'][] = ['slug' => $slug, 'title' => sanitize_text_field(isset($t['title']) ? $t['title'] : ''), 'hidden' => (!empty($t['hidden']) && !self::locked($slug)) ? 1 : 0];
      if (!empty($sub[$i]) && is_array($sub[$i])) {
        $rows = $sub[$i];
        uasort($rows, [__CLASS__, 'cmp_pos']);
        foreach ($rows as $r) {
          $ss = self::clean_slug(isset($r['slug']) ? $r['slug'] : '');
          if ($ss === '') { continue; }
          $out['sub'][$slug][] = ['slug' => $ss, 'title' => sanitize_text_field(isset($r['title']) ? $r['title'] : ''), 'hidden' => (!empty($r['hidden']) && !self::locked($ss)) ? 1 : 0];
        }
      }
    }
    update_option(self::OPT, $out);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('options-general.php')));
    exit;
  }
  public static function reset() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_reset');
    delete_option(self::OPT);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'reset' => 1], admin_url('options-general.php')));
    exit;
  }
  public static function save_bar() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_save_bar');
    $in = isset($_POST['bar']) ? (array) wp_unslash($_POST['bar']) : [];
    $out = ['lists' => []];
    $old = [];
    foreach (self::custom_links() as $cl) { $old[$cl['id']] = $cl; }
    $cus = [];
    $seen = get_option(self::SEEN, []);
    foreach ($in as $g) {
      if (!is_array($g) || empty($g['rows']) || !is_array($g['rows'])) { continue; }
      $parent = self::clean_slug(isset($g['parent']) ? $g['parent'] : '');
      $rows = $g['rows'];
      uasort($rows, [__CLASS__, 'cmp_pos']);
      foreach ($rows as $r) {
        $id = self::clean_slug(isset($r['slug']) ? $r['slug'] : '');
        if ($id === '') { continue; }
        if (!empty($r['custom'])) {
          if (!empty($r['del'])) { continue; }
          $title = sanitize_text_field(isset($r['title']) ? $r['title'] : '');
          $url = trim(isset($r['url']) ? $r['url'] : '');
          if ($title === '' || $url === '') { continue; }
          if (!preg_match('~^([a-z][a-z0-9+.-]*:|/|#)~i', $url)) { $url = admin_url($url); }
          elseif ($url[0] === '/') { $url = home_url($url); }
          if (!isset($old[$id])) { $id = 'mk-ame-link-' . strtolower(wp_generate_password(6, false)); }
          $eff = $parent; $guard = 0;
          while (isset($seen[$eff]) && !empty($seen[$eff]['g']) && $seen[$eff]['p'] !== '' && $guard++ < 5) { $eff = $seen[$eff]['p']; }
          $cus[] = ['id' => $id, 'title' => $title, 'url' => esc_url_raw($url), 'parent' => $eff, 'blank' => !empty($old[$id]['blank']) ? 1 : 0];
          $out['lists'][$eff][] = ['slug' => $id, 'title' => '', 'hidden' => !empty($r['hidden']) ? 1 : 0];
          continue;
        }
        $out['lists'][$parent][] = ['slug' => $id, 'title' => sanitize_text_field(isset($r['title']) ? $r['title'] : ''), 'hidden' => (!empty($r['hidden']) && !self::locked($id)) ? 1 : 0];
      }
    }
    update_option(self::BAR, $out);
    update_option(self::CUS, $cus);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'top', 'saved' => 1], admin_url('options-general.php')));
    exit;
  }
    public static function reset_bar() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_reset_bar');
    delete_option(self::BAR);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'top', 'reset' => 1], admin_url('options-general.php')));
    exit;
  }

  // ---------- Custom links in the top bar ----------
  private static function custom_links() {
    $c = get_option(self::CUS, []);
    return is_array($c) ? $c : [];
  }
  public static function bar_custom($bar) {
    if (!current_user_can('manage_options')) { return; }
    if (is_admin() && !self::$sim) { return; }
    foreach (self::custom_links() as $c) {
      $meta = ['class' => 'mk-ame-custom'];
      if (!empty($c['blank'])) { $meta['target'] = '_blank'; }
      $bar->add_node(['id' => $c['id'], 'title' => esc_html($c['title']), 'href' => $c['url'], 'parent' => $c['parent'], 'meta' => $meta]);
    }
  }
  // ---------- Display ----------
  private static function row($name, $slug, $orig, $title, $hidden, $sep, $extra, $icon = false) {
    $locked = self::locked($slug);
    $cust = strpos($slug, 'mk-ame-link-') === 0;
    $h = '<li class=~ame-row' . ($sep ? ' ame-sep' : '') . ($cust ? ' ame-custom' : '') . '~><div class=~ame-head~><span class=~ame-grip~ title=~' . esc_attr(self::t('Drag to reorder')) . '~>&#8942;&#8942;</span>';
    $h .= '<input type=~hidden~ name=~' . $name . '[slug]~ value=~' . esc_attr($slug) . '~><input type=~hidden~ class=~pos~ name=~' . $name . '[pos]~ value=~0~>';
    if ($sep) {
      $h .= '<span class=~ame-name ame-muted~>' . esc_html(self::t('Separator')) . '</span>';
    } else {
      $val = $title !== '' ? $title : (($cust || $icon) ? '' : $orig);
      $canreset = !$cust && !$icon && $orig !== '';
      $h .= '<input type=~text~ class=~ame-title~ name=~' . $name . '[title]~ value=~' . esc_attr($val) . '~' . ($canreset ? ' data-orig=~' . esc_attr($orig) . '~' : '') . ' placeholder=~' . esc_attr($orig !== '' ? $orig : $slug) . '~>';
      if ($canreset) { $h .= '<button type=~button~ class=~ame-reset~ style=~display:none~>' . esc_html(self::t('Reset')) . '</button>'; }
      if ($icon) { $h .= '<span class=~ame-icon-note~>' . esc_html(self::t('Icon in the bar, no text')) . '</span>'; }
    }
    $h .= $extra;
    $h .= '<label class=~ame-hide~><input type=~checkbox~ name=~' . $name . '[hidden]~ value=~1~' . ($hidden ? ' checked' : '') . ($locked ? ' disabled' : '') . '> ' . esc_html(self::t('Hide')) . '</label>';
    $h .= '</div>';
    return $h;
  }

  public static function page() {
    if (!current_user_can('manage_options')) { return; }
    $tab = (isset($_GET['tab']) && $_GET['tab'] === 'top') ? 'top' : 'left';
    $base = admin_url('options-general.php?page=' . self::SLUG);
    $css = '<style>.ame ul{margin:0;padding:0;list-style:none;max-width:1200px}.ame-row{margin:0 0 6px}.ame-head{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px}.ame-grip{cursor:grab;color:#8c8f94;font-size:16px;line-height:1;letter-spacing:-3px;user-select:none}.ame-title{flex:1;min-width:0}.ame-name{flex:1}.ame-muted{color:#8c8f94;font-style:italic}.ame-sep .ame-head{background:#f6f7f7;border-style:dashed}.ame-hide{white-space:nowrap}.ame-sub{margin:6px 0 10px 32px!important}.ame-ph{height:44px;border:1px dashed #2271b1;background:#f0f6fc;margin-bottom:6px}.ame-row:has(> .ame-head input[type=checkbox]:checked) > .ame-head{opacity:.55}.ame h2{margin:24px 0 8px}.ame .nav-tab-wrapper{margin-bottom:16px}.ame-url{flex:1 1 auto;min-width:0}.ame-custom .ame-title{flex:0 0 240px}.ame-reset{background:none;border:0;color:#2271b1;cursor:pointer;text-decoration:underline;padding:0 4px;font-size:13px}.ame-hide{min-width:100px}.ame-remove{background:none;border:0;color:#b32d2e;cursor:pointer;text-decoration:underline;padding:0 4px;font-size:13px}.ame-remove:hover{color:#8a1f1f}.ame-custom > .ame-head{border-left:3px solid #2271b1}.ame-title::placeholder{color:#8c8f94;font-style:italic;opacity:1}.ame-icon-note{color:#8c8f94;font-size:12px;font-style:italic;white-space:nowrap}.ame-where{font-size:11px;font-weight:600;line-height:1;padding:4px 8px;border-radius:10px;white-space:nowrap}.ame-where-a{background:#f0f0f1;color:#50575e}.ame-where-f{background:#e6f4ea;color:#1e6b34}.ame .ame-head,.ame-cols{display:grid;grid-template-columns:20px 36px minmax(160px,1fr) 170px 110px 64px;grid-template-areas:~grip tog name note where hide~;column-gap:12px;align-items:center}.ame-tab-left .ame-head,.ame-tab-left .ame-cols{grid-template-columns:20px 36px minmax(160px,1fr) 170px 64px;grid-template-areas:~grip tog name note hide~}.ame-head>.ame-grip{grid-area:grip}.ame-head>.ame-toggle,.ame-head>.ame-remove{grid-area:tog;justify-self:start}.ame-head>.ame-title,.ame-head>.ame-name{grid-area:name;width:100%;min-width:0}.ame-head>.ame-reset,.ame-head>.ame-icon-note{grid-area:note;justify-self:start}.ame-head>.ame-url{grid-area:note;width:100%;min-width:0}.ame-head>.ame-where{grid-area:where;justify-self:start}.ame-head>.ame-hide{grid-area:hide;justify-self:center;min-width:0;font-size:0}.ame-cols{max-width:1200px;box-sizing:border-box;padding:0 13px;margin:0 0 6px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#646970}.ame-cols>span:last-child{justify-self:center}.ame-toggle{display:inline-flex;align-items:center;gap:1px;background:none;border:0;padding:2px;cursor:pointer;color:#2271b1;border-radius:3px}.ame-toggle:hover{background:#f0f6fc}.ame-toggle .dashicons{font-size:16px;width:16px;height:16px;transition:transform .15s}.ame-toggle.is-open .dashicons{transform:rotate(90deg)}.ame-count{font-size:11px;color:#646970}.ame-head>.ame-remove{padding:2px;text-decoration:none}.ame-remove .dashicons{font-size:16px;width:16px;height:16px}.ame-lang-nl .ame-head,.ame-lang-nl .ame-cols{grid-template-columns:20px 36px minmax(160px,1fr) 170px 110px 72px}.ame-lang-nl.ame-tab-left .ame-head,.ame-lang-nl.ame-tab-left .ame-cols{grid-template-columns:20px 36px minmax(160px,1fr) 170px 72px}@media(max-width:960px){.ame .ame-head{display:flex;flex-wrap:wrap}.ame-head>.ame-title{flex:1 1 200px}.ame-head>.ame-hide{font-size:13px}.ame-cols{display:none}}</style>';
    $js = '<script>jQuery(function($){$(~.ame-list~).sortable({handle:~.ame-grip~,items:~>li~,connectWith:($(~#ame-add~).length?~.ame-list~:false),placeholder:~ame-ph~,receive:function(e,ui){if(!ui.item.hasClass(~ame-custom~)){ui.sender.sortable(~cancel~)}}});$(~.ame-toggle~).on(~click~,function(){var o=$(this).toggleClass(~is-open~).hasClass(~is-open~);$(this).attr(~aria-expanded~,o?~true~:~false~).closest(~li~).children(~ul.ame-sub~).toggle(o)});$(document).on(~click~,~.ame-remove~,function(){var li=$(this).closest(~li~);if(li.hasClass(~ame-new~)){li.remove()}else{li.find(~.ame-del~).first().val(1);li.hide()}});function sy(i){var o=i.attr(~data-orig~);i.siblings(~.ame-reset~).toggle(o!==undefined&&i.val()!==o)}$(~.ame-title[data-orig]~).each(function(){sy($(this))}).on(~input~,function(){sy($(this))});$(document).on(~click~,~.ame-reset~,function(){var i=$(this).siblings(~.ame-title~);i.val(i.attr(~data-orig~));sy(i)});var n=0;$(~#ame-add~).on(~click~,function(){n++;var p=~bar[0][rows][~+(900+n)+~]~;var h=$(~<div>~).addClass(~ame-head~);h.append($(~<span>~).addClass(~ame-grip~).html(~&#8942;&#8942;~));h.append($(~<input>~).attr({type:~hidden~,name:p+~[slug]~,value:~new~}));h.append($(~<input>~).attr({type:~hidden~,name:p+~[pos]~,value:0}).addClass(~pos~));h.append($(~<input>~).attr({type:~text~,name:p+~[title]~,placeholder:~' . esc_js(self::t('Name')) . '~}).addClass(~ame-title~));h.append($(~<input>~).attr({type:~text~,name:p+~[url]~,list:~ame-urls~,placeholder:~' . esc_js(self::t('URL or admin page')) . '~}).addClass(~ame-url~));h.append($(~<input>~).attr({type:~hidden~,name:p+~[custom]~,value:1}));h.append($(~<button>~).attr({type:~button~,title:~' . esc_js(__('Delete')) . '~}).addClass(~ame-remove~).append($(~<span>~).addClass(~dashicons dashicons-trash~)));h.append($(~<label>~).addClass(~ame-hide~).append($(~<input>~).attr({type:~checkbox~,name:p+~[hidden]~,value:1})).append(document.createTextNode(~ ' . esc_js(self::t('Hide')) . '~)));var li=$(~<li>~).addClass(~ame-row ame-custom ame-new~).append(h);$(~ul.ame-first~).append(li);$(~.ame-list~).sortable(~refresh~);li[0].scrollIntoView({block:~center~});h.find(~.ame-title~).focus()});$(~.ame-form~).on(~submit~,function(){$(~.ame-title[data-orig]~).each(function(){if($(this).val()===$(this).attr(~data-orig~)){$(this).val(~~)}});$(~ul.ame-list~).each(function(){var idx=$(this).data(~idx~);$(this).children(~li~).each(function(i){var hd=$(this).children(~.ame-head~);hd.find(~input~).each(function(){var nm=this.name;if(nm&&nm.indexOf(~bar[~)===0){this.name=~bar[~+idx+~][rows][~+i+~]~+nm.substring(nm.lastIndexOf(~[~))}});hd.find(~.pos~).val(i)})})})});</script>';
    $h = '<div class=~wrap ame ame-tab-' . $tab . ' ame-lang-' . self::lang() . '~><h1>' . esc_html(self::t('Menu Editor')) . '</h1><nav class=~nav-tab-wrapper~><a class=~nav-tab' . ($tab === 'left' ? ' nav-tab-active' : '') . '~ href=~' . esc_url($base) . '~>' . esc_html(self::t('Left Menu Editor')) . '</a><a class=~nav-tab' . ($tab === 'top' ? ' nav-tab-active' : '') . '~ href=~' . esc_url($base . '&tab=top') . '~>' . esc_html(self::t('Top Menu Editor')) . '</a></nav>';
    if (isset($_GET['saved'])) { $h .= '<div class=~notice notice-success is-dismissible~><p>' . esc_html(self::t('Saved.')) . '</p></div>'; }
    if (isset($_GET['reset'])) { $h .= '<div class=~notice notice-success is-dismissible~><p>' . esc_html(self::t('Reset to the defaults.')) . '</p></div>'; }
    $h .= $tab === 'top' ? self::tab_top() : self::tab_left();
    $h .= '</div>';
    echo strtr($css . $h . $js, '~', chr(34));
  }

  private static function forms($list_html, $save, $reset) {
    $h = '<form class=~ame-form~ method=~post~ action=~' . esc_url(admin_url('admin-post.php')) . '~><input type=~hidden~ name=~action~ value=~' . $save . '~>' . wp_nonce_field($save, '_wpnonce', true, false) . $list_html;
    $h .= '<p class=~submit~><button type=~submit~ class=~button button-primary~>' . esc_html(self::t('Save changes')) . '</button></p></form>';
    $h .= '<form method=~post~ action=~' . esc_url(admin_url('admin-post.php')) . '~ onsubmit=~return confirm(&quot;' . esc_attr(self::t('Reset everything to the defaults?')) . '&quot;)~><input type=~hidden~ name=~action~ value=~' . $reset . '~>' . wp_nonce_field($reset, '_wpnonce', true, false) . '<button type=~submit~ class=~button-link button-link-delete~>' . esc_html(self::t('Reset to defaults')) . '</button></form>';
    return $h;
  }

  private static function tab_left() {
    global $menu, $submenu;
    $o = self::$orig ? self::$orig : ['menu' => $menu, 'submenu' => $submenu];
    $s = self::settings();
    $tops = $o['menu'];
    ksort($tops, SORT_NUMERIC);
    $byslug = [];
    foreach ($tops as $it) { $byslug[$it[2]] = $it; }
    $st = [];
    foreach ((array) (isset($s['top']) ? $s['top'] : []) as $t) { $st[$t['slug']] = $t; }
    $order = [];
    foreach ($st as $slug => $t) { if (isset($byslug[$slug])) { $order[] = $slug; } }
    foreach ($byslug as $slug => $it) { if (!in_array($slug, $order, true)) { $order[] = $slug; } }
    $h = '<p>' . esc_html(self::t('The admin menu on the left. Drag the items into the order you want, change a name, or tick Hide. Leave a name empty to use the default name. Hidden items can still be reached through their URL: this is tidying up, not security.')) . '</p>' . self::cols(false) . '<ul class=~ame-list~>';
    $i = 0;
    foreach ($order as $slug) {
      $it = $byslug[$slug];
      $sep = ($it[0] === '' || strpos($slug, 'separator') === 0);
      $t = isset($st[$slug]) ? $st[$slug] : ['title' => '', 'hidden' => 0];
      $subs = isset($o['submenu'][$slug]) ? $o['submenu'][$slug] : [];
      ksort($subs, SORT_NUMERIC);
      $bys = [];
      foreach ($subs as $sit) { $bys[$sit[2]] = $sit; }
      $ss = [];
      if (isset($s['sub'][$slug])) { foreach ($s['sub'][$slug] as $x) { $ss[$x['slug']] = $x; } }
      $sorder = [];
      foreach ($ss as $k => $x) { if (isset($bys[$k])) { $sorder[] = $k; } }
      foreach ($bys as $k => $x) { if (!in_array($k, $sorder, true)) { $sorder[] = $k; } }
      $extra = '';
      $inner = '';
      if (!$sep && count($bys) > 0) {
        $extra = '<button type=~button~ class=~ame-toggle~ title=~' . esc_attr(self::t('Submenu')) . '~ aria-expanded=~false~><span class=~dashicons dashicons-arrow-right-alt2~></span><span class=~ame-count~>' . count($bys) . '</span></button>';
        $inner = '<ul class=~ame-list ame-sub~ style=~display:none~>';
        $j = 0;
        foreach ($sorder as $k) {
          $sit = $bys[$k];
          $sv = isset($ss[$k]) ? $ss[$k] : ['title' => '', 'hidden' => 0];
          $inner .= self::row('sub[' . $i . '][' . $j . ']', $k, self::plain($sit[0]), $sv['title'], $sv['hidden'], false, '') . '</li>';
          $j++;
        }
        $inner .= '</ul>';
      }
      $h .= self::row('top[' . $i . ']', $slug, self::plain($it[0]), $t['title'], $t['hidden'], $sep, $extra) . $inner . '</li>';
      $i++;
    }
    $h .= '</ul>';
    return self::forms($h, 'mk_ame_save', 'mk_ame_reset');
  }

  // All known top bar items: simulated live, plus items seen earlier (including on the front end)
  private static function bar_nodes() {
    $seen = get_option(self::SEEN, []);
    if (!is_array($seen)) { $seen = []; }
    $mine = [];
    foreach (self::custom_links() as $cl) { $mine[] = $cl['id']; }
    $pruned = false;
    foreach ($seen as $sid => $sr) { if (strpos($sid, 'mk-ame-link-') === 0 && !in_array($sid, $mine, true)) { unset($seen[$sid]); $pruned = true; } }
    if ($pruned) { update_option(self::SEEN, $seen, false); }
    $live = [];
    try {
      require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
      $bar = new WP_Admin_Bar();
      $bar->initialize();
      $bar->add_menus();
      self::$sim = true; do_action_ref_array('admin_bar_menu', [&$bar]); self::$sim = false;
      foreach ((array) $bar->get_nodes() as $id => $n) {
        if (!empty($n->group)) { $live[$id] = ['t' => '', 'p' => (string) $n->parent, 'g' => 1]; continue; }
        $lb = self::bar_label($id, $n->title);
        $live[$id] = ['t' => $lb[0], 'p' => (string) $n->parent] + ($lb[1] ? ['i' => 1] : []);
      }
    } catch (\Throwable $e) {}
    foreach ($live as $id => $r) { if (strpos($id, 'mk-ame-link-') !== 0) { $live[$id]['a'] = 1; } }
    foreach ($seen as $id => $r) {
      if (!isset($live[$id])) { $live[$id] = $r; continue; }
      foreach (['a', 'f'] as $c) { if (!empty($r[$c])) { $live[$id][$c] = 1; } }
    }
    return $live;
  }

  private static function bar_list($parent, $kids, $nodes, $sm, &$k, $nested) {
    if (empty($kids[$parent])) { return ''; }
    $ids = $kids[$parent];
    $order = [];
    if (!empty($sm[$parent])) { foreach ($sm[$parent] as $r) { if (in_array($r['slug'], $ids, true)) { $order[] = $r['slug']; } } }
    foreach ($ids as $id) { if (!in_array($id, $order, true)) { $order[] = $id; } }
    $cm = [];
    foreach (self::custom_links() as $cl) { $cm[$cl['id']] = $cl; }
    $idx = $k++;
    $cls = 'ame-list' . ($nested ? ' ame-sub' : ($idx === 0 ? ' ame-first' : ''));
    $h = ($nested ? '' : self::cols(true)) . '<input type=~hidden~ name=~bar[' . $idx . '][parent]~ value=~' . esc_attr($parent) . '~><ul class=~' . $cls . '~ data-idx=~' . $idx . '~' . ($nested ? ' style=~display:none~' : '') . '>';
    $j = 0;
    foreach ($order as $id) {
      $saved = null;
      if (!empty($sm[$parent])) { foreach ($sm[$parent] as $r) { if ($r['slug'] === $id) { $saved = $r; } } }
      $ph = $nodes[$id]['t'];
      $inner = self::bar_list($id, $kids, $nodes, $sm, $k, true);
      if (!empty(self::$gb[$id])) { foreach (self::$gb[$id] as $gid) { $inner .= self::bar_list($gid, $kids, $nodes, $sm, $k, true); } }
      $nm = 'bar[' . $idx . '][rows][' . $j . ']';
      $title = $saved ? $saved['title'] : '';
      $extra = $inner !== '' ? '<button type=~button~ class=~ame-toggle~ title=~' . esc_attr(self::t('Submenu')) . '~ aria-expanded=~false~><span class=~dashicons dashicons-arrow-right-alt2~></span></button>' : '';
      if (isset($cm[$id])) {
        $cc = $cm[$id];
        $title = $cc['title'];
        $extra = '<input type=~text~ class=~ame-url~ list=~ame-urls~ name=~' . $nm . '[url]~ value=~' . esc_attr($cc['url']) . '~ placeholder=~' . esc_attr(self::t('URL')) . '~><input type=~hidden~ name=~' . $nm . '[custom]~ value=~1~><input type=~hidden~ class=~ame-del~ name=~' . $nm . '[del]~ value=~0~><button type=~button~ class=~ame-remove~ title=~' . esc_attr__('Delete') . '~><span class=~dashicons dashicons-trash~></span></button>' . $extra;
      }
      if (self::$ctxk) {
        $ia = !empty($nodes[$id]['a']); $if = !empty($nodes[$id]['f']);
        if ($ia && !$if) { $extra = '<span class=~ame-where ame-where-a~>' . esc_html(self::t('Admin only')) . '</span>' . $extra; }
        elseif ($if && !$ia) { $extra = '<span class=~ame-where ame-where-f~>' . esc_html(self::t('Frontend only')) . '</span>' . $extra; }
      }
      $h .= self::row($nm, $id, $ph, $title, $saved ? $saved['hidden'] : 0, false, $extra, !empty($nodes[$id]['i']) && !isset($cm[$id])) . $inner . '</li>';
      $j++;
    }
    return $h . '</ul>';
  }
  private static function tab_top() {
    $nodes = self::bar_nodes();
    $s = get_option(self::BAR, []);
    $sm = isset($s['lists']) && is_array($s['lists']) ? $s['lists'] : [];
    $kids = [];
    self::$gb = [];
    foreach ($nodes as $id => $n) {
      if (!empty($n['g'])) { if ($n['p'] !== '') { self::$gb[$n['p']][] = $id; } continue; }
      $kids[$n['p']][] = $id;
    }
    $roots = [];
    foreach (array_keys($kids) as $p) {
      $isg = isset($nodes[$p]) && !empty($nodes[$p]['g']);
      if ($p === '' || !isset($nodes[$p]) || ($isg && $nodes[$p]['p'] === '')) { $roots[] = $p; }
    }
    usort($roots, function ($a, $b) { return ($a === '' ? 0 : 1) - ($b === '' ? 0 : 1); });
    $h = '<h2>' . esc_html(self::t('Order, names and visibility')) . '</h2><p>' . self::t_html('<strong>This is about the black bar at the top</strong> (the menu on the left is on the other tab). The bar has a left side (for example Site, New, Test mode) and a right side (your account and search). Many items have a dropdown, which you open with the arrow in front of the name. The bar shows in the admin and on the website. Drag the items into the order you want, change a name, or tick Hide. The button below the list adds a custom link (it only appears on the website, not in the admin). You can then drag it anywhere, including into a dropdown (first open the dropdown of that item with the arrow). Only custom links can move to a different place. Leave a name empty to use the default name. Items that only appear on the website (such as Edit) show up here after you have viewed the website once while logged in. My Account can be renamed but not hidden. Some items only exist in the admin or only on the website, which is why WordPress does not show the same options everywhere.') . '</p>';
    self::$ctxk = false;
    foreach ($nodes as $n) { if (!empty($n['f'])) { self::$ctxk = true; break; } }
    $h .= '<p class=~description~>' . (self::$ctxk ? self::t_html('<strong>Admin only</strong> items appear only in the bar inside the WordPress admin. <strong>Frontend only</strong> items appear only on the frontend of the website while you are logged in. Items without a label appear in both places.') : esc_html(self::t('Visit the frontend once while logged in. After that, this list shows which items are admin only or frontend only.'))) . '</p>';
    $k = 0;
    foreach ($roots as $p) {
      $title = $p === '' ? self::t('Top bar: left side') : ($p === 'top-secondary' ? self::t('Top bar: right side (the first item sits furthest to the right)') : $p);
      $h .= '<h2>' . esc_html($title) . '</h2>' . self::bar_list($p, $kids, $nodes, $sm, $k, false);
    }
    $h .= '<p><button type=~button~ class=~button~ id=~ame-add~>' . esc_html(self::t('+ Add new item')) . '</button></p>';
    $dl = '<datalist id=~ame-urls~>';
    foreach (['plugins.php' => self::t('Plugins'), 'themes.php' => self::t('Themes'), 'edit.php?post_type=page' => self::t('Pages'), 'upload.php' => self::t('Media'), 'users.php' => self::t('Users'), 'options-general.php' => self::t('Settings'), 'site-editor.php' => self::t('Site Editor'), 'admin.php?page=fluent-snippets' => 'Fluent Snippets', 'admin.php?page=fluent-cart' => 'FluentCart', 'admin.php?page=fluent-booking' => 'Fluent Booking'] as $u => $l) { $dl .= '<option value=~' . esc_attr($u) . '~>' . esc_html($l) . '</option>'; }
    foreach ((array) get_pages(['number' => 50]) as $pg) { $dl .= '<option value=~' . esc_attr(get_permalink($pg)) . '~>' . esc_html($pg->post_title) . '</option>'; }
    $h .= $dl . '</datalist>';
    return self::forms($h, 'mk_ame_save_bar', 'mk_ame_reset_bar');
  }
}
MK_Admin_Menu::init();
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
  array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=mk-admin-menu')) . '">' . esc_html__('Settings') . '</a>');
  return $links;
});
require_once __DIR__ . '/includes/updater.php';
new MK_GitHub_Updater(__FILE__, 'rinothecoder/wp-admin-manager');
}
