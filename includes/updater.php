<?php
/**
 * Minimal GitHub release updater.
 *
 * The repository is public, so no setup is needed: every site checks the latest GitHub release.
 * Only if the repository is ever made private again, add this line to wp-config.php on every site:
 *   define('MK_GITHUB_TOKEN', 'github_pat_...');   // read-only token, Contents: read
 *
 * A release is a git tag such as v1.0.1 (GitHub "Release"). The tag must be higher than the Version header.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MK_GitHub_Updater', false)) {

final class MK_GitHub_Updater {
  private $file;
  private $basename;
  private $repo;
  private $token;

  public function __construct($file, $repo) {
    $this->file = $file;
    $this->basename = plugin_basename($file);
    $this->repo = $repo;
    $this->token = defined('MK_GITHUB_TOKEN') ? (string) MK_GITHUB_TOKEN : '';
    add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
    add_filter('plugins_api', [$this, 'info'], 10, 3);
    add_filter('http_request_args', [$this, 'auth'], 10, 2);
    add_filter('upgrader_source_selection', [$this, 'fix_dir'], 10, 4);
    add_filter('plugin_action_links_' . $this->basename, [$this, 'links']);
    add_action('admin_post_mk_gh_check_' . md5($this->repo), [$this, 'force_check']);
  }

  private function key() { return 'mk_gh_release_' . md5($this->repo); }

  private function api($path) {
    $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version')];
    if ($this->token !== '') { $headers['Authorization'] = 'Bearer ' . $this->token; }
    return wp_remote_get('https://api.github.com/repos/' . $this->repo . $path, ['timeout' => 15, 'headers' => $headers]);
  }

  // Latest release, cached for 6 hours (failures for 1 hour so a bad token does not hammer GitHub).
  private function latest() {
    $cached = get_transient($this->key());
    if ($cached !== false) { return $cached ?: null; }
    $res = $this->api('/releases/latest');
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
      set_transient($this->key(), '', HOUR_IN_SECONDS);
      return null;
    }
    $r = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($r) || empty($r['tag_name'])) { set_transient($this->key(), '', HOUR_IN_SECONDS); return null; }
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

  // GitHub zipball downloads of a private repo need the token. A public repo needs none.
  public function auth($args, $url) {
    if ($this->token !== '' && strpos($url, 'https://api.github.com/repos/' . $this->repo . '/zipball') === 0) {
      $args['headers']['Authorization'] = 'Bearer ' . $this->token;
      $args['headers']['Accept'] = 'application/vnd.github+json';
    }
    return $args;
  }

  // GitHub names the unzipped folder owner-repo-sha. Rename it to the real plugin folder.
  public function fix_dir($source, $remote, $upgrader, $extra) {
    if (empty($extra['plugin']) || $extra['plugin'] !== $this->basename) { return $source; }
    global $wp_filesystem;
    $target = trailingslashit(dirname($source)) . dirname($this->basename) . '/';
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
