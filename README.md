# ExamplePress MU

A self-updating WordPress MU plugin that serves as the platform kernel for the ExamplePress ecosystem. Provides governance, routing, feature management, admin UI, companion app lifecycle, REST APIs, and a Vite-powered build pipeline.

## Architecture

All PHP lives under the `ExamplePress\MU` namespace with PSR-4 autoloading. Zero procedural functions in the global namespace.

```
mu-plugins/
├── examplepress-mu.php              # Loader + shared kernel installer (cold-start + self-upgrade). Version header is the source of truth.
├── examplepress.json                # Master platform configuration (source of truth)
├── package.json                     # npm: vite, nanostores
├── vite.config.js                   # 10 entry points → examplepress-mu/dist/
└── examplepress-mu/                 # Platform kernel
    ├── bootstrap.php                # Constants, Composer autoloader, Kernel::boot()
    ├── composer.json                # PSR-4: ExamplePress\MU\ → src/
    ├── src/
    │   ├── Kernel.php               # Single boot entry point
    │   ├── Config/
    │   │   ├── ConfigManager.php    # Merges MU + theme examplepress.json configs
    │   │   ├── FeatureRegistry.php  # Filterable feature flag system
    │   │   └── DependencyManager.php # Aggregates deps from MU + companion apps
    │   ├── Governance/
    │   │   ├── PlatformPolicy.php   # Permalinks, managed options, opt-in cap stripping
    │   │   ├── AppValidator.php     # Zero-trust plugin validation (single + multisite)
    │   │   └── EditorGuard.php      # FSE lockdown (gated by editor-guard feature)
    │   ├── Infrastructure/
    │   │   ├── AppDiscovery.php     # Scans plugins for examplepress.json
    │   │   ├── AppRegistry.php      # ep_app CPT + CRUD + merged queries + destroy
    │   │   ├── GitHub.php           # GitHub App auth, repo creation, scaffold push
    │   │   ├── Scaffolder.php       # Template repo scaffolding (Git Database API)
    │   │   ├── Updater.php          # WP-Cron self-updater with atomic swap + rollback
    │   │   ├── ThemeUpdateProvider.php # GitHub-backed theme update pipeline (absorbed the former examplepress-theme-update companion)
    │   │   ├── CliCommand.php       # WP-CLI: wp examplepress init
    │   │   ├── RouteRegistry.php    # Multi-origin route registration
    │   │   ├── Router.php           # Template dispatch (namespaced, no redeclaration)
    │   │   ├── PluginManager.php    # Updater/demo install + daily-cron stale cleanup
    │   │   ├── Notifications.php    # System warning aggregation + archive REST
    │   │   └── Helpers.php          # Filesystem utilities (hardened, force-direct mode)
    │   ├── API/
    │   │   ├── CompanionPluginController.php # Shared base for demo controller (and any future companion plugin controllers)
    │   │   ├── ThemeUpdateController.php     # REST surface for ThemeUpdateProvider (/theme-update/*)
    │   │   ├── AppsController.php       # App CRUD, scaffold, connect, destroy, health, GET /apps
    │   │   ├── ConnectionsController.php # GitHub/Troy settings, tests, OAuth callbacks
    │   │   ├── DemoController.php       # Demo plugin lifecycle (subclass of CompanionPluginController)
    │   │   └── FilesystemController.php # In-browser editor: tree, read, write
    │   └── Admin/
    │       ├── MenuManager.php      # Top-level menu + submenu registration
    │       ├── AssetManager.php     # Vite manifest reader + script/style enqueue
    │       ├── PageController.php   # HTML shell + template includes
    │       ├── DataProvider.php     # window.ExamplePressData localization
    │       └── Templates/           # Per-page HTML skeletons (tabs, panels, modals)
    │           ├── apps.php
    │           ├── theme.php
    │           ├── navigation.php
    │           ├── dependencies.php
    │           ├── library.php
    │           ├── settings.php
    │           ├── notifications.php
    │           ├── system.php
    │           ├── docs.php
    │           └── editor.php
    └── assets/
        └── src/                     # Vite entry points (JS + CSS per admin page)
            ├── apps/
            ├── theme/
            ├── navigation/
            ├── dependencies/
            ├── library/
            ├── settings/
            ├── notifications/
            ├── system/
            ├── docs/
            ├── editor/
            ├── css/                 # admin-settings.css, login.css, base.css
            ├── lib/                 # Shared JS (api, tabs, modal, dom, datatable)
            └── stores/              # Nanostores state (apps, connections, notifications)
```

## How It Works

