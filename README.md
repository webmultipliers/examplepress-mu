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
    │   └── class-app-validator.php  # Zero-trust plugin governance
    └── admin/
        ├── plugins-view.php         # "ExamplePress Apps" tab on plugins.php
        ├── admin-registry.php       # Top-level menu + extensible subpage API
        └── features/
            └── strict-mode.php      # Fleet-wide policy configuration
```

## How It Works

1. WordPress automatically loads `examplepress-mu.php` from the `mu-plugins/` root.
2. The loader checks for `examplepress-mu/bootstrap.php`. If missing, it fetches the latest release from GitHub, extracts it, and places the files.
3. The kernel boots and initializes all subsystems:
   - **FSE Guards** lock down the Site Editor across three vectors (page redirect, REST API, template resolution).
   - **App Validator** filters `option_active_plugins` to enforce manifest-based governance before plugins load.
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

The MU kernel registers a top-level "ExamplePress" menu and fires the `examplepress_mu_register_admin_pages` action. Both the kernel and the theme can add subpages:

```php
add_action( 'examplepress_mu_register_admin_pages', function() {
    ExamplePress_MU_Admin_Registry::add_subpage(
        'my-feature',
        __( 'My Feature', 'my-plugin' ),
        'my_render_callback'
    );
} );
```

## Development

```bash
git clone https://github.com/webmultipliers/examplepress-mu.git
```

Work inside the `examplepress-mu/` directory. The thin loader at the root and the application directory are both versioned together.
