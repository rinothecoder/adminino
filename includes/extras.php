<?php
/**
 * Admin Manager: side panel and extra features.
 *
 * Side panel navigation, Overview, Admin footer, Dashboard clean-up, Notices bundle, Duplicate,
 * SVG uploads and settings export / import.
 * Everything is off by default and nothing runs until it is switched on.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_AME_Extras', false)) {

final class MK_AME_Extras {
  const OPT = 'mk_ame_extras';
  const SEEN = 'mk_ame_dash_seen';
  const TABS = ['overview', 'footer', 'dash', 'notices', 'dup', 'media', 'io'];

  public static function init() {
    add_action('admin_post_mk_ame_save_extras', [__CLASS__, 'save']);
    add_action('admin_post_mk_ame_export', [__CLASS__, 'export']);
    add_action('admin_post_mk_ame_import', [__CLASS__, 'import']);
    add_action('admin_action_mk_ame_dup', [__CLASS__, 'dup']);

    $s = self::settings();

    if ($s['footer']['on']) {
      add_filter('admin_footer_text', [__CLASS__, 'footer_left'], 99);
      if (!$s['footer']['ver']) { add_filter('update_footer', '__return_empty_string', 99); }
    }
    if ($s['dash']['on']) {
      add_action('wp_dashboard_setup', [__CLASS__, 'dash_seen'], 998);
      add_action('wp_dashboard_setup', [__CLASS__, 'dash_hide'], 999);
      if (in_array('welcome', $s['dash']['hide'], true)) { add_action('admin_head-index.php', [__CLASS__, 'welcome_css']); }
    }
    if ($s['notices']['on']) {
      add_filter('admin_body_class', [__CLASS__, 'body_class']);
      add_action('admin_head', [__CLASS__, 'notices_css']);
      add_action('admin_footer', [__CLASS__, 'notices_js']);
    }
    if ($s['dup']['on'] && $s['dup']['types']) {
      add_filter('post_row_actions', [__CLASS__, 'row_action'], 10, 2);
      add_filter('page_row_actions', [__CLASS__, 'row_action'], 10, 2);
    }
    if ($s['svg']['on']) {
      add_filter('upload_mimes', [__CLASS__, 'svg_mimes']);
      add_filter('wp_check_filetype_and_ext', [__CLASS__, 'svg_check'], 10, 5);
      add_filter('wp_handle_upload_prefilter', [__CLASS__, 'svg_scan']);
    }
  }

  private static function t($s) { return MK_Admin_Menu::tr($s); }

  // ---------- Settings ----------
  public static function settings() {
    $o = get_option(self::OPT, []);
    $o = is_array($o) ? $o : [];
    $g = function ($k) use ($o) { return (isset($o[$k]) && is_array($o[$k])) ? $o[$k] : []; };
    $f = $g('footer'); $d = $g('dash'); $n = $g('notices'); $u = $g('dup'); $v = $g('svg');
    return [
      'footer' => ['on' => !empty($f['on']) ? 1 : 0, 'text' => isset($f['text']) ? (string) $f['text'] : '', 'ver' => isset($f['ver']) ? (!empty($f['ver']) ? 1 : 0) : 1],
      'dash' => ['on' => !empty($d['on']) ? 1 : 0, 'hide' => (isset($d['hide']) && is_array($d['hide'])) ? array_values(array_map('sanitize_key', $d['hide'])) : []],
      'notices' => ['on' => !empty($n['on']) ? 1 : 0],
      'dup' => ['on' => !empty($u['on']) ? 1 : 0, 'types' => (isset($u['types']) && is_array($u['types'])) ? array_values(array_map('sanitize_key', $u['types'])) : []],
      'svg' => ['on' => !empty($v['on']) ? 1 : 0, 'roles' => (isset($v['roles']) && is_array($v['roles'])) ? array_values(array_map('sanitize_key', $v['roles'])) : []],
    ];
  }

  // ---------- Features ----------
  public static function footer_left($text) {
    $s = self::settings();
    return wp_kses_post($s['footer']['text']);
  }

  private static function core_widgets() {
    return [
      'dashboard_right_now' => 'At a Glance',
      'dashboard_activity' => 'Activity',
      'dashboard_quick_press' => 'Quick Draft',
      'dashboard_primary' => 'WordPress Events and News',
      'dashboard_site_health' => 'Site Health Status',
    ];
  }
  public static function dash_seen() {
    global $wp_meta_boxes;
    if (empty($wp_meta_boxes['dashboard']) || !is_array($wp_meta_boxes['dashboard'])) { return; }
    $seen = get_option(self::SEEN, []);
    $seen = is_array($seen) ? $seen : [];
    $new = $seen;
    foreach ($wp_meta_boxes['dashboard'] as $ctx) {
      foreach ((array) $ctx as $prio) {
        foreach ((array) $prio as $id => $box) {
          if (is_array($box) && !empty($box['title'])) { $new[sanitize_key($id)] = trim(wp_strip_all_tags((string) $box['title'])); }
        }
      }
    }
    if ($new !== $seen) { update_option(self::SEEN, $new, false); }
  }
  public static function dash_hide() {
    $s = self::settings();
    foreach ($s['dash']['hide'] as $id) {
      if ($id === 'welcome') { continue; }
      foreach (['normal', 'side', 'column3', 'column4'] as $ctx) { remove_meta_box($id, 'dashboard', $ctx); }
    }
  }
  public static function welcome_css() {
    echo '<style>#welcome-panel{display:none!important}</style>';
  }

  public static function body_class($c) { return $c . ' mk-nb'; }
  private static function on_own_page() {
    $sc = function_exists('get_current_screen') ? get_current_screen() : null;
    return $sc && $sc->id === 'toplevel_page_' . MK_Admin_Menu::SLUG;
  }
  public static function notices_css() {
    if (self::on_own_page()) { return; }
    echo '<style>.mk-nb #wpbody-content .notice:not(.inline),.mk-nb #wpbody-content div.updated:not(.inline),.mk-nb #wpbody-content div.error:not(.inline),.mk-nb #wpbody-content .update-nag{display:none}'
      . '.mk-nb #mk-nb-list .notice,.mk-nb #mk-nb-list div.updated,.mk-nb #mk-nb-list div.error,.mk-nb #mk-nb-list .update-nag{display:block!important}'
      . '#mk-nb{margin:14px 20px 0 2px;border:1px solid #c3c4c7;border-left:4px solid #dba617;background:#fff;border-radius:2px}'
      . '#mk-nb-t{display:block;width:100%;text-align:left;padding:8px 12px;cursor:pointer;background:none;border:0;font-size:13px;color:#1d2327}'
      . '#mk-nb-t:after{content:"\\25BE";float:right}#mk-nb.open #mk-nb-t:after{content:"\\25B4"}'
      . '#mk-nb-list{display:none;padding:0 12px 6px}#mk-nb.open #mk-nb-list{display:block}</style>';
  }
  public static function notices_js() {
    if (self::on_own_page()) { return; }
    $label = wp_json_encode(self::t('Notices'));
    echo '<script>jQuery(function($){setTimeout(function(){var sel="#wpbody-content .notice:not(.inline), #wpbody-content div.updated:not(.inline), #wpbody-content div.error:not(.inline), #wpbody-content .update-nag";'
      . 'var $n=$(sel).filter(function(){return !$(this).closest("#mk-nb").length&&$.trim($(this).text()).length;});if(!$n.length)return;'
      . 'var $b=$("<div id=\\"mk-nb\\"><button type=\\"button\\" id=\\"mk-nb-t\\"></button><div id=\\"mk-nb-list\\"></div></div>");$b.find("#mk-nb-t").text(' . $label . '+" ("+$n.length+")");'
      . '$b.find("#mk-nb-list").append($n);var $a=$(".wp-header-end").first();if($a.length){$a.after($b);}else{$("#wpbody-content > .wrap").first().prepend($b);}'
      . '$b.on("click","#mk-nb-t",function(){$b.toggleClass("open");});},60);});</script>';
  }

  public static function row_action($actions, $post) {
    $s = self::settings();
    if (!in_array($post->post_type, $s['dup']['types'], true)) { return $actions; }
    if (!current_user_can('edit_post', $post->ID)) { return $actions; }
    $url = wp_nonce_url(admin_url('admin.php?action=mk_ame_dup&post=' . (int) $post->ID), 'mk_ame_dup_' . (int) $post->ID);
    $actions['mk_ame_dup'] = '<a href="' . esc_url($url) . '">' . esc_html(self::t('Duplicate')) . '</a>';
    return $actions;
  }
  public static function dup() {
    $id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    check_admin_referer('mk_ame_dup_' . $id);
    $post = get_post($id);
    if (!$post || !current_user_can('edit_post', $id)) { wp_die(esc_html(self::t('You are not allowed to do this.'))); }
    $arr = get_post($id, ARRAY_A);
    foreach (['ID', 'guid', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_name'] as $k) { unset($arr[$k]); }
    $arr['post_status'] = 'draft';
    $arr['post_author'] = get_current_user_id();
    $arr['post_title'] = $post->post_title . ' (copy)';
    $new = wp_insert_post(wp_slash($arr), true);
    if (is_wp_error($new) || !$new) { wp_die(esc_html(self::t('Could not duplicate.'))); }
    foreach (get_object_taxonomies($post->post_type) as $tax) {
      $terms = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
      if (!is_wp_error($terms)) { wp_set_object_terms($new, $terms, $tax); }
    }
    foreach (get_post_meta($id) as $k => $vals) {
      if (in_array($k, ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date'], true)) { continue; }
      foreach ($vals as $v) { add_post_meta($new, $k, wp_slash(maybe_unserialize($v))); }
    }
    $args = $post->post_type === 'post' ? [] : ['post_type' => $post->post_type];
    wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
    exit;
  }

  private static function svg_allowed() {
    $s = self::settings();
    $u = wp_get_current_user();
    return $u && $u->exists() && (bool) array_intersect((array) $u->roles, $s['svg']['roles']);
  }
  public static function svg_mimes($m) { if (self::svg_allowed()) { $m['svg'] = 'image/svg+xml'; } return $m; }
  public static function svg_check($data, $file, $filename, $mimes, $real_mime = null) {
    if (self::svg_allowed() && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
      return ['ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => isset($data['proper_filename']) ? $data['proper_filename'] : false];
    }
    return $data;
  }
  public static function svg_scan($file) {
    if (isset($file['name'], $file['tmp_name']) && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'svg') {
      $c = is_readable($file['tmp_name']) ? file_get_contents($file['tmp_name']) : '';
      if ($c === false || preg_match('/<script|javascript:|\son[a-z]+\s*=|<foreignObject|<!ENTITY|<iframe|<embed|<object/i', $c)) {
        $file['error'] = self::t('This SVG contains code that is not allowed.');
      }
    }
    return $file;
  }

  // ---------- Save / export / import ----------
  private static function back($tab, $flag, $extra = []) {
    wp_safe_redirect(add_query_arg(array_merge(['page' => MK_Admin_Menu::SLUG, 'tab' => $tab, $flag => 1], $extra), admin_url('admin.php')));
    exit;
  }
  public static function save() {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('You are not allowed to do this.'))); }
    check_admin_referer('mk_ame_save_extras');
    $p = wp_unslash($_POST);
    $sec = isset($p['section']) ? sanitize_key($p['section']) : '';
    $s = self::settings();
    $on = function ($k) use ($p) { return !empty($p[$k]) ? 1 : 0; };
    switch ($sec) {
      case 'footer':
        $s['footer']['on'] = $on('on');
        if ($s['footer']['on']) {
          $s['footer']['text'] = wp_kses_post(isset($p['text']) ? $p['text'] : '');
          $s['footer']['ver'] = $on('ver');
        }
        break;
      case 'dash':
        $s['dash']['on'] = $on('dash_on');
        $s['dash']['hide'] = isset($p['hide']) ? array_values(array_map('sanitize_key', (array) $p['hide'])) : [];
        break;
      case 'notices':
        $s['notices']['on'] = $on('notices_on');
        break;
      case 'dup':
        $s['dup']['on'] = $on('dup_on');
        $s['dup']['types'] = isset($p['dup_types']) ? array_values(array_map('sanitize_key', (array) $p['dup_types'])) : [];
        break;
      case 'media':
        $s['svg']['on'] = $on('svg_on');
        $s['svg']['roles'] = isset($p['svg_roles']) ? array_values(array_map('sanitize_key', (array) $p['svg_roles'])) : [];
        break;
      default:
        wp_die(esc_html(self::t('Unknown section.')));
    }
    update_option(self::OPT, $s);
    self::back($sec, 'saved');
  }

  private static function export_keys() {
    return ['mk_ame_settings', 'mk_ame_menu_scopes', 'mk_ame_bar_settings', 'mk_ame_bar_custom', 'mk_ame_colors', 'mk_ame_order', self::OPT];
  }
  public static function export() {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('You are not allowed to do this.'))); }
    check_admin_referer('mk_ame_export');
    $out = ['plugin' => 'admin-manager', 'version' => 1, 'options' => []];
    foreach (self::export_keys() as $k) {
      $v = get_option($k, null);
      if ($v !== null) { $out['options'][$k] = $v; }
    }
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin-manager-settings.json"');
    echo wp_json_encode($out, JSON_PRETTY_PRINT);
    exit;
  }
  public static function import() {
    if (!current_user_can('manage_options')) { wp_die(esc_html(self::t('You are not allowed to do this.'))); }
    check_admin_referer('mk_ame_import');
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name']) || (int) $_FILES['file']['size'] > 1048576) { self::back('io', 'import_error'); }
    $j = json_decode((string) file_get_contents($_FILES['file']['tmp_name']), true);
    if (!is_array($j) || ($j['plugin'] ?? '') !== 'admin-manager' || empty($j['options']) || !is_array($j['options'])) { self::back('io', 'import_error'); }
    $n = 0;
    foreach (self::export_keys() as $k) {
      if (isset($j['options'][$k]) && is_array($j['options'][$k])) { update_option($k, $j['options'][$k]); $n++; }
    }
    self::back('io', 'imported', ['n' => $n]);
  }

  // ---------- Side panel ----------
  public static function sidebar($tab, $base) {
    $st = self::status();
    $groups = [
      ['', [['overview', 'Overview', false]]],
      ['Visual', [['colors', 'Colors', $st['colors']], ['logos', 'Logos', $st['logos']], ['footer', 'Admin footer', $st['footer']]]],
      ['Navigation', [['left', 'Left Menu Editor', $st['left']], ['top', 'Top Menu Editor', $st['top']]]],
      ['Admin screens', [['dash', 'Dashboard', $st['dash']], ['notices', 'Notices', $st['notices']]]],
      ['Content', [['order', 'Post ordering', true], ['dup', 'Duplicate', $st['dup']], ['media', 'SVG uploads', $st['media']]]],
      ['Manage', [['io', 'Export / import', false]]],
    ];
    $h = '<div class="amx-shell" style="' . self::vars() . '"><nav class="amx-side">';
    foreach ($groups as $g) {
      if ($g[0] !== '') { $h .= '<div class="amx-grp">' . esc_html(self::t($g[0])) . '</div>'; }
      foreach ($g[1] as $i) {
        $url = $i[0] === 'overview' ? $base . '&tab=overview' : ($i[0] === 'left' ? $base . '&tab=left' : $base . '&tab=' . $i[0]);
        $h .= '<a href="' . esc_url($url) . '" class="' . ($tab === $i[0] ? 'on' : '') . '"><span>' . esc_html(self::t($i[1])) . '</span><i class="amx-dot' . ($i[2] ? ' show' : '') . '"></i></a>';
      }
    }
    return self::css() . self::scene_css() . $h . '</nav><div class="amx-main">';
  }
  public static function sidebar_end() {
    return '</div></div><script>(function(){function f(){var l=document.querySelectorAll(\'.amx-th\');for(var i=0;i<l.length;i++){var s=l[i].querySelector(\'.amx-sc\');if(s&&l[i].clientWidth){s.style.transform=\'scale(\'+(l[i].clientWidth/520)+\')\';}}}window.amxF=f;f();if(!window.amxR){window.amxR=1;window.addEventListener(\'resize\',function(){window.amxF();});window.addEventListener(\'load\',function(){window.amxF();});}})();</script>' . self::nav_js();
  }
  private static function nav_js() {
    $js = <<<'AMXJS'
(function(){
if(window.amxGo){return;}
var MSG=%MSG%,dirty=false;
function wrap(){return document.querySelector('.wrap.ame');}
function mark(e){var t=e.target;if(t&&t.closest&&t.closest('.ame-form')){dirty=true;}}
document.addEventListener('input',mark,true);document.addEventListener('change',mark,true);
if(window.jQuery){jQuery(document).on('sortupdate',function(){dirty=true;});}
try{history.replaceState({amx:1},'',location.href);}catch(x){}
function revive(n){var s=n.querySelectorAll('script');for(var i=0;i<s.length;i++){var o=s[i],c=document.createElement('script');c.text=o.text;o.parentNode.replaceChild(c,o);}}
function swap(t,url,push){
var d=new DOMParser().parseFromString(t,'text/html'),nw=d.querySelector('.wrap.ame'),old=wrap();
if(!nw||!old){document.open();document.write(t);document.close();return;}
var n=document.importNode(nw,true);old.parentNode.replaceChild(n,old);revive(n);
if(d.title){document.title=d.title;}
if(push){try{history.pushState({amx:1},'',url);}catch(x){}}
var r=n.getBoundingClientRect();if(r.top<0){window.scrollTo(0,window.pageYOffset+r.top-50);}
if(window.jQuery){jQuery(document).trigger('wp-updates-notice-added');}
dirty=false;
}
function sig(m){var o=[],l=m.querySelectorAll('li');for(var i=0;i<l.length;i++){var a=l[i].querySelector('a');if(!a||!a.getAttribute('href')){continue;}o.push((a?a.getAttribute('href'):'')+'|'+l[i].textContent.trim().length+'|'+(l[i].classList.contains('mk-ame-hidden')?1:0));}return o.join(',');}
function load(url,opts,push){
var w=wrap();if(w){w.classList.add('amx-busy');}
fetch(url,opts||{credentials:'same-origin'}).then(function(r){return r.text().then(function(t){return [t,r.url,r.ok];});}).then(function(a){
if(opts&&opts.method==='POST'){
var d=new DOMParser().parseFromString(a[0],'text/html'),m1=document.getElementById('adminmenu'),m2=d.getElementById('adminmenu');
if(m1&&m2&&sig(m1)!==sig(m2)){location.href=a[1];return;}
}
swap(a[0],a[1],push);
}).catch(function(){var w2=wrap();if(w2){w2.classList.remove('amx-busy');}if(!(opts&&opts.method==='POST')){location.href=url;}});
}
function go(url){
if(dirty&&!confirm(MSG)){return;}
if(/[?&]tab=logos/.test(url)&&!(window.wp&&wp.media)){location.href=url;return;}
load(url,null,true);
}
window.amxGo=go;
document.addEventListener('click',function(e){
if(e.defaultPrevented||e.button||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey){return;}
var a=e.target.closest&&e.target.closest('a'),w=wrap();
if(!a||!w||!w.contains(a)||a.target||a.hasAttribute('download')){return;}
var h=a.getAttribute('href')||'';
if(h.indexOf('page=mk-admin-menu')<0){return;}
e.preventDefault();go(a.href);
});
document.addEventListener('submit',function(e){
var f=e.target,w=wrap();
if(e.defaultPrevented||!w||!w.contains(f)){return;}
if((f.getAttribute('action')||'').indexOf('admin-post.php')<0){return;}
e.preventDefault();
var fd=new FormData(f);
if(e.submitter&&e.submitter.name){fd.append(e.submitter.name,e.submitter.value);}
dirty=false;
load(f.getAttribute('action'),{method:'POST',body:fd,credentials:'same-origin'},true);
});
window.addEventListener('popstate',function(e){if(e.state&&e.state.amx){dirty=false;load(location.href,null,false);}});
})();
AMXJS;
    return '<script>' . str_replace('%MSG%', wp_json_encode(self::t('You have unsaved changes. Leave without saving?')), $js) . '</script>';
  }

  private static function css() {
    return '<style>.amx-shell{display:grid;grid-template-columns:220px minmax(0,1fr);background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow:clip;margin-top:14px;max-width:1280px}'
      . '.amx-side{background:#fafafa;border-right:1px solid #dcdcde;padding:10px 0}.amx-grp{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#646970;padding:14px 18px 4px}'
      . '.amx-side a{display:flex;align-items:center;justify-content:space-between;padding:8px 18px;color:#1d2327;text-decoration:none;border-left:3px solid transparent;box-shadow:none}.amx-side a:hover{background:#f0f0f1}'
      . '.amx-side a.on{background:var(--amx-tint);border-left-color:var(--amx-main);color:var(--amx-main);font-weight:600}.amx-dot{width:7px;height:7px;border-radius:50%;background:var(--amx-main);opacity:0}.amx-dot.show{opacity:1}'
      . '.amx-main{padding:40px 26px 26px;min-width:0;transition:opacity .15s}.amx-busy .amx-main{opacity:.45;pointer-events:none}.wrap.ame h2.amx-t{font-size:24px;line-height:1.3;font-weight:600;margin:0 0 6px;padding:0;color:#1d2327}.wrap.ame h3.amx-h3{font-size:16px;line-height:1.4;font-weight:600;margin:30px 0 10px;padding:0;color:#1d2327}.wrap.ame .amx-main>.notice{margin:0 0 4px}.wrap.ame .amx-main>.notice+.amx-t{margin-top:20px}.wrap.ame .amx-lead,.wrap.ame .amd-intro{margin:0 0 18px;color:#646970;max-width:640px;font-size:13px;line-height:1.5}.amx-lead{color:#646970;max-width:640px;margin:0 0 18px}'
      . '.amx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}.amx-card{border:1px solid #dcdcde;border-radius:6px;padding:14px;display:flex;flex-direction:column;gap:6px;background:#fff}'
      . '.amx-card.active{border-color:var(--amx-main);background:var(--amx-tint)}.amx-card h3{margin:0;font-size:16px;display:flex;justify-content:space-between;align-items:center;gap:8px}.amx-card p{margin:0;color:#646970;font-size:13px;flex:1}'
      . '.amx-tg{position:relative;width:36px;height:20px;flex:none;display:inline-block}.amx-tg input{opacity:0;position:absolute;inset:0;margin:0;cursor:pointer;z-index:2;width:100%;height:100%}'
      . '.amx-tg i{position:absolute;inset:0;background:#a7aaad;border-radius:20px;transition:.15s}.amx-tg i:after{content:"";position:absolute;width:16px;height:16px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.15s}'
      . '.amx-tg input:checked+i{background:var(--amx-main)}.amx-tg input:checked+i:after{left:18px}'
      . '.amx-sec{border:1px solid #dcdcde;border-radius:6px;margin-bottom:16px}.amx-sec-h{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;gap:12px}.amx-sec-h b{display:block;font-size:16px;font-weight:600}.amx-sec-h span{color:#646970;font-size:13px}'
      . '.amx-sec-b{border-top:1px solid #dcdcde;padding:14px 16px;background:#fcfcfc}.amx-chk{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px 18px}.amx-chk label{display:flex;gap:8px;align-items:center}'
      . '.amx-row{display:grid;grid-template-columns:160px minmax(0,1fr);gap:12px;align-items:start;margin-bottom:12px}.amx-row>label{font-weight:600;padding-top:6px}.amx-main textarea,.amx-main select,.amx-main input.amx-w{width:100%;max-width:480px}'
      . '.amx-hint{color:#646970;font-size:12.5px;margin-top:4px}.amx-mock{border:1px dashed #dcdcde;border-radius:6px;padding:12px;background:#fff;margin-top:10px;font-size:13px}.amx-mock .foot{margin-top:10px;padding-top:8px;border-top:1px solid #eee;color:#646970;font-size:12px}'
 . '.amx-ov-g{margin:22px 0 10px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#646970}.amx-ov-g:first-of-type{margin-top:6px}.amx-ov{display:flex;flex-wrap:wrap;gap:18px}.amx-ov > .amx-ov-card{flex:0 1 320px;min-width:0;max-width:100%}.amx-ov-card{border:1px solid #dcdcde;border-radius:8px;overflow:hidden;background:#fff;display:flex;flex-direction:column}'
      . '.amx-ov-b{padding:14px 16px 16px;display:flex;flex-direction:column;gap:6px;flex:1;align-items:flex-start}.amx-ov-t{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%}.amx-ov-t h3{margin:0;font-size:16px}.amx-ov-b p{margin:0 0 8px;color:#646970;font-size:13px;flex:1}'
      . '.amx-st{font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#f0f0f1;color:#50575e}.amx-st.on{background:#e6f4ea;color:#1e6b34}'
      . ''
      . '@media(max-width:900px){.amx-shell{grid-template-columns:1fr}.amx-side{border-right:0;border-bottom:1px solid #dcdcde;display:flex;overflow-x:auto;padding:0}.amx-grp{display:none}.amx-side a{white-space:nowrap;border-left:0;border-bottom:3px solid transparent;gap:8px}.amx-side a.on{border-bottom-color:var(--amx-main)}.amx-row{grid-template-columns:1fr}.amx-main{padding:22px 16px 20px}}</style>';
  }

  // ---------- Tabs ----------
  private static function toggle($name, $checked, $extra = '') {
    return '<label class="amx-tg"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked' : '') . ' ' . $extra . '><i></i></label>';
  }
  private static function form_open($section) {
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mk_ame_save_extras"><input type="hidden" name="section" value="' . esc_attr($section) . '">' . wp_nonce_field('mk_ame_save_extras', '_wpnonce', true, false);
  }
  private static function form_close() {
    return '<p class="submit"><button type="submit" class="button button-primary">' . esc_html(self::t('Save changes')) . '</button></p></form>';
  }
  private static function head($title, $lead) {
    return '<h2 class="amx-t">' . esc_html(self::t($title)) . '</h2><p class="amx-lead">' . esc_html(self::t($lead)) . '</p>';
  }

  public static function tab($tab) {
    switch ($tab) {
      case 'footer': return self::tab_footer();
      case 'dash': return self::tab_dash();
      case 'notices': return self::tab_notices();
      case 'dup': return self::tab_dup();
      case 'media': return self::tab_media();
      case 'io': return self::tab_io();
      default: return self::tab_overview();
    }
  }

  private static function status() {
    $s = self::settings();
    $has = function ($o) { $v = get_option($o); return !empty($v); };
    return [
      'left' => $has('mk_ame_settings') || $has('mk_ame_menu_scopes'),
      'top' => $has('mk_ame_bar_settings') || $has('mk_ame_bar_custom'),
      'colors' => $has('mk_ame_colors'),
      'logos' => $has('mk_ame_logos'),
      'order' => true,
      'footer' => (bool) $s['footer']['on'],
      'dash' => (bool) $s['dash']['on'],
      'notices' => (bool) $s['notices']['on'],
      'dup' => (bool) $s['dup']['on'],
      'media' => (bool) $s['svg']['on'],
    ];
  }

  private static function tint($hex, $a = 0.9) {
    $h = ltrim((string) $hex, '#');
    if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
    if (strlen($h) !== 6) { return '#f0f6fc'; }
    $o = '#';
    foreach ([0, 2, 4] as $i) { $c = hexdec(substr($h, $i, 2)); $o .= sprintf('%02x', (int) round($c + (255 - $c) * $a)); }
    return $o;
  }

  // Small preview scenes. Built as real HTML at WordPress sizes and scaled down, so spacing and type look like the real admin.
  // They use the main color of the Colors tab, so they follow the admin color.
  public static function vars() {
    $main = MK_AME_Design::colors()['main'];
    return '--amx-main:' . esc_attr($main) . ';--amx-tint:' . esc_attr(self::tint($main)) . ';--amx-light:' . esc_attr(MK_AME_Design::palette($main)['light']);
  }

  public static function thumb($key) {
    $e = function ($s) { return esc_html($s); };
    $cut = function ($s, $n) { $s = wp_strip_all_tags((string) $s); return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); };
    $titles = function () use ($cut) {
      $out = [];
      foreach (get_posts(['post_type' => 'page', 'numberposts' => 3, 'orderby' => 'menu_order title', 'order' => 'ASC']) as $p) { if ($p->post_title !== '') { $out[] = $cut($p->post_title, 24); } }
      foreach (['Home', 'About us', 'Contact'] as $f) { if (count($out) < 3) { $out[] = $f; } }
      return $out;
    };
    $dot = '<span class="sc-dot"></span>';
    $grip = '<span class="sc-grip"></span>';
    $mi = function ($label, $cls = '', $pre = '') use ($dot) { return '<div class="sc-mi ' . $cls . '">' . $pre . $dot . '<span>' . $label . '</span></div>'; };
    $win = function ($style, $inner, $cls = '') { return '<div class="sc-win ' . $cls . '" style="' . $style . '">' . $inner . '</div>'; };
    $bar = '<div class="sc-bar"><span class="sc-dot"></span><span class="sc-bl"></span></div>';
    $menu = function ($sub = true) use ($mi, $e) {
      $h = $mi($e(__('Dashboard')), 'cur');
      if ($sub) { $h .= '<div class="sc-sub"><div class="c">' . $e(__('Home')) . '</div><div class="hv">' . $e(__('Updates')) . '</div></div>'; }
      return $h . $mi($e(__('Posts'))) . $mi($e(__('Media'))) . $mi($e(__('Pages')));
    };
    $o = '';
    switch ($key) {
      case 'left':
        $inner = '<div class="sc-side" style="width:100%;padding:6px 0">';
        $inner .= $mi($e(__('Dashboard')), 'cur', $grip) . $mi('<span class="sc-rn">Blog</span>', '', $grip) . $mi($e(__('Media')), 'lift', $grip) . $mi($e(__('Pages')), '', $grip);
        $inner .= $mi('<s>' . $e(__('Comments')) . '</s> <span class="sc-tag">' . $e(self::t('Hide')) . '</span>', 'off', $grip) . $mi($e(__('Appearance')), '', $grip) . $mi($e(__('Plugins')), '', $grip) . '</div>';
        $o = $win('left:140px;top:14px;width:240px;background:#1d2327', $inner);
        break;
      case 'top':
        $user = wp_get_current_user();
        $howdy = sprintf(__('Howdy, %s'), ($user && $user->display_name) ? $user->display_name : 'Admin');
        $dd = '<div class="sc-dd"><div>' . $e(__('Post')) . '</div><div>' . $e(__('Media')) . '</div><div>' . $e(__('Page')) . '</div><div class="cu">' . $e(__('Custom Link')) . '</div></div>';
        $inner = '<div class="sc-bar" style="border-radius:6px;gap:16px"><span class="sc-dot"></span><b class="sc-bn">' . $e($cut(get_bloginfo('name'), 16)) . '</b><span class="sc-bub"></span><div class="sc-new">+ ' . $e(__('New')) . $dd . '</div><span style="margin-left:auto">' . $e($howdy) . '</span><span class="sc-dot"></span></div>';
        $o = $win('left:20px;right:20px;top:44px;background:none;box-shadow:none;overflow:visible', $inner);
        break;
      case 'colors':
        $inner = $bar . '<div class="sc-row"><div class="sc-side">' . $menu() . '</div><div class="sc-main"><div class="sc-h1">' . $e(__('Dashboard')) . '</div><div class="sc-card"><div class="sc-card-t">' . $e(__('Welcome to WordPress!')) . '</div><div class="sc-p">Lorem ipsum dolor sit amet, consectetur adipiscing elit sed do. <span class="sc-link">' . $e(__('Learn more')) . '</span></div></div><div class="sc-btn">' . $e(__('Save Changes')) . '</div></div></div>';
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', $inner);
        break;
      case 'logos':
        $side = '<div class="sc-side" style="padding-top:16px"><div class="sc-lg">LOGO</div>' . $menu(false) . '</div>';
        $login = '<div class="sc-login"><div class="sc-lg2">LOGO</div><div class="sc-form"><div class="sc-lbl">' . $e(__('Username or Email Address')) . '</div><div class="sc-in"></div><div class="sc-lbl">' . $e(__('Password')) . '</div><div class="sc-in foc"></div><div class="sc-fr"><span class="sc-chk"></span><span class="sc-p">' . $e(__('Remember Me')) . '</span><span class="sc-btn" style="margin-left:auto">' . $e(__('Log In')) . '</span></div></div></div>';
        $o = $win('left:20px;top:16px;width:168px;bottom:0;background:#1d2327;border-radius:8px 0 0 0', $side) . $win('left:204px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', $login);
        break;
      case 'order':
      case 'order_before':
        $ob = $key === 'order_before';
        $tt = $titles();
        $rows = '<div class="sc-th"><span style="width:30px"></span><span style="flex:1">' . $e(__('Title')) . '</span><span style="width:110px">' . $e(__('Date')) . '</span></div>';
        foreach ($tt as $i => $t) {
          $rows .= '<div class="sc-tr' . (($i === 1 && !$ob) ? ' lift2' : '') . '">' . ($ob ? '<span style="width:8px;margin-right:14px;flex:none"></span>' : '<span class="sc-grip dk" style="margin-right:14px"></span>') . '<span style="flex:1"><b class="sc-link0">' . $e($t) . '</b></span><span class="sc-p" style="width:110px">' . $e(__('Published')) . '<br>' . $e(wp_date('j M Y')) . '</span>' . (($i === 1 && !$ob) ? '<span class="sc-ud">&#8597;</span>' : '') . '</div>';
        }
        $inner = '<div class="sc-main"><div class="sc-h1">' . $e(__('Pages')) . '</div><div class="sc-tbl">' . $rows . '</div></div>';
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', $inner);
        break;
      case 'footer':
        $f = self::settings()['footer'];
        $txt = trim(wp_strip_all_tags($f['text'])) !== '' ? wp_kses_post($f['text']) : $e(self::t('Website made by')) . ' <span class="sc-link">ABC Agency</span>';
        $ver = sprintf(__('Version %s'), get_bloginfo('version'));
        $inner = '<div class="sc-row" style="height:100%"><div class="sc-side" style="height:100%">' . $menu(false) . '</div><div class="sc-main" style="position:relative;flex:1"><div class="sc-h1">' . $e(__('Dashboard')) . '</div><div class="sc-card"><div class="sc-p">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor.</div></div><div class="sc-foot"><span>' . $txt . '</span><span class="sc-p" style="margin-left:auto;padding-right:20px">' . ($f['ver'] ? $e($ver) : '') . '</span></div></div></div>';
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', $inner);
        break;
      case 'dash':
        $pb = function ($title, $body, $cls = '') use ($e) { return '<div class="sc-pb ' . $cls . '"><div class="sc-pb-h">' . $e($title) . ($cls ? ' <span class="sc-tag" style="float:right">' . $e(self::t('Hide')) . '</span>' : '') . '</div><div class="sc-pb-b">' . $body . '</div></div>'; };
        $grid = '<div class="sc-g2">' . $pb(self::t('At a Glance'), '<div class="sc-p">5 ' . $e(__('Pages')) . '<br>12 ' . $e(__('Posts')) . '</div>') . $pb(self::t('Activity'), '<div class="sc-p">' . $e(__('Recently Published')) . '<br>Lorem ipsum dolor</div>') . $pb(self::t('Quick Draft'), '<div class="sc-in" style="height:28px"></div>') . $pb(self::t('Site Health Status'), '<div class="sc-p">Lorem ipsum dolor sit</div>', 'off') . '</div>';
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', '<div class="sc-main"><div class="sc-h1">' . $e(__('Dashboard')) . '</div>' . $grid . '</div>');
        break;
      case 'notices':
      case 'notices_before':
        $n = [['#dba617', self::t('A new version of this plugin is available.')], ['#72aee6', self::t('Thanks for installing! Get 20% off the Pro version.')], ['#d63638', self::t('Your license key needs to be renewed.')]];
        $nh = '';
        if ($key === 'notices_before') {
          foreach ($n as $x) { $nh .= '<div class="sc-notice" style="border-left-color:' . $x[0] . '">' . $e($x[1]) . '</div>'; }
        } else {
          $nh = '<div class="sc-notice" style="border-left-color:#dba617;font-weight:600">' . $e(self::t('Notices')) . ' (3)<span class="sc-chev">&#8964;</span></div>';
        }
        $inner = '<div class="sc-main"><div class="sc-h1">' . $e(__('Plugins')) . '</div>' . $nh . '<div class="sc-tbl" style="margin-top:' . ($key === 'notices' ? '14' : '6') . 'px"><div class="sc-tr"><span style="flex:1"><b class="sc-link0">Lorem Plugin</b><br><span class="sc-p">Lorem ipsum dolor sit amet.</span></span></div><div class="sc-tr"><span style="flex:1"><b class="sc-link0">Dolor Forms</b><br><span class="sc-p">Consectetur adipiscing elit.</span></span></div></div></div>';
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', $inner);
        break;
      case 'dup':
        $tt = $titles();
        $dx = $key === 'dup';
        $rows = '<div class="sc-th"><span style="width:50px"></span><span style="flex:1">' . $e(__('Title')) . '</span>' . '' . '</div>';
        foreach ([0, 1] as $i) {
          $rows .= '<div class="sc-tr" style="height:66px;align-items:flex-start;padding-top:10px"><span class="sc-img" style="margin-right:12px"></span><span style="flex:1"><b class="sc-link0">' . $e($tt[$i]) . '</b><br><span class="sc-ra">' . $e(__('Edit')) . ' | ' . $e(__('Trash')) . ' | ' . $e(__('View')) . ($dx ? ' | <span class="sc-dupl">' . $e(self::t('Duplicate')) . '</span>' : '') . '</span></span>' . ($cx ? '<span class="sc-p" style="width:40px">' . (12 + 9 * $i) . '</span><span class="sc-p" style="width:96px">' . $e(wp_date('j M Y')) . '</span>' : '') . '</div>';
        }
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', '<div class="sc-main"><div class="sc-h1">' . $e(__('Pages')) . '</div><div class="sc-tbl">' . $rows . '</div></div>');
        break;
      case 'media':
        $tiles = '';
        for ($i = 0; $i < 8; $i++) {
          $tiles .= $i === 5
            ? '<div class="sc-tile sv"><span class="sc-cir"></span><span class="sc-tri"></span><span class="sc-tag" style="position:absolute;left:8px;bottom:8px">SVG</span></div>'
            : '<div class="sc-tile g' . ($i % 3) . '"></div>';
        }
        $o = $win('left:20px;top:16px;right:0;bottom:0;border-radius:8px 0 0 0', '<div class="sc-main"><div class="sc-h1">' . $e(__('Media Library')) . '</div><div class="sc-grid">' . $tiles . '</div></div>');
        break;
      default:
        return '';
    }
    return '<div class="amx-sc" aria-hidden="true">' . $o . '</div>';
  }

  private static function scene_css() {
    return <<<'CSS'
<style>
.amx-th{position:relative;display:block;overflow:hidden;aspect-ratio:520/300;background:var(--amx-tint);user-select:none;box-shadow:none!important;outline:none}
.amx-sc{position:absolute;left:0;top:0;width:520px;height:300px;transform-origin:0 0;transform:scale(.45);font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327;pointer-events:none;text-align:left}
.amx-sc *{box-sizing:border-box;margin:0;padding:0}
.amx-sc .sc-win{position:absolute;background:#f0f0f1;overflow:hidden;box-shadow:0 8px 28px rgba(0,0,0,.14);border-radius:8px}
.amx-sc .sc-bar{height:32px;background:#1d2327;display:flex;align-items:center;gap:12px;padding:0 12px;color:#f0f0f1;font-size:13px;flex:none}
.amx-sc .sc-bl{height:6px;width:90px;border-radius:3px;background:#3c434a}
.amx-sc .sc-bn{font-weight:600;font-size:13px}
.amx-sc .sc-bub{width:14px;height:12px;border-radius:3px;background:#a7aaad;display:inline-block}
.amx-sc .sc-dot{width:18px;height:18px;border-radius:50%;background:#a7aaad;display:inline-block;flex:none}
.amx-sc .sc-bar .sc-dot{width:16px;height:16px}
.amx-sc .sc-row{display:flex;align-items:stretch}
.amx-sc .sc-side{background:#1d2327;color:#f0f0f1;width:160px;flex:none;min-height:100%}
.amx-sc .sc-mi{display:flex;align-items:center;gap:10px;height:34px;padding:0 10px;font-size:14px;color:#f0f0f1;position:relative}
.amx-sc .sc-mi .sc-dot{opacity:.8}
.amx-sc .sc-mi.cur{background:var(--amx-main);color:#fff}
.amx-sc .sc-mi.cur .sc-dot{background:#fff;opacity:1}
.amx-sc .sc-sub{background:#0a0a0a;padding:7px 0 8px}
.amx-sc .sc-sub div{padding:5px 12px 5px 38px;font-size:13px;line-height:1.4;color:rgba(240,240,241,.7)}
.amx-sc .sc-sub div.c{color:#fff;font-weight:600}
.amx-sc .sc-sub div.hv{color:var(--amx-light)}
.amx-sc .sc-main{padding:20px;min-width:0;flex:1}
.amx-sc .sc-h1{font-size:23px;font-weight:400;line-height:1.3;margin-bottom:14px;color:#1d2327}
.amx-sc .sc-card{background:#fff;border:1px solid #c3c4c7;padding:14px 16px;margin-bottom:14px}
.amx-sc .sc-card-t{font-size:14px;font-weight:600;margin-bottom:6px}
.amx-sc .sc-p{color:#50575e;font-size:13px}
.amx-sc .sc-link{color:var(--amx-main);text-decoration:underline}
.amx-sc .sc-link0{color:var(--amx-main);font-weight:600;font-size:14px}
.amx-sc .sc-btn{display:inline-block;background:var(--amx-main);color:#fff;border-radius:3px;padding:0 14px;line-height:32px;font-size:13px}
.amx-sc .sc-grip{width:8px;height:15px;flex:none;background:radial-gradient(circle,#8c8f94 1.3px,transparent 1.6px) 0 0/4px 5px}
.amx-sc .sc-grip.dk{background:radial-gradient(circle,#a7aaad 1.3px,transparent 1.6px) 0 0/4px 5px}
.amx-sc .sc-mi.lift{background:#3c434a;box-shadow:0 8px 16px rgba(0,0,0,.5);outline:2px solid var(--amx-main);border-radius:3px;transform:translateX(10px)}
.amx-sc .sc-mi.off{opacity:.5}
.amx-sc .sc-rn{border:1px solid rgba(255,255,255,.65);border-radius:3px;padding:1px 10px 1px 6px;margin-left:-6px}
.amx-sc .sc-tag{background:var(--amx-main);color:#fff;font-size:11px;font-weight:600;border-radius:9px;padding:1px 9px;line-height:16px;display:inline-block}
.amx-sc .sc-new{position:relative;height:32px;line-height:32px;padding:0 12px;background:var(--amx-main);color:#fff;margin:0 -4px}
.amx-sc .sc-dd{position:absolute;left:0;top:32px;width:180px;background:#2c3338;padding:6px 0;box-shadow:0 8px 20px rgba(0,0,0,.3)}
.amx-sc .sc-dd div{padding:5px 14px;line-height:18px;color:#f0f0f1;font-size:13px}
.amx-sc .sc-dd div.cu{border-left:3px solid var(--amx-main);padding-left:11px;color:var(--amx-light)}
.amx-sc .sc-lg{margin:0 10px 14px;height:44px;border-radius:6px;background:var(--amx-main);color:#fff;font-weight:700;letter-spacing:.08em;display:flex;align-items:center;justify-content:center}
.amx-sc .sc-login{padding:22px 26px}
.amx-sc .sc-lg2{margin:0 auto 18px;width:150px;height:40px;border-radius:6px;background:#1d2327;color:#fff;font-weight:700;letter-spacing:.08em;display:flex;align-items:center;justify-content:center}
.amx-sc .sc-form{background:#fff;border:1px solid #c3c4c7;padding:20px 22px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.amx-sc .sc-lbl{font-size:14px;margin-bottom:4px}
.amx-sc .sc-in{height:36px;border:1px solid #8c8f94;border-radius:4px;margin-bottom:14px;background:#fff}
.amx-sc .sc-in.foc{border-color:var(--amx-main);box-shadow:0 0 0 1px var(--amx-main)}
.amx-sc .sc-fr{display:flex;align-items:center;gap:8px;margin-top:4px}
.amx-sc .sc-chk{width:16px;height:16px;border:1px solid #8c8f94;border-radius:3px;display:inline-block}
.amx-sc .sc-tbl{background:#fff;border:1px solid #c3c4c7}
.amx-sc .sc-th{display:flex;align-items:center;gap:0;height:36px;padding:0 14px;border-bottom:1px solid #c3c4c7;font-size:13px;font-weight:600}
.amx-sc .sc-tr{display:flex;align-items:center;height:58px;padding:0 14px;border-bottom:1px solid #f0f0f1;background:#fff}
.amx-sc .sc-tr.lift2{box-shadow:0 8px 20px rgba(0,0,0,.2);outline:2px solid var(--amx-main);position:relative;z-index:2;transform:scale(1.02)}
.amx-sc .sc-ud{color:var(--amx-main);font-size:20px;font-weight:700;margin-left:8px}
.amx-sc .sc-foot{position:absolute;left:0;right:0;bottom:0;height:44px;display:flex;align-items:center;padding:0 20px;color:#50575e;font-size:13px}
.amx-sc .sc-g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.amx-sc .sc-pb{background:#fff;border:1px solid #c3c4c7}
.amx-sc .sc-pb-h{padding:8px 12px;border-bottom:1px solid #c3c4c7;font-size:14px;font-weight:600;line-height:1.4}
.amx-sc .sc-pb-b{padding:10px 12px;min-height:52px}
.amx-sc .sc-pb.off{border:2px dashed var(--amx-main);background:rgba(255,255,255,.5)}
.amx-sc .sc-pb.off .sc-pb-h,.amx-sc .sc-pb.off .sc-pb-b{opacity:.55}
.amx-sc .sc-pb.off .sc-pb-h .sc-tag{opacity:1}
.amx-sc .sc-notice{background:#fff;border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:9px 12px;font-size:13px;margin-bottom:8px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.amx-sc .sc-chev{float:right;font-weight:400;color:#646970}
.amx-sc .sc-img{width:40px;height:40px;background:linear-gradient(135deg,#c9ced3,#e3e6e9);flex:none;display:inline-block;border-radius:2px}
.amx-sc .sc-ra{font-size:12px;color:var(--amx-main)}
.amx-sc .sc-dupl{background:var(--amx-tint);border-radius:3px;padding:0 5px;font-weight:600;outline:1px solid var(--amx-main)}
.amx-sc .sc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.amx-sc .sc-tile{aspect-ratio:1;border-radius:2px;position:relative;overflow:hidden}
.amx-sc .sc-tile.g0{background:linear-gradient(135deg,#bfc7cf,#e0e4e8)}
.amx-sc .sc-tile.g1{background:linear-gradient(135deg,#d4cfc6,#ebe8e2)}
.amx-sc .sc-tile.g2{background:linear-gradient(135deg,#c3cdc6,#e1e8e3)}
.amx-sc .sc-tile.sv{background:#fff;outline:2px solid var(--amx-main)}
.amx-sc .sc-cir{position:absolute;left:18px;top:14px;width:34px;height:34px;border-radius:50%;background:var(--amx-main)}
.amx-sc .sc-tri{position:absolute;left:48px;top:36px;border-left:20px solid transparent;border-right:20px solid transparent;border-bottom:34px solid var(--amx-light)}
.amx-cmp{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.amx-cmp .amx-th{border:1px solid #dcdcde;border-radius:8px}
.amx-cap{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:6px}
.amx-io{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:18px 0 12px}
.amx-io-c{border:1px solid #dcdcde;border-radius:8px;padding:20px;display:flex;flex-direction:column;gap:10px;background:#fff}
.amx-io-c h3{margin:0;font-size:16px}.amx-io-c p{margin:0;color:#50575e}
.amx-drop{display:flex;align-items:center;justify-content:center;gap:8px;border:2px dashed #c3c4c7;border-radius:8px;padding:22px 12px;color:#50575e;cursor:pointer;text-align:center;background:#fafafa}
.amx-drop:hover{border-color:var(--amx-main)}.amx-drop input{display:none}
@media(max-width:700px){.amx-cmp,.amx-io{grid-template-columns:1fr}}
</style>
CSS;
  }

  private static function tab_overview() {
    $on = self::status();
    $main = MK_AME_Design::colors()['main'];
    $base = admin_url('admin.php?page=' . MK_Admin_Menu::SLUG);
    $groups = [
      ['Visual', [
        ['colors', 'colors', 'Colors', 'One main color for your whole admin.'],
        ['logos', 'logos', 'Logos', 'Menu logo and login logo.'],
        ['footer', 'footer', 'Admin footer', 'Your own text at the bottom of the admin.'],
      ]],
      ['Navigation', [
        ['left', 'left', 'Left Menu Editor', 'Order, names and visibility of the left menu.'],
        ['top', 'top', 'Top Menu Editor', 'Clean up the admin bar and add your own links.'],
      ]],
      ['Admin screens', [
        ['dash', 'dash', 'Dashboard', 'Hide dashboard widgets and the welcome panel.'],
        ['notices', 'notices', 'Bundle notices', 'Put plugin notices in one small collapsible box.'],
      ]],
      ['Content', [
        ['order', 'order', 'Post ordering', 'Drag your content into your own order.'],
        ['dup', 'dup', 'Duplicate', 'A Duplicate link under each title in your lists.'],
        ['media', 'media', 'SVG uploads', 'Allow SVG files in the media library.'],
      ]],
    ];
    $h = self::head('Overview', 'Everything Admin Manager can do. Click Set up to open a feature and switch it on there. A feature that is off loads nothing.');
    $h .= '<div class="amx-ov" style="' . self::vars() . '">';
    foreach ($groups as $g) {
      foreach ($g[1] as $c) {
        $url = esc_url($base . '&tab=' . $c[1]);
        $is = !empty($on[$c[0]]);
        $h .= '<div class="amx-ov-card"><a class="amx-th" href="' . $url . '" tabindex="-1">' . self::thumb($c[0]) . '</a><div class="amx-ov-b"><div class="amx-ov-t"><h3>' . esc_html(self::t($c[2])) . '</h3><span class="amx-st' . ($is ? ' on' : '') . '">' . esc_html(self::t($is ? 'On' : 'Off')) . '</span></div><p>' . esc_html(self::t($c[3])) . '</p><a class="button" href="' . $url . '">' . esc_html(self::t('Set up')) . '</a></div></div>';
      }
    }
    $h .= '</div>';
    return $h;
  }

  private static function tab_footer() {
    $s = self::settings()['footer'];
    $wp = sprintf(__('Thank you for creating with <a href="%s">WordPress</a>.'), esc_url(__('https://wordpress.org/')));
    $h = self::head('Admin footer', 'Replace "Thank you for creating with WordPress" with your own text.');
    $h .= self::form_open('footer');
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Turn on')) . '</label><div>' . self::toggle('on', $s['on']) . '</div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Footer text')) . '</label><div><textarea name="text" id="amx-ft" rows="3"' . ($s['on'] ? '' : ' disabled style="opacity:.6"') . '>' . esc_textarea($s['on'] ? $s['text'] : $wp) . '</textarea><div class="amx-hint">' . esc_html(self::t('Leave empty to hide the footer text. Simple HTML such as links is allowed.')) . '</div></div></div>';
    $h .= '<div class="amx-row"><label>' . esc_html(self::t('Version number')) . '</label><div><label id="amx-fv"' . ($s['on'] ? '' : ' style="opacity:.6"') . '><input type="checkbox" name="ver" value="1"' . (($s['ver'] || !$s['on']) ? ' checked' : '') . ($s['on'] ? '' : ' disabled') . '> ' . esc_html(self::t('Show the WordPress version on the right')) . '</label></div></div>';
    $h .= self::form_close();
    $h .= '<script>(function(){var t=document.querySelector("input[name=on]"),ta=document.getElementById("amx-ft"),v=document.querySelector("#amx-fv input");if(!t||!ta){return;}var wp=' . wp_json_encode($wp) . ',custom=' . wp_json_encode($s['text']) . ',cv=' . ($s['ver'] ? 'true' : 'false') . ';t.addEventListener("change",function(){if(t.checked){ta.disabled=false;ta.style.opacity="";ta.value=custom!==""?custom:wp;v.disabled=false;v.checked=cv;v.parentNode.style.opacity="";}else{custom=ta.value;cv=v.checked;ta.value=wp;ta.disabled=true;ta.style.opacity=".6";v.checked=true;v.disabled=true;v.parentNode.style.opacity=".6";}});})();</script>';
    return $h;
  }

  private static function tab_dash() {
    $s = self::settings();
    $widgets = ['welcome' => 'Welcome'] + self::core_widgets();
    $seen = get_option(self::SEEN, []);
    if (is_array($seen)) {
      foreach ($seen as $id => $title) { if (!isset($widgets[$id]) && $title !== '') { $widgets[$id] = $title; } }
    }
    $h = self::head('Dashboard', 'Hide the dashboard widgets your clients do not need. Things are only hidden, nothing is deleted.');
    $h .= self::form_open('dash');
    $h .= '<div class="amx-sec"><div class="amx-sec-h"><div><b>' . esc_html(self::t('Dashboard widgets')) . '</b><span>' . esc_html(self::t('Choose which widgets are hidden.')) . '</span></div>' . self::toggle('dash_on', $s['dash']['on']) . '</div><div class="amx-sec-b"><div class="amx-chk">';
    foreach ($widgets as $id => $title) {
      $label = isset(self::core_widgets()[$id]) || $id === 'welcome' ? self::t($title) : $title;
      $h .= '<label><input type="checkbox" name="hide[]" value="' . esc_attr($id) . '"' . ($s['dash']['on'] && in_array($id, $s['dash']['hide'], true) ? ' checked' : '') . ($s['dash']['on'] ? '' : ' disabled') . '> ' . esc_html($label) . '</label>';
    }
    $h .= '</div><div class="amx-hint" style="margin-top:10px">' . esc_html(self::t('Widgets from plugins show up here after you have opened the dashboard once.')) . '</div></div></div>';
    $h .= self::form_close();
    // Off: nothing is hidden, so every box is empty and greyed out. Switching on ticks all of them.
    $h .= '<script>(function(){var t=document.querySelector("input[name=dash_on]");if(!t)return;var b=document.querySelectorAll("input[name=\\"hide[]\\"]");function sync(c){for(var i=0;i<b.length;i++){b[i].disabled=!c;b[i].parentNode.style.opacity=c?"":".5";}}t.addEventListener("change",function(){for(var i=0;i<b.length;i++){b[i].checked=t.checked;}sync(t.checked);});sync(t.checked);})();</script>';
    return $h;
  }

  private static function tab_notices() {
    $s = self::settings();
    $h = self::head('Notices', 'Messages, warnings and ads from plugins appear on every admin page. Keep them out of the way.');
    $h .= self::form_open('notices');
    $h .= '<div class="amx-sec"><div class="amx-sec-h"><div><b>' . esc_html(self::t('Bundle notices')) . '</b><span>' . esc_html(self::t('Plugin notices go in one small collapsible box, instead of on top of every page.')) . '</span></div>' . self::toggle('notices_on', $s['notices']['on']) . '</div>';
    $h .= '<div class="amx-sec-b"><p style="margin:0 0 14px;max-width:640px">' . esc_html(self::t('Plugins often add messages, warnings and ads above every admin page. With this on, they are moved into one small box that you can open when you want, so your pages start clean.')) . '</p>';
    $h .= '<div class="amx-cmp" style="' . self::vars() . '"><div><div class="amx-cap">' . esc_html(self::t('Without bundling')) . '</div><div class="amx-th">' . self::thumb('notices_before') . '</div></div><div><div class="amx-cap">' . esc_html(self::t('With bundling')) . '</div><div class="amx-th">' . self::thumb('notices') . '</div></div></div></div></div>';
    return $h . self::form_close();
  }

  private static function tab_dup() {
    $s = self::settings();
    $types = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
      if (in_array($pt->name, ['attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face'], true)) { continue; }
      $types[$pt->name] = $pt->labels->name;
    }
    $h = self::head('Duplicate', 'Copy a page or post in one click.');
    $h .= self::form_open('dup');
    $h .= '<div class="amx-sec"><div class="amx-sec-h"><div><b>' . esc_html(self::t('Duplicate')) . '</b><span>' . esc_html(self::t('Adds a Duplicate link under each title.')) . '</span></div>' . self::toggle('dup_on', $s['dup']['on']) . '</div><div class="amx-sec-b"><div class="amx-chk">';
    foreach ($types as $name => $label) {
      $h .= '<label><input type="checkbox" name="dup_types[]" value="' . esc_attr($name) . '"' . ($s['dup']['on'] && in_array($name, $s['dup']['types'], true) ? ' checked' : '') . ($s['dup']['on'] ? '' : ' disabled') . '> ' . esc_html($label) . '</label>';
    }
    $h .= '</div></div></div>';
    $h .= '<script>(function(){var tg=document.querySelector("input[name=dup_on]");if(!tg){return;}var bx=document.querySelectorAll("input[name^=dup_types]");function sync(c){bx.forEach(function(b){b.disabled=!c;b.parentNode.style.opacity=c?"":".5";});}tg.addEventListener("change",function(){if(tg.checked){var any=false;bx.forEach(function(b){if(b.checked){any=true;}});if(!any){bx.forEach(function(b){if(b.value==="post"||b.value==="page"){b.checked=true;}});}}else{bx.forEach(function(b){b.checked=false;});}sync(tg.checked);});sync(tg.checked);})();</script>';
    return $h . self::form_close();
  }


  private static function tab_media() {
    $s = self::settings()['svg'];
    $h = self::head('SVG uploads', 'Allow SVG files in the media library.');
    $h .= self::form_open('media');
    $h .= '<div class="amx-sec"><div class="amx-sec-h"><div><b>' . esc_html(self::t('SVG uploads')) . '</b><span>' . esc_html(self::t('Allow SVG files in the media library.')) . '</span></div>' . self::toggle('svg_on', $s['on']) . '</div>';
    $h .= '<div class="amx-sec-b"><div class="amx-chk">';
    foreach (wp_roles()->get_names() as $key => $name) {
      $h .= '<label><input type="checkbox" name="svg_roles[]" value="' . esc_attr($key) . '"' . ($s['on'] && in_array($key, $s['roles'], true) ? ' checked' : '') . ($s['on'] ? '' : ' disabled') . '> ' . esc_html(translate_user_role($name)) . '</label>';
    }
    $h .= '</div><div class="amx-hint" style="margin-top:10px">' . esc_html(self::t('Choose which roles may upload SVG files. SVG files can contain code, so only pick roles you trust. Files with scripts are always refused.')) . '</div></div></div>';
    $h .= self::form_close();
    $h .= '<script>(function(){var t=document.querySelector("input[name=svg_on]");if(!t)return;var b=document.querySelectorAll("input[name^=svg_roles]");function sync(c){for(var i=0;i<b.length;i++){b[i].disabled=!c;b[i].parentNode.style.opacity=c?"":".5";}}t.addEventListener("change",function(){for(var i=0;i<b.length;i++){b[i].checked=t.checked&&b[i].value==="administrator";}sync(t.checked);});sync(t.checked);})();</script>';
    return $h;
  }

  private static function tab_io() {
    $exp = wp_nonce_url(admin_url('admin-post.php?action=mk_ame_export'), 'mk_ame_export');
    $h = self::head('Export / import', 'Copy the settings of Admin Manager to another website.');
    if (isset($_GET['imported'])) { $h .= '<div class="notice notice-success inline"><p>' . esc_html(sprintf(self::t('Imported %d groups of settings.'), isset($_GET['n']) ? (int) $_GET['n'] : 0)) . '</p></div>'; }
    if (isset($_GET['import_error'])) { $h .= '<div class="notice notice-error inline"><p>' . esc_html(self::t('That file could not be imported.')) . '</p></div>'; }
    $h .= '<p style="max-width:640px;margin:0">' . esc_html(self::t('Export saves everything you set up in Admin Manager (menus, colors, post ordering, footer, dashboard and more) in one small file. Import that file on another website that has Admin Manager, and it gets the same setup. Only Admin Manager settings are included, not your posts, pages or other plugins.')) . '</p>';
    $h .= '<div class="amx-io"><div class="amx-io-c"><h3>' . esc_html(self::t('Export')) . '</h3><p>' . esc_html(self::t('Download your Admin Manager settings as a file.')) . '</p><div><a class="button button-primary" href="' . esc_url($exp) . '">' . esc_html(self::t('Export settings')) . '</a></div></div>';
    $h .= '<div class="amx-io-c"><h3>' . esc_html(self::t('Import')) . '</h3><p>' . esc_html(self::t('Choose a settings file that you exported from Admin Manager. This replaces the settings on this site.')) . '</p>';
    $h .= '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;flex-direction:column;gap:10px"><input type="hidden" name="action" value="mk_ame_import">' . wp_nonce_field('mk_ame_import', '_wpnonce', true, false);
    $h .= '<label class="amx-drop" id="amx-drop"><input type="file" name="file" accept=".json,application/json"><span id="amx-fn">' . esc_html(self::t('Choose a file')) . '</span></label>';
    $h .= '<div><button type="submit" class="button button-primary" id="amx-imp" disabled>' . esc_html(self::t('Import settings')) . '</button></div></form></div></div>';
    $h .= '<p class="amx-hint">' . esc_html(self::t('Logos are not included, because the images are different on every site.')) . '</p>';
    $h .= '<script>(function(){var f=document.querySelector("#amx-drop input"),n=document.getElementById("amx-fn"),b=document.getElementById("amx-imp"),d=n.textContent;f.addEventListener("change",function(){var ok=f.files&&f.files.length;n.textContent=ok?f.files[0].name:d;b.disabled=!ok;});})();</script>';
    return $h;
  }
}

}
