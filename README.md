# Admin Menu Manager

Controls the order, names and visibility of the WordPress admin menu (left) and the admin bar (top).

Settings live under **Settings > Menu Editor**.

## Features

- Reorder, rename and hide items in the left admin menu
- Reorder, rename and hide items in the top admin bar, including icon-only mode
- Add custom links to the admin bar (URL or admin page)
- Add separators
- Reset each editor back to the WordPress default
- English and Dutch interface, picked from the site language

## Languages

The interface is Dutch when the WordPress locale starts with `nl`, otherwise English. Dutch strings live in `languages/nl.php`.

To force a language, use the `mk_ame_locale` filter:

```php
add_filter('mk_ame_locale', fn() => 'en_US');
```

## Updates from GitHub

This repo is private. Sites get updates through the built-in updater in `includes/updater.php`.

1. Create a fine-grained GitHub token with read-only **Contents** access to this repo.
2. Add it to `wp-config.php` on every site that should get updates:

   ```php
   define('MK_GITHUB_TOKEN', 'github_pat_...');
   ```

3. Without the constant the updater does nothing and makes no requests.

The plugin row gets a **Check for updates** link to skip the 6 hour cache.

## Releasing a new version

1. Bump `Version:` in the header of `admin-menu-manager.php`.
2. Commit and push.
3. Create a GitHub Release with a tag higher than the old version, e.g. `v1.0.1`. The release notes show up as the changelog in WordPress.

The updater renames GitHub's zip folder back to `admin-menu-manager`, so the plugin folder stays the same on every site.

## Moving from the snippet version

If the old Fluent Snippets version is still active, the plugin shows a notice and stays inactive. Turn off the snippet and it takes over.

## Stored options

- `mk_ame_settings` left menu
- `mk_ame_bar_settings` admin bar
- `mk_ame_bar_seen` admin bar items seen so far
- `mk_ame_bar_custom` custom admin bar links

## Requirements

- WordPress with PHP 7.4 or higher

## Author

Rino de Boer
