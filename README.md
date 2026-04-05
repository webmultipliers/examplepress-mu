# ExamplePress MU

A self-updating WordPress MU plugin that provides platform governance, FSE lockdown, and plugin validation for the ExamplePress ecosystem.

## Structure

```
mu-plugins/
├── examplepress-mu.php              # Thin loader (sits at mu-plugins root)
└── examplepress-mu/                 # Platform kernel
    ├── bootstrap.php                # Entry point — constants, includes, boot
    ├── includes/
    │   ├── class-updater.php        # Self-updater (12h transient, SHA-256 verified)
    │   ├── class-fse-guard.php      # FSE lockdown (redirect, REST, resolution)
    │   ├── class-app-validator.php  # Zero-trust plugin governance
    │   ├── class-platform-policy.php # Fleet-wide policies (caps, permalinks, managed options, 404)
    │   ├── class-admin-policy.php   # Admin policies (dashboard widgets, post-lock window)
    │   ├── class-editor-policy.php  # Editor policies (block patterns, block types, Openverse)
    │   ├── class-helpers.php        # Filesystem utilities (copy/delete dir)
    │   ├── class-app-discovery.php  # Plugin scanning for examplepress.json
    │   ├── class-app-registry.php   # ep_app CPT + CRUD + merged queries
    │   ├── class-dependencies.php   # Dependency resolution from theme config
    │   ├── class-github.php         # GitHub App auth, repo creation, push, Troy
    │   ├── class-scaffolder.php     # Template repo scaffolding + placeholder replacement
    │   ├── class-notifications.php  # Notification aggregation + archive REST
    │   ├── class-plugin-manager.php # Updater/demo plugin install + stale dir cleanup
    │   ├── class-cli.php            # WP-CLI: wp examplepress init
    │   └── api-apps.php             # Agent REST API (GET /examplepress-mu/v1/apps)
    ├── api/
    │   ├── apps.php                 # App CRUD, scaffold, connect, destroy, health
    │   ├── connections.php          # GitHub/Troy connection settings + tests
    │   ├── demo.php                 # Demo plugin install/update/settings
    │   ├── updater.php              # Updater plugin install/update/settings
    │   └── filesystem.php           # In-browser editor: tree, read, write
    └── admin/
        ├── plugins-view.php         # "ExamplePress Apps" tab on plugins.php
        └── admin-registry.php       # Top-level menu + extensible subpage API
```

## How It Works

1. WordPress automatically loads `examplepress-mu.php` from the `mu-plugins/` root.
2. The loader checks for `examplepress-mu/bootstrap.php`. If missing, it fetches the latest release from GitHub, extracts it, and places the files.
3. The kernel boots and initializes all subsystems:
   - **FSE Guards** lock down the Site Editor across three vectors (page redirect, REST API, template resolution). Bypassed when `EP_DEV_MODE` is defined.
   - **App Validator** filters `option_active_plugins` to enforce manifest-based governance before plugins load.
   - **Platform Policy** strips dangerous capabilities, enforces `/%postname%/` permalinks, locks managed options, disables 404 redirect guessing, and defines `DISALLOW_FILE_EDIT`.
   - **Admin Policy** removes default dashboard widgets (At a Glance, Activity, Quick Draft, Site Health, Welcome) and sets the post-lock window to 30 seconds.
   - **Editor Policy** disables remote and core block patterns, provides an opt-in block type whitelist via `examplepress_mu_allowed_block_types`, and controls the Openverse media category.
   - **App Discovery** scans plugin directories for `examplepress.json` manifests to discover companion apps.
   - **App Registry** persists app records as `ep_app` CPT posts, merging live filesystem state with persistent GitHub/Troy metadata.
   - **Dependency Checker** resolves plugin, class, and function dependencies declared in the theme's `examplepress.json`.
   - **GitHub Integration** handles GitHub App JWT auth, repo creation, scaffold pushing, and Troy provisioning.
   - **Scaffolder** creates companion plugins from GitHub template repos with placeholder replacement.
   - **Notifications** aggregates system warnings (missing deps, dev mode, health failures) with per-user archive support.
   - **Plugin Manager** installs/updates the updater and demo companion plugins from GitHub, with stale directory cleanup.
   - **REST API** exposes the full platform API under `examplepress/v1` — app CRUD, scaffold, connections, demo/updater management, and filesystem access for the in-browser editor.
   - **Agent REST API** exposes `GET /wp-json/examplepress-mu/v1/apps` (admin-only) for external tools and AI agents.
   - **WP-CLI** provides `wp examplepress init` to generate starter configuration.
   - **Self-Updater** checks GitHub releases every 12 hours via `admin_init` and silently upgrades when a new version is available.
   - **Admin UI** adds an "ExamplePress Apps" tab to `plugins.php` and an extensible top-level menu.

## Theme Hand-off

The kernel defines `EP_MU_ACTIVE = true` at boot. The `examplepress-theme` checks for this constant — when present, the theme disables its own soft guards and defers all governance to the MU kernel.

## Installation

### Option A: Auto-Install (Loader Only)

Drop just `examplepress-mu.php` into `wp-content/mu-plugins/`. On first load, it will download and extract the full application from the latest GitHub release.

### Option B: Manual Install

Download the latest release `.zip`, extract it into `wp-content/mu-plugins/`. The structure maps directly — no extra steps required.

## Plugin Governance

Plugins that declare `Theme: examplepress-theme` in their plugin header are treated as ExamplePress apps and must pass validation:

1. An `examplepress.json` manifest must exist at the plugin root.
2. The manifest must include `name` and `slug` fields.
3. The manifest must not request any banned permissions (`edit_themes`, `install_plugins`, `manage_options`, etc.).

Plugins that fail validation are silently removed from the active plugins array for that request.

## Admin Registry

The MU kernel registers the top-level "ExamplePress" menu (using the shared `EP_ADMIN_MENU_SLUG` constant). The theme's admin-registry attaches its own subpages and fires the `examplepress_register_admin_pages` action so companion plugins can add theirs:

```php
add_action( 'examplepress_register_admin_pages', function() {
    examplepress_register_admin_page( 'my-feature', [
        'page_title' => __( 'My Feature', 'my-plugin' ),
        'menu_title' => __( 'My Feature', 'my-plugin' ),
        'position'   => 80,
        'render'     => 'my_render_callback',
    ] );
} );
```

## Developer Mode

Define `EP_DEV_MODE` in `wp-config.php` to bypass FSE guards while building companion plugins:

```php
define( 'EP_DEV_MODE', true );
```

## Development

```bash
git clone https://github.com/webmultipliers/examplepress-mu.git
```

Work inside the `examplepress-mu/` directory. The thin loader at the root and the application directory are both versioned together.
