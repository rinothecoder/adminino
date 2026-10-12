<?php
// Run with: php tests/dashboard.test.php /path/to/wordpress/wp-load.php
// Exercises real WordPress hooks, while intercepting every Adminino option write.
if (empty($argv[1]) || !is_file($argv[1])) {
  fwrite(STDERR, "Pass the path to a test WordPress installation's wp-load.php.\n");
  exit(1);
}
define('WP_INSTALLING', true);
define('WP_ADMIN', true);
require $argv[1];
require ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('dashboard');
$settings = ['dash' => ['on' => 0, 'hide' => ['inquirino-dashboard']]];
$seen = [];
add_filter('pre_option_mk_ame_extras', function () use (&$settings) { return $settings; });
add_filter('pre_option_mk_ame_dash_seen', function () use (&$seen) { return $seen; });
add_filter('pre_update_option', function ($value, $option, $old) use (&$seen) {
  if ($option === 'mk_ame_dash_seen') { $seen = $value; }
  return $old;
}, 999, 3);
require __DIR__ . '/../admin-menu-manager.php';
$count = 0;
function expect_dashboard($ok, $message) {
  global $count;
  $count++;
  if (!$ok) { throw new RuntimeException($message); }
}
$GLOBALS['wp_meta_boxes'] = [];
add_action('wp_dashboard_setup', function () {
  add_meta_box('inquirino-dashboard', '<b>Inquirino</b> Leads', '__return_empty_string', 'dashboard', 'normal');
  add_meta_box('removed-widget', 'Removed', '__return_empty_string', 'dashboard', 'side');
  remove_meta_box('removed-widget', 'dashboard', 'side');
}, 1000);
add_action('admin_head-index.php', function () {
  add_meta_box('late-plugin-widget', 'Late plugin widget', '__return_empty_string', 'dashboard', 'side');
}, 1000);
do_action('wp_dashboard_setup');
expect_dashboard(($seen['inquirino-dashboard'] ?? '') === 'Inquirino Leads', 'Discover plugin widgets while hiding is off');
expect_dashboard(!isset($seen['removed-widget']), 'Do not discover removed widgets');
do_action('admin_head-index.php');
expect_dashboard(isset($seen['late-plugin-widget']), 'Discover widgets registered late in dashboard setup');
expect_dashboard(is_array($GLOBALS['wp_meta_boxes']['dashboard']['normal']['default']['inquirino-dashboard']), 'Discovery does not hide widgets while off');
$settings['dash']['on'] = 1;
do_action('admin_head-index.php');
expect_dashboard($GLOBALS['wp_meta_boxes']['dashboard']['normal']['default']['inquirino-dashboard'] === false, 'Configured plugin widget is hidden');
expect_dashboard(isset($seen['inquirino-dashboard']), 'Hidden plugin widgets remain selectable');
expect_dashboard(is_array($GLOBALS['wp_meta_boxes']['dashboard']['side']['default']['late-plugin-widget']), 'Unselected plugin widget remains visible');
$inventory = new ReflectionMethod('MK_AME_Extras', 'dashboard_widgets');
$inventory->setAccessible(true);
expect_dashboard(($inventory->invoke(null)['inquirino-dashboard'] ?? '') === 'Inquirino Leads', 'Plugin title reaches settings inventory');
echo "PASS: $count dashboard checks\n";
