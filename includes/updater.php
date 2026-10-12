<?php
/** Public GitHub release updates. No token or wp-config.php setup is needed. */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_GitHub_Updater', false)) {

final class MK_GitHub_Updater {
  private $file;
  private $basename;
  private $repo;

  public function __construct($file, $repo) {
    $this->file = $file;
    $this->basename = plugin_basename($file);
    $this->repo = $repo;
    add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
    add_filter('plugins_api', [$this, 'info'], 10, 3);
    add_filter('upgrader_source_selection', [$this, 'fix_dir'], 10, 4);
    add_filter('plugin_action_links_' . $this->basename, [$this, 'links']);
    add_action('admin_post_mk_gh_check_' . md5($this->repo), [$this, 'force_check']);
  }

  private function key() { return 'mk_gh_public_release_' . md5($this->repo); }

  private function api($path) {
    $data = get_file_data($this->file, ['Version' => 'Version']);
    return wp_safe_remote_get('https://api.github.com/repos/' . $this->repo . $path, [
      'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 262144,
      'user-agent' => 'Adminino Updater/' . $data['Version'],
      'headers' => ['Accept' => 'application/vnd.github+json'], 'cookies' => [],
    ]);
  }

  // Cache published releases for six hours and failures for fifteen minutes.
  private function latest() {
    $cached = get_transient($this->key());
    if ($cached !== false) { return $cached ?: null; }
    $res = $this->api('/releases/latest');
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
      set_transient($this->key(), '', 15 * MINUTE_IN_SECONDS);
      return null;
    }
    $r = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($r) || !empty($r['draft']) || !empty($r['prerelease'])
      || !is_string($r['tag_name'] ?? null) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $r['tag_name'])) {
      set_transient($this->key(), '', 15 * MINUTE_IN_SECONDS); return null;
    }
    // Offer the exact release commit, instead of a movable tag or branch.
    $res = $this->api('/commits/' . rawurlencode($r['tag_name']));
    $commit = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200
      || !is_array($commit) || !is_string($commit['sha'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $commit['sha'])) {
      set_transient($this->key(), '', 15 * MINUTE_IN_SECONDS); return null;
    }
    $r['zipball_url'] = 'https://api.github.com/repos/' . $this->repo . '/zipball/' . $commit['sha'];
    $r['html_url'] = 'https://github.com/' . $this->repo . '/releases/tag/' . rawurlencode($r['tag_name']);
    set_transient($this->key(), $r, 6 * HOUR_IN_SECONDS);
    return $r;
  }

  public function check($transient) {
    if (!is_object($transient) || empty($transient->checked)) { return $transient; }
    $r = $this->latest();
    if (!$r) { return $transient; }
    $new = ltrim((string) $r['tag_name'], 'vV');
    $cur = isset($transient->checked[$this->basename]) ? $transient->checked[$this->basename] : '0';
    if (version_compare($new, $cur, '>')) {
      $transient->response[$this->basename] = (object) [
        'id' => $this->repo,
        'slug' => dirname($this->basename),
        'plugin' => $this->basename,
        'new_version' => $new,
        'url' => isset($r['html_url']) ? $r['html_url'] : '',
        'package' => isset($r['zipball_url']) ? $r['zipball_url'] : '',
      ];
    }
    return $transient;
  }

  public function info($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname($this->basename)) { return $result; }
    $r = $this->latest();
    if (!$r) { return $result; }
    $data = get_plugin_data($this->file, false, false);
    return (object) [
      'name' => $data['Name'],
      'slug' => dirname($this->basename),
      'version' => ltrim((string) $r['tag_name'], 'vV'),
      'author' => $data['AuthorName'],
      'homepage' => isset($r['html_url']) ? $r['html_url'] : '',
      'download_link' => isset($r['zipball_url']) ? $r['zipball_url'] : '',
      'sections' => ['description' => wp_kses_post($data['Description']), 'changelog' => isset($r['body']) ? nl2br(esc_html($r['body'])) : ''],
    ];
  }

  // GitHub names the unzipped folder owner-repo-sha. Rename it to the real plugin folder.
  public function fix_dir($source, $remote, $upgrader, $extra) {
    if (empty($extra['plugin']) || $extra['plugin'] !== $this->basename) { return $source; }
    if (is_wp_error($source)) { return $source; }
    global $wp_filesystem;
    $updates = get_site_transient('update_plugins');
    $offered = $updates->response[$this->basename] ?? null;
    $header = $wp_filesystem->get_contents(trailingslashit($source) . 'admin-menu-manager.php');
    if (!$offered || !is_string($header)
      || !preg_match('/^\s*\* Plugin Name:\s*Adminino\s*$/m', $header)
      || !preg_match('/^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$/m', $header, $version)
      || $version[1] !== ($offered->new_version ?? '')) {
      return new WP_Error('mk_gh_version', 'The downloaded Adminino plugin does not match the published release.');
    }
    $required_files = ['includes/design.php', 'includes/look.php', 'includes/extras.php', 'includes/ordering.php', 'includes/help.php', 'includes/updater.php', 'languages/nl.php', 'assets/lucide.json', 'assets/adminino-icon.svg', 'assets/adminino-sidebar.svg'];
    if (version_compare($version[1], '1.3.5', '>=')) { $required_files[] = 'includes/abilities.php'; }
    foreach ($required_files as $required) {
      if (!$wp_filesystem->is_file(trailingslashit($source) . $required)) {
        return new WP_Error('mk_gh_incomplete', 'The published Adminino release is missing required files.');
      }
    }
    $target = trailingslashit(dirname(untrailingslashit($source))) . dirname($this->basename) . '/';
    if (untrailingslashit($source) === untrailingslashit($target)) { return $source; }
    return $wp_filesystem->move($source, $target, true) ? $target : new WP_Error('mk_gh_rename', 'Could not rename the update folder.');
  }

  public function links($links) {
    $url = wp_nonce_url(admin_url('admin-post.php?action=mk_gh_check_' . md5($this->repo)), 'mk_gh_check');
    $links[] = '<a href="' . esc_url($url) . '">Check for updates</a>';
    return $links;
  }

  public function force_check() {
    if (!current_user_can('update_plugins')) { wp_die('Access denied.'); }
    check_admin_referer('mk_gh_check');
    delete_transient($this->key());
    delete_site_transient('update_plugins');
    wp_safe_redirect(admin_url('update-core.php?force-check=1'));
    exit;
  }
}

}
