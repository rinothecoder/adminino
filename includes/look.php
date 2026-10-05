<?php
/**
 * Adminino: Fonts and Icons (Visual).
 *
 * Fonts: your own .woff2 font from the Media Library, loaded locally, for the whole admin and the top bar.
 * Icons: Lucide line icons for the left menu. WordPress items get a suggested icon, plugins keep their own
 * until you pick one. The full icon set is only loaded when you open the icon picker.
 * Both are off by default and load nothing until switched on.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_AME_Look', false)) {

final class MK_AME_Look {
  const FONTS = 'mk_ame_fonts';
  const ICONS = 'mk_ame_icons';
  const SPACE = 'mk_ame_spacing';
  const FAMILY = 'Adminino Font';
  // Suggested icons for the WordPress menu items. Plugins keep their own icon unless you pick one.
  const DEF = [
    'index.php' => 'layout-dashboard',
    'edit.php' => 'pin',
    'upload.php' => 'image',
    'edit.php?post_type=page' => 'file',
    'edit-comments.php' => 'message-square',
    'themes.php' => 'paintbrush',
    'plugins.php' => 'plug',
    'users.php' => 'users',
    'profile.php' => 'users',
    'tools.php' => 'wrench',
    'options-general.php' => 'settings',
  ];

  // Suggested icons for the top bar. The WordPress logo keeps its own (Lucide has no WordPress logo).
  const DEF_BAR = [
    'site-name' => 'house',
    'comments' => 'message-square',
    'new-content' => 'plus',
    'updates' => 'refresh-cw',
    'customize' => 'paintbrush',
    'edit' => 'pencil',
    'site-editor' => 'pencil',
    'view' => 'eye',
    'archive' => 'archive',
  ];
  // The dashicon WordPress shows for these items, for the editor.
  const OWN_BAR = [
    'wp-logo' => 'dashicons-wordpress',
    'comments' => 'dashicons-admin-comments',
    'new-content' => 'dashicons-plus',
    'updates' => 'dashicons-update',
    'customize' => 'dashicons-admin-customizer',
    'edit' => 'dashicons-edit',
    'site-editor' => 'dashicons-admin-appearance',
    'view' => 'dashicons-visibility',
    'archive' => 'dashicons-archive',
  ];

  public static function init() {
    add_action('admin_head', [__CLASS__, 'admin_head'], 20);
    add_action('wp_before_admin_bar_render', [__CLASS__, 'bar_icons'], 10001);
    add_action('wp_head', [__CLASS__, 'front_head'], 99);
    add_action('admin_post_mk_ame_save_look', [__CLASS__, 'save']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    add_filter('upload_mimes', [__CLASS__, 'font_mimes']);
    add_filter('wp_check_filetype_and_ext', [__CLASS__, 'font_check'], 10, 4);
  }

  private static function t($s) { return MK_Admin_Menu::tr($s); }
  private static function url($f) { return plugin_dir_url(dirname(__FILE__)) . 'assets/' . $f; }

  // ---------- Settings ----------
  public static function fonts() {
    $o = get_option(self::FONTS, []);
    $o = is_array($o) ? $o : [];
    return [
      'on' => !empty($o['on']) ? 1 : 0,
      'reg' => isset($o['reg']) ? (int) $o['reg'] : 0,
      'bold' => isset($o['bold']) ? (int) $o['bold'] : 0,
      'bar' => isset($o['bar']) ? (!empty($o['bar']) ? 1 : 0) : 1,
    ];
  }
  public static function icons() {
    $o = get_option(self::ICONS, []);
    $o = is_array($o) ? $o : [];
    $size = isset($o['size']) ? (int) $o['size'] : 20;
    $stroke = isset($o['stroke']) ? (float) $o['stroke'] : 2;
    return [
      'on' => !empty($o['on']) ? 1 : 0,
      // Fixed: 20px and a 1.75 line work well everywhere, so there are no settings for them.
      'size' => 20,
      'stroke' => 1.75,
      'map' => (isset($o['map']) && is_array($o['map'])) ? $o['map'] : [],
      'bar_on' => !empty($o['bar_on']) ? 1 : 0,
      'bmap' => (isset($o['bmap']) && is_array($o['bmap'])) ? $o['bmap'] : [],
      'svg' => (isset($o['svg']) && is_array($o['svg'])) ? $o['svg'] : [],
    ];
  }
  // The Lucide icon for a menu item, or '' for its own icon.
  public static function pick($slug, $s = null) {
    $s = $s ? $s : self::icons();
    if (isset($s['map'][$slug])) { return $s['map'][$slug] === '-' ? '' : $s['map'][$slug]; }
    // The switch only turns the suggested icons on. Icons you picked yourself always show.
    return ($s['on'] && isset(self::DEF[$slug])) ? self::DEF[$slug] : '';
  }
  // Same for a top bar item. On the website the site name shows the dashboard icon, in the admin a house.
  private static function bar_def($id, $front) {
    if ($id === 'site-name') { return $front ? 'layout-dashboard' : 'house'; }
    return isset(self::DEF_BAR[$id]) ? self::DEF_BAR[$id] : '';
  }
  public static function pick_bar($id, $front, $s = null) {
    $s = $s ? $s : self::icons();
    if (isset($s['bmap'][$id])) { return $s['bmap'][$id] === '-' ? '' : $s['bmap'][$id]; }
    return $s['bar_on'] ? self::bar_def($id, $front) : '';
  }
  private static function lib() {
    $f = dirname(__DIR__) . '/assets/lucide.json';
    $j = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
    return (is_array($j) && isset($j['i'])) ? $j['i'] : [];
  }
  // Keep the drawings of the icons in use with the settings, so normal admin pages never read the full set.
  private static function store($s) {
    $names = array_merge(array_values(self::DEF), array_values(self::DEF_BAR), ['layout-dashboard']);
    foreach (array_merge($s['map'], $s['bmap']) as $v) { if ($v !== '-' && strpos($v, 'att:') !== 0) { $names[] = $v; } }
    $lib = self::lib();
    $svg = [];
    foreach (array_unique($names) as $n) { if (isset($lib[$n][0])) { $svg[$n] = $lib[$n][0]; } }
    $s['svg'] = $svg;
    update_option(self::ICONS, $s);
  }
  // Fill the drawings once (for previews before the first save).
  public static function ensure() {
    $s = self::icons();
    if (!$s['svg']) { self::store($s); $s = self::icons(); }
    return $s;
  }
  // From the Left Menu Editor: icon[slug] = lucide name, '-' (own icon) or '' (suggested / own).
  public static function save_map($in, $key = 'map') {
    $key = $key === 'bmap' ? 'bmap' : 'map';
    $s = self::icons();
    foreach ((array) $in as $slug => $v) {
      $slug = substr(trim(wp_strip_all_tags((string) $slug)), 0, 300);
      $v = (string) $v;
      if ($slug === '') { continue; }
      if ($v === '') { unset($s[$key][$slug]); continue; }
      if ($v === '-' || preg_match('/^[a-z0-9-]{1,60}$/', $v) || preg_match('/^att:\d+$/', $v)) { $s[$key][$slug] = $v; }
    }
    self::store($s);
  }

  public static function svg($inner, $stroke = 2, $color = 'currentColor') {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="' . $stroke . '" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
  }
  // The item's own icon as HTML (dashicon, image or nothing).
  public static function own_icon($icon) {
    $icon = (string) $icon;
    if (strpos($icon, 'dashicons-') === 0) { return '<span class="dashicons ' . esc_attr($icon) . '"></span>'; }
    if (strpos($icon, 'data:image/svg+xml') === 0) { return self::mask_html($icon); }
    if (strpos($icon, 'data:image/') === 0 || preg_match('#^https?://#', $icon)) { return '<img src="' . esc_attr($icon) . '" alt="">'; }
    return '<span class="ame-ico-none"></span>';
  }

  // An SVG (plugin icon or your own upload) drawn in the current text color, like WordPress does in the menu.
  public static function mask_html($url) {
    return '<span class="ame-svgm" style="--m:url(\'' . esc_attr($url) . '\')"></span>';
  }
  private static function att_url($v) {
    if (strpos((string) $v, 'att:') !== 0) { return ''; }
    $u = wp_get_attachment_url((int) substr($v, 4));
    return ($u && strtolower(pathinfo((string) wp_parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'svg') ? $u : '';
  }
  // HTML for the icon a menu item gets: Lucide, your own SVG, or the item's own icon.
  public static function icon_html($slug, $own, $s = null, $stroke = 2) {
    $s = $s ? $s : self::icons();
    $n = self::pick($slug, $s);
    if ($n !== '' && isset($s['svg'][$n])) { return self::svg($s['svg'][$n], $stroke); }
    $u = self::att_url($n);
    if ($u) { return self::mask_html($u); }
    return self::own_icon($own);
  }

  // ---------- Output ----------
  private static function font_src($id) {
    if (!$id) { return ''; }
    $u = wp_get_attachment_url($id);
    if (!$u) { return ''; }
    $ext = strtolower(pathinfo((string) wp_parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($ext, ['woff2', 'woff'], true)) { return ''; }
    return 'url("' . esc_url($u) . '") format("' . $ext . '")';
  }
  private static function font_faces() {
    $f = self::fonts();
    if (!$f['on']) { return ''; }
    $reg = self::font_src($f['reg']);
    if ($reg === '') { return ''; }
    $bold = self::font_src($f['bold']);
    $o = '@font-face{font-family:"' . self::FAMILY . '";src:' . $reg . ';font-weight:' . ($bold ? '100 500' : '400') . ';font-style:normal;font-display:swap}';
    if ($bold) { $o .= '@font-face{font-family:"' . self::FAMILY . '";src:' . $bold . ';font-weight:600 900;font-style:normal;font-display:swap}'; }
    return $o;
  }
  private static function stack() {
    return '"' . self::FAMILY . '",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif';
  }
  // The top bar resets every element with #wpadminbar *, so its text elements are named one by one. Icon fonts are left alone.
  private static function bar_css() {
    $sel = '#wpadminbar,#wpadminbar a,#wpadminbar li,#wpadminbar input,#wpadminbar strong,#wpadminbar b,#wpadminbar em,#wpadminbar span:not(.ab-icon):not(.dashicons):not([class*="dashicons-"]),#wpadminbar div:not(.dashicons):not([class*="dashicons-"]),#wpadminbar #adminbarsearch .adminbar-input';
    return $sel . '{font-family:' . self::stack() . '}';
  }
  public static function admin_head() {
    $o = '';
    $faces = self::font_faces();
    if ($faces !== '') {
      $o .= $faces . 'body.wp-admin,body.wp-admin button,body.wp-admin input,body.wp-admin select,body.wp-admin textarea,body.wp-admin .button,body.wp-admin h1,body.wp-admin h2,body.wp-admin h3,body.wp-admin h4,body.wp-admin h5,body.wp-admin h6,#adminmenu,#adminmenu a,#adminmenu .wp-menu-name,.media-modal,.media-modal button,.media-modal input,.media-modal select,.media-modal textarea,.media-frame,.media-frame input,.media-frame select,.media-frame textarea,.media-frame button,.media-frame label,body.login{font-family:' . self::stack() . '}' . self::bar_css();
    }
    $o .= self::icons_css() . self::spacing_css();
    if ($o !== '') { echo '<style id="mk-ame-look">' . $o . '</style>'; }
  }
  public static function front_head() {
    if (!is_admin_bar_showing()) { return; }
    $f = self::fonts();
    if (!$f['bar']) { return; }
    $faces = self::font_faces();
    if ($faces !== '') { echo '<style id="mk-ame-look">' . $faces . self::bar_css() . '</style>'; }
  }
  private static function icons_css() {
    $s = self::icons();
    global $menu;
    $sz = $s['size'];
    $mt = round((34 - $sz) / 2, 1);
    $o = '';
    foreach ((array) $menu as $it) {
      if (empty($it[2]) || empty($it[5])) { continue; }
      $n = self::pick($it[2], $s);
      if ($n === '') { continue; }
      if (isset($s['svg'][$n])) { $mu = 'data:image/svg+xml,' . rawurlencode(self::svg($s['svg'][$n], $s['stroke'], '#000')); }
      else { $mu = self::att_url($n); if (!$mu) { continue; } $mu = esc_url($mu); }
      $id = preg_replace('|[^a-zA-Z0-9_:.]|', '-', $it[5]);
      $sel = '#adminmenu li[id="' . esc_attr($id) . '"] div.wp-menu-image';
      $mask = 'url("' . $mu . '") center/contain no-repeat';
      $o .= $sel . '{background-image:none!important}' . $sel . ' img{display:none!important}'
        . $sel . ':before{content:""!important;display:block!important;box-sizing:border-box;width:' . $sz . 'px!important;height:' . $sz . 'px!important;margin:' . $mt . 'px auto 0!important;padding:0!important;background:currentColor!important;-webkit-mask:' . $mask . ';mask:' . $mask . '}';
    }
    return $o;
  }

  // Top bar icons. Items that already have an icon get it replaced; an item without one gets the icon in front of its name.
  public static function bar_icons() {
    global $wp_admin_bar;
    $s = self::icons();
    if (!is_object($wp_admin_bar)) { return; }
    $front = !is_admin();
    $sz = $s['size'];
    $o = '';
    foreach ((array) $wp_admin_bar->get_nodes() as $id => $n) {
      if (!empty($n->group) || in_array($id, ['search', 'my-account', 'menu-toggle'], true)) { continue; }
      $v = self::pick_bar($id, $front, $s);
      if ($v === '') { continue; }
      if (isset($s['svg'][$v])) { $mu = 'data:image/svg+xml,' . rawurlencode(self::svg($s['svg'][$v], $s['stroke'], '#000')); }
      else { $mu = self::att_url($v); if (!$mu) { continue; } $mu = esc_url($mu); }
      $mask = 'url("' . $mu . '") center/contain no-repeat';
      $paint = 'content:""!important;display:block!important;width:' . $sz . 'px!important;height:' . $sz . 'px!important;padding:0!important;top:0!important;background:currentColor!important;-webkit-mask:' . $mask . ';mask:' . $mask;
      $b = '#wpadminbar li[id="wp-admin-bar-' . esc_attr($id) . '"] > .ab-item';
      if (strpos((string) $n->title, 'ab-icon') !== false) {
        $o .= $b . ' .ab-icon{width:' . $sz . 'px!important;height:20px!important;padding:6px 0!important;margin-right:6px!important}' . $b . ' .ab-icon:before{' . $paint . ';margin:' . round((20 - $sz) / 2, 1) . 'px 0 0!important}';
      } else {
        $o .= $b . ':before{' . $paint . ';float:left!important;margin:' . round((32 - $sz) / 2, 1) . 'px 6px 0 0!important}';
      }
    }
    if ($o !== '') { echo '<style id="mk-ame-bar-icons">@media screen and (min-width:783px){' . $o . '}</style>'; }
  }
  public static function bar_picker_cell($id, $front) {
    $s = self::icons();
    $cur = isset($s['bmap'][$id]) ? $s['bmap'][$id] : '';
    $ownc = $id === 'site-name' ? ($front ? 'dashicons-dashboard' : 'dashicons-admin-home') : (isset(self::OWN_BAR[$id]) ? self::OWN_BAR[$id] : '');
    $own = $ownc ? '<span class="dashicons ' . $ownc . '"></span>' : '<span class="ame-ico-none"></span>';
    $v = self::pick_bar($id, $front, $s);
    $lu = false;
    if ($v !== '' && isset($s['svg'][$v])) { $show = self::svg($s['svg'][$v], 2); $lu = true; }
    elseif ($v !== '' && self::att_url($v)) { $show = self::mask_html(self::att_url($v)); $lu = true; }
    else { $show = $own; }
    return self::cell('baricon[' . $id . ']', $cur, $s['bar_on'] ? self::bar_def($id, $front) : '', $own, $show, $lu);
  }
  private static function cell($name, $cur, $def, $own, $show, $lu) {
    return '<input type="hidden" class="ame-icon-v" name="' . esc_attr($name) . '" value="' . esc_attr($cur) . '"><button type="button" class="ame-ico' . ($lu ? ' is-lu' : '') . '" title="' . esc_attr(self::t('Choose icon')) . '" data-def="' . esc_attr($def) . '" data-own="' . esc_attr($own) . '">' . $show . '</button>';
  }

  // ---------- Fonts in the Media Library ----------
  public static function font_mimes($m) {
    if (current_user_can('manage_options')) { $m['woff2'] = 'font/woff2'; $m['woff'] = 'font/woff'; }
    return $m;
  }
  public static function font_check($data, $file, $filename, $mimes) {
    if (!current_user_can('manage_options')) { return $data; }
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($ext === 'woff2' || $ext === 'woff') {
      return ['ext' => $ext, 'type' => 'font/' . $ext, 'proper_filename' => isset($data['proper_filename']) ? $data['proper_filename'] : false];
    }
    return $data;
  }
  public static function assets($hook) {
    if ($hook === 'toplevel_page_' . MK_Admin_Menu::SLUG) { wp_enqueue_media(); }
  }

  // ---------- Save ----------
  public static function save() {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('You are not allowed to do this.'))); }
    check_admin_referer('mk_ame_save_look');
    $p = wp_unslash($_POST);
    $sec = isset($p['section']) ? sanitize_key($p['section']) : '';
    if ($sec === 'fonts') {
      $f = self::fonts();
      $f['on'] = !empty($p['on']) ? 1 : 0;
      $f['reg'] = isset($p['reg']) ? absint($p['reg']) : 0;
      $f['bold'] = isset($p['bold']) ? absint($p['bold']) : 0;
      $f['bar'] = !empty($p['bar']) ? 1 : 0;
      update_option(self::FONTS, $f);
    } elseif ($sec === 'spacing') {
      $sp = ['pl' => max(0, min(40, isset($p['pl']) ? (int) $p['pl'] : 0)), 'pr' => max(0, min(40, isset($p['pr']) ? (int) $p['pr'] : 0)), 'gap' => max(0, min(16, isset($p['gap']) ? (int) $p['gap'] : 0))];
      if ($sp['pl'] || $sp['pr'] || $sp['gap']) { update_option(self::SPACE, $sp); } else { delete_option(self::SPACE); }
    } elseif ($sec === 'icons') {
      $s = self::icons();
      $s['on'] = !empty($p['on']) ? 1 : 0;
      if (!empty($p['no_icons'])) { update_option('mk_ame_no_icons', ['off' => 1]); } else { delete_option('mk_ame_no_icons'); }
      self::store($s);
    } elseif ($sec === 'baricons') {
      $s = self::icons();
      $s['bar_on'] = !empty($p['bar_on']) ? 1 : 0;
      self::store($s);
    } else {
      wp_die(esc_html(self::t('Unknown section.')));
    }
    $back = isset($p['back']) ? sanitize_key($p['back']) : '';
    $to = in_array($back, ['left', 'top', 'topfront'], true) ? $back : $sec;
    wp_safe_redirect(add_query_arg(['page' => MK_Admin_Menu::SLUG, 'tab' => $to, 'saved' => 1], admin_url('admin.php')));
    exit;
  }

  // ---------- Tabs ----------
  private static function head($title, $lead) {
    return '<h2 class="amx-t">' . esc_html(self::t($title)) . '</h2><p class="amx-lead">' . esc_html(self::t($lead)) . '</p>';
  }
  private static function form_open($section) {
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-amx-reload="1"><input type="hidden" name="action" value="mk_ame_save_look"><input type="hidden" name="section" value="' . esc_attr($section) . '">' . wp_nonce_field('mk_ame_save_look', '_wpnonce', true, false);
  }
  private static function submit() {
    return '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(self::t('Save changes')) . '</button></p>';
  }
  private static function form_close() {
    return '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(self::t('Save changes')) . '</button></p></form>';
  }
  private static function toggle($name, $checked) {
    return '<label class="amx-tg"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked' : '') . '><i></i></label>';
  }
  private static function css() {
    return '<style>.amx-fpick{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.amx-fname{font-size:13px;color:#50575e}.amx-fname:empty:before{content:attr(data-empty);color:#8c8f94;font-style:italic}'
      . '.amx-fpv{border:1px solid #dcdcde;border-radius:8px;padding:22px 24px;max-width:560px;background:#fff}.amx-fpv h3{margin:0 0 6px;font-size:22px;font-weight:600}.amx-fpv p{margin:0 0 14px;font-size:14px;color:#3c434a}.amx-fpv .amx-frow{display:flex;gap:10px;align-items:center}.amx-fpv input{max-width:220px}'
      . '.amx-ipv{background:#1d2327;border-radius:8px;padding:6px 0;width:240px;color:#f0f0f1}.amx-ipv div{display:flex;align-items:center;gap:10px;height:34px;padding:0 12px;font-size:14px}.amx-ipv div.cur{background:var(--wp-admin-theme-color,#2271b1);color:#fff}'
      . '.amx-ipv .ic{display:flex;align-items:center;justify-content:center;width:20px;height:20px;flex:none;color:#a7aaad}.amx-ipv .cur .ic{color:#fff}.amx-ipv .ic svg{display:block;width:20px;height:20px}.amx-ipv .ic img{width:18px;height:18px;opacity:.7}.amx-ipv .ic .dashicons{font-size:20px;width:20px;height:20px}'
      . '.amx-ipv .ame-svgm{display:block;width:20px;height:20px;background:currentColor;-webkit-mask:var(--m) center/contain no-repeat;mask:var(--m) center/contain no-repeat}.amx-ipv.noic .ic{display:none!important}.amx-ipv .lu{display:none}.amx-ipv.on .lu{display:flex}.amx-ipv.on .ow.has{display:none}.amx-ipv .ame-ico-none{width:14px;height:14px;border-radius:50%;background:#a7aaad;opacity:.7}'
      . '.amx-tbp{display:flex;align-items:center;gap:2px;background:#1d2327;border-radius:6px;height:34px;padding:0 6px;color:#f0f0f1;font-size:13px;overflow:hidden;max-width:900px}.amx-tbp > span{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 9px;white-space:nowrap}.amx-tbp > span.r{margin-left:auto}.amx-tbp .ic{display:flex;align-items:center;justify-content:center;flex:none;color:#a7aaad}.amx-tbp .ic .dashicons{font-size:20px;width:20px;height:20px}.amx-tbp .ic svg,.amx-tbp .ic .ame-svgm{display:block;width:20px;height:20px}.amx-tbp .ame-svgm{background:currentColor;-webkit-mask:var(--m) center/contain no-repeat;mask:var(--m) center/contain no-repeat}.amx-tbp .lu{display:none}.amx-tbp.on .lu{display:flex}.amx-tbp.on .ow.has{display:none}'
      . '.amx-icol{display:grid;grid-template-columns:minmax(0,1fr) 240px;gap:28px;align-items:start}.amx-rng{display:flex;align-items:center;gap:12px}.amx-rng input{width:220px}.amx-rng output{min-width:40px;font-weight:600}'
      . '@media(max-width:900px){.amx-icol{grid-template-columns:1fr}}</style>';
  }

  public static function tab_fonts() {
    $f = self::fonts();
    $name = function ($id) { return $id ? basename((string) get_attached_file($id)) : ''; };
    $src = function ($id) { $u = $id ? wp_get_attachment_url($id) : ''; return $u ? $u : ''; };
    $h = self::css() . self::head('Fonts', 'Use your own font in the whole admin, including the top bar and form fields. The font is loaded from your own website, not from Google.');
    $h .= self::form_open('fonts');
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Turn on')) . '</label><div>' . self::toggle('on', $f['on']) . '</div></div>';
    foreach ([['reg', 'Regular', 'Required. A .woff2 file from your Media Library. You can upload it in the window that opens.'], ['bold', 'Bold', 'Optional. Used for headings and bold text. Without it the browser makes the regular font bold.']] as $r) {
      $k = $r[0];
      $h .= '<div class="amx-row"><label>' . esc_html(self::t($r[1])) . '</label><div><div class="amx-fpick" data-k="' . $k . '"><input type="hidden" name="' . $k . '" value="' . (int) $f[$k] . '" data-url="' . esc_attr($src($f[$k])) . '"><button type="button" class="button amx-fbtn">' . esc_html(self::t('Choose font file')) . '</button><span class="amx-fname" data-empty="' . esc_attr(self::t('No file chosen')) . '">' . esc_html($name($f[$k])) . '</span><button type="button" class="button-link button-link-delete amx-fclear"' . ($f[$k] ? '' : ' style="display:none"') . '>' . esc_html(self::t('Remove')) . '</button></div><div class="amx-hint">' . esc_html(self::t($r[2])) . '</div></div></div>';
    }
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Website')) . '</label><div><label><input type="checkbox" name="bar" value="1"' . ($f['bar'] ? ' checked' : '') . '> ' . esc_html(self::t('Also use this font in the top bar on your website')) . '</label></div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Preview')) . '</label><div><div class="amx-fpv" id="amx-fpv"><h3>' . esc_html(self::t('The quick brown fox')) . '</h3><p>' . esc_html(self::t('This is how text in your admin looks. Menus, buttons and form fields use the same font.')) . ' <strong>' . esc_html(self::t('Bold text looks like this.')) . '</strong></p><div class="amx-frow"><input type="text" value="' . esc_attr(self::t('A form field')) . '"><button type="button" class="button button-primary">' . esc_html(__('Save Changes')) . '</button></div></div></div></div>';
    $h .= self::form_close();
    $h .= '<script>(function($){if(!$)return;var pv=document.getElementById("amx-fpv");if(!pv)return;var n=0;'
      . 'function apply(){var r=$(".amx-fpick[data-k=reg] input").attr("data-url")||"",b=$(".amx-fpick[data-k=bold] input").attr("data-url")||"",on=$("input[name=on]").prop("checked");if(!on||!r||!window.FontFace){pv.style.fontFamily="";$(pv).find("h3,p,input,button,strong").css("font-family","");return;}n++;var fam="amx-pv-"+n,ls=[new FontFace(fam,"url("+JSON.stringify(r)+")",{weight:b?"100 500":"400"}).load()];if(b){ls.push(new FontFace(fam,"url("+JSON.stringify(b)+")",{weight:"600 900"}).load());}Promise.all(ls).then(function(fs){fs.forEach(function(f){document.fonts.add(f);});pv.style.fontFamily=fam;$(pv).find("h3,p,input,button,strong").css("font-family",fam);}).catch(function(){});}'
      . '$(".amx-fbtn").on("click",function(){var box=$(this).closest(".amx-fpick"),inp=box.find("input");if(!window.wp||!wp.media)return;var fr=wp.media({title:' . wp_json_encode(self::t('Choose font file')) . ',button:{text:' . wp_json_encode(self::t('Use this font')) . '},multiple:false});fr.on("select",function(){var a=fr.state().get("selection").first().toJSON();var fn=a.filename||(a.url||"").split("/").pop();if(!/\.woff2?$/i.test(fn)){alert(' . wp_json_encode(self::t('Please choose a .woff2 or .woff file.')) . ');return;}inp.val(a.id).attr("data-url",a.url);box.find(".amx-fname").text(fn);box.find(".amx-fclear").show();inp.trigger("change");apply();});fr.open();});'
      . '$(".amx-fclear").on("click",function(){var box=$(this).closest(".amx-fpick");box.find("input").val(0).attr("data-url","").trigger("change");box.find(".amx-fname").text("");$(this).hide();apply();});$("input[name=on]").on("change",apply);apply();})(window.jQuery);</script>';
    return $h;
  }

  // Top level items of the left menu for the previews: [slug, name, own icon].
  private static function menu_items() {
    global $menu;
    $out = [];
    $m = (array) $menu;
    ksort($m, SORT_NUMERIC);
    foreach ($m as $it) {
      if (empty($it[2]) || !isset($it[0]) || $it[0] === '' || strpos((string) $it[4], 'wp-menu-separator') !== false || strpos((string) $it[4], 'mk-ame-hidden') !== false) { continue; }
      $nm = trim(wp_strip_all_tags(preg_replace('/<span.*$/s', '', (string) $it[0])));
      if ($nm === '') { continue; }
      $out[] = [$it[2], $nm, isset($it[6]) ? $it[6] : ''];
    }
    return $out;
  }
  // The icon switches at the top of the menu editors. They save straight away.
  public static function quick_icons($where) {
    $s = self::icons();
    $sw = function ($name, $on, $label) { return '<label class="amx-qi">' . self::toggle($name, $on) . '<span>' . esc_html(self::t($label)) . '</span></label>'; };
    $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-amx-reload="1" class="amx-qf"><input type="hidden" name="action" value="mk_ame_save_look"><input type="hidden" name="back" value="' . esc_attr($where) . '">' . wp_nonce_field('mk_ame_save_look', '_wpnonce', false, false);
    if ($where === 'left') {
      $off = get_option('mk_ame_no_icons', []);
      $form .= '<input type="hidden" name="section" value="icons">' . $sw('on', $s['on'], 'Use Lucide icons') . $sw('no_icons', !empty($off['off']), "Don't show any icons at all");
    } else {
      $form .= '<input type="hidden" name="section" value="baricons">' . $sw('bar_on', $s['bar_on'], 'Use Lucide icons in the top bar');
    }
    $form .= '</form>';
    $css = '<style>.amx-qf{display:flex;flex-wrap:wrap;gap:12px 28px;align-items:center;margin:0 0 18px;max-width:1200px}.amx-qi{display:flex;gap:10px;align-items:center;font-size:13px;color:#1d2327;margin:0}</style>';
    $js = '<script>(function(){var f=document.querySelector(".amx-qf");if(!f)return;f.addEventListener("change",function(){if(f.requestSubmit){f.requestSubmit();}else{f.submit();}});})();</script>';
    return $css . $form . $js;
  }

  // Icons per place: 'left' for the left menu, 'bar' for the top bar.
  public static function tab_icons($area = 'left') {
    $s = self::ensure();
    $base = admin_url('admin.php?page=' . MK_Admin_Menu::SLUG);
    $sw = function ($name, $on, $label) { return '<label style="display:flex;gap:10px;align-items:center">' . self::toggle($name, $on) . '<span>' . esc_html(self::t($label)) . '</span></label>'; };
    if ($area === 'bar') {
      $u = wp_get_current_user();
      $h = self::css() . self::head('Top bar icons', 'Clean line icons from Lucide for the top bar, in the admin and on your website. WordPress items get a suggested icon. Plugins keep their own icon until you pick another one.');
      $h .= self::form_open('baricons');
      $h .= '<div class="amx-row"><label>' . esc_html(self::t('Lucide icons')) . '</label><div>' . $sw('bar_on', $s['bar_on'], 'Use Lucide icons in the top bar') . '</div></div>';
      $h .= '<div class="amx-row"><label>' . esc_html(self::t('Per item')) . '</label><div><p style="margin:6px 0 0">' . esc_html(self::t('Pick another icon for an item in the top bar editors: click the icon in front of the name.')) . ' <a href="' . esc_url($base . '&tab=top') . '">' . esc_html(self::t('Top bar in the admin')) . '</a>, <a href="' . esc_url($base . '&tab=topfront') . '">' . esc_html(self::t('Top bar on your website')) . '</a></p></div></div>';
      $h .= '<div class="amx-row"><label>' . esc_html(self::t('Preview')) . '</label><div><div class="amx-tbp' . ($s['bar_on'] ? ' on' : '') . '" id="amx-tbp">';
      $tb = [['site-name', 'dashicons-admin-home', get_bloginfo('name')], ['updates', 'dashicons-update', '3'], ['comments', 'dashicons-admin-comments', '2'], ['new-content', 'dashicons-plus', __('New')], ['view', 'dashicons-visibility', __('View Site')]];
      foreach ($tb as $t) {
        $v = self::pick_bar($t[0], false, $s);
        $lu = ($v !== '' && isset($s['svg'][$v])) ? self::svg($s['svg'][$v], $s['stroke']) : (($v !== '' && self::att_url($v)) ? self::mask_html(self::att_url($v)) : '');
        $h .= '<span>' . ($lu !== '' ? '<b class="ic lu">' . $lu . '</b>' : '') . '<b class="ic ow' . ($lu !== '' ? ' has' : '') . '"><span class="dashicons ' . $t[1] . '"></span></b>' . esc_html($t[2]) . '</span>';
      }
      $h .= '<span class="r">' . esc_html(sprintf(__('Howdy, %s'), ($u && $u->display_name) ? $u->display_name : 'Admin')) . '</span></div></div></div>';
      $h .= self::submit() . '</form>';
      $h .= '<script>(function(){var tb=document.getElementById("amx-tbp"),bo=document.querySelector("input[name=bar_on]");if(!tb||!bo)return;function run(){tb.classList.toggle("on",bo.checked);}bo.addEventListener("change",run);run();})();</script>';
      return $h;
    }
    $off = get_option('mk_ame_no_icons', []);
    $h = self::css() . self::head('Left menu icons', 'Clean line icons from Lucide for the left menu. WordPress items get a suggested icon. Plugins keep their own icon until you pick another one.');
    $h .= self::form_open('icons') . '<div class="amx-icol"><div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Lucide icons')) . '</label><div>' . $sw('on', $s['on'], 'Use Lucide icons in the left menu') . '</div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('No icons')) . '</label><div>' . $sw('no_icons', !empty($off['off']), "Don't show any icons at all") . '<div class="amx-hint">' . esc_html(self::t('Hides the icons next to the menu items. In the collapsed menu and on small screens they stay, otherwise you could not tell the items apart.')) . '</div></div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Per item')) . '</label><div><p style="margin:6px 0 0">' . esc_html(self::t('Pick another icon for an item in the left menu items: click the icon in front of the name.')) . ' <a href="' . esc_url($base . '&tab=left') . '">' . esc_html(self::t('Left menu items')) . '</a></p></div></div>';
    $h .= self::submit() . '</div><div><div class="amx-cap">' . esc_html(self::t('Preview')) . '</div><div class="amx-ipv' . ($s['on'] ? ' on' : '') . (!empty($off['off']) ? ' noic' : '') . '" id="amx-ipv">';
    $first = true;
    foreach (self::menu_items() as $it) {
      $n = self::pick($it[0], $s);
      $lu = ($n !== '' && (isset($s['svg'][$n]) || self::att_url($n))) ? self::icon_html($it[0], $it[2], $s, $s['stroke']) : '';
      $h .= '<div' . ($first ? ' class="cur"' : '') . '>' . ($lu !== '' ? '<span class="ic lu">' . $lu . '</span>' : '') . '<span class="ic ow' . ($lu !== '' ? ' has' : '') . '">' . self::own_icon($it[2]) . '</span>' . esc_html($it[1]) . '</div>';
      $first = false;
    }
    $h .= '</div></div></div></form>';
    $h .= '<script>(function(){var pv=document.getElementById("amx-ipv"),on=document.querySelector("input[name=on]"),ni=document.querySelector("input[name=no_icons]");if(!pv||!on||!ni)return;function run(){pv.classList.toggle("on",on.checked);pv.classList.toggle("noic",ni.checked);}on.addEventListener("change",run);ni.addEventListener("change",run);run();})();</script>';
    return $h;
  }


  // ---------- Spacing of the left menu ----------
  public static function spacing() {
    $o = get_option(self::SPACE, []);
    $o = is_array($o) ? $o : [];
    $old = isset($o['pad']) ? (int) $o['pad'] : 0;
    $c = function ($k, $max, $d) use ($o) { return max(0, min($max, isset($o[$k]) ? (int) $o[$k] : $d)); };
    return ['pl' => $c('pl', 40, $old), 'pr' => $c('pr', 40, $old), 'gap' => $c('gap', 16, 0)];
  }
  // Side padding makes the menu wider (only when it shows names). The gap is the space between the items.
  private static function spacing_css() {
    $sp = self::spacing();
    $o = '';
    if ($sp['pl'] || $sp['pr']) {
      $p = $sp['pl'];
      $r = $sp['pr'];
      $w = 160 + $p + $r;
      $nf = 'body:not(.folded) ';
      $o .= '@media screen and (min-width:961px){' . $nf . '#adminmenuback,' . $nf . '#adminmenuwrap,' . $nf . '#adminmenu{width:' . $w . 'px}' . $nf . '#wpcontent,' . $nf . '#wpfooter{margin-left:' . $w . 'px}'
        . $nf . '#adminmenu .wp-submenu{left:' . $w . 'px;width:' . $w . 'px}' . $nf . '#adminmenu li.wp-has-current-submenu .wp-submenu{left:auto}'
        . $nf . '#adminmenu a.menu-top{padding-left:' . $p . 'px;padding-right:' . $r . 'px}'
        . $nf . '#adminmenu .wp-has-current-submenu ul>li>a{padding-left:' . (12 + $p) . 'px;padding-right:' . (12 + $r) . 'px}'
        . $nf . '#adminmenu .wp-not-current-submenu li>a{padding-left:' . (14 + $p) . 'px;padding-right:' . (16 + $r) . 'px}}';
    }
    if ($sp['gap']) {
      $o .= '@media screen and (min-width:783px){#adminmenu li.menu-top{margin-bottom:' . $sp['gap'] . 'px}}';
    }
    return $o;
  }
  public static function tab_spacing() {
    $sp = self::spacing();
    $h = self::css() . self::head('Left menu spacing', 'Give the left menu more room. Padding makes the black bar wider, the gap adds space between the items. This only changes the left menu.');
    $h .= self::form_open('spacing') . '<div class="amx-icol" style="grid-template-columns:minmax(0,1fr) auto"><div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Padding left')) . '</label><div><div class="amx-rng"><input type="range" name="pl" min="0" max="40" step="1" value="' . (int) $sp['pl'] . '"><output>' . (int) $sp['pl'] . 'px</output></div><div class="amx-hint">' . esc_html(self::t('Extra space in front of the icons. The menu gets wider by the same amount.')) . '</div></div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Padding right')) . '</label><div><div class="amx-rng"><input type="range" name="pr" min="0" max="40" step="1" value="' . (int) $sp['pr'] . '"><output>' . (int) $sp['pr'] . 'px</output></div><div class="amx-hint">' . esc_html(self::t('Extra space after the names.')) . '</div></div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Gap')) . '</label><div><div class="amx-rng"><input type="range" name="gap" min="0" max="16" step="1" value="' . (int) $sp['gap'] . '"><output>' . (int) $sp['gap'] . 'px</output></div><div class="amx-hint">' . esc_html(self::t('Space between the menu items.')) . '</div></div></div>';
    $h .= '<div class="amx-row"><label></label><div class="amx-hint">' . esc_html(self::t('Set both to 0 for the WordPress default. In the collapsed menu and on small screens the padding is not used.')) . '</div></div>';
    $h .= self::submit() . '</div><div><div class="amx-cap">' . esc_html(self::t('Preview')) . '</div><div class="amx-spv" id="amx-spv">';
    $first = true;
    $n = 0;
    $ic = self::icons();
    foreach (self::menu_items() as $it) {
      if ($n++ >= 9) { break; }
      $icon = $ic['on'] ? self::icon_html($it[0], $it[2], $ic) : self::own_icon($it[2]);
      $h .= '<div class="it' . ($first ? ' cur' : '') . '"><span class="ic">' . $icon . '</span>' . esc_html($it[1]) . '</div>';
      if ($first) { $h .= '<div class="sub"><div class="c">' . esc_html(__('Home')) . '</div><div>' . esc_html(__('Updates')) . '</div></div>'; }
      $first = false;
    }
    $h .= '</div><div class="amx-hint" id="amx-spw"></div></div></div></form>';
    $h .= '<style>.amx-spv{--p:0px;--r:0px;--g:0px;background:#1d2327;border-radius:8px;padding:6px 0;color:#f0f0f1;width:calc(160px + var(--p) + var(--r));transition:width .15s;box-sizing:border-box}.amx-spv .it{display:flex;align-items:center;gap:10px;height:34px;padding:0 calc(8px + var(--r)) 0 calc(8px + var(--p));margin-bottom:var(--g);font-size:14px}.amx-spv .it.cur{background:var(--wp-admin-theme-color,#2271b1);color:#fff;margin-bottom:0}'
      . '.amx-spv .ic{display:flex;align-items:center;justify-content:center;width:20px;height:20px;flex:none;color:#a7aaad}.amx-spv .cur .ic{color:#fff}.amx-spv .ic svg{width:18px;height:18px}.amx-spv .ic img{width:18px;height:18px;opacity:.7}.amx-spv .ic .dashicons{font-size:20px;width:20px;height:20px}.amx-spv .ame-svgm{display:block;width:18px;height:18px;background:currentColor;-webkit-mask:var(--m) center/contain no-repeat;mask:var(--m) center/contain no-repeat}.amx-spv .ame-ico-none{width:14px;height:14px;border-radius:50%;background:#a7aaad;opacity:.7}'
      . '.amx-spv .sub{background:#2c3338;padding:7px 0 8px;margin-bottom:var(--g)}.amx-spv .sub div{padding:5px calc(12px + var(--r)) 5px calc(36px + var(--p));font-size:13px;color:#c3c4c7}.amx-spv .sub .c{color:#fff;font-weight:600}</style>';
    $h .= '<script>(function(){var pv=document.getElementById("amx-spv");if(!pv)return;var pd=document.querySelector("input[name=pl]"),pr=document.querySelector("input[name=pr]"),gp=document.querySelector("input[name=gap]"),w=document.getElementById("amx-spw"),WT=' . wp_json_encode(self::t('Menu width: %spx')) . ';'
      . 'function run(){pv.style.setProperty("--p",pd.value+"px");pv.style.setProperty("--r",pr.value+"px");pr.nextElementSibling.textContent=pr.value+"px";pv.style.setProperty("--g",gp.value+"px");pd.nextElementSibling.textContent=pd.value+"px";gp.nextElementSibling.textContent=gp.value+"px";w.textContent=WT.replace("%s",160+(+pd.value)+(+pr.value));}'
      . '[pd,pr,gp].forEach(function(e){e.addEventListener("input",run);});run();})();</script>';
    return $h;
  }

  // ---------- Icon picker in the Left Menu Editor ----------
  // The icon button in front of a top level item. Clicking it opens the picker.
  public static function picker_cell($slug, $own) {
    $s = self::icons();
    $cur = isset($s['map'][$slug]) ? $s['map'][$slug] : '';
    $show = self::icon_html($slug, $own, $s);
    $n = self::pick($slug, $s);
    $lu = $n !== '' && (isset($s['svg'][$n]) || self::att_url($n));
    $def = ($s['on'] && isset(self::DEF[$slug])) ? self::DEF[$slug] : '';
    return self::cell('icon[' . $slug . ']', $cur, $def, self::own_icon($own), $show, $lu);
  }
  private static function svg_ok() {
    $x = MK_AME_Extras::settings();
    $u = wp_get_current_user();
    return !empty($x['svg']['on']) && $u && (bool) array_intersect((array) $u->roles, (array) $x['svg']['roles']);
  }
  // CSS and JS for the picker. No tilde characters here: this goes through the ~ to quote swap of the editor page.
  public static function picker_assets() {
    $l = [
      'search' => self::t('Search icons'),
      'own' => self::t('Original icon'),
      'up' => self::t('Upload SVG'),
      'upt' => self::t('Choose an SVG icon'),
      'nosvg' => self::t('SVG uploads are off. Switch them on under Content > SVG uploads, or pick an SVG that is already in your Media Library.'),
      'notsvg' => self::t('Please choose an .svg file.'),
      'def' => self::t('Suggested icon'),
      'none' => self::t('No icons found.'),
      'load' => self::t('Loading icons...'),
    ];
    $css = '<style>.ame .ame-row > .ame-head{position:relative}.ame .ame-row > .ame-head > .ame-ico{order:1;flex:none}.ame-ico{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:1px solid transparent;border-radius:6px;background:#1d2327;color:#c3c4c7;cursor:pointer}.ame-ico:hover,.ame-ico:focus{border-color:var(--amx-main,#2271b1);color:#fff;outline:0}.ame-ico svg{width:18px;height:18px}.ame-ico img{width:18px;height:18px;opacity:.75}.ame-ico .dashicons{font-size:18px;width:18px;height:18px}.ame-ico-none{display:block;width:12px;height:12px;border-radius:50%;background:#8c8f94}.ame-svgm{display:block;width:18px;height:18px;background:currentColor;-webkit-mask:var(--m) center/contain no-repeat;mask:var(--m) center/contain no-repeat}'
      . '.ame-icp{position:absolute;z-index:60;top:calc(100% + 6px);left:40px;width:380px;max-width:calc(100vw - 80px);background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:12px}.ame-icp input[type=search]{width:100%;margin:0 0 8px}.ame-icp-acts{display:flex;gap:8px;margin:0 0 8px}.ame-icp-acts .button{font-size:12px;min-height:26px;line-height:24px}'
      . '.ame-icp-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:4px;max-height:260px;overflow-y:auto}.ame-icp-grid button{display:flex;align-items:center;justify-content:center;aspect-ratio:1;border:1px solid transparent;border-radius:6px;background:none;color:#1d2327;cursor:pointer;padding:0}.ame-icp-grid button:hover,.ame-icp-grid button:focus{background:var(--amx-tint,#f0f6fc);border-color:var(--amx-main,#2271b1);outline:0}.ame-icp-grid button.on{background:var(--amx-main,#2271b1);color:#fff}.ame-icp-grid svg{width:20px;height:20px}.ame-icp-msg{grid-column:1/-1;color:#646970;font-size:12px;padding:8px 2px}'
      . '.amx-pvs b.ic{display:flex;align-items:center;justify-content:center;width:18px;height:18px;flex:none;color:#a7aaad}.amx-pvs div.cur b.ic{color:#fff}.amx-pvs b.ic svg,.amx-pvs b.ic img{width:18px;height:18px}.amx-pvs b.ic .dashicons{font-size:18px;width:18px;height:18px}</style>';
    $js = '<script>(function($){if(!$)return;var URL=' . wp_json_encode(self::url('lucide.json') . '?v=' . (int) @filemtime(dirname(__DIR__) . '/assets/lucide.json')) . ',L=' . wp_json_encode($l) . ',SVGOK=' . (self::svg_ok() ? 'true' : 'false') . ',lib=window.amxLucide||null,req=null;'
      . 'function load(){if(lib){return $.Deferred().resolve(lib).promise();}if(!req){req=$.getJSON(URL).then(function(d){lib=window.amxLucide=d.i;return lib;});}return req;}'
      . 'function svg(i){return "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\">"+i+"</svg>";}'
      . 'function close(){$(".ame-icp").remove();}'
      . 'function mark(b){var f=b.closest("form");f.attr("data-amx-reload","1");b.closest("li").addClass("is-edit");var v=b.siblings(".ame-icon-v")[0];if(v){v.dispatchEvent(new Event("change",{bubbles:true}));}}'
      . 'function pick(b,val,html,lu){b.siblings(".ame-icon-v").val(val);b.html(html).toggleClass("is-lu",!!lu);close();mark(b);b.trigger("focus");}'
      . 'function open(b){close();var cur=b.siblings(".ame-icon-v").val(),def=b.attr("data-def")||"";var p=$("<div class=\"ame-icp\"></div>");var q=$("<input type=\"search\">").attr("placeholder",L.search);var acts=$("<div class=\"ame-icp-acts\"></div>");'
      . 'acts.append($("<button type=\"button\" class=\"button\"></button>").text(L.own).on("click",function(){pick(b,def?"-":"",b.attr("data-own"),false);}));'
      . 'if(def){acts.append($("<button type=\"button\" class=\"button\"></button>").text(L.def).on("click",function(){load().then(function(d){pick(b,"",d[def]?svg(d[def][0]):b.attr("data-own"),!!d[def]);});}));}'
      . 'acts.append($("<button type=\"button\" class=\"button\"></button>").text(L.up).on("click",function(){if(!window.wp||!wp.media){return;}if(!SVGOK){alert(L.nosvg);}var fr=wp.media({title:L.upt,library:{type:"image/svg+xml"},multiple:false});fr.on("select",function(){var a=fr.state().get("selection").first().toJSON();if(!/\.svg$/i.test(a.filename||a.url||"")){alert(L.notsvg);return;}var sp=$("<span class=\"ame-svgm\"></span>");sp[0].style.setProperty("--m","url(\""+a.url+"\")");pick(b,"att:"+a.id,sp[0].outerHTML,true);});close();fr.open();}));'
      . 'var g=$("<div class=\"ame-icp-grid\"></div>").append($("<div class=\"ame-icp-msg\"></div>").text(L.load));p.append(acts,q,g);b.closest(".ame-head").append(p);q.trigger("focus");'
      . 'load().then(function(d){var keys=Object.keys(d);function draw(){var s=$.trim(q.val()).toLowerCase(),h=[],n=0;for(var i=0;i<keys.length&&n<240;i++){var k=keys[i];if(s&&k.indexOf(s)<0&&(d[k][1]||"").toLowerCase().indexOf(s)<0)continue;n++;h.push("<button type=\"button\" data-n=\""+k+"\" title=\""+k+"\""+(k===cur?" class=\"on\"":"")+">"+svg(d[k][0])+"</button>");}g.html(n?h.join(""):"<div class=\"ame-icp-msg\">"+L.none+"</div>");}q.on("input",draw);draw();g.on("click","button",function(){var k=$(this).attr("data-n");pick(b,k,svg(d[k][0]),true);});});'
      . 'p.on("keydown",function(e){if(e.which===27){e.preventDefault();close();b.trigger("focus");}if(e.which===13){e.preventDefault();}});}'
      . '$(document).off("click.amxic").on("click.amxic",".ame-ico",function(e){e.preventDefault();var b=$(this);if(b.siblings(".ame-icp").length){close();return;}open(b);});'
      . '$(document).off("mousedown.amxic").on("mousedown.amxic",function(e){if(!$(e.target).closest(".ame-icp,.ame-ico").length){close();}});'
      . '$(document).off("change.amxic").on("change.amxic","input[name=no_icons]",function(){$(this).closest("form").attr("data-amx-reload","1");});'
      . '})(window.jQuery);</script>';
    return $css . $js;
  }
}

}
