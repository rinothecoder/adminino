<?php
/**
 * Adminino: Get started, About and Release notes.
 * The same page structure as the other Rino plugins (eyebrow, title, card).
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_AME_Help', false)) {

final class MK_AME_Help {
  private static function t($s) { return MK_Admin_Menu::tr($s); }
  private static function img($f) { return plugin_dir_url(dirname(__FILE__)) . 'assets/' . $f; }
  private static function link($tab) { return admin_url('admin.php?page=' . MK_Admin_Menu::SLUG . '&tab=' . $tab); }

  private static function css() {
    return '<style>.amh-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:36px 40px;max-width:620px;margin-left:auto;margin-right:auto}.amh-card + .amh-card{margin-top:16px}'
      . '.amh-eyebrow{display:block;color:var(--amx-main,#2271b1);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}'
      . '.amh-card h2{font-size:24px;font-weight:600;color:#1d2327;margin:5px 0 8px;padding:0;line-height:1.25}.amh-intro{margin-bottom:28px}.amh-intro p{color:#646970;margin:0}'
      . '.amh-card p{font-size:14px;line-height:1.7;color:#3c434a;margin:0 0 14px}.amh-card p:last-child{margin-bottom:0}'
      . '.amh-photo{display:block;width:80px;height:80px;border-radius:50%;object-fit:cover;margin-bottom:28px}.amh-sign{display:block;margin-top:28px;width:60px;height:auto}'
      . '.amh-steps{list-style:none;margin:0;padding:0;counter-reset:amh}.amh-steps li{counter-increment:amh;position:relative;padding:0 0 22px 46px;margin:0}.amh-steps li:last-child{padding-bottom:0}'
      . '.amh-steps li:before{content:counter(amh);position:absolute;left:0;top:0;width:30px;height:30px;border-radius:50%;background:var(--amx-tint,#f0f6fc);color:var(--amx-main,#2271b1);font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center}'
      . '.amh-steps h3{font-size:15px;font-weight:600;color:#1d2327;margin:4px 0 4px}.amh-steps p{font-size:13px;line-height:1.6;color:#646970;margin:0 0 6px}.amh-steps a{font-size:13px;margin-right:12px}'
      . '.amh-card .amh-note{font-size:13px;color:#646970;border-top:1px solid #dcdcde;margin:28px 0 0;padding-top:20px}'
      . '.amh-rel + .amh-rel{margin-top:28px}.amh-rel-h{display:flex;align-items:baseline;gap:12px;margin-bottom:14px}.amh-ver{font-size:16px;font-weight:700;color:#1d2327}.amh-date{font-size:13px;color:#8c8f94}'
      . '.amh-list{margin:0;padding-left:20px;list-style:disc}.amh-list li{font-size:14px;line-height:1.6;color:#3c434a;margin-bottom:8px}'
      . '@media(max-width:782px){.amh-card{padding:24px 20px}}</style>';
  }
  private static function intro($eyebrow, $title, $lead = '') {
    return '<div class="amh-intro"><span class="amh-eyebrow">' . esc_html(self::t($eyebrow)) . '</span><h2>' . esc_html(self::t($title)) . '</h2>' . ($lead !== '' ? '<p>' . esc_html(self::t($lead)) . '</p>' : '') . '</div>';
  }

  public static function tab($tab) {
    if ($tab === 'about') { return self::about(); }
    if ($tab === 'notes') { return self::notes(); }
    return self::start();
  }

  private static function start() {
    $steps = [
      ['Clean up the menus', 'Put the left menu in your own order, give items clearer names and hide what your client never uses. Then do the same for the top bar, in the admin and on the website, and add your own links. All of it per role.', [['left', 'Left menu'], ['spacing', 'Spacing'], ['top', 'Top bar admin'], ['topfront', 'Top bar frontend']]],
      ['Make it look like yours', 'Pick one main color, add your logo to the menu and the login page, and use your own font.', [['colors', 'Colors'], ['menulogo', 'Logo'], ['loginlogo', 'Login logo'], ['fonts', 'Fonts']]],
      ['Calm the admin screens', 'Hide dashboard widgets and put plugin notices in one small box.', [['dash', 'Dashboard'], ['notices', 'Notices']]],
      ['Make content easier', 'Drag posts and pages into your own order, copy any post with one click, and allow SVG uploads only for the roles you trust.', [['order', 'Post ordering'], ['dup', 'Duplicate'], ['media', 'SVG uploads']]],
      ['Use it on your next site', 'Export your settings and import them on another website, so you only set it up once.', [['io', 'Export / import']]],
    ];
    $h = self::css() . '<div class="amh-card">' . self::intro('Get started', 'A clean admin in a few minutes', 'Five steps. Do them in this order, or only the ones you need.') . '<ol class="amh-steps">';
    foreach ($steps as $s) {
      $links = '';
      foreach ($s[2] as $l) { $links .= '<a href="' . esc_url(self::link($l[0])) . '">' . esc_html(self::t($l[1])) . ' &rarr;</a>'; }
      $h .= '<li><h3>' . esc_html(self::t($s[0])) . '</h3><p>' . esc_html(self::t($s[1])) . '</p>' . $links . '</li>';
    }
    $h .= '</ol><p class="amh-note">' . esc_html(self::t('Everything in Adminino is off until you switch it on, and a feature that is off loads nothing. Hidden menu items can still be opened through their URL, so hiding is not a security measure.')) . '</p></div>';
    return $h;
  }

  private static function about() {
    $p = [
      "Hey, I'm Rino. I build WordPress websites for clients, and every time I hand one over, the admin is a mess. A long menu full of things they never use, notices from every plugin, and a top bar they don't understand.",
      'I always used other plugins to clean that up. A combination of plugins, really. But none of them had exactly the options I wanted, so in the end I built my own.',
      'I wanted it to be easy, for me and for anyone using it. Every option shows you a preview of what it does, so you see the result before you switch it on. On a new site I don\'t have to think, I just look and click.',
      'And because I set up a lot of sites, there is an export. Set it up once, import it on the next website, and you are done.',
      'Let me be honest about what Adminino is not. It is not a security plugin. Hiding a menu item does not block the page behind it. Use roles and capabilities for that.',
      'I hope Adminino makes the admin of your clients feel a little calmer. That is really all it is supposed to do.',
    ];
    $h = self::css() . '<div class="amh-card">' . self::intro('About', 'The story behind Adminino') . '<img src="' . esc_url(self::img('rino-profile.jpg')) . '" alt="Rino de Boer" class="amh-photo" width="80" height="80">';
    foreach ($p as $x) { $h .= '<p>' . esc_html(self::t($x)) . '</p>'; }
    $h .= '<img src="' . esc_url(self::img('rino-signature.svg')) . '" alt="Rino" class="amh-sign" width="60" height="32" aria-hidden="true"></div>';
    return $h;
  }

  private static function notes() {
    $rel = [
      ['1.3.2', 'October 2026', ['A warning when the Admin Menu Organizer of ASE overrides the menu order.', 'Notices from other plugins now show below the Adminino header.', 'New Get started, About and Release notes pages.']],
      ['1.3.1', 'October 2026', ['The top bar lists save again.', 'Top bar per role: customize it for a role, or go back to the default bar.', 'Your own top bar links show for every logged-in user. Hide them per role with the eye.']],
      ['1.3.0', 'October 2026', ['The side panel is grouped by place: Left menu, Top bar and Look.', 'Separate top bar lists for the admin and the frontend.', 'Lucide icons for the left menu and the top bar, with a picker per item and your own SVG.', 'Spacing for the left menu, and double separators.', 'Your own admin font, loaded from your own website.', 'A floating save message instead of the notice bar.']],
      ['1.2.2', 'October 2026', ['Updates from GitHub work without a token.']],
      ['1.2.1', 'October 2026', ['New header bar, rounded corners for logos, and the new name: Adminino.']],
      ['1.2.0', 'October 2026', ['Own menu item, a new editor and many small improvements.']],
      ['1.1.0', 'September 2026', ['Colors, logos and top bar fixes.']],
      ['1.0.0', 'September 2026', ['First version.']],
    ];
    $h = self::css() . '<div class="amh-card">' . self::intro('Release notes', 'What is new in Adminino');
    foreach ($rel as $r) {
      $h .= '<div class="amh-rel"><div class="amh-rel-h"><span class="amh-ver">v' . esc_html($r[0]) . '</span><span class="amh-date">' . esc_html(self::t($r[1])) . '</span></div><ul class="amh-list">';
      foreach ($r[2] as $li) { $h .= '<li>' . esc_html(self::t($li)) . '</li>'; }
      $h .= '</ul></div>';
    }
    return $h . '</div>';
  }
}

}
