# The app skeleton

Every companion app needs three files at its root: a plugin bootstrap,
a manifest, and (optionally) a composer.json. None of them contain
business logic — they exist to register the app with WordPress, the
ExamplePress kernel, and Blockstudio.

## `{slug}.php` — plugin bootstrap

```php
<?php
/**
 * Plugin Name: {Human readable name}
 * Description: {One-sentence description, must match the manifest.}
 * Version:     1.0.0
 * Theme:       {{theme_slug}}
 * Text Domain: {slug}
 * Requires at least: 6.9
 * Requires PHP: 8.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Route origin: claim a namespace + URL conditions ────────────
//
// The kernel router walks every registered origin in priority order
// (lower = wins). Each entry maps a route slug to a callable that
// returns true when the current request matches. The router then
// dispatches to the block "{namespace}/{prefix}-{slug}" — by default
// "{slug}/template-{slug}" (the prefix can be overridden, see below).
if ( function_exists( 'examplepress_register_route_origin' ) ) {
    examplepress_register_route_origin( '{slug}', [
        'front'   => fn() => is_front_page() || is_home(),
        'single'  => fn() => is_singular(),
        'archive' => fn() => is_archive(),
        'search'  => fn() => is_search(),
        '404'     => fn() => is_404(),
    ], 10 );
}

// ── Blockstudio: scan the app/ directory for blocks ─────────────
//
// This MUST be called inside the `init` action so Blockstudio is loaded.
// The dir setting tells Blockstudio where to recursively look for
// block.json files. Convention is `app/` at the plugin root.
add_action( 'init', function () {
    if ( ! class_exists( 'Blockstudio\\Build' ) ) {
        return;
    }
    Blockstudio\Build::init( [
        'dir' => plugin_dir_path( __FILE__ ) . 'app',
    ] );
} );
```

That's the entire bootstrap. No `register_activation_hook`, no class
definitions, no admin pages. Real logic lives in the blocks under
`app/`.

## When to skip the route origin

If your app only adds reusable components meant to be inserted via
the block editor or composed inside other apps' templates, you do NOT
need to register a route origin. The bootstrap drops the
`examplepress_register_route_origin(...)` block and only keeps the
`Build::init` call. The blocks are still registered and available in
the inserter.

## `examplepress.json` — manifest

The kernel's `AppValidator` reads this before allowing the plugin to
load. The required top-level keys are `name`, `slug`, and `description`;
everything else is convention.

```json
{
    "$schema": "https://www.examplepress.com/schema/app",
    "name": "Human Readable Name",
    "slug": "{slug}",
    "description": "One sentence describing what the app does.",
    "version": "1.0.0",
    "supports_ai_iteration": true,
    "updater": {
        "github_repo": "",
        "asset_filename": "{slug}.zip",
        "default_channel": "stable",
        "requires_wp": "6.9",
        "requires_php": "8.4"
    },
    "routing": {
        "priority": 10,
        "routes": {
            "{slug}": {
                "urls": ["/app/{slug}/"],
                "desc": "{Human readable description of this route}"
            }
        }
    }
}
```

### Field rules

| Field | Required | Notes |
|---|---|---|
| `name` | yes | Used in admin lists. |
| `slug` | yes | Must match `[a-z0-9-]+`. Must equal the plugin directory name AND the bootstrap file name (without `.php`). |
| `description` | yes | One sentence. Surfaced in admin. |
| `version` | yes | Strict semver. The kernel uses this for the GitHub release tag. |
| `supports_ai_iteration` | yes | Always `true` for AI-generated apps. The eject UI flow flips it to `false`. |
| `updater.github_repo` | no | Filled in by the kernel after the first push. Leave empty in generated output. |
| `updater.asset_filename` | yes | `{slug}.zip` — the release asset filename the kernel will download. |
| `updater.requires_wp` / `requires_php` | yes | Use `6.9` / `8.4` unless you have a reason. |
| `routing.priority` | no | Lower wins when multiple apps claim the same conditions. Default `10`. |
| `routing.routes` | no | Documentation map of slug → URL pattern + description. The actual route conditions live in the bootstrap; this block exists so admins can see what URLs the app claims. |

### Hard rules

- The `slug` MUST match the directory name. Slug `staff-directory` →
  directory `staff-directory/` → bootstrap `staff-directory.php` →
  bootstrap `Text Domain: staff-directory` → manifest
  `"slug": "staff-directory"`. The `AppValidator` rejects mismatches.
- The `Text Domain:` header in the bootstrap MUST equal the slug.
  Every `__()`/`esc_html__()`/`esc_html_e()` call in the app uses
  this exact string as its second argument.
- Never set `supports_ai_iteration: false` in a generation. That field
  flips only via the eject flow.
- Never include `troy.*` keys with values. The kernel populates them
  after install. Generated output may include the empty stub block.
- Never include a `permissions` array unless the user explicitly asks
  for elevated permissions — the kernel's banned-list will reject most
  of them.

## `composer.json` — optional

Only ship this if your app has actual composer dependencies. The
ExamplePress kernel does NOT require it. If you ship one, the minimal
shape is:

```json
{
    "name": "{slug}/{slug}",
    "description": "{Same description as the manifest}",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=8.4"
    }
}
```