1. WordPress loads `examplepress-mu.php` from `mu-plugins/`. If the kernel is missing, it downloads the latest release from GitHub.
2. `bootstrap.php` defines constants, loads the Composer autoloader (with PSR-4 fallback), and calls `Kernel::boot()`.
3. The Kernel initializes all subsystems:

**Governance** (MU-enforced, each layer is filterable):
- **PlatformPolicy** — enforces `/%postname%/` (opt-out via `examplepress_mu_enforce_permalinks`), defines `DISALLOW_FILE_EDIT` (opt-out via `examplepress_mu_disallow_file_edit`), locks managed options, and only registers a `user_has_cap` filter when `examplepress_mu_stripped_capabilities` returns a non-empty list
- **AppValidator** — hooks both `option_active_plugins` and `site_option_active_sitewide_plugins`, validates manifest + required fields + filterable banned-permissions list, with a final `examplepress_mu_validate_app` escape hatch and a `flushCache()` for refresh actions
- **EditorGuard** — Site Editor lockdown gated by the `editor-guard` feature flag (default on); also bypassed by `EP_DEV_MODE` or the `examplepress_mu_bypass_editor_guard` filter

**Config**:
- **ConfigManager** — deep-merges the MU plugin's `examplepress.json` (infrastructure baseline) with the theme's `examplepress.json` (design tokens, Blockstudio settings). Theme values win.
- **FeatureRegistry** — toggleable features via `examplepress_mu_feature_{id}` filters. Resolution: PHP filter > JSON config > registration default.
- **DependencyManager** — aggregates dependencies from both the MU config and active companion apps

**Infrastructure**:
- **Updater** — WP-Cron job (twicedaily) checks GitHub releases and silently upgrades. Stages payload to a temp dir, swaps atomically, rolls back on failure. No admin login required.
- **PluginManager** — installs the demo companion plugin; stale-directory cleanup runs on a daily WP-Cron schedule. Demo repo origin is filterable via `examplepress_mu_demo_repo`.
- **ThemeUpdateProvider** — owns the full theme update lifecycle directly in the kernel (no companion plugin). Hooks `pre_set_site_transient_update_themes` + `themes_api` to inject GitHub release records into the native Themes screen. Supports a channel/pin model (stable/development) with the same resolution priority as the other update surfaces. Theme repo is filterable via `examplepress_mu_theme_repo` (default: `webmultipliers/examplepress-theme`).
- **Scaffolder** — creates companion plugins from GitHub template repos using the Git Database API (blob > tree > commit > ref) to avoid rate-limiting
- **Router + RouteRegistry** — namespaced multi-origin template dispatch. Companion plugins register route origins; the router resolves per-request.
- **AppRegistry** — persists app records as `ep_app` CPT posts, merging live filesystem state with GitHub/Troy metadata. Bounded query (filter via `examplepress_mu_apps_query_limit`).
- **GitHub** — App JWT auth, installation tokens, repo creation, scaffold push (inline-tree fast path with binary/oversize blob fallback), Troy provisioning
- **Helpers** — hardened `WP_Filesystem` accessor (output suppression, error checking, optional `forceDirect` mode for REST/cron contexts) plus a native `readFile()` for safe REST reads

**REST API** — all endpoints under `examplepress-mu/v1`, all using `manage_options` permission (not stripped capabilities):
- Apps: CRUD, scaffold, connect, health, destroy (with confirmation nonce)
- Connections: GitHub/Troy settings, tests, OAuth callbacks
- Demo/Updater: plugin lifecycle management
- Filesystem: directory tree, file read/write (sandboxed, 1MB limit)
- Notifications: archive/restore

**Admin UI** — 10 pages built with Vite + vanilla JS:
- Apps, Theme, Navigation, Dependencies, Library, Settings, Notifications, System, Docs, Editor
- Monaco code editor loaded from CDN (not bundled)

## Configuration

The MU plugin's `examplepress.json` at the repo root is the infrastructure baseline. The theme's `examplepress.json` supplies design tokens. They are deep-merged at runtime — theme values override MU defaults for `design`, `blockstudio`, and `features` keys.

## Installation

### Option A: Auto-Install

Drop `examplepress-mu.php` into `wp-content/mu-plugins/`. On first load, it downloads and extracts the kernel from the latest GitHub release (with SHA-256 checksum verification for release assets).

### Option B: Manual Install

Download the latest release `.zip`, extract into `wp-content/mu-plugins/`. The structure maps directly.

## Plugin Governance

Plugins declaring `Theme: examplepress-theme` in their header are treated as ExamplePress apps and must pass validation:

1. An `examplepress.json` manifest at the plugin root
2. Required fields: `name`, `slug`
3. No banned permissions — the banned-list is empty by default and populated via the `examplepress_mu_banned_permissions` filter
4. Final approval via the `examplepress_mu_validate_app` filter (return `false` to forcibly reject; useful for stricter fleets, return `true` to bypass for local dev)

