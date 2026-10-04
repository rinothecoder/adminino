<?php
/**
 * Adminino: Colors and Logos tabs.
 *
 * Colors: one main color becomes a full admin color scheme. The scheme is generated from the
 * core "Modern" scheme (dark menu) by moving every shade of its highlight color to the new main color,
 * written once to uploads/admin-manager/ and forced for every user.
 * Logos: a light logo at the top of the left menu and a dark logo on the login page, each with a width.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_AME_Design', false)) {

final class MK_AME_Design {
  const COLORS = 'mk_ame_colors';
  const LOGOS = 'mk_ame_logos';
  const SCHEME = 'mk_custom';
  const DEF_MAIN = '#3858e9';
  const MENU_MIN = 40;
  const MENU_MAX = 140;
  const LOGIN_MIN = 60;
  const LOGIN_MAX = 320;

  public static function init() {
    add_action('admin_init', [__CLASS__, 'register_scheme'], 2);
    add_filter('get_user_option_admin_color', [__CLASS__, 'force_scheme'], 99);
    add_action('admin_head', [__CLASS__, 'admin_head']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'front_bar'], 20);
    add_action('adminmenu', [__CLASS__, 'menu_logo']);
    add_action('login_head', [__CLASS__, 'login_css'], 99);
    add_filter('login_headerurl', [__CLASS__, 'login_url']);
    add_filter('login_headertext', [__CLASS__, 'login_text']);
    add_action('admin_post_mk_ame_save_colors', [__CLASS__, 'save_colors']);
    add_action('admin_post_mk_ame_reset_colors', [__CLASS__, 'reset_colors']);
    add_action('admin_post_mk_ame_save_logos', [__CLASS__, 'save_logos']);
    add_action('admin_post_mk_ame_reset_logos', [__CLASS__, 'reset_logos']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
  }

  private static function t($s) { return MK_Admin_Menu::tr($s); }

  // ---------- Settings ----------
  public static function colors() {
    $c = get_option(self::COLORS, []);
    $c = is_array($c) ? $c : [];
    $main = isset($c['main']) ? sanitize_hex_color($c['main']) : '';
    return ['on' => !empty($c['on']) ? 1 : 0, 'main' => $main ? $main : self::DEF_MAIN];
  }
  public static function logos() {
    $l = get_option(self::LOGOS, []);
    $l = is_array($l) ? $l : [];
    $out = [];
    foreach (self::logo_defaults() as $k => $def) {
      $v = isset($l[$k]) ? $l[$k] : $def;
      if (is_array($def)) { $v = self::pad(is_array($v) ? $v : [], $def); } elseif (is_int($def)) { $v = (int) $v; } else { $v = self::align($v, $def); }
      $out[$k] = $v;
    }
    return $out;
  }
  private static function logo_defaults() {
    return [
      'menu_id' => 0, 'menu_w' => 120, 'menu_align' => 'center', 'menu_r' => 0, 'menu_pad' => ['t' => 10, 'r' => 10, 'b' => 14, 'l' => 10],
      'login_id' => 0, 'login_w' => 200, 'login_align' => 'center', 'login_r' => 0, 'login_pad' => ['t' => 10, 'r' => 0, 'b' => 25, 'l' => 0],
    ];
  }
  private static function align($v, $def = 'center') {
    return in_array($v, ['left', 'center', 'right'], true) ? $v : $def;
  }
  private static function pad($v, $def) {
    $o = [];
    foreach (['t', 'r', 'b', 'l'] as $k) { $o[$k] = (isset($v[$k]) && $v[$k] !== '') ? max(0, min(100, (int) $v[$k])) : $def[$k]; }
    return $o;
  }
  private static function pad_css($p) {
    return (int) $p['t'] . 'px ' . (int) $p['r'] . 'px ' . (int) $p['b'] . 'px ' . (int) $p['l'] . 'px';
  }

  // ---------- Color math ----------
  private static function parse($s) {
    $s = trim((string) $s);
    if ($s !== '' && $s[0] === '#') {
      $h = substr($s, 1);
      if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
      if (strlen($h) !== 6 || !ctype_xdigit($h)) { return null; }
      return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
    if (preg_match('/rgb\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*\)/', $s, $m)) { return [(float) $m[1], (float) $m[2], (float) $m[3]]; }
    return null;
  }
  private static function to_hsl($c) {
    $r = $c[0] / 255; $g = $c[1] / 255; $b = $c[2] / 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
    if ($max == $min) { return [0, 0, $l]; }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    if ($max == $r) { $h = ($g - $b) / $d + ($g < $b ? 6 : 0); } elseif ($max == $g) { $h = ($b - $r) / $d + 2; } else { $h = ($r - $g) / $d + 4; }
    return [$h * 60, $s, $l];
  }
  private static function from_hsl($h, $s, $l) {
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    if ($s == 0) { $v = $l * 255; return [$v, $v, $v]; }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function ($t) use ($p, $q) {
      if ($t < 0) { $t += 1; }
      if ($t > 1) { $t -= 1; }
      if ($t < 1 / 6) { return $p + ($q - $p) * 6 * $t; }
      if ($t < 1 / 2) { return $q; }
      if ($t < 2 / 3) { return $p + ($q - $p) * (2 / 3 - $t) * 6; }
      return $p;
    };
    return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
  }
  private static function hex($c) {
    $v = function ($x) { return max(0, min(255, (int) round($x))); };
    return sprintf('#%02x%02x%02x', $v($c[0]), $v($c[1]), $v($c[2]));
  }
  private static function clamp($v) { return max(0, min(1, $v)); }
  private static function lightness($hex, $dl) {
    $x = self::to_hsl(self::parse($hex));
    return self::hex(self::from_hsl($x[0], $x[1], self::clamp($x[2] + $dl)));
  }
  // A shade of the original highlight color, moved to the new main color with the same saturation and lightness offset.
  private static function shift($c, $from, $to, $min_sat = 0.3, $max_hue = 6) {
    $x = self::to_hsl($c);
    if ($x[1] < $min_sat) { return null; }
    $d = abs($x[0] - $from[0]);
    if (min($d, 360 - $d) > $max_hue) { return null; }
    return self::hex(self::from_hsl($to[0], self::clamp($to[1] + $x[1] - $from[1]), self::clamp($to[2] + $x[2] - $from[2])));
  }
  private static function rgb_list($hex) {
    $c = self::parse($hex);
    return (int) $c[0] . ',' . (int) $c[1] . ',' . (int) $c[2];
  }
  // Shades used by the previews and the login page.
  public static function palette($main) {
    $to = self::to_hsl(self::parse($main));
    $from = self::to_hsl(self::parse(self::orig()));
    $light = self::shift(self::parse('#7b90ff'), $from, $to);
    return ['main' => $main, 'dark' => self::lightness($main, -0.1), 'light' => $light ? $light : self::lightness($main, 0.17)];
  }

  // ---------- Color scheme file ----------
  private static function src_dir() { return ABSPATH . 'wp-admin/css/colors/modern/'; }
  private static function orig() {
    $f = self::src_dir() . 'colors.scss';
    if (is_readable($f) && preg_match('/\$highlight-color:\s*(#[0-9a-fA-F]{3,6})\s*;/', (string) file_get_contents($f), $m)) { return $m[1]; }
    return self::DEF_MAIN;
  }
  private static function build($src, $main) {
    $from = self::to_hsl(self::parse(self::orig()));
    $to = self::to_hsl(self::parse($main));
    $css = preg_replace_callback('/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|rgb\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*\)/', function ($m) use ($from, $to) {
      $c = self::parse($m[0]);
      if (!$c) { return $m[0]; }
      $n = self::shift($c, $from, $to);
      return $n ? $n : $m[0];
    }, $src);
    $d10 = self::lightness($main, -0.05);
    $d20 = self::lightness($main, -0.1);
    $vars = array_merge([
      '--wp-admin-theme-color:' . $main,
      '--wp-admin-theme-color--rgb:' . self::rgb_list($main),
      '--wp-admin-theme-color-darker-10:' . $d10,
      '--wp-admin-theme-color-darker-10--rgb:' . self::rgb_list($d10),
      '--wp-admin-theme-color-darker-20:' . $d20,
      '--wp-admin-theme-color-darker-20--rgb:' . self::rgb_list($d20),
    ], self::tokens($from, $to));
    // The block editor and the newer screens only know the core scheme names. For any other scheme they fall back
    // to the default blue and write it as inline styles on their root elements ([data-wpds-root-provider]).
    // !important is the only thing that beats those inline values.
    $css .= "\n/* Adminino: theme color and WordPress design tokens */\nbody.admin-color-" . self::SCHEME . ',[data-wpds-root-provider],[data-wpds-corner-radius]{' . implode(' !important;', $vars) . " !important}\n";
    return $css;
  }
  // WordPress 7 design tokens (--wpds-*): the newer admin screens (such as Settings > Connectors) use these
  // instead of the color scheme, and core defines them in one fixed blue. Move every brand token to the main color.
  private static function tokens($from, $to) {
    $f = ABSPATH . WPINC . '/css/dist/theme/design-tokens.css';
    if (!is_readable($f)) { return []; }
    $src = (string) file_get_contents($f);
    if (!preg_match_all('/(--wpds-color-[a-z-]*(?:brand|focus)[a-z-]*)\s*:\s*(#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})\s*;/', $src, $m, PREG_SET_ORDER)) { return []; }
    $out = [];
    foreach ($m as $x) {
      // Text on top of the brand color stays as it is (near white).
      if (isset($out[$x[1]]) || strpos($x[1], 'foreground-interactive-brand-strong') !== false) { continue; }
      $c = self::parse($x[2]);
      $n = $c ? self::shift($c, $from, $to, 0.05, 30) : null;
      if ($n) { $out[$x[1]] = $x[1] . ':' . $n; }
    }
    return array_values($out);
  }
  private static function upload_dir() {
    $up = wp_upload_dir(null, false);
    if (!empty($up['error'])) { return null; }
    return ['dir' => trailingslashit($up['basedir']) . 'admin-manager', 'url' => set_url_scheme(trailingslashit($up['baseurl']) . 'admin-manager')];
  }
  private static function key($main) {
    return substr(md5(strtolower($main) . '|' . get_bloginfo('version') . '|' . @filemtime(__FILE__)), 0, 10);
  }
  // Returns the URL of the generated scheme, writing it (and the RTL version) when missing.
  private static function scheme_url($main) {
    $u = self::upload_dir();
    if (!$u || !is_readable(self::src_dir() . 'colors.css')) { return ''; }
    $name = 'colors-' . self::key($main);
    $file = $u['dir'] . '/' . $name . '.css';
    if (!file_exists($file) || !file_exists($u['dir'] . '/' . $name . '-bar.css')) {
      if (!wp_mkdir_p($u['dir'])) { return ''; }
      foreach (['' => 'colors.css', '-rtl' => 'colors-rtl.css'] as $suffix => $src) {
        if (!is_readable(self::src_dir() . $src)) { continue; }
        $css = self::build(file_get_contents(self::src_dir() . $src), $main);
        if (file_put_contents($u['dir'] . '/' . $name . $suffix . '.css', $css) === false) { return ''; }
        if ($suffix === '') { file_put_contents($u['dir'] . '/' . $name . '-bar.css', self::bar_only($css) . '#wpadminbar{--wp-admin-theme-color:' . $main . ';--wp-admin-theme-color--rgb:' . self::rgb_list($main) . "}\n"); }
      }
      self::cleanup($name);
    }
    return $u['url'] . '/' . $name . '.css';
  }
  // Only the admin bar rules of a scheme, for the front end (where WordPress never loads color schemes).
  private static function bar_only($css) {
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $out = '';
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
      $open = strpos($css, '{', $i);
      if ($open === false) { break; }
      $sel = trim(substr($css, $i, $open - $i));
      $d = 0;
      for ($j = $open; $j < $len; $j++) {
        if ($css[$j] === '{') { $d++; } elseif ($css[$j] === '}') { $d--; if ($d === 0) { break; } }
      }
      $body = substr($css, $open + 1, $j - $open - 1);
      if (strpos($sel, '@') === 0) {
        $inner = self::bar_only($body);
        if (trim($inner) !== '') { $out .= $sel . '{' . $inner . "}\n"; }
      } elseif (strpos($body, '{') === false) {
        $parts = array_filter(array_map('trim', explode(',', $sel)), function ($x) { return strpos($x, '#wpadminbar') !== false; });
        if ($parts) { $out .= implode(',', $parts) . '{' . trim($body) . "}\n"; }
      }
      $i = $j + 1;
    }
    return $out;
  }
  public static function front_bar() {
    if (is_admin() || !is_admin_bar_showing()) { return; }
    $c = self::colors();
    if (!$c['on']) { return; }
    $url = self::scheme_url($c['main']);
    if ($url === '') { return; }
    wp_enqueue_style('mk-ame-bar', substr($url, 0, -4) . '-bar.css', ['admin-bar'], null);
  }
  private static function cleanup($keep = '') {
    $u = self::upload_dir();
    if (!$u) { return; }
    foreach ((array) glob($u['dir'] . '/colors-*.css') as $f) {
      if ($keep === '' || strpos(basename($f), $keep) !== 0) { @unlink($f); }
    }
  }

  public static function register_scheme() {
    $c = self::colors();
    if (!$c['on']) { return; }
    $url = self::scheme_url($c['main']);
    if ($url === '') { return; }
    global $_wp_admin_css_colors;
    $icons = isset($_wp_admin_css_colors['modern']) ? $_wp_admin_css_colors['modern']->icon_colors : ['base' => '#f3f1f1', 'focus' => '#fff', 'current' => '#fff'];
    $p = self::palette($c['main']);
    wp_admin_css_color(self::SCHEME, 'Adminino', $url, ['#1e1e1e', '#3c3c3c', $p['main'], $p['light']], $icons);
    remove_action('admin_color_scheme_picker', 'admin_color_scheme_picker');
    add_action('admin_color_scheme_picker', [__CLASS__, 'picker_note']);
  }
  public static function force_scheme($v) {
    $c = self::colors();
    return $c['on'] ? self::SCHEME : $v;
  }
  public static function picker_note() {
    echo '<p class="description">' . esc_html(self::t('Set by Adminino for all users.')) . '</p>';
  }

  // ---------- Logos ----------
  private static function img_id($id) {
    $id = absint($id);
    return ($id && get_post_type($id) === 'attachment' && strpos((string) get_post_mime_type($id), 'image/') === 0) ? $id : 0;
  }
  private static function src($id) {
    $u = $id ? wp_get_attachment_image_url($id, 'full') : '';
    return $u ? $u : '';
  }
  // Width and height of an image, including SVG files (read from the file itself).
  private static function dims($id) {
    $m = wp_get_attachment_metadata($id);
    if (!empty($m['width']) && !empty($m['height'])) { return [(float) $m['width'], (float) $m['height']]; }
    $f = get_attached_file($id);
    if ($f && is_readable($f) && preg_match('/\.svg$/i', $f)) {
      $x = (string) file_get_contents($f, false, null, 0, 4096);
      if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $x, $v) && (float) $v[1] > 0) { return [(float) $v[1], (float) $v[2]]; }
      if (preg_match('/\swidth\s*=\s*["\']([\d.]+)/i', $x, $w) && preg_match('/\sheight\s*=\s*["\']([\d.]+)/i', $x, $h) && (float) $w[1] > 0) { return [(float) $w[1], (float) $h[1]]; }
    }
    return null;
  }

  public static function admin_head() {
    $l = self::logos();
    if (!self::src($l['menu_id'])) { return; }
    echo '<style>#adminmenu #mk-ame-logo{margin:0;padding:' . self::pad_css($l['menu_pad']) . ';text-align:' . $l['menu_align'] . ';line-height:0;box-sizing:border-box}#adminmenu #mk-ame-logo img{display:inline-block;max-width:100%;height:auto;border-radius:' . (int) $l['menu_r'] . 'px}.folded #adminmenu #mk-ame-logo{display:none}@media screen and (max-width:960px){.auto-fold #adminmenu #mk-ame-logo{display:none}}@media screen and (max-width:782px){.auto-fold #adminmenu #mk-ame-logo{display:block}}</style>';
  }
  // Printed inside the left menu list, then moved to the top of it straight away.
  public static function menu_logo() {
    $l = self::logos();
    $src = self::src($l['menu_id']);
    if (!$src) { return; }
    echo '<li id="mk-ame-logo" aria-hidden="true"><img src="' . esc_url($src) . '" alt="" style="width:' . (int) $l['menu_w'] . 'px"></li>';
    echo '<script>(function(){var l=document.getElementById("mk-ame-logo"),u=l&&l.parentNode;if(u){u.insertBefore(l,u.firstChild);}})();</script>';
  }

  public static function login_css() {
    $css = '';
    $l = self::logos();
    $src = self::src($l['login_id']);
    if ($src) {
      $w = (int) $l['login_w'];
      $d = self::dims($l['login_id']);
      $h = $d ? (int) round($w * $d[1] / $d[0]) : (int) round($w / 3);
      $margin = ['left' => '0 auto 0 0', 'center' => '0 auto', 'right' => '0 0 0 auto'];
      $css .= '.login h1{padding:' . self::pad_css($l['login_pad']) . ';box-sizing:border-box}.login h1 a{background-image:url("' . esc_url($src) . '")!important;background-size:contain!important;background-position:center!important;background-repeat:no-repeat!important;width:' . $w . 'px!important;height:' . $h . 'px!important;max-width:100%;border-radius:' . (int) $l['login_r'] . 'px;margin:' . $margin[$l['login_align']] . '!important}';
    }
    $c = self::colors();
    if ($c['on']) {
      $p = self::palette($c['main']);
      $m = $p['main']; $d = $p['dark'];
      $d10 = self::lightness($m, -0.05);
      // The login page has no user, so no color scheme: set the theme color variables and the key elements directly.
      $css .= 'body.login{--wp-admin-theme-color:' . $m . ';--wp-admin-theme-color--rgb:' . self::rgb_list($m) . ';--wp-admin-theme-color-darker-10:' . $d10 . ';--wp-admin-theme-color-darker-10--rgb:' . self::rgb_list($d10) . ';--wp-admin-theme-color-darker-20:' . $d . ';--wp-admin-theme-color-darker-20--rgb:' . self::rgb_list($d) . '}';
      $css .= 'body.login.wp-core-ui .button-primary{background:' . $m . ';border-color:' . $m . ';color:#fff}body.login.wp-core-ui .button-primary:hover,body.login.wp-core-ui .button-primary:focus,body.login.wp-core-ui .button-primary:active{background:' . $d . ';border-color:' . $d . ';color:#fff}body.login.wp-core-ui .button-primary:focus{box-shadow:0 0 0 1px #fff,0 0 0 3px ' . $m . '}';
      $css .= 'body.login a{color:' . $m . '}body.login #nav a,body.login #backtoblog a,body.login .privacy-policy-link{color:#50575e}body.login #nav a:hover,body.login #backtoblog a:hover,body.login .privacy-policy-link:hover{color:' . $m . '}';
      $css .= 'body.login input[type=text]:focus,body.login input[type=password]:focus,body.login input[type=email]:focus,body.login input[type=checkbox]:focus{border-color:' . $m . ';box-shadow:0 0 0 1px ' . $m . ';outline:2px solid transparent}body.login .button.wp-hide-pw .dashicons{color:' . $m . '}body.login .message,body.login .notice-info,body.login .success{border-left-color:' . $m . '}';
      $css .= 'body.login input[type=checkbox]:checked{background:' . $m . ';border-color:' . $m . '}body.login input[type=checkbox]:checked::before{filter:brightness(0) invert(1)}';
    }
    if ($css !== '') { echo '<style id="mk-ame-login">' . $css . '</style>'; }
  }
  public static function login_url($url) {
    $l = self::logos();
    return self::src($l['login_id']) ? home_url('/') : $url;
  }
  public static function login_text($text) {
    $l = self::logos();
    return self::src($l['login_id']) ? get_bloginfo('name') : $text;
  }

  // ---------- Save ----------
  private static function guard($action) {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('Access denied.'))); }
    check_admin_referer($action);
  }
  private static function back($tab, $flag) {
    wp_safe_redirect(add_query_arg(['page' => MK_Admin_Menu::SLUG, 'tab' => $tab, $flag => 1], admin_url('admin.php')));
    exit;
  }
  public static function save_colors() {
    self::guard('mk_ame_save_colors');
    $main = isset($_POST['main']) ? sanitize_hex_color(wp_unslash($_POST['main'])) : '';
    $c = ['on' => !empty($_POST['on']) ? 1 : 0, 'main' => $main ? strtolower($main) : self::DEF_MAIN];
    update_option(self::COLORS, $c);
    if ($c['on']) { self::scheme_url($c['main']); } else { self::cleanup(); }
    self::back('colors', 'saved');
  }
  public static function reset_colors() {
    self::guard('mk_ame_reset_colors');
    delete_option(self::COLORS);
    self::cleanup();
    self::back('colors', 'reset');
  }
  public static function save_logos() {
    self::guard('mk_ame_save_logos');
    $n = function ($k, $def, $min, $max) { $v = isset($_POST[$k]) ? (int) $_POST[$k] : $def; return max($min, min($max, $v)); };
    $d = self::logo_defaults();
    $post = function ($k) { return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : null; };
    update_option(self::LOGOS, [
      'menu_id' => self::img_id(isset($_POST['menu_id']) ? $_POST['menu_id'] : 0),
      'menu_w' => $n('menu_w', 120, self::MENU_MIN, self::MENU_MAX),
      'menu_align' => self::align(sanitize_key((string) $post('menu_align'))),
      'menu_r' => $n('menu_r', 0, 0, 200),
      'menu_pad' => self::pad(is_array($post('menu_pad')) ? $post('menu_pad') : [], $d['menu_pad']),
      'login_id' => self::img_id(isset($_POST['login_id']) ? $_POST['login_id'] : 0),
      'login_w' => $n('login_w', 200, self::LOGIN_MIN, self::LOGIN_MAX),
      'login_align' => self::align(sanitize_key((string) $post('login_align'))),
      'login_r' => $n('login_r', 0, 0, 200),
      'login_pad' => self::pad(is_array($post('login_pad')) ? $post('login_pad') : [], $d['login_pad']),
    ]);
    self::back('logos', 'saved');
  }
  public static function reset_logos() {
    self::guard('mk_ame_reset_logos');
    delete_option(self::LOGOS);
    self::back('logos', 'reset');
  }

  // ---------- Settings page ----------
  public static function assets($hook) {
    if ($hook !== 'toplevel_page_' . MK_Admin_Menu::SLUG) { return; }
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    wp_enqueue_style('wp-color-picker'); wp_enqueue_script('wp-color-picker');
    if ($tab === 'logos') { wp_enqueue_media(); }
  }

  private static function forms_end($save, $reset) {
    $h = '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(self::t('Save changes')) . '</button></p></form>';
    $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;' . esc_attr(self::t('Reset everything to the defaults?')) . '&quot;)"><input type="hidden" name="action" value="' . $reset . '">' . wp_nonce_field($reset, '_wpnonce', true, false) . '<button type="submit" class="button-link button-link-delete">' . esc_html(self::t('Reset to defaults')) . '</button></form>';
    return $h;
  }
  private static function form_start($save) {
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . $save . '">' . wp_nonce_field($save, '_wpnonce', true, false);
  }

  // Logo slot in a preview: the image, or a dashed placeholder.
  private static function logo_slot($key, $src, $w, $align = 'center', $pad = null, $r = 0) {
    $st = 'text-align:' . self::align($align) . ($pad ? ';padding:' . self::pad_css($pad) : '');
    $in = $src ? '<img src="' . esc_url($src) . '" alt="" style="width:' . (int) $w . 'px;border-radius:' . (int) $r . 'px">' : '<span class="amd-ph" style="width:' . (int) $w . 'px;border-radius:' . (int) $r . 'px">' . esc_html(self::t('Your logo')) . '</span>';
    return '<div class="amd-logo-slot" data-logo="' . esc_attr($key) . '" style="' . esc_attr($st) . '">' . $in . '</div>';
  }
  // Placeholder admin screen: top bar, left menu (optionally with logo) and some content.
  private static function mock_admin($logo_html) {
    $items = [['dashboard', __('Dashboard'), 1], ['admin-post', __('Posts'), 0], ['admin-media', __('Media'), 0], ['admin-page', __('Pages'), 0], ['admin-comments', __('Comments'), 0], ['admin-appearance', __('Appearance'), 0], ['admin-plugins', __('Plugins'), 0], ['admin-users', __('Users'), 0], ['admin-settings', __('Settings'), 0]];
    $h = '<div class="amd-admin"><div class="amd-bar"><span class="dashicons dashicons-wordpress"></span><span class="amd-bar-line"></span></div><div class="amd-side">' . $logo_html;
    foreach ($items as $i) {
      $h .= '<div class="amd-mi' . ($i[2] ? ' is-cur' : '') . '"><span class="dashicons dashicons-' . $i[0] . '"></span>' . esc_html($i[1]) . '</div>';
      if ($i[2]) { $h .= '<div class="amd-sub"><div class="is-cur">' . esc_html(__('Home')) . '</div><div class="is-hover">' . esc_html(__('Updates')) . '</div></div>'; }
    }
    $h .= '</div><div class="amd-main"><div class="amd-line amd-w40 amd-dark"></div><div class="amd-card"><div class="amd-line amd-w80"></div><div class="amd-line amd-w60"></div><div class="amd-line amd-w70"></div><span class="amd-link">' . esc_html(__('Learn more')) . '</span></div><span class="amd-btn">' . esc_html(__('Save Changes')) . '</span></div></div>';
    return $h;
  }
  private static function mock_login($logo_html) {
    return '<div class="amd-login">' . $logo_html . '<div class="amd-login-box"><div class="amd-lbl">' . esc_html(__('Username or Email Address')) . '</div><div class="amd-input"></div><div class="amd-lbl">' . esc_html(__('Password')) . '</div><div class="amd-input is-focus"></div><div class="amd-login-row"><span class="amd-check"></span><span class="amd-lbl">' . esc_html(__('Remember Me')) . '</span><span class="amd-btn">' . esc_html(__('Log In')) . '</span></div></div><div class="amd-login-nav"><span class="amd-line amd-w40"></span></div></div>';
  }
  private static function vars($main) {
    $p = self::palette($main);
    return '--amd-main:' . $p['main'] . ';--amd-dark:' . $p['dark'] . ';--amd-light:' . $p['light'];
  }
  private static function css() {
    return '<style>.amd-intro{max-width:760px}.amd-prev{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin:8px 0 8px}.amd-prev-col h3{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#646970}.amd-admin{display:grid;grid-template-columns:160px 380px;grid-template-rows:32px auto;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;background:#f0f0f1;font-size:14px}.amd-bar{grid-column:1/3;background:#1e1e1e;display:flex;align-items:center;gap:10px;padding:0 10px}.amd-bar .dashicons{color:#f0f0f1}.amd-bar-line{height:6px;width:120px;border-radius:3px;background:#3c3c3c}.amd-side{background:#1e1e1e;color:#f0f0f1;padding:0 0 14px}.amd-side .amd-logo-slot{line-height:0;box-sizing:border-box}.amd-side .amd-logo-slot img{max-width:100%;height:auto}.amd-side .amd-ph{border-color:#8c8f94;color:#c3c4c7}.amd-mi{display:flex;align-items:center;gap:8px;padding:7px 10px;line-height:20px;white-space:nowrap;overflow:hidden}.amd-mi .dashicons{color:#a7aaad;font-size:20px;width:20px;height:20px}.amd-mi.is-cur{background:var(--amd-main);color:#fff}.amd-mi.is-cur .dashicons{color:#fff}.amd-sub{background:#0c0c0c;padding:6px 0}.amd-sub div{padding:5px 12px 5px 38px;font-size:13px;color:#bbb}.amd-sub .is-cur{color:#fff;font-weight:600}.amd-sub .is-hover{color:var(--amd-light)}.amd-main{padding:20px}.amd-line{display:block;height:8px;border-radius:4px;background:#dcdcde;margin:0 0 10px}.amd-dark{background:#a7aaad;height:12px;margin-bottom:16px}.amd-w40{width:40%}.amd-w60{width:60%}.amd-w70{width:70%}.amd-w80{width:80%}.amd-card{background:#fff;border:1px solid #dcdcde;padding:16px;margin:0 0 16px}.amd-link{color:var(--amd-main);text-decoration:underline;font-size:13px}.amd-btn{display:inline-block;background:var(--amd-main);color:#fff;border-radius:3px;padding:0 12px;line-height:30px;font-size:13px}.amd-login{width:360px;box-sizing:border-box;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;padding:36px 20px 24px;display:flex;flex-direction:column;align-items:center}.amd-login .amd-logo-slot{width:320px;box-sizing:border-box;margin:0;line-height:0}.amd-login .amd-logo-slot img{max-width:100%;height:auto}.amd-login-box{width:320px;box-sizing:border-box;background:#fff;border:1px solid #c3c4c7;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04)}.amd-lbl{font-size:13px;color:#1e1e1e;margin:0 0 4px}.amd-input{height:36px;border:1px solid #8c8f94;border-radius:4px;margin:0 0 14px;background:#fff}.amd-input.is-focus{border-color:var(--amd-main);box-shadow:0 0 0 1px var(--amd-main)}.amd-login-row{display:flex;align-items:center;gap:6px}.amd-login-row .amd-lbl{margin:0;flex:1}.amd-check{width:16px;height:16px;border:1px solid #8c8f94;border-radius:4px}.amd-login-nav{width:320px;padding:16px 24px 0;box-sizing:border-box}.amd-ph{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:40px;max-width:100%;border:2px dashed #8c8f94;border-radius:4px;color:#646970;font-size:12px;line-height:1.2}.amd-note{color:#646970;font-style:italic;margin:4px 0 0}.amd-cards{display:flex;flex-wrap:wrap;gap:20px;margin:0 0 8px}.amd-card-set{flex:1 1 380px;max-width:540px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px}.amd-card-set h3{margin:0 0 8px}.amd-pick{display:flex;align-items:center;gap:12px;margin:16px 0 0}.amd-thumb{width:72px;height:48px;display:flex;align-items:center;justify-content:center;border:1px solid #dcdcde;border-radius:4px;overflow:hidden}.amd-thumb img{max-width:64px;max-height:40px}.amd-thumb.is-dark{background:#1e1e1e}.amd-thumb.is-light{background:#f0f0f1}.amd-grid{display:grid;grid-template-columns:160px minmax(0,1fr);gap:22px 16px;align-items:start;margin:20px 0 4px;padding-top:20px;border-top:1px solid #f0f0f1}.amd-flabel{font-weight:600;color:#1d2327;line-height:30px;white-space:nowrap}.amd-ctrl{min-width:0}.amd-ctrl .description{margin:6px 0 0}.amd-range{display:flex;align-items:center;gap:12px;min-height:30px}.amd-range input{flex:1;max-width:280px;margin:0}.amd-range output{min-width:44px;font-variant-numeric:tabular-nums;color:#50575e}.amd-seg{display:inline-flex;border:1px solid #8c8f94;border-radius:4px;overflow:hidden}.amd-seg label{position:relative;cursor:pointer;border-left:1px solid #dcdcde}.amd-seg label:first-child{border-left:0}.amd-seg input{position:absolute;opacity:0;width:1px;height:1px}.amd-seg .dashicons{display:block;padding:5px 10px;width:20px;height:20px;color:#50575e}.amd-seg input:checked+.dashicons{background:var(--wp-admin-theme-color,#2271b1);color:#fff}.amd-seg input:focus-visible+.dashicons{box-shadow:inset 0 0 0 2px #1e1e1e}.amd-pads{display:flex;flex-wrap:wrap;gap:10px}.amd-pads label{display:flex;flex-direction:column;align-items:center;gap:4px}.amd-pads input{width:58px;min-height:30px;padding:0 6px;text-align:center}.amd-pads span{font-size:11px;color:#646970}@media(max-width:600px){.amd-grid{grid-template-columns:1fr;gap:8px}.amd-flabel{line-height:1.4;margin-top:10px}}.amd-range input{flex:1;max-width:260px}.amd-range output{min-width:48px;font-variant-numeric:tabular-nums}@media(max-width:782px){.amd-admin{grid-template-columns:160px 1fr}.amd-login{width:100%}}</style>';
  }

  public static function tab($tab) {
    return self::css() . ($tab === 'logos' ? self::tab_logos() : self::tab_colors());
  }

  private static function tab_colors() {
    $c = self::colors();
    $l = self::logos();
    $h = '<h2 class="amx-t">' . esc_html(self::t('Colors')) . '</h2><p class="amd-intro">' . esc_html(self::t('Pick one main color. Adminino works out all the other shades (buttons, links, hover and focus states) and uses the scheme for every user. The left menu and the top bar stay dark.')) . '</p>';
    $h .= self::form_start('mk_ame_save_colors') . '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html(self::t('Custom colors')) . '</th><td><label><input type="checkbox" name="on" value="1"' . checked($c['on'], 1, false) . '> ' . esc_html(self::t('Use this color scheme for all users')) . '</label><p class="description">' . esc_html(self::t('While this is on, users cannot pick their own color scheme on their profile page.')) . '</p></td></tr>';
    $h .= '<tr><th scope="row"><label for="amd-main">' . esc_html(self::t('Main color')) . '</label></th><td><input type="text" id="amd-main" name="main" class="amd-color" value="' . esc_attr($c['main']) . '" data-default-color="' . esc_attr(self::DEF_MAIN) . '"><p class="description">' . esc_html(self::t('The login page uses the main color too, for the login button, links and input focus.')) . '</p></td></tr></table>';
    $menu = self::src($l['menu_id']) ? self::logo_slot('menu', self::src($l['menu_id']), $l['menu_w'], $l['menu_align'], $l['menu_pad'], $l['menu_r']) : '';
    $login = self::src($l['login_id']) ? self::logo_slot('login', self::src($l['login_id']), $l['login_w'], $l['login_align'], $l['login_pad'], $l['login_r']) : '';
    $h .= '<h3 class="amx-h3">' . esc_html(self::t('Preview')) . '</h3><div class="amd-prev" style="' . esc_attr(self::vars($c['main'])) . '"><div class="amd-prev-col"><h3>' . esc_html(self::t('Admin')) . '</h3>' . self::mock_admin($menu) . '</div><div class="amd-prev-col"><h3>' . esc_html(self::t('Login page')) . '</h3>' . self::mock_login($login) . '</div></div><p class="amd-note">' . esc_html(self::t('Placeholder content, only to show the colors.')) . '</p>';
    $h .= self::forms_end('mk_ame_save_colors', 'mk_ame_reset_colors');
    $h .= '<script>jQuery(function($){var O=' . wp_json_encode(self::orig()) . ',T="#7b90ff";' . self::js_color() . 'function paint(m){if(!/^#[0-9a-f]{6}$/i.test(m)){return;}var p=$(".amd-prev")[0],t=hsl(m),o=hsl(O),x=hsl(T);p.style.setProperty("--amd-main",m);p.style.setProperty("--amd-dark",hex(t[0],t[1],cl(t[2]-.1)));p.style.setProperty("--amd-light",hex(t[0],cl(t[1]+x[1]-o[1]),cl(t[2]+x[2]-o[2])));}$(".amd-color").wpColorPicker({change:function(e,ui){paint(ui.color.toString());},clear:function(){paint(' . wp_json_encode(self::DEF_MAIN) . ');}});});</script>';
    return $h;
  }
  private static function js_color() {
    return 'function cl(v){return Math.max(0,Math.min(1,v));}function hsl(c){var r=parseInt(c.substr(1,2),16)/255,g=parseInt(c.substr(3,2),16)/255,b=parseInt(c.substr(5,2),16)/255,mx=Math.max(r,g,b),mn=Math.min(r,g,b),l=(mx+mn)/2,h=0,s=0,d=mx-mn;if(d){s=l>.5?d/(2-mx-mn):d/(mx+mn);h=mx===r?(g-b)/d+(g<b?6:0):mx===g?(b-r)/d+2:(r-g)/d+4;}return[h*60,s,l];}function hex(h,s,l){h=((h%360)+360)%360/360;function f(t){var q=l<.5?l*(1+s):l+s-l*s,p=2*l-q;if(t<0)t+=1;if(t>1)t-=1;if(t<1/6)return p+(q-p)*6*t;if(t<.5)return q;if(t<2/3)return p+(q-p)*(2/3-t)*6;return p;}var v=s?[f(h+1/3),f(h),f(h-1/3)]:[l,l,l];return"#"+v.map(function(x){return("0"+Math.round(x*255).toString(16)).slice(-2);}).join("");}';
  }

  private static function logo_card($key, $title, $desc, $hint, $id, $w, $min, $max, $dark) {
    $l = self::logos();
    $src = self::src($id);
    $h = '<div class="amd-card-set" data-key="' . $key . '"><h3>' . esc_html($title) . '</h3><p class="description">' . MK_Admin_Menu::tr_html($desc) . '</p>';
    $h .= '<div class="amd-pick" data-key="' . $key . '"><span class="amd-thumb ' . ($dark ? 'is-dark' : 'is-light') . '">' . ($src ? '<img src="' . esc_url($src) . '" alt="">' : '') . '</span><input type="hidden" name="' . $key . '_id" value="' . ($src ? (int) $id : '') . '"><button type="button" class="button amd-choose">' . esc_html(self::t('Choose logo')) . '</button><button type="button" class="button-link button-link-delete amd-remove"' . ($src ? '' : ' style="display:none"') . '>' . esc_html(self::t('Remove')) . '</button></div>';
    $h .= '<div class="amd-grid">';
    // Width
    $h .= '<label class="amd-flabel" for="amd-' . $key . '-w">' . esc_html(self::t('Width')) . '</label><div class="amd-ctrl"><div class="amd-range"><input type="range" id="amd-' . $key . '-w" name="' . $key . '_w" data-key="' . $key . '" min="' . $min . '" max="' . $max . '" step="1" value="' . (int) $w . '"><output>' . (int) $w . 'px</output></div></div>';
    // Alignment
    $h .= '<span class="amd-flabel">' . esc_html(self::t('Alignment')) . '</span><div class="amd-ctrl"><span class="amd-seg" role="radiogroup" aria-label="' . esc_attr(self::t('Alignment')) . '">';
    foreach (['left' => 'Left', 'center' => 'Center', 'right' => 'Right'] as $v => $label) {
      $h .= '<label title="' . esc_attr(self::t($label)) . '"><input type="radio" name="' . $key . '_align" value="' . $v . '"' . checked($l[$key . '_align'], $v, false) . '><span class="dashicons dashicons-editor-align' . $v . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html(self::t($label)) . '</span></label>';
    }
    $h .= '</span></div>';
    // Spacing
    $h .= '<span class="amd-flabel">' . esc_html(self::t('Spacing (padding)')) . '</span><div class="amd-ctrl"><div class="amd-pads">';
    foreach (['t' => 'Top', 'r' => 'Right', 'b' => 'Bottom', 'l' => 'Left'] as $side => $label) {
      $h .= '<label><input type="number" class="amd-pad" data-side="' . $side . '" name="' . $key . '_pad[' . $side . ']" min="0" max="100" step="1" value="' . (int) $l[$key . '_pad'][$side] . '"><span>' . esc_html(self::t($label)) . '</span></label>';
    }
    $h .= '</div></div>';
    // Rounded corners
    $h .= '<label class="amd-flabel" for="amd-' . $key . '-r">' . esc_html(self::t('Rounded corners')) . '</label><div class="amd-ctrl"><div class="amd-pads"><label><input type="number" id="amd-' . $key . '-r" class="amd-rad" data-key="' . $key . '" name="' . $key . '_r" min="0" max="200" step="1" value="' . (int) $l[$key . '_r'] . '"><span>px</span></label></div></div>';
    $h .= '</div></div>';
    return $h;
  }

  private static function tab_logos() {
    $l = self::logos();
    $c = self::colors();
    $h = '<h2 class="amx-t">' . esc_html(self::t('Logos')) . '</h2><p class="amd-intro">' . esc_html(self::t('Add your logo to the top of the left menu and to the login page. Use the width sliders to get the size right, the preview below updates straight away.')) . '</p>';
    $h .= self::form_start('mk_ame_save_logos') . '<div class="amd-cards">';
    $h .= self::logo_card('menu', self::t('Left menu logo'), 'Shown at the top of the left menu, above Dashboard. The menu has a dark background, so use a <strong>white version</strong> of your logo. A PNG with a transparent background or an SVG works best.', self::t('The menu is 160px wide. Between 100 and 130px usually looks best. The logo is hidden when the menu is collapsed.'), $l['menu_id'], $l['menu_w'], self::MENU_MIN, self::MENU_MAX, true);
    $h .= self::logo_card('login', self::t('Login page logo'), 'Replaces the WordPress logo above the login form. The login page has a light background, so use a <strong>dark version</strong> of your logo. A PNG with a transparent background or an SVG works best.', self::t('The login form is 320px wide. Between 150 and 250px usually looks best.'), $l['login_id'], $l['login_w'], self::LOGIN_MIN, self::LOGIN_MAX, false);
    $h .= '</div>';
    $main = $c['on'] ? $c['main'] : self::DEF_MAIN;
    $h .= '<h3 class="amx-h3">' . esc_html(self::t('Preview')) . '</h3><div class="amd-prev" style="' . esc_attr(self::vars($main)) . '"><div class="amd-prev-col"><h3>' . esc_html(self::t('Left menu')) . '</h3>' . self::mock_admin(self::logo_slot('menu', self::src($l['menu_id']), $l['menu_w'], $l['menu_align'], $l['menu_pad'], $l['menu_r'])) . '</div><div class="amd-prev-col"><h3>' . esc_html(self::t('Login page')) . '</h3>' . self::mock_login(self::logo_slot('login', self::src($l['login_id']), $l['login_w'], $l['login_align'], $l['login_pad'], $l['login_r'])) . '</div></div><p class="amd-note">' . esc_html(self::t('Placeholder content. Only the logos and their sizes are real.')) . '</p>';
    $h .= self::forms_end('mk_ame_save_logos', 'mk_ame_reset_logos');
    $txt = ['title' => self::t('Choose a logo'), 'use' => self::t('Use this logo'), 'ph' => self::t('Your logo')];
    $h .= '<script>jQuery(function($){var T=' . wp_json_encode($txt) . ';function size(k){var w=$("input[name="+k+"_w]").val();$("input[name="+k+"_w]").siblings("output").text(w+"px");$("[data-logo="+k+"] img,[data-logo="+k+"] .amd-ph").css("width",w+"px");var r=parseInt($("input[name="+k+"_r]").val(),10);r=isNaN(r)?0:Math.max(0,Math.min(200,r));$("[data-logo="+k+"] img,[data-logo="+k+"] .amd-ph").css("border-radius",r+"px");}function set(k,url){$("[data-logo="+k+"]").each(function(){$(this).empty().append(url?$("<img>").attr({src:url,alt:""}):$("<span>").addClass("amd-ph").text(T.ph));});var b=$(".amd-pick[data-key="+k+"]");b.find(".amd-thumb").empty().append(url?$("<img>").attr({src:url,alt:""}):"");b.find(".amd-remove").toggle(!!url);size(k);}$(".amd-pick").each(function(){var b=$(this),k=b.data("key"),fr;b.find(".amd-choose").on("click",function(e){e.preventDefault();if(!fr){fr=wp.media({title:T.title,button:{text:T.use},library:{type:"image"},multiple:false});fr.on("select",function(){var a=fr.state().get("selection").first().toJSON();b.find("input[type=hidden]").val(a.id);set(k,a.url);});}fr.open();});b.find(".amd-remove").on("click",function(e){e.preventDefault();b.find("input[type=hidden]").val("");set(k,"");});});$(".amd-range input,.amd-rad").on("input change",function(){size($(this).data("key"));});function lay(k){var c=$(".amd-card-set[data-key="+k+"]"),a=c.find(".amd-seg input:checked").val()||"center",p=c.find(".amd-pad").map(function(){var v=parseInt(this.value,10);return(isNaN(v)?0:Math.max(0,Math.min(100,v)))+"px";}).get().join(" ");$("[data-logo="+k+"]").css({textAlign:a,padding:p});}$(".amd-seg input,.amd-pad").on("input change",function(){lay($(this).closest(".amd-card-set").data("key"));});});</script>';
    return $h;
  }
}

}
