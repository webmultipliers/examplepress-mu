# ExamplePress MU

A self-updating WordPress MU plugin that serves as the platform kernel for the ExamplePress ecosystem. Provides governance, routing, feature management, admin UI, companion app lifecycle, REST APIs, and a Vite-powered build pipeline.

## Architecture

All PHP lives under the `ExamplePress\MU` namespace with PSR-4 autoloading. Zero procedural functions in the global namespace.

```
mu-plugins/
├── examplepress-mu.php              # Thin loader — downloads kernel if missing
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
    │   │   ├── PlatformPolicy.php   # Cap stripping, permalinks, managed options
    │   │   ├── AppValidator.php     # Zero-trust plugin validation (single + multisite)
    │   │   ├── EditorGuard.php      # FSE lockdown (admin only, frontend pass-through)
    │   │   ├── AdminPolicy.php      # Dashboard widget/admin customization governance
    │   │   └── EditorPolicy.php     # Block pattern/type restriction governance
    │   ├── Infrastructure/
    │   │   ├── AppDiscovery.php     # Scans plugins for examplepress.json
    │   │   ├── AppRegistry.php      # ep_app CPT + CRUD + merged queries + destroy
    │   │   ├── GitHub.php           # GitHub App auth, repo creation, scaffold push
    │   │   ├── Scaffolder.php       # Template repo scaffolding (Git Database API)
    │   │   ├── Updater.php          # WP-Cron self-updater (twicedaily schedule)
    │   │   ├── CliCommand.php       # WP-CLI: wp examplepress init
    │   │   ├── RouteRegistry.php    # Multi-origin route registration
    │   │   ├── Router.php           # Template dispatch (namespaced, no redeclaration)
    │   │   ├── PluginManager.php    # Updater/demo plugin install + stale cleanup
    │   │   ├── Notifications.php    # System warning aggregation + archive REST
    │   │   └── Helpers.php          # Filesystem utilities
    │   ├── API/
    │   │   ├── AgentController.php      # GET /apps (read-only, external tools)
    │   │   ├── AppsController.php       # App CRUD, scaffold, connect, destroy, health
    │   │   ├── ConnectionsController.php # GitHub/Troy settings, tests, OAuth callbacks
    │   │   ├── DemoController.php       # Demo plugin lifecycle
    │   │   ├── UpdaterController.php    # Updater plugin lifecycle
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

**Governance** (non-toggleable, MU-enforced):
- **PlatformPolicy** — strips dangerous capabilities, enforces `/%postname%/`, locks managed options, disables file editing
- **AppValidator** — hooks both `option_active_plugins` and `site_option_active_sitewide_plugins` to enforce manifest-based governance before plugins load
- **EditorGuard** — blocks Site Editor access in admin (redirect, REST, template resolution) while allowing frontend template resolution for the block theme

**Config**:
- **ConfigManager** — deep-merges the MU plugin's `examplepress.json` (infrastructure baseline) with the theme's `examplepress.json` (design tokens, Blockstudio settings). Theme values win.
- **FeatureRegistry** — toggleable features via `examplepress_mu_feature_{id}` filters. Resolution: PHP filter > JSON config > registration default.
- **DependencyManager** — aggregates dependencies from both the MU config and active companion apps

**Infrastructure**:
- **Updater** — WP-Cron job (twicedaily) checks GitHub releases and silently upgrades. No admin login required.
- **Scaffolder** — creates companion plugins from GitHub template repos using the Git Database API (blob > tree > commit > ref) to avoid rate-limiting
- **Router + RouteRegistry** — namespaced multi-origin template dispatch. Companion plugins register route origins; the router resolves per-request.
- **AppRegistry** — persists app records as `ep_app` CPT posts, merging live filesystem state with GitHub/Troy metadata
- **GitHub** — App JWT auth, installation tokens, repo creation, scaffold push, Troy provisioning

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
3. No banned permissions (`manage_options`, `edit_themes`, `install_plugins`, etc.)

Failed plugins are removed from the active plugins array before WordPress loads them. This applies to both single-site and multisite network-activated plugins.

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

Bypasses all FSE guards. Also surfaced as a notification in the admin UI.

## Hook Reference

All MU-owned hooks use the `examplepress_mu_` prefix. Theme-owned hooks (`examplepress_route_data`, `examplepress_route_resolved`) keep their unprefixed names.

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_feature_{id}` | filter | Toggle any feature on/off |
| `examplepress_mu_feature_{id}_{key}` | filter | Override a feature option value |
| `examplepress_mu_features` | filter | Bulk-modify the feature registry |
| `examplepress_mu_resolved_origin` | filter | Filter the resolved route origin |
| `examplepress_mu_template_prefix` | filter | Override template block prefix (default: `template`) |
| `examplepress_mu_template_block_name` | filter | Override the assembled block name |
| `examplepress_mu_template_repo` | filter | Override the scaffold template repository |
| `examplepress_mu_bypass_editor_guard` | filter | Bypass FSE guards programmatically |
| `examplepress_mu_managed_options` | filter | Lock wp_options to specific values |
| `examplepress_mu_permalink_structure` | filter | Override enforced permalink structure |
| `examplepress_mu_register_admin_pages` | action | Register additional admin pages |
| `examplepress_mu_admin_pages` | filter | Modify the admin page registry |
| `examplepress_mu_can_edit_app_files` | filter | Control filesystem editor access |

## WP-CLI

```bash
wp examplepress init          # Generate examplepress.json in current directory
wp examplepress init --force  # Overwrite existing
```

## Requirements

- PHP 8.1+
- WordPress 6.4+
- Composer (for autoloader; fallback PSR-4 autoloader included)