Failed plugins are removed from the active plugins array before WordPress loads them. This applies to both single-site and multisite network-activated plugins. Call `AppValidator::flushCache()` after admin "refresh" actions when manifest contents may have changed mid-request.

## Building Assets

From the repo root:

```bash
npm install
npm run build    # Production → examplepress-mu/dist/
npm run dev      # Vite dev server with HMR
```

Monaco Editor is loaded from CDN at runtime (only on the Editor page) — it is not included in the Vite bundle.

## Developer Mode

```php
define( 'EP_DEV_MODE', true );
```

Bypasses the FSE EditorGuard (equivalent to disabling the `editor-guard` feature). Also surfaced as a notification in the admin UI.

## Hook Reference

All MU-owned hooks use the `examplepress_mu_` prefix. Theme-owned hooks (`examplepress_route_data`, `examplepress_route_resolved`) keep their unprefixed names.

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_feature_{id}` | filter | Toggle any feature on/off |
| `examplepress_mu_feature_{id}_{key}` | filter | Override a feature option value |
| `examplepress_mu_features` | filter | Bulk-modify the feature registry |
| `examplepress_mu_register_features` | action | Fired before core features are registered (inject/replace early) |
| `examplepress_mu_features_booted` | action | Fired after `bootAll()` wires every feature |
| `examplepress_mu_config_raw` | filter | Filter the merged MU + theme config before normalization |
| `examplepress_mu_config` | filter | Filter the final normalized config (cached after first call) |
| `examplepress_mu_resolved_origin` | filter | Filter the resolved route origin |
| `examplepress_mu_template_prefix` | filter | Override template block prefix (default: `template`) |
| `examplepress_mu_template_block_name` | filter | Override the assembled block name |
| `examplepress_mu_template_repo` | filter | Override the scaffold template repository |
| `examplepress_mu_theme_repo` | filter | Override the GitHub repo used for ExamplePress theme updates (default: `webmultipliers/examplepress-theme`) |
| `examplepress_mu_theme_update_channel` | filter | Force the theme update channel (`stable` or `development`); highest-priority override |
| `examplepress_mu_theme_manifest_url` | filter | Override the `updates.json` manifest URL per channel |
| `examplepress_mu_theme_variant` | filter | Pick a package variant from the manifest (default: `full`) |
| `examplepress_mu_demo_repo` | filter | Override the GitHub repo used for the demo plugin |
| `examplepress_mu_bypass_editor_guard` | filter | Bypass FSE guards programmatically |
| `examplepress_mu_enforce_permalinks` | filter | Opt out of `/%postname%/` enforcement |
| `examplepress_mu_disallow_file_edit` | filter | Opt out of the `DISALLOW_FILE_EDIT` define |
| `examplepress_mu_stripped_capabilities` | filter | Provide a list of caps to strip via `user_has_cap` |
| `examplepress_mu_managed_options` | filter | Lock wp_options to specific values |
| `examplepress_mu_permalink_structure` | filter | Override enforced permalink structure |
| `examplepress_mu_validate_app` | filter | Final accept/reject decision for an app manifest |
| `examplepress_mu_banned_permissions` | filter | List of permissions that disqualify a manifest |
| `examplepress_mu_app_scan_excludes` | filter | Plugin-directory entries to skip during app discovery |
| `examplepress_mu_discovered_apps` | filter | Final discovered app list from `AppDiscovery::scan()` |
| `examplepress_mu_apps_merged` | filter | Final merged registry/filesystem app list |
| `examplepress_mu_apps_query_limit` | filter | Cap on the `ep_app` CPT query (default: 500) |
| `examplepress_mu_data_health` | filter | Health payload before localization |
| `examplepress_mu_data_features` | filter | Features payload before localization |
| `examplepress_mu_data_blocks` | filter | Block-registry payload before localization |
| `examplepress_mu_register_admin_pages` | action | Register additional admin pages |
| `examplepress_mu_core_page` | filter | Override an individual core page definition (return null to suppress) |
| `examplepress_mu_admin_pages` | filter | Modify the final merged admin page registry |
| `examplepress_mu_can_edit_app_files` | filter | Control filesystem editor access |
| `examplepress_mu_github_inline_tree_threshold` | filter | Inline-content size threshold for `GitHub::pushScaffold` (default: 1 MB) |

## WP-CLI

```bash
wp examplepress init          # Generate examplepress.json in current directory
wp examplepress init --force  # Overwrite existing
```

## Requirements

- PHP 8.1+
- WordPress 6.4+
- Composer (for autoloader; fallback PSR-4 autoloader included)
