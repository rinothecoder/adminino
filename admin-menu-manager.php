<?php
/**
 * Plugin Name: Admin Manager
 * Description: Controls the WordPress admin: order, names and visibility of the left menu and the admin bar, a custom color scheme, your logos in the menu and on the login page, and drag and drop post ordering. Settings under Settings > Admin Manager.
 * Version: 1.2.0
 * Author: Rino de Boer
 * Requires PHP: 7.4
 * Update URI: https://github.com/rinothecoder/wp-admin-manager
 */

if (!defined('ABSPATH')) { exit; }

if (class_exists('MK_Admin_Menu', false)) {
  // The old snippet version is still active. Turn it off in Fluent Snippets first.
  add_action('admin_notices', function () {
    echo '<div class="notice notice-warning"><p><strong>Admin Manager:</strong> the Fluent Snippets version is still active. Deactivate that snippet so the plugin can run.</p></div>';
  });
} else {
final class MK_Admin_Menu {
  const OPT = 'mk_ame_settings';
  const BAR = 'mk_ame_bar_settings';
  const SEEN = 'mk_ame_bar_seen';
  const CUS = 'mk_ame_bar_custom';
  const SLUG = 'mk-admin-menu';
  const SCOPES = 'mk_ame_menu_scopes';
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
  public static function tr($s) { return self::t($s); }
  public static function tr_html($s) { return self::t_html($s); }

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
    add_action('admin_post_mk_ame_scope', [__CLASS__, 'scope_action']);
    add_action('deleted_user', [__CLASS__, 'user_deleted']);
    add_action('admin_post_mk_ame_save_bar', [__CLASS__, 'save_bar']);
    add_action('admin_post_mk_ame_reset_bar', [__CLASS__, 'reset_bar']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
  }

  // ---------- Left menu per role and per user ----------
  // The default settings live in OPT (as before). Role and user settings live in SCOPES.
  // The most specific one wins: user, then role, then default.
  private static function raw($opt) {
    $s = get_option($opt, []);
    return is_array($s) ? $s : [];
  }
  private static function scopes() {
    $s = self::raw(self::SCOPES);
    return ['roles' => (isset($s['roles']) && is_array($s['roles'])) ? $s['roles'] : [], 'users' => (isset($s['users']) && is_array($s['users'])) ? $s['users'] : []];
  }
  private static function cfg_for_user($uid) {
    $sc = self::scopes();
    if ($uid && isset($sc['users'][$uid]) && is_array($sc['users'][$uid])) { return $sc['users'][$uid]; }
    $u = $uid ? get_userdata($uid) : false;
    if ($u) {
      foreach ((array) $u->roles as $r) { if (isset($sc['roles'][$r]) && is_array($sc['roles'][$r])) { return $sc['roles'][$r]; } }
    }
    return self::raw(self::OPT);
  }
  private static function settings() {
    return self::cfg_for_user(get_current_user_id());
  }
  public static function user_deleted($id) {
    $s = self::scopes();
    if (isset($s['users'][$id])) { unset($s['users'][$id]); update_option(self::SCOPES, $s); }
  }

  // The person or role being set up, from the request.
  private static function scope_req() {
    $role = isset($_REQUEST['role']) ? sanitize_key(wp_unslash($_REQUEST['role'])) : 'all';
    $user = isset($_REQUEST['user']) ? absint($_REQUEST['user']) : 0;
    $names = wp_roles()->get_names();
    if ($user) {
      $u = get_userdata($user);
      if (!$u) { $user = 0; }
      elseif (!in_array($role, (array) $u->roles, true)) { $rs = array_values((array) $u->roles); $role = $rs ? $rs[0] : 'all'; }
    }
    if ($role !== 'all' && !isset($names[$role])) { $role = 'all'; $user = 0; }
    if ($role === 'all') { $user = 0; }
    return ['role' => $role, 'user' => $user];
  }
  private static function scope_default($sc) { return $sc['role'] === 'all' && !$sc['user']; }
  private static function scope_own($sc) {
    if ($sc['user']) { $s = self::scopes(); return isset($s['users'][$sc['user']]) ? $s['users'][$sc['user']] : null; }
    if ($sc['role'] !== 'all') { $s = self::scopes(); return isset($s['roles'][$sc['role']]) ? $s['roles'][$sc['role']] : null; }
    $d = self::raw(self::OPT);
    return $d ? $d : null;
  }
  private static function scope_parent($sc) {
    if ($sc['user'] && $sc['role'] !== 'all') { $s = self::scopes(); if (isset($s['roles'][$sc['role']])) { return $s['roles'][$sc['role']]; } }
    return self::raw(self::OPT);
  }
  private static function scope_effective($sc) {
    $o = self::scope_own($sc);
    return $o !== null ? $o : self::scope_parent($sc);
  }
  private static function scope_set($sc, $cfg) {
    if (self::scope_default($sc)) { update_option(self::OPT, $cfg); return; }
    $s = self::scopes();
    if ($sc['user']) { $s['users'][$sc['user']] = $cfg; } else { $s['roles'][$sc['role']] = $cfg; }
    update_option(self::SCOPES, $s);
  }
  private static function scope_unset($sc) {
    if (self::scope_default($sc)) { delete_option(self::OPT); return; }
    $s = self::scopes();
    if ($sc['user']) { unset($s['users'][$sc['user']]); } else { unset($s['roles'][$sc['role']]); }
    if (!$s['roles'] && !$s['users']) { delete_option(self::SCOPES); } else { update_option(self::SCOPES, $s); }
  }
  private static function role_label($slug) {
    $r = wp_roles()->roles;
    return isset($r[$slug]) ? translate_user_role($r[$slug]['name']) : $slug;
  }
  private static function scope_label($sc) {
    if ($sc['user']) { $u = get_userdata($sc['user']); return $u ? $u->display_name : '#' . $sc['user']; }
    return $sc['role'] === 'all' ? self::t('everyone') : self::role_label($sc['role']);
  }
  private static function scope_back($sc, $flag) {
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'left', 'role' => $sc['role'], 'user' => $sc['user'], $flag => 1], admin_url('admin.php')));
    exit;
  }
  public static function scope_action() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_scope');
    $sc = self::scope_req();
    $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';
    $flag = 'saved';
    if ($op === 'customize') {
      if (self::scope_own($sc) === null) { $p = self::scope_parent($sc); self::scope_set($sc, $p ? $p : ['top' => [], 'sub' => []]); }
    } elseif ($op === 'reset') {
      self::scope_unset($sc);
      $flag = 'reset';
    } elseif ($op === 'copy') {
      $src = isset($_POST['src']) ? sanitize_text_field(wp_unslash($_POST['src'])) : '';
      $s = self::scopes();
      $cfg = null;
      if ($src === 'def') { $cfg = self::raw(self::OPT); }
      elseif (strpos($src, 'r:') === 0 && isset($s['roles'][substr($src, 2)])) { $cfg = $s['roles'][substr($src, 2)]; }
      elseif (strpos($src, 'u:') === 0 && isset($s['users'][(int) substr($src, 2)])) { $cfg = $s['users'][(int) substr($src, 2)]; }
      if (is_array($cfg)) { self::scope_set($sc, $cfg); }
    }
    self::scope_back($sc, $flag);
  }

  // The "who are you setting this up for" bar, the status banner and the live preview column.
  private static function scope_bar($sc) {
    $base = admin_url('admin.php?page=' . self::SLUG . '&tab=left');
    $scopes = self::scopes();
    $def = self::scope_default($sc);
    $own = self::scope_own($sc) !== null;
    $ro = '<option value=~' . esc_url($base) . '~' . selected($sc['role'], 'all', false) . '>' . esc_html(self::t('Everyone (default)')) . '</option>';
    foreach (wp_roles()->get_names() as $slug => $n) {
      $ro .= '<option value=~' . esc_url($base . '&role=' . $slug) . '~' . selected($sc['role'], $slug, false) . '>' . esc_html(translate_user_role($n)) . (isset($scopes['roles'][$slug]) ? ' &bull;' : '') . '</option>';
    }
    if ($sc['role'] === 'all') {
      $uo = '<option>' . esc_html(self::t('All users')) . '</option>';
    } else {
      $uo = '<option value=~' . esc_url($base . '&role=' . $sc['role']) . '~' . selected($sc['user'], 0, false) . '>' . esc_html(self::t('Everyone with this role')) . '</option>';
      foreach (get_users(['role' => $sc['role'], 'orderby' => 'display_name', 'number' => 200]) as $u) {
        $uo .= '<option value=~' . esc_url($base . '&role=' . $sc['role'] . '&user=' . $u->ID) . '~' . selected($sc['user'], $u->ID, false) . '>' . esc_html($u->display_name) . (isset($scopes['users'][$u->ID]) ? ' &bull;' : '') . '</option>';
      }
    }
    $chip = $def ? self::t('Default') : ($own ? self::t('Own settings') : self::t('Inherited'));
    $h = '<h2 class=~amx-t~>' . esc_html(self::t('Left Menu Editor')) . '</h2><p class=~amx-lead~>' . esc_html(self::t('Drag to reorder, rename or hide items. Set it per role or per user: the menu of a user goes before the menu of their role, and that goes before the default. Hidden items can still be reached through their URL.')) . '</p>';
    $hid = '<input type=~hidden~ name=~role~ value=~' . esc_attr($sc['role']) . '~><input type=~hidden~ name=~user~ value=~' . (int) $sc['user'] . '~>';
    $form = function ($op, $inner) use ($hid) {
      return '<form method=~post~ action=~' . esc_url(admin_url('admin-post.php')) . '~ class=~amx-inl~><input type=~hidden~ name=~action~ value=~mk_ame_scope~><input type=~hidden~ name=~op~ value=~' . $op . '~>' . wp_nonce_field('mk_ame_scope', '_wpnonce', false, false) . $hid . $inner . '</form>';
    };
    $name = self::scope_label($sc);
    $parent = $sc['user'] ? sprintf(self::t('the role %s'), self::role_label($sc['role'])) : self::t('the default menu');
    // Copy from: everything that has its own settings, except the one being edited.
    $opts = [];
    if (!$def && self::raw(self::OPT)) { $opts['def'] = self::t('Default'); }
    foreach ($scopes['roles'] as $r => $x) { if (!($sc['role'] === $r && !$sc['user'])) { $opts['r:' . $r] = sprintf(self::t('Role %s'), self::role_label($r)); } }
    foreach ($scopes['users'] as $uid => $x) { $uu = get_userdata($uid); if ($uu && $uid !== $sc['user']) { $opts['u:' . $uid] = $uu->display_name; } }
    $copy = '';
    if ($opts) {
      $co = '<option value=~~>' . esc_html(self::t('Copy from...')) . '</option>';
      foreach ($opts as $k => $l) { $co .= '<option value=~' . esc_attr($k) . '~>' . esc_html($l) . '</option>'; }
      $copy = '<details class=~amx-copy~><summary>' . esc_html(self::t('Copy from...')) . '</summary><div class=~amx-pop~>' . $form('copy', '<select name=~src~ required>' . $co . '</select> <button type=~submit~ class=~button~ onclick=~return confirm(&quot;' . esc_attr(self::t('This replaces the current settings. Continue?')) . '&quot;)~>' . esc_html(self::t('Copy')) . '</button>') . '</div></details>';
    }
    $act = '';
    if ($def) {
      $state = self::t('Everyone without their own settings sees this menu.');
    } elseif (!$own) {
      $state = $sc['user'] ? sprintf(self::t('%1$s uses the menu of the role %2$s'), $name, self::role_label($sc['role'])) : sprintf(self::t('%s uses the default menu'), $name);
      $state .= '. ' . self::t('View only. Click Customize to change this menu.');
      $act = $form('customize', '<button type=~submit~ class=~button button-primary~>' . esc_html(sprintf(self::t('Customize for %s'), $name)) . '</button>');
    } else {
      $state = sprintf(self::t('This goes before %s.'), $parent);
      $act = $form('reset', '<button type=~submit~ class=~button~ onclick=~return confirm(&quot;' . esc_attr(sprintf(self::t('Remove these settings and go back to %s?'), $parent)) . '&quot;)~>' . esc_html(sprintf(self::t('Back to %s'), $parent)) . '</button>');
    }
    $h .= '<div class=~amx-scope~><label>' . esc_html(self::t('Role')) . '<select onchange=~window.amxGo?amxGo(this.value):location.href=this.value~>' . $ro . '</select></label>';
    $h .= '<label>' . esc_html(self::t('User')) . '<select onchange=~window.amxGo?amxGo(this.value):location.href=this.value~' . ($sc['role'] === 'all' ? ' disabled' : '') . '>' . $uo . '</select></label><span class=~amx-act~>' . $act . $copy . '</span></div>';
    $h .= '<p class=~amx-state~><span class=~amx-chip' . (($own && !$def) ? ' own' : '') . '~>' . esc_html($chip) . '</span>' . esc_html($state) . '</p>';
    return $h;
  }
  private static function scope_css() {
    return '<style>.amx-scope{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;max-width:1200px;margin:22px 0 0}.amx-scope label{display:flex;align-items:center;gap:8px;font-size:13px;color:#646970;margin:0}.amx-scope select{min-width:190px}.amx-act{margin-left:auto;display:flex;gap:8px;align-items:center}.amx-inl{display:inline-flex;gap:6px;align-items:center;margin:0}.amx-inl select{min-width:150px}'
      . '.amx-chip{font-size:12px;font-weight:600;padding:2px 10px;border-radius:12px;background:#f0f0f1;color:#50575e;margin-right:10px}.amx-chip.own{background:var(--amx-tint,#f0f6fc);color:var(--amx-main,#2271b1)}.amx-state{margin:12px 0 22px;font-size:13px;color:#50575e;max-width:1200px}'
      . '.amx-copy{position:relative}.amx-copy summary{list-style:none;cursor:pointer;border:1px solid #c3c4c7;border-radius:3px;padding:0 10px;line-height:2.15;font-size:13px;color:#2c3338;background:#fff}.amx-copy summary::-webkit-details-marker{display:none}.amx-copy[open] summary{border-color:var(--amx-main,#2271b1)}.amx-pop{position:absolute;right:0;top:calc(100% + 6px);z-index:20;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px;box-shadow:0 6px 20px rgba(0,0,0,.12);white-space:nowrap}'
      . '.amx-sg{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:22px;align-items:start}.amx-pv{position:sticky;top:52px}.amx-pv h3{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}'
      . '.amx-pvs{background:#1d2327;border-radius:8px;padding:6px 0;color:#f0f0f1;min-height:80px}.amx-pvs div{display:flex;align-items:center;gap:10px;height:34px;padding:0 12px;font-size:14px}.amx-pvs i{width:16px;height:16px;border-radius:50%;background:#a7aaad;opacity:.8;flex:none}'
      . '.amx-pvs div.sub{height:28px;padding:0 12px 0 38px;font-size:13px;background:#2c3338;color:#c3c4c7}.amx-pvs div.sep{height:14px;padding:0}.amx-pvs div.cur{background:var(--wp-admin-theme-color,#2271b1);color:#fff}.amx-pvs div.cur i{background:#fff;opacity:1}.amx-pv p{color:#646970;font-size:12.5px;margin:8px 0 0}'
      . '.amx-lock .ame-list{opacity:.5;pointer-events:none}'
      . '@media(max-width:900px){.amx-sg{grid-template-columns:1fr}.amx-pv{position:static}}</style>';
  }
  private static function scope_preview($sc) {
    $title = sprintf(self::t('How %s sees it'), self::scope_label($sc));
    $tpl = wp_json_encode(self::t('%1$d of %2$d items hidden.'));
    $js = '<script>(function(){var ul=document.querySelector(~.amx-sg .ame-list~);var box=document.getElementById(~amx-pvl~),note=document.getElementById(~amx-pvn~);if(!ul||!box)return;var tpl=' . $tpl . ';'
      . 'function nm(li){var hd=li.querySelector(~:scope > .ame-head~);if(!hd)return null;var ti=hd.querySelector(~.ame-title~);return {hd:hd,n:ti?(ti.value||ti.getAttribute(~placeholder~)||~~):~~};}'
      . 'function run(){var vis=[],total=0;ul.querySelectorAll(~:scope > li~).forEach(function(li){if(li.classList.contains(~ame-sep~)){var sc=li.querySelector(~input[type=checkbox]~);if(!(sc&&sc.checked))vis.push(null);return;}var o=nm(li);if(!o)return;total++;var cb=o.hd.querySelector(~.ame-hide input~);if(cb&&cb.checked)return;if(!o.n)return;vis.push(o.n);var sub=li.querySelector(~:scope > ul.ame-sub~);if(sub&&sub.offsetParent!==null){sub.querySelectorAll(~:scope > li:not(.ame-sep)~).forEach(function(sl){var so=nm(sl);if(!so||!so.n)return;var sb=so.hd.querySelector(~.ame-hide input~);if(sb&&sb.checked)return;vis.push({s:so.n});});}});'
      . 'box.innerHTML=~~;var first=true,last=null,shown=0;vis.forEach(function(n){if(n===null){if(first||last===null)return;var sp=document.createElement(~div~);sp.className=~sep~;box.appendChild(sp);last=null;return;}if(typeof n===~object~){var sd=document.createElement(~div~);sd.className=~sub~;sd.appendChild(document.createTextNode(n.s));box.appendChild(sd);return;}var d=document.createElement(~div~);if(first){d.className=~cur~;first=false;}var ic=document.createElement(~i~);d.appendChild(ic);d.appendChild(document.createTextNode(n));box.appendChild(d);last=n;shown++;});if(box.lastChild&&box.lastChild.className===~sep~)box.removeChild(box.lastChild);note.textContent=tpl.replace(~%1$d~,total-shown).replace(~%2$d~,total);}'
      . 'run();ul.addEventListener(~input~,run);ul.addEventListener(~change~,run);ul.addEventListener(~click~,function(){setTimeout(run,30);});if(window.jQuery){jQuery(ul).on(~sortupdate sortstop sortreceive~,function(){setTimeout(run,0);});}})();</script>';
    return '<div class=~amx-pv~><h3>' . esc_html($title) . '</h3><div class=~amx-pvs~ id=~amx-pvl~></div><p id=~amx-pvn~></p></div>' . $js;
  }

  private static function locked($slug) {
    return in_array($slug, ['my-account', 'menu-toggle'], true);
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
    return '';
  }
  public static function add_page() {
    add_menu_page(self::t('Admin Manager'), self::t('Admin Manager'), 'manage_options', self::SLUG, [__CLASS__, 'page'], 'dashicons-align-right', 81);
    if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === self::SLUG) { wp_safe_redirect(add_query_arg($_GET, admin_url('admin.php'))); exit; }
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
    if ($hook === 'toplevel_page_' . self::SLUG) { wp_enqueue_script('jquery-ui-sortable'); }
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
    if (!is_object($wp_admin_bar)) { return; }
    $nodes = $wp_admin_bar->get_nodes();
    if (!$nodes) { return; }
    // WordPress puts some dropdown items in a separate group (for example Themes and Menus under the site name on the
    // website). A group is shown as its own block with extra space and often lands above the other items. The editor
    // shows these items as one list, so merge each group into its dropdown to make the bar follow the editor.
    $merged = [];
    foreach ($nodes as $gid => $g) {
      if (empty($g->group)) { continue; }
      $p = (string) $g->parent;
      if ($p === '' || !isset($nodes[$p]) || !empty($nodes[$p]->group)) { continue; }
      foreach ($nodes as $cid => $c) {
        if ((string) $c->parent === $gid && empty($c->group)) { $wp_admin_bar->add_node(['id' => $cid, 'parent' => $p]); }
      }
      $wp_admin_bar->remove_node($gid);
      $merged[$gid] = $p;
    }
    $s = get_option(self::BAR, []);
    if (empty($s['lists'])) { return; }
    $lists = $s['lists'];
    foreach ($merged as $gid => $p) {
      if (!empty($lists[$gid])) { $lists[$p] = array_merge(isset($lists[$p]) ? $lists[$p] : [], $lists[$gid]); unset($lists[$gid]); }
    }
    $nodes = $wp_admin_bar->get_nodes();
    foreach ($lists as $parent => $rows) {
      $order = [];
      foreach ($rows as $r) {
        $id = $r['slug'];
        if (!isset($nodes[$id])) { continue; }
        if (!empty($r['hidden']) && !self::locked($id) && $id !== 'menu-toggle') { $wp_admin_bar->remove_node($id); continue; }
        if ($r['title'] !== '') { $wp_admin_bar->add_node(['id' => $id, 'title' => self::bar_retitle($nodes[$id]->title, $r['title'])]); }
        $order[] = $id;
      }
      if ($parent === 'top-secondary') { $order = array_reverse($order); }
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
    $sc = self::scope_req();
    if (!self::scope_default($sc) && self::scope_own($sc) === null) { self::scope_back($sc, 'saved'); }
    self::scope_set($sc, $out);
    self::scope_back($sc, 'saved');
  }
  public static function reset() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_reset');
    delete_option(self::OPT);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'reset' => 1], admin_url('admin.php')));
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
          $cus[] = ['id' => $id, 'title' => $title, 'url' => esc_url_raw($url), 'parent' => $eff, 'blank' => !empty($old[$id]['blank']) ? 1 : 0, 'where' => (isset($r['where']) && in_array($r['where'], ['admin', 'front'], true)) ? $r['where'] : 'both'];
          $out['lists'][$eff][] = ['slug' => $id, 'title' => '', 'hidden' => !empty($r['hidden']) ? 1 : 0];
          continue;
        }
        $out['lists'][$parent][] = ['slug' => $id, 'title' => sanitize_text_field(isset($r['title']) ? $r['title'] : ''), 'hidden' => (!empty($r['hidden']) && !self::locked($id)) ? 1 : 0];
      }
    }
    update_option(self::BAR, $out);
    update_option(self::CUS, $cus);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'top', 'saved' => 1], admin_url('admin.php')));
    exit;
  }
    public static function reset_bar() {
    if (!current_user_can('manage_options')) { wp_die(self::t('Access denied.')); }
    check_admin_referer('mk_ame_reset_bar');
    delete_option(self::BAR);
    wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'top', 'reset' => 1], admin_url('admin.php')));
    exit;
  }

  // ---------- Custom links in the top bar ----------
  private static function custom_links() {
    $c = get_option(self::CUS, []);
    return is_array($c) ? $c : [];
  }
  public static function bar_custom($bar) {
    if (!current_user_can('manage_options')) { return; }
    foreach (self::custom_links() as $c) {
      $w = isset($c['where']) ? $c['where'] : 'front';
      if (!self::$sim && (is_admin() ? $w === 'front' : $w === 'admin')) { continue; }
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
      if ($icon) { $h .= '<span class=~ame-icon-note~>' . (strpos($slug, 'menu-toggle') !== false ? esc_html(self::t('(icon only visible on small screens)')) : '') . '</span>'; }
    }
    $h .= $extra;
    $h .= '<label class=~ame-hide~ title=~' . esc_attr(self::t('Hide')) . '~><input type=~checkbox~ name=~' . $name . '[hidden]~ value=~1~' . ($hidden ? ' checked' : '') . ($locked ? ' disabled' : '') . '>'.self::EYE.'<span class=~screen-reader-text~>' . esc_html(self::t('Hide')) . '</span></label>';
    if (!$sep) { $h .= '<button type=~button~ class=~ame-edit~ title=~' . esc_attr(self::t('Edit')) . '~>' . self::PEN . '</button>'; }
    $h .= '</div>';
    return $h;
  }

  public static function page() {
    if (!current_user_can('manage_options')) { return; }
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    if ($tab === '' && !isset($_GET['saved']) && !isset($_GET['reset'])) { $tab = 'overview'; }
    if (!in_array($tab, array_merge(['top', 'colors', 'logos', 'order'], MK_AME_Extras::TABS), true)) { $tab = 'left'; }
    $base = admin_url('admin.php?page=' . self::SLUG);
    $css = '<style>.ame ul{margin:0;padding:0;list-style:none;max-width:1200px}.ame-row{margin:0 0 6px}.ame-head{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px}.ame-grip{cursor:grab;color:#8c8f94;font-size:16px;line-height:1;letter-spacing:-3px;user-select:none}.ame-title{flex:1;min-width:0}.ame-name{flex:1}.ame-muted{color:#8c8f94;font-style:italic}.ame-sep .ame-head{background:#f6f7f7;border-style:dashed}.ame-hide{white-space:nowrap}.ame-sub{margin:6px 0 10px 32px!important}.ame-ph{height:44px;border:1px dashed var(--amx-main,#2271b1);background:var(--amx-tint,#f0f6fc);margin-bottom:6px}.ame-row:has(> .ame-head input[type=checkbox]:checked) > .ame-head{opacity:.55}.ame h2{margin:24px 0 8px}.ame .nav-tab-wrapper{margin-bottom:16px}.ame-url{flex:1 1 auto;min-width:0}.ame-custom .ame-title{flex:0 0 240px}.ame-reset{background:none;border:0;color:var(--amx-main,#2271b1);cursor:pointer;text-decoration:underline;padding:0 4px;font-size:13px}.ame-hide{min-width:100px}.ame-remove{background:none;border:0;color:#b32d2e;cursor:pointer;text-decoration:underline;padding:0 4px;font-size:13px}.ame-remove:hover{color:#8a1f1f}.ame-custom > .ame-head{border-left:3px solid var(--amx-main,#2271b1)}.ame-title::placeholder{color:#8c8f94;font-style:italic;opacity:1}.ame-icon-note{color:#8c8f94;font-size:12px;font-style:italic;white-space:nowrap}.ame-where{font-size:11px;font-weight:600;line-height:1;padding:4px 8px;border-radius:10px;white-space:nowrap}.ame-where-a{background:#f0f0f1;color:#50575e}.ame-where-f{background:#e6f4ea;color:#1e6b34}.ame .ame-head,.ame-cols{display:grid;grid-template-columns:20px 36px minmax(160px,1fr) 170px 110px 64px;grid-template-areas:~grip tog name note where hide~;column-gap:12px;align-items:center}.ame-tab-left .ame-head,.ame-tab-left .ame-cols{grid-template-columns:20px 36px minmax(160px,1fr) 170px 64px;grid-template-areas:~grip tog name note hide~}.ame-head>.ame-grip{grid-area:grip}.ame-head>.ame-toggle,.ame-head>.ame-remove{grid-area:tog;justify-self:start}.ame-head>.ame-title,.ame-head>.ame-name{grid-area:name;width:100%;min-width:0}.ame-head>.ame-reset,.ame-head>.ame-icon-note{grid-area:note;justify-self:start}.ame-head>.ame-url{grid-area:note;width:100%;min-width:0}.ame-head>.ame-where{grid-area:where;justify-self:start}.ame-head>.ame-hide{grid-area:hide;justify-self:center;min-width:0;font-size:0}.ame-cols{max-width:1200px;box-sizing:border-box;padding:0 13px;margin:0 0 6px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#646970}.ame-cols>span:last-child{justify-self:center}.ame-toggle{display:inline-flex;align-items:center;gap:1px;background:none;border:0;padding:2px;cursor:pointer;color:var(--amx-main,#2271b1);border-radius:3px}.ame-toggle:hover{background:var(--amx-tint,#f0f6fc)}.ame-toggle .dashicons{font-size:16px;width:16px;height:16px;transition:transform .15s}.ame-toggle.is-open .dashicons{transform:rotate(90deg)}.ame-count{font-size:11px;color:#646970}.ame-head>.ame-remove{padding:2px;text-decoration:none}.ame-remove .dashicons{font-size:16px;width:16px;height:16px}.ame-lang-nl .ame-head,.ame-lang-nl .ame-cols{grid-template-columns:20px 36px minmax(160px,1fr) 170px 110px 72px}.ame-lang-nl.ame-tab-left .ame-head,.ame-lang-nl.ame-tab-left .ame-cols{grid-template-columns:20px 36px minmax(160px,1fr) 170px 72px}@media(max-width:960px){.ame .ame-head{display:flex;flex-wrap:wrap}.ame-head>.ame-title{flex:1 1 200px}.ame-head>.ame-hide{font-size:13px}.ame-cols{display:none}}.ame-hide{position:relative;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;min-width:0;border-radius:6px;cursor:pointer;color:#8c8f94}.ame-hide:hover{background:#f0f0f1;color:#1d2327}.ame-hide input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer}.ame-hide input{appearance:none!important;-webkit-appearance:none!important;background:none!important;border:0!important;box-shadow:none!important;outline:0!important;opacity:0!important;position:absolute!important;inset:0;width:100%!important;height:100%!important;min-width:0!important;margin:0!important}.ame-hide input:before{display:none!important;content:none!important}.ame-eye{display:flex;pointer-events:none}.ame-eye svg{width:20px;height:20px}.ame-eye .off{display:none}.ame-hide input:checked + .ame-eye .on{display:none}.ame-hide input:checked + .ame-eye .off{display:block}.ame-hide input:disabled{cursor:default}.ame-hide:has(input:disabled){opacity:.5;cursor:default}.ame-hide:has(input:disabled):hover{background:none}.ame .ame-row > .ame-head{display:flex;flex-wrap:nowrap;align-items:center;gap:10px}.ame .ame-row > .ame-head > *{order:5}.ame .ame-row > .ame-head > .ame-grip{order:0}.ame .ame-row > .ame-head > .ame-toggle{order:1}.ame .ame-row > .ame-head > .ame-title,.ame .ame-row > .ame-head > .ame-name{order:2}.ame .ame-row > .ame-head > .ame-reset{order:3}.ame .ame-row > .ame-head > .ame-icon-note{order:4}.ame .ame-row > .ame-head > .ame-url{order:5}.ame .ame-row > .ame-head > .ame-where{order:6}.ame .ame-row > .ame-head > .ame-hide{order:8;margin-left:auto}.ame .ame-row > .ame-head > .ame-remove{order:7}.ame .ame-row > .ame-head > .ame-edit{order:9}.ame .ame-row > .ame-head > .ame-wsel{order:6;display:none;flex:none;min-height:30px}.ame .ame-row.is-edit > .ame-head > .ame-wsel{display:inline-block}.ame .ame-row.is-edit.ame-custom > .ame-head > .ame-where{display:none}.ame-edit .ck{display:none}.ame .ame-row.is-edit > .ame-head > .ame-edit .ck{display:block}.ame .ame-row.is-edit > .ame-head > .ame-edit .pen{display:none}.ame .ame-row.is-edit > .ame-head > .ame-edit{color:var(--amx-main,#2271b1)}.ame-addp{margin:8px 0 24px}.ame .ame-row > .ame-head > .ame-wsel{width:135px;max-width:135px;flex:none}.ame .ame-row > .ame-head > .ame-hide,.ame .ame-row > .ame-head > .ame-edit,.ame .ame-row > .ame-head > .ame-remove,.ame .ame-row > .ame-head > .ame-grip,.ame .ame-row > .ame-head > .ame-toggle,.ame .ame-row > .ame-head > .ame-where{flex:none}.ame .ame-row > .ame-head > .ame-title{flex:0 1 auto;width:auto}.ame .ame-row > .ame-head > .ame-url{flex:1 1 0;width:auto;min-width:0}.ame-icon-note:empty{display:none}.ame .ame-row:not(.is-edit) > .ame-head > .ame-title,.ame .ame-row:not(.is-edit) > .ame-head > .ame-url{border-color:transparent!important;background:transparent!important;box-shadow:none!important;pointer-events:none;padding-left:0;padding-right:0;field-sizing:content;min-width:40px;max-width:360px}.ame .ame-row:not(.is-edit) > .ame-head > .ame-title{color:#1d2327;font-weight:500}.ame .ame-row:not(.is-edit) > .ame-head > .ame-title::placeholder{color:#1d2327;font-style:normal;opacity:1}.ame .ame-row:not(.is-edit) > .ame-head > .ame-url{font-size:12px;color:#8c8f94;max-width:none}.ame .ame-row:not(.is-edit) > .ame-head > .ame-url:placeholder-shown{display:none}.ame .ame-row.is-edit > .ame-head > .ame-title{flex:0 1 200px;min-width:110px}.ame .ame-row.is-edit:not(.ame-custom) > .ame-head > .ame-title{flex:1 1 auto;max-width:420px}.ame .ame-row.is-edit > .ame-head > .ame-url{flex:1 1 200px;min-width:130px}.ame-edit,.ame-remove{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;margin:0;border:0;border-radius:6px;background:none;cursor:pointer;color:#8c8f94;text-decoration:none}.ame-edit:hover{background:#f0f0f1;color:#1d2327}.ame-remove{color:#b32d2e}.ame-remove:hover{background:#fcf0f1;color:#8a1f1f}.ame-edit svg,.ame-remove svg{width:18px;height:18px;pointer-events:none}.ame .ame-row > .ame-head > .ame-edit{visibility:hidden}.ame .ame-row > .ame-head:hover > .ame-edit,.ame .ame-row.is-edit > .ame-head > .ame-edit{visibility:visible}.ame .ame-row > .ame-head > .ame-remove{display:none}.ame .ame-row.is-edit > .ame-head > .ame-remove{display:inline-flex}@media(hover:none){.ame .ame-row > .ame-head > .ame-edit{visibility:visible}}</style>';
    $js = '<script>jQuery(function($){$(~.ame-list~).sortable({handle:~.ame-grip~,items:~>li~,connectWith:($(~.ame-add~).length?~.ame-list~:false),placeholder:~ame-ph~,receive:function(e,ui){if(!ui.item.hasClass(~ame-custom~)){ui.sender.sortable(~cancel~)}}});$(~.ame-toggle~).on(~click~,function(){var o=$(this).toggleClass(~is-open~).hasClass(~is-open~);$(this).attr(~aria-expanded~,o?~true~:~false~).closest(~li~).children(~ul.ame-sub~).toggle(o)});$(document).on(~click~,~.ame-remove~,function(){var li=$(this).closest(~li~);if(li.hasClass(~ame-new~)){li.remove()}else{li.find(~.ame-del~).first().val(1);li.hide()}});function sy(i){var o=i.attr(~data-orig~);i.siblings(~.ame-reset~).toggle(o!==undefined&&i.val()!==o)}$(~.ame-title[data-orig]~).each(function(){sy($(this))}).on(~input~,function(){sy($(this))});$(document).on(~click~,~.ame-reset~,function(){var i=$(this).siblings(~.ame-title~);i.val(i.attr(~data-orig~));sy(i)});$(document).off(~mousedown.amee~,~.ame-edit,.ame-remove~).on(~mousedown.amee~,~.ame-edit,.ame-remove~,function(e){e.preventDefault()});$(document).off(~click.amee~,~.ame-edit~).on(~click.amee~,~.ame-edit~,function(){var li=$(this).closest(~li~),hd=li.children(~.ame-head~);function snap(){return hd.find(~.ame-title,.ame-url,.ame-wsel~).map(function(){return this.value}).get().join(~|~)}if(!li.hasClass(~is-edit~)){li.addClass(~is-edit~).data(~snap~,snap());hd.find(~.ame-title~).focus();return}if(document.activeElement){document.activeElement.blur()}var isnew=li.hasClass(~ame-new~);if(isnew&&!hd.find(~.ame-title~).val()&&!hd.find(~.ame-url~).val()){li.remove();return}if(isnew||li.data(~snap~)!==snap()){var f=li.closest(~form~)[0];if(f){if(f.requestSubmit){f.requestSubmit()}else{$(f).trigger(~submit~)}return}}li.removeClass(~is-edit~)});$(document).off(~focusin.amee~,~.ame-head .ame-title,.ame-head .ame-url~).on(~focusin.amee~,~.ame-head .ame-title,.ame-head .ame-url~,function(){$(this).closest(~li~).addClass(~is-edit~)});$(document).off(~focusout.amee~,~.ame-head~).on(~focusout.amee~,~.ame-head~,function(){var h=this,li=$(h).closest(~li~);setTimeout(function(){if(!h.contains(document.activeElement)&&!li.hasClass(~ame-new~)){li.removeClass(~is-edit~)}},0)});$(document).off(~keydown.amee~,~.ame-head .ame-title,.ame-head .ame-url~).on(~keydown.amee~,~.ame-head .ame-title,.ame-head .ame-url~,function(e){if(e.which===13){e.preventDefault();$(this).blur()}});var n=0;$(~.ame-add~).on(~click~,function(){var ul=$(this).closest(~p~).prevAll(~ul.ame-list~).first();n++;var p=~bar[0][rows][~+(900+n)+~]~;var h=$(~<div>~).addClass(~ame-head~);h.append($(~<span>~).addClass(~ame-grip~).html(~&#8942;&#8942;~));h.append($(~<input>~).attr({type:~hidden~,name:p+~[slug]~,value:~new~}));h.append($(~<input>~).attr({type:~hidden~,name:p+~[pos]~,value:0}).addClass(~pos~));h.append($(~<input>~).attr({type:~text~,name:p+~[title]~,placeholder:~' . esc_js(self::t('Name')) . '~}).addClass(~ame-title~));h.append($(~<input>~).attr({type:~text~,name:p+~[url]~,list:~ame-urls~,placeholder:~' . esc_js(self::t('URL or admin page')) . '~}).addClass(~ame-url~));h.append($(~<input>~).attr({type:~hidden~,name:p+~[custom]~,value:1}));h.append($(~<select>~).attr({name:p+~[where]~}).addClass(~ame-wsel~).html(~<option value=both>' . esc_js(self::t('Admin and frontend')) . '</option><option value=admin>' . esc_js(self::t('Admin only')) . '</option><option value=front>' . esc_js(self::t('Frontend only')) . '</option>~));h.append($(~<button>~).attr({type:~button~,title:~' . esc_js(__('Delete')) . '~}).addClass(~ame-remove~).html(' . wp_json_encode(self::TRASH) . '));h.append($(~<label>~).addClass(~ame-hide~).append($(~<input>~).attr({type:~checkbox~,name:p+~[hidden]~,value:1})).append($(~<span>~).addClass(~ame-eye~).html(' . wp_json_encode(self::EYE_INNER) . ')));h.append($(~<button>~).attr({type:~button~,title:~' . esc_js(self::t('Edit')) . '~}).addClass(~ame-edit~).html(' . wp_json_encode(self::PEN) . '));var li=$(~<li>~).addClass(~ame-row ame-custom ame-new is-edit~).append(h);ul.append(li);$(~.ame-list~).sortable(~refresh~);li[0].scrollIntoView({block:~center~});h.find(~.ame-title~).focus()});$(~.ame-form~).on(~submit~,function(){$(~.ame-title[data-orig]~).each(function(){if($(this).val()===$(this).attr(~data-orig~)){$(this).val(~~)}});$(~ul.ame-list~).each(function(){var idx=$(this).data(~idx~);$(this).children(~li~).each(function(i){var hd=$(this).children(~.ame-head~);hd.find(~input~).each(function(){var nm=this.name;if(nm&&nm.indexOf(~bar[~)===0){this.name=~bar[~+idx+~][rows][~+i+~]~+nm.substring(nm.lastIndexOf(~[~))}});hd.find(~.pos~).val(i)})})})});</script>';
    $h = '<div class=~wrap ame ame-tab-' . $tab . ' ame-lang-' . self::lang() . '~><h1>' . esc_html(self::t('Admin Manager')) . '</h1>' . MK_AME_Extras::sidebar($tab, $base) . '';
    if (isset($_GET['saved'])) { $h .= '<div class=~notice notice-success is-dismissible inline~><p>' . esc_html(self::t('Saved.')) . '</p></div>'; }
    if (isset($_GET['reset'])) { $h .= '<div class=~notice notice-success is-dismissible inline~><p>' . esc_html(self::t('Reset to the defaults.')) . '</p></div>'; }
    if ($tab === 'colors' || $tab === 'logos' || $tab === 'order' || in_array($tab, MK_AME_Extras::TABS, true)) {
      // These tabs print their own markup with normal quotes.
      $body = in_array($tab, MK_AME_Extras::TABS, true) ? MK_AME_Extras::tab($tab) : ($tab === 'order' ? MK_AME_Order::tab() : MK_AME_Design::tab($tab));
      echo strtr($css . $h, '~', chr(34)) . $body . MK_AME_Extras::sidebar_end() . '</div>';
      return;
    }
    $h .= $tab === 'top' ? self::tab_top() : self::tab_left();
    $h .= MK_AME_Extras::sidebar_end();
    // Hiding a parent also shows its sub items as hidden (ticked and locked). On save their own choice is sent,
    // so unhiding the parent later brings them back exactly as they were.
    $js .= '<script>jQuery(function($){function kids(cb){return $(cb).closest(~li~).children(~ul~).find(~.ame-head .ame-hide input[type=checkbox]~);}function sync(cb){var on=cb.checked;kids(cb).each(function(){var k=$(this);if(on){if(!k.prop(~disabled~)){k.data(~own~,this.checked);}this.checked=true;k.prop(~disabled~,true);}else if(k.prop(~disabled~)){this.checked=!!k.data(~own~);k.prop(~disabled~,false);}});}$(~.ame-head .ame-hide input[type=checkbox]~).filter(function(){return kids(this).length>0;}).each(function(){if(this.checked){sync(this);}}).on(~change~,function(){sync(this);});$(~.ame-form~).on(~submit~,function(){$(this).find(~.ame-hide input:disabled~).each(function(){this.checked=!!$(this).data(~own~);$(this).prop(~disabled~,false);});});});</script>';
    echo strtr($css . $h . $js . '</div>', '~', chr(34));
  }

  private static function forms($list_html, $save, $reset, $hidden = '', $show_reset = true, $show_save = true) {
    $h = '<form class=~ame-form~ method=~post~ action=~' . esc_url(admin_url('admin-post.php')) . '~><input type=~hidden~ name=~action~ value=~' . $save . '~>' . wp_nonce_field($save, '_wpnonce', true, false) . $hidden . $list_html;
    if ($show_save) { $h .= '<p class=~submit~><button type=~submit~ class=~button button-primary~>' . esc_html(self::t('Save changes')) . '</button></p>'; }
    $h .= '</form>';
    if (!$show_reset) { return $h; }
    $h .= '<form method=~post~ action=~' . esc_url(admin_url('admin-post.php')) . '~ onsubmit=~return confirm(&quot;' . esc_attr(self::t('Reset everything to the defaults?')) . '&quot;)~><input type=~hidden~ name=~action~ value=~' . $reset . '~>' . wp_nonce_field($reset, '_wpnonce', true, false) . '<button type=~submit~ class=~button-link button-link-delete~>' . esc_html(self::t('Reset to defaults')) . '</button></form>';
    return $h;
  }

  private static function tab_left() {
    global $menu, $submenu;
    $o = self::$orig ? self::$orig : ['menu' => $menu, 'submenu' => $submenu];
    $sc = self::scope_req();
    $s = self::scope_effective($sc);
    $tops = $o['menu'];
    ksort($tops, SORT_NUMERIC);
    $byslug = [];
    foreach ($tops as $it) { $byslug[$it[2]] = $it; }
    $st = [];
    foreach ((array) (isset($s['top']) ? $s['top'] : []) as $t) { $st[$t['slug']] = $t; }
    $order = [];
    foreach ($st as $slug => $t) { if (isset($byslug[$slug])) { $order[] = $slug; } }
    foreach ($byslug as $slug => $it) { if (!in_array($slug, $order, true)) { $order[] = $slug; } }
    $h = self::cols(false) . '<ul class=~ame-list~>';
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
    $isdef = self::scope_default($sc);
    $locked = !$isdef && self::scope_own($sc) === null;
    $hidden = '<input type=~hidden~ name=~role~ value=~' . esc_attr($sc['role']) . '~><input type=~hidden~ name=~user~ value=~' . (int) $sc['user'] . '~>';
    $form = self::forms($h, 'mk_ame_save', 'mk_ame_reset', $hidden, $isdef, !$locked);
    $msg = '';
    return self::scope_css() . self::scope_bar($sc) . '<div class=~amx-sg~><div class=~' . ($locked ? 'amx-lock' : '') . '~>' . $msg . $form . '</div>' . self::scope_preview($sc) . '</div>';
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
    if ($parent === 'top-secondary') { $ids = array_reverse($ids); }
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
      if ($id === 'menu-toggle') { continue; }
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
        $extra = '<input type=~text~ class=~ame-url~ list=~ame-urls~ name=~' . $nm . '[url]~ value=~' . esc_attr($cc['url']) . '~ placeholder=~' . esc_attr(self::t('URL')) . '~><input type=~hidden~ name=~' . $nm . '[custom]~ value=~1~><input type=~hidden~ class=~ame-del~ name=~' . $nm . '[del]~ value=~0~><button type=~button~ class=~ame-remove~ title=~' . esc_attr__('Delete') . '~>' . self::TRASH . '</button>' . $extra;
        $w = isset($cc['where']) ? $cc['where'] : 'front';
        $extra = '<select class=~ame-wsel~ name=~' . $nm . '[where]~><option value=~both~' . selected($w, 'both', false) . '>' . esc_html(self::t('Admin and frontend')) . '</option><option value=~admin~' . selected($w, 'admin', false) . '>' . esc_html(self::t('Admin only')) . '</option><option value=~front~' . selected($w, 'front', false) . '>' . esc_html(self::t('Frontend only')) . '</option></select>' . $extra;
        if ($w === 'admin') { $extra = '<span class=~ame-where ame-where-a~>' . esc_html(self::t('Admin only')) . '</span>' . $extra; }
        elseif ($w === 'front') { $extra = '<span class=~ame-where ame-where-f~>' . esc_html(self::t('Frontend only')) . '</span>' . $extra; }
      }
      if (self::$ctxk && !isset($cm[$id])) {
        $ia = !empty($nodes[$id]['a']); $if = !empty($nodes[$id]['f']);
        if ($ia && !$if) { $extra = '<span class=~ame-where ame-where-a~>' . esc_html(self::t('Admin only')) . '</span>' . $extra; }
        elseif ($if && !$ia) { $extra = '<span class=~ame-where ame-where-f~>' . esc_html(self::t('Frontend only')) . '</span>' . $extra; }
      }
      $h .= self::row($nm, $id, $ph, $title, $saved ? $saved['hidden'] : 0, false, $extra, !empty($nodes[$id]['i']) && !isset($cm[$id])) . $inner . '</li>';
      $j++;
    }
    return $h . '</ul>';
  }
  const PEN='<svg class="pen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg><svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  const TRASH='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>';
  const EYE_INNER='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="on"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="off"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>';
  const EYE='<span class="ame-eye"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="on"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="off"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg></span>';
  private static function bar_preview() {
    $js = '<script>jQuery(function($){var root=document.querySelector(~.ame-tab-top~);var bar=document.getElementById(~amx-bar~);if(!root||!bar)return;var L=bar.querySelector(~.l~),R=bar.querySelector(~.r~);'
      . 'function items(ul){var o=[];ul.querySelectorAll(~:scope > li:not(.ame-sep)~).forEach(function(li){var hd=li.querySelector(~:scope > .ame-head~);if(!hd)return;var cb=hd.querySelector(~.ame-hide input~);if(cb&&cb.checked)return;var ti=hd.querySelector(~.ame-title~);var n=ti?(ti.value||ti.getAttribute(~placeholder~)||~~):~~;var icon=!!hd.querySelector(~.ame-icon-note~);var ps=hd.querySelector(~input.pos~);var sl=ps?ps.previousElementSibling:null;var id=sl?sl.value.replace(/^wp-admin-bar-/,~~):~~;if(!n&&!icon)return;o.push({n:n,i:icon&&!ti.value,id:id});});return o;}'
      . 'var IC={~wp-logo~:~wordpress~,~menu-toggle~:~menu~,~site-name~:~admin-home~,~comments~:~admin-comments~,~new-content~:~plus~,~updates~:~update~,~search~:~search~,~edit~:~edit~,~customize~:~admin-customizer~,~appearance~:~admin-appearance~,~view~:~visibility~,~archive~:~archive~};var CT={~comments~:~2~,~updates~:~3~};var ONLY={~wp-logo~:1,~menu-toggle~:1,~search~:1};function fill(box,list){box.innerHTML=~~;list.forEach(function(x){var d=document.createElement(~span~);var ic=IC[x.id];if(ic){var e=document.createElement(~b~);e.className=~dashicons dashicons-~+ic;d.appendChild(e);}else if(x.i){var c=document.createElement(~i~);d.appendChild(c);}var nm=x.n;if(x.id===~my-account~)nm=(nm||~Howdy, %s~).replace(/%\d?\$?s/,~Rino~);if(!(ic&&ONLY[x.id])&&!x.i){d.appendChild(document.createTextNode(nm));}var ct=CT[x.id];if(ct){if(x.id===~comments~){var b=document.createElement(~em~);b.textContent=ct;d.appendChild(b);}else{d.appendChild(document.createTextNode(ct));}}if(x.id===~my-account~){var av=document.createElement(~u~);d.appendChild(av);}box.appendChild(d);});}'
      . 'function run(){var left=[],right=[];root.querySelectorAll(~form ul.ame-list:not(.ame-sub)~).forEach(function(ul){var pr=ul.previousElementSibling;var par=pr&&pr.tagName===~INPUT~?pr.value:~~;if(par===~top-secondary~)right=items(ul);else if(par===~~)left=items(ul);});fill(L,left);fill(R,right);}'
      . 'run();var f=root.querySelector(~form~);if(f){f.addEventListener(~input~,run);f.addEventListener(~change~,run);f.addEventListener(~click~,function(){setTimeout(run,30);});}$(root).on(~sortupdate sortstop sortreceive~,function(){setTimeout(run,0);});});</script>';
    $css = '<style>.amx-barpv{position:sticky;top:32px;z-index:6;margin:22px 0 4px;padding-top:6px;background:#fff}.amx-barpv .amx-bar{display:flex;justify-content:space-between;align-items:center;background:#1d2327;border-radius:6px;height:34px;padding:0 6px;color:#f0f0f1;font-size:13px;overflow:hidden}'
      . '.amx-bar .l,.amx-bar .r{display:flex;align-items:center;height:34px;min-width:0}.amx-bar .r{flex-direction:row-reverse}.amx-bar span{display:inline-flex;align-items:center;gap:5px;height:34px;padding:0 9px;white-space:nowrap;line-height:1}'
      . '.amx-bar .dashicons{display:block;flex:none;font-size:18px;width:18px;height:18px;line-height:1;color:#a7aaad;margin:0}.amx-bar i{display:block;flex:none;width:16px;height:16px;border-radius:50%;background:#a7aaad}'
      . '.amx-bar u{display:block;flex:none;width:18px;height:18px;border-radius:3px;background:#8c8f94;margin-left:4px}.amx-bar em{font-style:normal;display:block;background:#d63638;color:#fff;border-radius:9px;min-width:18px;height:18px;padding:0 5px;box-sizing:border-box;font-size:11px;line-height:18px;text-align:center}</style>';
    return $css . '<div class=~amx-barpv~><div class=~amx-bar~ id=~amx-bar~><div class=~l~></div><div class=~r~></div></div></div>' . $js;
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
    $h = '<h2 class=~amx-t~>' . esc_html(self::t('Top Menu Editor')) . '</h2><p class=~amx-lead~>' . esc_html(self::t('The black bar at the top, in the admin and on the website. Drag to reorder, rename, or hide items.')) . '</p>';
    self::$ctxk = false;
    foreach ($nodes as $n) { if (!empty($n['f'])) { self::$ctxk = true; break; } }
    $h .= '<p class=~description~>' . esc_html(self::$ctxk ? self::t('Admin only: shown in the admin. Frontend only: shown on the website. No label: both.') : self::t('Visit the website once while logged in to see more items here.')) . '</p>';
    $h .= self::bar_preview();
    $k = 0;
    foreach ($roots as $p) {
      $title = $p === '' ? self::t('Top bar: left side') : ($p === 'top-secondary' ? self::t('Top bar: right side') : $p);
      $h .= '<h3 class=~amx-h3~>' . esc_html($title) . '</h3>' . self::bar_list($p, $kids, $nodes, $sm, $k, false);
      if ($p === '' || $p === 'top-secondary') { $h .= '<p class=~ame-addp~><button type=~button~ class=~button ame-add~>' . esc_html(self::t('+ Add menu item')) . '</button></p>'; }
    }
    $dl = '<datalist id=~ame-urls~>';
    foreach (['plugins.php' => self::t('Plugins'), 'themes.php' => self::t('Themes'), 'edit.php?post_type=page' => self::t('Pages'), 'upload.php' => self::t('Media'), 'users.php' => self::t('Users'), 'options-general.php' => self::t('Settings'), 'site-editor.php' => self::t('Site Editor'), 'admin.php?page=fluent-snippets' => 'Fluent Snippets', 'admin.php?page=fluent-cart' => 'FluentCart', 'admin.php?page=fluent-booking' => 'Fluent Booking'] as $u => $l) { $dl .= '<option value=~' . esc_attr($u) . '~>' . esc_html($l) . '</option>'; }
    foreach ((array) get_pages(['number' => 50]) as $pg) { $dl .= '<option value=~' . esc_attr(get_permalink($pg)) . '~>' . esc_html($pg->post_title) . '</option>'; }
    $h .= $dl . '</datalist>';
    return self::forms($h, 'mk_ame_save_bar', 'mk_ame_reset_bar');
  }
}
MK_Admin_Menu::init();
require_once __DIR__ . '/includes/design.php';
MK_AME_Design::init();
require_once __DIR__ . '/includes/ordering.php';
MK_AME_Order::init();
require_once __DIR__ . '/includes/extras.php';
MK_AME_Extras::init();
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
  array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=mk-admin-menu')) . '">' . esc_html__('Settings') . '</a>');
  return $links;
});
require_once __DIR__ . '/includes/updater.php';
new MK_GitHub_Updater(__FILE__, 'rinothecoder/wp-admin-manager');
}
