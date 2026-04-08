# ExamplePress MU

A self-updating WordPress MU plugin that serves as the platform kernel for the ExamplePress ecosystem. Provides governance, routing, feature management, admin UI, companion app lifecycle, REST APIs, an optional generative-UI agent, and a Vite-powered build pipeline.

## Architecture

All PHP lives under the `ExamplePress\MU` namespace with PSR-4 autoloading. Zero procedural functions in the global namespace.

```
mu-plugins/
├── examplepress-mu.php              # Loader + shared kernel installer (cold-start + self-upgrade). Version header is the source of truth.
├── examplepress.json                # Master platform configuration (source of truth)
├── package.json                     # npm: vite, nanostores
├── vite.config.js                   # 10 entry points → examplepress-mu/dist/
└── examplepress-mu/                 # Platform kernel
    ├── bootstrap.php                # Constants, Composer autoloader, Action Scheduler, Kernel::boot()
    ├── composer.json                # PSR-4: ExamplePress\MU\ → src/
    ├── config/
    │   └── prism.php                # Minimal Prism config (providers, request_timeout) — read by PrismContainer
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
    │   │   ├── GitHub.php           # GitHub App auth, repo creation, push (scaffold + iterative), releases, tree fetch
    │   │   ├── Scaffolder.php       # Template repo scaffolding (Git Database API)
    │   │   ├── Updater.php          # WP-Cron self-updater with atomic swap + rollback
    │   │   ├── ThemeUpdateProvider.php # GitHub-backed theme update pipeline (absorbed the former examplepress-theme-update companion)
    │   │   ├── PrismContainer.php   # Minimal Laravel container (no Acorn) booting Prism for the agent
    │   │   ├── MinimalApplication.php # 32-method Application contract shim, extends Illuminate\Container\Container
    │   │   ├── prism-helpers.php    # Tiny config()/app()/event() globals replacing illuminate/foundation helpers
    │   │   ├── CliCommand.php       # WP-CLI: wp examplepress init
    │   │   ├── RouteRegistry.php    # Multi-origin route registration
    │   │   ├── Router.php           # Template dispatch (namespaced, no redeclaration)
    │   │   ├── PluginManager.php    # Updater/demo install + daily-cron stale cleanup
    │   │   ├── Notifications.php    # System warning aggregation + archive REST
    │   │   └── Helpers.php          # Filesystem utilities (hardened, force-direct mode)
    │   ├── Agent/
    │   │   ├── LLMClient.php        # Thin wrapper over Prism (Anthropic + OpenAI), structured JSON enforcement
    │   │   ├── GeneratedApp.php     # Value object — manifest + files + commit_message + version
    │   │   └── GenerationJob.php    # Action Scheduler job: drafting → writing_code → pushing → done
    │   ├── API/
    │   │   ├── CompanionPluginController.php # Shared base for demo controller (and any future companion plugin controllers)
    │   │   ├── ThemeUpdateController.php     # REST surface for ThemeUpdateProvider (/theme-update/*)
    │   │   ├── AppsController.php       # App CRUD, scaffold, connect, destroy, health, GET /apps
    │   │   ├── AgentController.php      # /agent/generate, /iterate/{slug}, /eject/{slug}, /jobs/{id}, /providers
    │   │   ├── ConnectionsController.php # GitHub/Troy/Agent settings, tests, OAuth callbacks
    │   │   ├── DemoController.php       # Demo plugin lifecycle (subclass of CompanionPluginController)
    │   │   └── FilesystemController.php # In-browser editor: tree, read, write
    │   └── Admin/
    │       ├── MenuManager.php      # Top-level menu + submenu registration
    │       ├── AssetManager.php     # Vite manifest reader + script/style enqueue
    │       ├── PageController.php   # HTML shell + template includes
    │       ├── DataProvider.php     # window.ExamplePressData localization
    │       └── Templates/           # Per-page HTML skeletons (tabs, panels, modals)
    │           ├── apps.php
    │           ├── updates.php
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
            ├── updates/
            ├── theme/
            ├── navigation/
            ├── dependencies/
            ├── library/
            ├── settings/
            ├── notifications/
            ├── system/
            ├── docs/
            ├── editor/
            ├── css/                 # admin-settings.css, base.css, updates.css, login.css
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

**Generative UI Agent** (optional, off by default):
- **PrismContainer** — boots a hand-rolled Laravel container with the absolute minimum services Prism needs (`container`, `config`, `events`, `http`, `support`). Skips `roots/acorn` and `illuminate/foundation` entirely — `MinimalApplication` is a 32-method shim implementing `Illuminate\Contracts\Foundation\Application`. Total agent-stack vendor footprint: ~23 MB (vs ~58 MB under Acorn). Boot is gated by the `agent` feature flag and fail-soft: a runtime error logs and auto-disables the feature for the request rather than fataling the kernel.
- **LLMClient** — wraps Prism's structured-output API. Provider (`anthropic`/`openai`), model, and API key are read at call-time from `ep_agent_provider`/`ep_agent_model`/`ep_agent_api_key` options. The system prompt embeds a strict JSON schema (manifest + files + commit_message + version), the Blockstudio style guide, and the zero-trust security contract.
- **GenerationJob** — Action Scheduler job runner. Job state lives in a single capped `ep_agent_jobs` option (50 entries, FIFO eviction — no `wp_posts`/serialized-markup bloat). Pipeline: validate → `GitHub::createRepo` → `GitHub::pushFiles` → `GitHub::createRelease` → `AppRegistry::set` → `AppUpdateProvider::flush`. Iteration mode loads the current repo tree via `GitHub::fetchRepoTree` and chains a new commit on top.
- **AppValidator::validateGenerated** — zero-trust check on every LLM payload. Hard rejects on `eval`/`exec`/`system`/`shell_exec`/`passthru`/`proc_open`/`popen`/backtick operators/`base64_decode($var)`, plus path traversal, absolute paths, and non-boolean `supports_ai_iteration`. Filterable via `examplepress_mu_validate_generated_app`.
- **Eject button** — flips `supports_ai_iteration` to `false` in the manifest, commits, releases a new patch version. The chat panel locks; the repo is now developer-mode only.

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
- Connections: GitHub/Troy/Agent settings, tests, OAuth callbacks
- Demo/Updater: plugin lifecycle management
- Filesystem: directory tree, file read/write (sandboxed, 1MB limit)
- Notifications: archive/restore
- Agent: `POST /agent/generate`, `POST /agent/iterate/{slug}`, `POST /agent/eject/{slug}`, `GET /agent/jobs/{id}`, `GET /agent/jobs`, `GET /agent/providers` — gated by the `agent` feature flag, returns `503 agent_unavailable` if PrismContainer failed to boot

**Admin UI** — 10 pages built with Vite + vanilla JS:
- Apps, Theme, Navigation, Dependencies, Library, Settings, Notifications, System, Docs, Editor
- Monaco code editor loaded from CDN (not bundled)

## Configuration

The MU plugin's `examplepress.json` at the repo root is the infrastructure baseline. The theme's `examplepress.json` supplies design tokens. They are deep-merged at runtime — theme values override MU defaults for `design`, `blockstudio`, and `features` keys.

## Generative UI Agent

The agent is an optional feature that lets site owners describe a companion app in natural language and have it scaffolded, validated, pushed to a private GitHub repo, tagged as a release, and installed via the existing AppUpdateProvider pipeline — without ever writing block markup or config to `wp_posts` / `wp_options`.

### Enabling

1. **Settings → AI Agent** in the admin UI.
2. Toggle **Enable Agent**.
3. Pick provider (`anthropic` or `openai`), model (e.g. `claude-3-5-sonnet-latest`, `gpt-4o`), paste API key, save.
4. Reload — `PrismContainer::boot()` runs on `after_setup_theme:20`, the **✨ Generate with AI** button appears on the Apps page.

### Workflow

| Step | What happens |
|---|---|
| 1. Prompt | User enters a description on the Apps page. `AgentController::generate` enqueues an Action Scheduler job. |
| 2. Drafting | `LLMClient::generateApp()` calls Prism with a strict JSON schema (manifest + files + commit_message + version). |
| 3. Validating | `AppValidator::validateGenerated()` hard-rejects banned tokens, path traversal, malformed manifests. |
| 4. Pushing | `GitHub::createRepo` → `GitHub::pushFiles` (initial commit on `main`) → `GitHub::createRelease` (tag `v1.0.0`). |
| 5. Installing | `AppRegistry::set` + `AppUpdateProvider::flush` — the existing update pipeline picks up the release on the next tick. |
| 6. Iterating | Per-app **✨ Iterate** affordance loads the current repo tree as context, sends a follow-up prompt, pushes a new commit chained from the parent SHA, releases `v1.0.1`. |
| 7. Ejecting | **Eject to Developer Mode** flips `supports_ai_iteration: false` in the manifest, commits, releases a new patch. The chat panel locks. |

### Architectural choices

- **No Acorn.** Originally booted via `roots/acorn`, replaced by a hand-rolled `PrismContainer` + `MinimalApplication` shim. Vendor footprint dropped from ~91 MB to ~56 MB total (~35 MB saved on the agent stack alone — a 60% reduction).
- **No `wp_posts` bloat.** All generated code lives only in Git. The kernel only persists an `ep_app` CPT row (slug + version + GitHub coordinates) — identical to manually scaffolded apps.
- **Zero-trust validation.** Every LLM payload runs through `AppValidator::validateGenerated()` before any disk or GitHub call. Banned tokens (`eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`, backticks, `base64_decode($var)`) are hard-rejected. Filterable via `examplepress_mu_validate_generated_app`.
- **Action Scheduler, not WP-Cron.** Async jobs are enqueued via `as_enqueue_async_action()` so generations survive PHP request timeouts. Requires real server-side cron in production (not WP pseudo-cron).
- **Fail-soft boot.** If `PrismContainer::boot()` throws (missing vendor, bad config), it logs and auto-disables the `agent` feature filter for the request. Manual scaffold mode (`+ New App`) keeps working.

### Configuration

The agent reads three options (settable via the UI or `wp option update`):

| Option | Default | Notes |
|---|---|---|
| `ep_agent_enabled` | `false` | Toggles the `examplepress_mu_feature_agent` filter via the bridge in `Kernel::boot()` |
| `ep_agent_provider` | `anthropic` | `anthropic` or `openai` |
| `ep_agent_model` | `claude-3-5-sonnet-latest` | Free-form |
| `ep_agent_api_key` | — | Stored in `wp_options`. Restrict `manage_options` accordingly. |
| `ep_agent_jobs` | `[]` | Job state, capped at 50 entries with FIFO eviction |

The job runner is `Agent\GenerationJob::handle()`, registered against the `ep_agent_generate` Action Scheduler hook in `Kernel::boot()`. Run jobs manually with:

```bash
wp action-scheduler run --hooks=ep_agent_generate
```

### Prerequisites for the agent path

- Real server-side cron (e.g. `* * * * * wp cron event run --due-now`). WP pseudo-cron is not acceptable.
- PHP 8.2+ (Prism requires it).
- GitHub App with `Administration: R/W`, `Contents: R/W`, `Metadata: Read` on the target org, with permission to create private repos.
- Active Anthropic and/or OpenAI API credentials.
- The template repo (resolved via `Scaffolder::getTemplateRepo()`) must contain a clean root `examplepress.json` and be Blockstudio-compatible.

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
| `examplepress_mu_should_update_now` | filter | Per-cron-tick gate on the kernel self-updater (default: `true`). Return `false` to skip a tick — useful for quiet hours, publish-in-progress locks, or release freezes. |
| `examplepress_mu_demo_repo` | filter | Override the GitHub repo used for the demo plugin |
| `examplepress_mu_bypass_editor_guard` | filter | Bypass FSE guards programmatically |
| `examplepress_mu_enforce_permalinks` | filter | Opt out of `/%postname%/` enforcement |
| `examplepress_mu_disallow_file_edit` | filter | Opt out of the `DISALLOW_FILE_EDIT` define |
| `examplepress_mu_stripped_capabilities` | filter | Provide a list of caps to strip via `user_has_cap` |
| `examplepress_mu_managed_options` | filter | Lock wp_options to specific values |
| `examplepress_mu_permalink_structure` | filter | Override enforced permalink structure |
| `examplepress_mu_validate_app` | filter | Final accept/reject decision for an app manifest |
| `examplepress_mu_validate_generated_app` | filter | Final accept/reject decision for an AI-generated app payload (manifest + files), with banned-token + path-traversal checks already applied |
| `examplepress_mu_agent_iterate_max_files` | filter | Cap on the number of repo files fed back to the LLM during iteration (default: 80) |
| `examplepress_mu_feature_agent` | filter | Toggle the Generative UI Agent on/off (also bridged to the `ep_agent_enabled` option) |
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

- PHP **8.2+** (Prism + `illuminate/*` ^12 require it; the kernel itself runs on 8.1, but the agent stack does not)
- WordPress 6.4+
- Composer (for autoloader; fallback PSR-4 autoloader included)
- Real server-side cron (only required if you enable the Generative UI Agent — Action Scheduler must process jobs in the background)
- ext-fileinfo (Prism requirement)

## Building for Production

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

The `composer.json` uses a `replace` block (`laravel/framework: 12.99.99`) so the agent stack pulls only six `illuminate/*` sub-packages instead of the full Laravel meta-package — the entire Laravel `view`/`console`/`routing`/`database`/`queue`/`cache`/`log`/`auth`/`broadcasting` chain is excluded by design. See `examplepress-mu/src/Infrastructure/PrismContainer.php` for the rationale.
