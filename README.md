# ExamplePress MU

A self-updating WordPress MU plugin that serves as the platform kernel for the ExamplePress ecosystem — governance, admin UI, app lifecycle, REST APIs, and build pipeline.

## Structure

```
mu-plugins/
├── examplepress-mu.php              # Thin loader (sits at mu-plugins root)
└── examplepress-mu/                 # Platform kernel
    ├── bootstrap.php                # Entry point — constants, includes, boot
    ├── inc/
    │   ├── class-updater.php        # Self-updater (12h transient, SHA-256 verified)
    │   ├── class-fse-guard.php      # FSE lockdown (redirect, REST, resolution)
    │   ├── class-app-validator.php  # Zero-trust plugin governance
    │   ├── class-platform-policy.php # Fleet-wide policies (caps, permalinks, managed options, 404)
    │   ├── class-cli.php            # WP-CLI: wp examplepress init
    │   ├── helpers.php              # Filesystem utilities (copy/delete dir)
    │   ├── app-discovery.php        # Plugin scanning for examplepress.json
    │   ├── app-registry.php         # ep_app CPT + CRUD + merged queries
    │   ├── dependencies.php         # Dependency resolution
    │   ├── github.php               # GitHub App auth, repo creation, push, Troy
    │   ├── scaffolder.php           # Template repo scaffolding + placeholder replacement
    │   ├── notifications.php        # Notification aggregation + archive REST
    │   ├── plugin-manager.php       # Updater/demo plugin install + stale dir cleanup
    │   ├── config.php               # Reads theme's examplepress.json, normalises design/blockstudio config
    │   ├── feature-registry.php     # Filterable feature flag system
    │   ├── features.php             # Loads feature definition files
    │   ├── route-registry.php       # Multi-origin route registration
    │   ├── router.php               # Single-entry-point template dispatch
    │   ├── features/
    │   │   ├── theme-support.php    # title-tag, responsive-embeds, post-thumbnails, html5
    │   │   ├── editor-controls.php  # Block patterns, block types, Openverse
    │   │   ├── admin-customization.php # Dashboard widgets, login branding, post lock
    │   │   ├── design-tokens.php    # Colors, layout, typography, spacing, shadows
    │   │   └── blockstudio.php      # Blockstudio integration features
    │   └── admin/
    │       ├── plugins-view.php     # "ExamplePress Apps" tab on plugins.php
    │       ├── admin-assets.php     # Vite-aware asset enqueue (dev server + production manifest)
    │       ├── admin-registry.php   # Declarative page registry + menu builder
    │       ├── settings-data.php    # Data helpers for admin page payloads
    │       └── pages/               # Per-page render + data functions
    │           ├── shared.php       # Header, modals
    │           ├── apps.php         # App management + scaffold UI
    │           ├── theme.php        # Design token inspector
    │           ├── navigation.php   # Menu management
    │           ├── dependencies.php # Dependency status
    │           ├── library.php      # Component library
    │           ├── settings.php     # GitHub/Troy connections
    │           ├── notifications.php # System notifications
    │           ├── system.php       # Health, features, routes, blocks, config
    │           ├── docs.php         # Guides and hook reference
    │           └── editor.php       # Monaco in-browser code editor
    ├── api/
    │   ├── agent.php                # Agent REST API (GET /examplepress-mu/v1/apps)
    │   ├── apps.php                 # App CRUD, scaffold, connect, destroy, health
    │   ├── connections.php          # GitHub/Troy connection settings + tests
    │   ├── demo.php                 # Demo plugin install/update/settings
    │   ├── updater.php              # Updater plugin install/update/settings
    │   └── filesystem.php           # In-browser editor: tree, read, write
    ├── assets/
    │   ├── css/                     # Static CSS (login branding, admin base)
    │   └── src/                     # Vite entry points (JS per admin page)
    ├── schema/
    │   └── examplepress-theme.json  # JSON Schema for theme's examplepress.json
    ├── package.json                 # npm: vite, monaco-editor, nanostores
    └── vite.config.js               # 10 entry points → dist/
```

## How It Works

1. WordPress automatically loads `examplepress-mu.php` from the `mu-plugins/` root.
2. The loader checks for `examplepress-mu/bootstrap.php`. If missing, it fetches the latest release from GitHub, extracts it, and places the files.
3. The kernel boots and initializes all subsystems:
   - **FSE Guards** lock down the Site Editor across three vectors (page redirect, REST API, template resolution). Bypassed when `EP_DEV_MODE` is defined.
   - **App Validator** filters `option_active_plugins` to enforce manifest-based governance before plugins load.
   - **Platform Policy** strips dangerous capabilities, enforces `/%postname%/` permalinks, locks managed options, disables 404 redirect guessing, and defines `DISALLOW_FILE_EDIT`.
   - **Feature Registry** provides toggleable governance via `examplepress_register_feature()` — admin customization, editor controls, design tokens, theme support, and Blockstudio integration. Features are filterable and configurable via the theme's `examplepress.json`.
   - **App Discovery** scans plugin directories for `examplepress.json` manifests to discover companion apps.
   - **App Registry** persists app records as `ep_app` CPT posts, merging live filesystem state with persistent GitHub/Troy metadata.
   - **Dependency Checker** resolves plugin, class, and function dependencies declared in the theme's `examplepress.json`.
   - **GitHub Integration** handles GitHub App JWT auth, repo creation, scaffold pushing, and Troy provisioning.
   - **Scaffolder** creates companion plugins from GitHub template repos with placeholder replacement.
   - **Notifications** aggregates system warnings (missing deps, dev mode, health failures) with per-user archive support.
   - **Plugin Manager** installs/updates the updater and demo companion plugins from GitHub, with stale directory cleanup.
   - **REST API** exposes the full platform API under `examplepress/v1` — app CRUD, scaffold, connections, demo/updater management, and filesystem access. A separate agent endpoint at `examplepress-mu/v1/apps` provides a minimal read-only view for external tools.
   - **Route Registry + Router** manages multi-origin template dispatch for companion plugins.
   - **WP-CLI** provides `wp examplepress init` to generate starter configuration.
   - **Self-Updater** checks GitHub releases every 12 hours via `admin_init` and silently upgrades when a new version is available.
   - **Admin UI** provides a full admin dashboard with 10 pages (Apps, Theme, Navigation, Dependencies, Library, Settings, Notifications, System, Docs, Editor) built with Vite + vanilla JS.

## Theme Hand-off

The kernel defines `EP_MU_ACTIVE = true` at boot. The `examplepress-theme` checks for this constant — when present, the theme defers all governance to the MU kernel. The theme remains responsible for blockstudio blocks, patterns, and `functions.php` setup.

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

The MU kernel registers the top-level "ExamplePress" menu (using the shared `EP_ADMIN_MENU_SLUG` constant). The admin registry attaches subpages and fires the `examplepress_register_admin_pages` action so companion plugins can add theirs:

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

## Building Assets

Admin page JS/CSS is built with Vite. From the `examplepress-mu/` directory:

```bash
npm install
npm run build    # Production build → dist/
npm run dev      # Dev server with HMR (define EP_VITE_DEV in wp-config.php)
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
