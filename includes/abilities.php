<?php
/** Optional WordPress abilities for Adminino navigation and dashboard settings. */
if (!defined('ABSPATH')) { exit; }

final class MK_AME_Abilities {
  private static $ticket = null;

  public static function init() {
    add_action('wp_abilities_api_init', [__CLASS__, 'register']);
    add_filter('determine_current_user', [__CLASS__, 'inventory_user'], 5);
    add_action('plugins_loaded', [__CLASS__, 'inventory_auth'], -9999);
    add_action('admin_post_mk_ame_inventory', [__CLASS__, 'inventory_endpoint']);
    add_action('admin_post_nopriv_mk_ame_inventory', [__CLASS__, 'inventory_endpoint']);
  }

  public static function allowed() { return current_user_can('manage_options'); }

  public static function register() {
    if (!function_exists('wp_register_ability')) { return; }
    $properties = [
      'section' => ['type' => 'string', 'enum' => ['left_menu', 'admin_bar', 'dashboard']],
      'role' => ['type' => 'string', 'default' => 'all', 'description' => 'Role slug, or all for the shared default. Dashboard settings are global.'],
      'user_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Optional user override for left_menu only.'],
      'context' => ['type' => 'string', 'enum' => ['admin', 'front'], 'default' => 'admin', 'description' => 'Use front for the frontend admin_bar; its native inventory uses the homepage.'],
    ];
    $schema = ['type' => 'object', 'properties' => $properties, 'required' => ['section'], 'additionalProperties' => false];
    $descriptions = [
      'get-settings' => 'Read Adminino navigation or dashboard settings, their actual inheritance source and a revision for reviewed changes. Supports left_menu per role/user, admin_bar per role and admin/front context, and the global dashboard. Requires manage_options.',
      'list-items' => 'List native menu or dashboard item IDs and parent IDs for the requested scope, with effective labels, visibility and supported changes. Use these exact IDs in update-settings; do not guess them. Frontend toolbar items are based on the homepage. Requires manage_options.',
      'update-settings' => 'Patch only specified Adminino item hidden/title choices, preserving all other settings. Preview with dry_run=true (default), then apply with dry_run=false and the returned revision as expected_revision. Enable dashboard hiding explicitly with enabled=true. Does not change WordPress permissions, reorder items, create/delete custom links, or rename shared custom links. Requires manage_options.',
    ];
    foreach ($descriptions as $name => $description) {
      $input = $schema; $write = $name === 'update-settings';
      if ($write) {
        $input['properties'] += [
          'changes' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
            'parent' => ['type' => 'string', 'default' => '', 'maxLength' => 300],
            'hidden' => ['type' => 'boolean'],
            'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'A label override; an empty string restores the native label.'],
          ], 'required' => ['id'], 'additionalProperties' => false]],
          'enabled' => ['type' => 'boolean', 'description' => 'Dashboard hiding on/off; valid only for dashboard.'],
          'dry_run' => ['type' => 'boolean', 'default' => true],
          'expected_revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Required when applying; use the revision returned by the preview.'],
        ];
      }
      wp_register_ability('adminino/' . $name, [
        'label' => 'Adminino: ' . ucwords(str_replace('-', ' ', $name)),
        'description' => $description, 'category' => 'site',
        'input_schema' => $input, 'output_schema' => ['type' => 'object'],
        'execute_callback' => [__CLASS__, str_replace('-', '_', $name)],
        'permission_callback' => [__CLASS__, 'allowed'],
        'meta' => ['public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => !$write, 'destructive' => $write, 'idempotent' => true]],
      ]);
    }
  }

  private static function prepare($input) {
    if (!self::allowed()) { return new WP_Error('adminino_access_denied', 'Adminino settings require manage_options.'); }
    $section = $input['section'] ?? '';
    $role = $input['role'] ?? 'all'; $user_id = $input['user_id'] ?? 0; $ctx = $input['context'] ?? 'admin';
    if (!in_array($section, ['left_menu', 'admin_bar', 'dashboard'], true) || !in_array($ctx, ['admin', 'front'], true)) { return new WP_Error('adminino_scope', 'Unknown section or context.'); }
    if ($role !== 'all' && !get_role($role)) { return new WP_Error('adminino_scope', 'Unknown role.'); }
    if ($section !== 'admin_bar' && $ctx !== 'admin') { return new WP_Error('adminino_scope', 'The front context is only available for admin_bar.'); }
    if ($section !== 'left_menu' && $user_id) { return new WP_Error('adminino_scope', 'User overrides are only available for left_menu.'); }
    if ($section === 'dashboard' && $role !== 'all') { return new WP_Error('adminino_scope', 'Dashboard settings are global; use role all.'); }
    if ($user_id) {
      $user = get_userdata($user_id);
      if (!$user || !$user->roles) { return new WP_Error('adminino_scope', 'Unknown user or user without a role.'); }
      if ($role === 'all') { $role = array_values($user->roles)[0]; }
      if (!in_array($role, $user->roles, true)) { return new WP_Error('adminino_scope', 'The user does not have the selected role.'); }
    }
    return ['role' => $role, 'user' => $user_id, 'section' => $section, 'context' => $ctx];
  }

  private static function settings($scope) {
    $state = MK_Admin_Menu::automation_settings($scope, $scope['section'], $scope['context']);
    $state['revision'] = hash('sha256', wp_json_encode($state));
    return $state;
  }

  public static function get_settings($input) {
    $scope = self::prepare($input);
    return is_wp_error($scope) ? $scope : self::settings($scope);
  }

  // A single-use read ticket lets the native admin request run with the caller's
  // existing rights. It creates no login session and is never returned to the client.
  public static function inventory_user($user_id) {
    if (($_REQUEST['action'] ?? '') !== 'mk_ame_inventory' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return $user_id; }
    if (self::$ticket !== null) { return self::$ticket['user_id']; }
    $token = $_SERVER['HTTP_X_ADMININO_INVENTORY'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) { return $user_id; }
    $key = 'mk_ame_inventory_' . hash('sha256', $token);
    $ticket = get_transient($key);
    if (!is_array($ticket) || empty($ticket['user_id']) || !user_can($ticket['user_id'], 'manage_options')) { return $user_id; }
    delete_transient($key);
    self::$ticket = $ticket;
    return $ticket['user_id'];
  }

  public static function inventory_auth() {
    $user_id = self::inventory_user(0);
    if (self::$ticket !== null) { wp_set_current_user($user_id); }
  }

  public static function inventory_endpoint() {
    if (self::$ticket === null || !self::allowed()) { wp_send_json_error([], 403); }
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    try {
      $scope = self::$ticket['scope'];
      $items = MK_Admin_Menu::automation_inventory($scope, $scope['section'], $scope['context']);
    } catch (Throwable $error) { wp_send_json_error([], 500); }
    wp_send_json_success(['items' => $items]);
  }

  private static function inventory($scope) {
    $token = bin2hex(random_bytes(32)); $key = 'mk_ame_inventory_' . hash('sha256', $token);
    set_transient($key, ['user_id' => get_current_user_id(), 'scope' => $scope], 60);
    try {
      $response = wp_safe_remote_post(admin_url('admin-post.php'), [
        'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 1048576,
        'headers' => ['X-Adminino-Inventory' => $token], 'body' => ['action' => 'mk_ame_inventory'], 'cookies' => [],
      ]);
    } finally { delete_transient($key); }
    if (is_wp_error($response)) { return new WP_Error('adminino_inventory_unavailable', 'The native admin inventory could not be loaded. Check whether the site allows loopback HTTP requests.'); }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response) !== 200 || empty($data['success']) || !isset($data['data']['items']) || !is_array($data['data']['items'])) {
      return new WP_Error('adminino_inventory_unavailable', 'The native admin inventory could not be loaded. No settings were changed.');
    }
    return $data['data']['items'];
  }

  private static function item_setting($config, $section, $item) {
    if ($section === 'dashboard') { return ['title' => '', 'hidden' => !empty($config['on']) && in_array($item['id'], $config['hide'], true)]; }
    $rows = $section === 'admin_bar' ? ($config['lists'][$item['parent']] ?? []) : ($item['parent'] === '' ? $config['top'] : ($config['sub'][$item['parent']] ?? []));
    foreach ($rows as $row) { if ($row['slug'] === $item['id']) { return ['title' => $row['title'], 'hidden' => !empty($row['hidden'])]; } }
    return ['title' => '', 'hidden' => false];
  }

  public static function list_items($input) {
    $scope = self::prepare($input);
    if (is_wp_error($scope)) { return $scope; }
    $items = self::inventory($scope);
    if (is_wp_error($items)) { return $items; }
    $state = self::settings($scope); $by_id = [];
    foreach ($items as &$item) {
      $setting = self::item_setting($state['config'], $scope['section'], $item);
      $item['title'] = $setting['title'];
      $item['hidden'] = $item['can_hide'] && $setting['hidden'];
      $item['effective_label'] = $setting['title'] !== '' ? $setting['title'] : $item['label'];
      $item['effective_hidden'] = $item['hidden'];
      if (!isset($by_id[$item['id']]) || $item['parent'] === '') { $by_id[$item['id']] = $item; }
    }
    unset($item);
    foreach ($items as &$item) {
      $parent = $item['parent']; $visited = [];
      while ($parent !== '' && isset($by_id[$parent]) && !isset($visited[$parent])) {
        $visited[$parent] = true;
        if ($by_id[$parent]['hidden']) { $item['effective_hidden'] = true; break; }
        $parent = $by_id[$parent]['parent'];
      }
    }
    unset($item);
    return ['settings' => $state, 'items' => $items, 'basis' => $scope['section'] === 'admin_bar' && $scope['context'] === 'front' ? 'homepage' : 'native_admin'];
  }

  public static function update_settings($input) {
    $scope = self::prepare($input);
    if (is_wp_error($scope)) { return $scope; }
    $changes = $input['changes'] ?? [];
    if (!$changes && !array_key_exists('enabled', $input)) { return new WP_Error('adminino_changes', 'Provide at least one item change or dashboard enabled setting.'); }
    if (array_key_exists('enabled', $input) && $scope['section'] !== 'dashboard') { return new WP_Error('adminino_changes', 'enabled is only available for dashboard.'); }
    $dry = $input['dry_run'] ?? true;
    if (!$dry && empty($input['expected_revision'])) { return new WP_Error('adminino_revision_required', 'Preview the changes first and pass its revision as expected_revision.'); }
    $before = self::settings($scope);
    if (isset($input['expected_revision']) && !hash_equals($before['revision'], $input['expected_revision'])) { return new WP_Error('adminino_stale_revision', 'These settings changed since the preview. Read them again before applying.'); }
    $items = $changes ? self::inventory($scope) : [];
    if (is_wp_error($items)) { return $items; }
    $available = [];
    foreach ($items as $item) { $available[$item['parent'] . "\0" . $item['id']] = $item; }
    $config = $before['config']; $seen = [];
    foreach ($changes as $change) {
      $parent = $change['parent'] ?? ''; $key = $parent . "\0" . $change['id'];
      if (!isset($available[$key]) || isset($seen[$key])) { return new WP_Error('adminino_item', 'Unknown or duplicate item/parent combination. Use list-items for this scope.'); }
      $item = $available[$key]; $seen[$key] = true;
      if (!array_key_exists('hidden', $change) && !array_key_exists('title', $change)) { return new WP_Error('adminino_changes', 'Each item change needs hidden or title.'); }
      if (array_key_exists('hidden', $change) && !$item['can_hide']) { return new WP_Error('adminino_locked_item', 'This item cannot be hidden or shown through Adminino.'); }
      if (array_key_exists('title', $change) && !$item['can_rename']) { return new WP_Error('adminino_locked_item', 'This item cannot be renamed. Shared custom link titles must be edited in the UI.'); }
      if ($scope['section'] === 'dashboard') {
        $config['hide'] = array_values(array_diff($config['hide'], [$change['id']]));
        if ($change['hidden']) { $config['hide'][] = $change['id']; }
        continue;
      }
      if ($scope['section'] === 'admin_bar') { $rows =& $config['lists'][$parent]; }
      elseif ($parent === '') { $rows =& $config['top']; }
      else { $rows =& $config['sub'][$parent]; }
      if (!is_array($rows)) { $rows = []; }
      $index = null;
      foreach ($rows as $i => $row) { if ($row['slug'] === $change['id']) { $index = $i; break; } }
      if ($index === null) { $rows[] = ['slug' => $change['id'], 'title' => '', 'hidden' => 0]; $index = array_key_last($rows); }
      if (array_key_exists('title', $change)) { $rows[$index]['title'] = sanitize_text_field($change['title']); }
      if (array_key_exists('hidden', $change)) { $rows[$index]['hidden'] = $change['hidden'] ? 1 : 0; }
      unset($rows);
    }
    if (array_key_exists('enabled', $input)) { $config['on'] = $input['enabled'] ? 1 : 0; }
    // Inventory discovery can take time; refuse to overwrite a concurrent edit.
    if (!hash_equals($before['revision'], self::settings($scope)['revision'])) { return new WP_Error('adminino_stale_revision', 'These settings changed while loading the inventory. Preview again.'); }
    $changed = $config !== $before['config'] || !$before['has_own_settings'];
    $after = $before; $after['config'] = $config;
    if ($changed && $scope['section'] !== 'dashboard') {
      $after['has_own_settings'] = true;
      $after['source'] = ['type' => $scope['user'] ? 'user' : ($scope['role'] === 'all' ? 'default' : 'role'), 'role' => $scope['role'], 'user_id' => $scope['user']];
    }
    unset($after['revision']); $after['revision'] = hash('sha256', wp_json_encode($after));
    if (!$dry && $changed) {
      MK_Admin_Menu::automation_save($scope, $scope['section'], $scope['context'], $config);
      $saved = self::settings($scope);
      if ($saved['config'] !== $config || !$saved['has_own_settings']) { return new WP_Error('adminino_write_failed', 'The settings could not be saved or verified. Read them again.'); }
      $after = $saved;
    }
    return ['dry_run' => $dry, 'changed' => $changed, 'revision' => $before['revision'], 'before' => $before, 'after' => $after];
  }
}
