<?php
// php tests/abilities.test.php /path/to/test-wordpress/wp-load.php
// Real WordPress ability validation and scope resolution; all writes/network are fixtures.
if (empty($argv[1]) || !is_file($argv[1])) { fwrite(STDERR, "Pass a test WordPress wp-load.php path.\n"); exit(1); }
define('WP_INSTALLING', true); define('WP_ADMIN', true);
require $argv[1]; require ABSPATH . 'wp-admin/includes/admin.php';
wp_using_ext_object_cache(true); // Temporary inventory tickets stay in the process cache.
$options = [
  'mk_ame_settings' => ['top' => [['slug' => 'edit.php', 'title' => 'Blog', 'hidden' => 1], ['slug' => 'plugins.php', 'title' => 'Keep', 'hidden' => 0]], 'sub' => []],
  'mk_ame_menu_scopes' => ['roles' => ['author' => ['top' => [['slug' => 'edit.php', 'title' => 'Author blog', 'hidden' => 0]], 'sub' => []]], 'users' => []],
  'mk_ame_bar_settings' => ['lists' => ['' => [['slug' => 'comments', 'title' => 'Replies', 'hidden' => 0], ['slug' => 'updates', 'title' => 'Keep updates', 'hidden' => 1]]]],
  'mk_ame_bar_settings_front' => ['lists' => []], 'mk_ame_bar_roles' => [],
  'mk_ame_extras' => ['dash' => ['on' => 0, 'hide' => ['old-widget']], 'footer' => ['on' => 1, 'text' => 'Keep footer'], 'future' => ['untouched' => true]],
];
$writes = [];
add_filter('pre_option', function ($value, $key) use (&$options) { return $options[$key] ?? $value; }, 10, 2);
add_filter('pre_update_option', function ($value, $key, $old) use (&$options, &$writes) { $writes[] = $key; $options[$key] = $value; return $old; }, 999, 3);
$native = [
  ['id' => 'edit.php', 'parent' => '', 'label' => 'Posts', 'kind' => 'item', 'can_hide' => true, 'can_rename' => true],
  ['id' => 'edit.php', 'parent' => 'edit.php', 'label' => 'All Posts', 'kind' => 'item', 'can_hide' => true, 'can_rename' => true],
  ['id' => 'comments', 'parent' => '', 'label' => 'Comments', 'kind' => 'item', 'can_hide' => true, 'can_rename' => true],
  ['id' => 'my-account', 'parent' => 'top-secondary', 'label' => 'Profile', 'kind' => 'item', 'can_hide' => false, 'can_rename' => true],
  ['id' => 'mk-ame-link-shared', 'parent' => '', 'label' => 'Shared', 'kind' => 'custom_link', 'can_hide' => true, 'can_rename' => false],
  ['id' => 'new-widget', 'parent' => '', 'label' => 'Plugin widget', 'kind' => 'widget', 'can_hide' => true, 'can_rename' => false],
];
$http_failure = false; $concurrent = false;
add_filter('pre_http_request', function ($pre, $args, $url) use ($native, &$http_failure, &$concurrent, &$options) {
  if (!isset($args['headers']['X-Adminino-Inventory'])) { return new WP_Error('network_disabled', 'Unrelated test network disabled'); }
  if ($concurrent) { $options['mk_ame_settings']['top'][0]['title'] = 'Concurrent edit'; }
  if ($http_failure) { return new WP_Error('offline', 'Fixture failure'); }
  return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'data' => ['items' => $native]])];
}, 10, 3);
require __DIR__ . '/../admin-menu-manager.php';
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$GLOBALS['current_user'] = $admin;
$get = wp_get_ability('adminino/get-settings'); $list = wp_get_ability('adminino/list-items'); $update = wp_get_ability('adminino/update-settings');
$count = 0;
function expect_ability($ok, $label) { global $count; $count++; if (!$ok) { throw new RuntimeException($label); } }
function run_ability($ability, $input) { $result = $ability->execute($input); if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); } return $result; }
function rejected_ability($ability, $input) { return is_wp_error($ability->execute($input)); }
expect_ability($get && $list && $update, 'All three abilities register');
expect_ability(count(array_filter(wp_get_abilities(), function ($a) { return strpos($a->get_name(), 'adminino/') === 0; })) === 3, 'Exactly three Adminino abilities');
$state = run_ability($get, ['section' => 'left_menu', 'role' => 'editor']);
expect_ability($state['source']['type'] === 'default' && !$state['has_own_settings'], 'Role inheritance is explicit');
$items = run_ability($list, ['section' => 'left_menu']);
expect_ability($items['items'][0]['effective_label'] === 'Blog', 'Effective label includes saved override');
expect_ability($items['items'][1]['effective_hidden'], 'Child inherits hidden parent even when IDs repeat');
$preview = run_ability($update, ['section' => 'left_menu', 'role' => 'editor', 'changes' => [['id' => 'edit.php', 'hidden' => false]]]);
expect_ability($preview['dry_run'] && !$writes, 'Preview is the default and writes nothing');
expect_ability($preview['after']['source']['type'] === 'role', 'Preview reports new role override');
$applied = run_ability($update, ['section' => 'left_menu', 'role' => 'editor', 'changes' => [['id' => 'edit.php', 'hidden' => false]], 'dry_run' => false, 'expected_revision' => $preview['revision']]);
expect_ability(!$applied['dry_run'] && $applied['after']['config']['top'][0]['hidden'] === 0, 'Apply saves exact reviewed role patch');
expect_ability($options['mk_ame_settings']['top'][0]['hidden'] === 1, 'Role patch does not change shared menu');
expect_ability($options['mk_ame_menu_scopes']['roles']['editor']['top'][1]['title'] === 'Keep', 'Absent menu settings retained');
expect_ability($options['mk_ame_menu_scopes']['roles']['author']['top'][0]['title'] === 'Author blog', 'Other roles retained');
expect_ability(rejected_ability($update, ['section' => 'left_menu', 'role' => 'editor', 'changes' => [['id' => 'edit.php', 'hidden' => true]], 'dry_run' => false, 'expected_revision' => $preview['revision']]), 'Stale revisions rejected');
expect_ability(rejected_ability($update, ['section' => 'left_menu', 'changes' => [['id' => 'edit.php', 'hidden' => true]], 'dry_run' => false]), 'Apply requires reviewed revision');
$writes = [];
expect_ability(rejected_ability($update, ['section' => 'admin_bar', 'changes' => [['id' => 'comments', 'hidden' => true], ['id' => 'missing', 'hidden' => true]]]), 'Unknown items reject the entire batch');
expect_ability(!$writes, 'Invalid batch has no writes');
expect_ability(rejected_ability($update, ['section' => 'admin_bar', 'changes' => [['id' => 'my-account', 'parent' => 'top-secondary', 'hidden' => true]]]), 'Locked profile cannot be hidden');
expect_ability(rejected_ability($update, ['section' => 'admin_bar', 'changes' => [['id' => 'mk-ame-link-shared', 'title' => 'Changed']]]), 'Shared custom link titles cannot change by role');
expect_ability(rejected_ability($update, ['section' => 'admin_bar', 'changes' => [['id' => 'comments', 'hidden' => 'yes']]]), 'WordPress validates boolean input');
expect_ability(rejected_ability($get, ['section' => 'dashboard', 'role' => 'editor']), 'Unsupported dashboard scope rejected');
expect_ability(rejected_ability($get, ['section' => 'admin_bar', 'user_id' => $admin->ID]), 'Unsupported toolbar user scope rejected');
expect_ability(rejected_ability($get, ['section' => 'left_menu', 'role' => 'typo']), 'Role typos cannot become global writes');
expect_ability(rejected_ability($get, ['section' => 'left_menu', 'context' => 'front']), 'Unsupported frontend left menu rejected');
$bar = run_ability($update, ['section' => 'admin_bar', 'role' => 'editor', 'changes' => [['id' => 'comments', 'hidden' => true, 'title' => 'Feedback']]]);
run_ability($update, ['section' => 'admin_bar', 'role' => 'editor', 'changes' => [['id' => 'comments', 'hidden' => true, 'title' => 'Feedback']], 'dry_run' => false, 'expected_revision' => $bar['revision']]);
expect_ability($options['mk_ame_bar_roles']['admin']['editor']['lists'][''][1]['title'] === 'Keep updates', 'Absent toolbar choices retained');
expect_ability($options['mk_ame_bar_settings']['lists'][''][0]['title'] === 'Replies', 'Role toolbar change preserves shared bar');
$dash = run_ability($update, ['section' => 'dashboard', 'enabled' => true, 'changes' => [['id' => 'new-widget', 'hidden' => true]]]);
run_ability($update, ['section' => 'dashboard', 'enabled' => true, 'changes' => [['id' => 'new-widget', 'hidden' => true]], 'dry_run' => false, 'expected_revision' => $dash['revision']]);
expect_ability($options['mk_ame_extras']['dash']['on'] === 1 && $options['mk_ame_extras']['dash']['hide'] === ['old-widget', 'new-widget'], 'Dashboard patch keeps absent widgets');
expect_ability($options['mk_ame_extras']['footer']['text'] === 'Keep footer' && $options['mk_ame_extras']['future']['untouched'], 'Other extras and unrecognized settings preserved');
$http_failure = true; $writes = [];
expect_ability(rejected_ability($update, ['section' => 'left_menu', 'changes' => [['id' => 'edit.php', 'hidden' => false]]]) && !$writes, 'Inventory failures never write settings');
$http_failure = false; $concurrent = true;
expect_ability(rejected_ability($update, ['section' => 'left_menu', 'changes' => [['id' => 'edit.php', 'hidden' => false]]]), 'Concurrent inventory-time edits are detected');
$concurrent = false;
$uid = 999999;
wp_cache_set($uid, (object) ['ID' => $uid, 'user_login' => 'ability_fixture', 'display_name' => 'Fixture'], 'users');
add_filter('get_user_metadata', function ($value, $id, $key) use ($uid) { global $wpdb; return $id === $uid && $key === $wpdb->get_blog_prefix() . 'capabilities' ? [['author' => true, 'editor' => true]] : $value; }, 10, 3);
$user = run_ability($get, ['section' => 'left_menu', 'user_id' => $uid, 'role' => 'editor']);
expect_ability($user['source']['role'] === 'author', 'Multi-role users inherit the actual first configured role');
$GLOBALS['current_user'] = get_userdata($uid);
expect_ability(rejected_ability($get, ['section' => 'left_menu']) && rejected_ability($list, ['section' => 'dashboard']) && rejected_ability($update, ['section' => 'dashboard', 'enabled' => false]), 'All abilities refuse non-administrators');
$GLOBALS['current_user'] = $admin;
expect_ability(MK_AME_Abilities::inventory_user(0) === 0, 'Anonymous inventory cannot authenticate without a ticket');
$token = bin2hex(random_bytes(32)); $key = 'mk_ame_inventory_' . hash('sha256', $token);
set_transient($key, ['user_id' => $admin->ID, 'scope' => ['role' => 'editor', 'user' => 0, 'section' => 'left_menu', 'context' => 'admin']], 60);
$_REQUEST['action'] = 'mk_ame_inventory'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['HTTP_X_ADMININO_INVENTORY'] = $token;
expect_ability(MK_AME_Abilities::inventory_user(0) === $admin->ID && get_transient($key) === false, 'Read ticket uses issuer rights and is consumed');
$ticket = new ReflectionProperty('MK_AME_Abilities', 'ticket'); $ticket->setAccessible(true); $ticket->setValue(null, null);
expect_ability(MK_AME_Abilities::inventory_user(0) === 0, 'Consumed ticket cannot be replayed');
set_transient($key, ['user_id' => $uid, 'scope' => []], 60);
expect_ability(MK_AME_Abilities::inventory_user(0) === 0, 'Read ticket cannot elevate a non-admin issuer');
unset($_REQUEST['action'], $_SERVER['HTTP_X_ADMININO_INVENTORY']);
echo "PASS: $count ability checks\n";
