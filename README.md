<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress&logoColor=white" alt="WordPress 6.4+">
  <img src="https://img.shields.io/badge/Vite-6-646CFF?logo=vite&logoColor=white" alt="Vite 6">
  <img src="https://img.shields.io/badge/License-Proprietary-red" alt="License">
</p>

# ExamplePress MU

**The platform kernel for the ExamplePress ecosystem.** A self-updating WordPress MU plugin that provides governance enforcement, companion app lifecycle management, a 10-page admin dashboard, REST APIs, and an optional AI-powered agent — all in a single zero-procedural, fully namespaced PHP package.

---

## Highlights

- **Self-Updating Kernel** — Auto-downloads from GitHub releases with SHA-256 verification, atomic swaps, and automatic rollback on failure
- **Zero-Trust Plugin Governance** — Validates every companion plugin's manifest, permissions, and code before WordPress loads it
- **Generative App Agent** — Describe an app in plain English; the agent scaffolds the PHP/Blockstudio code, validates it against 20 zero-trust rules, pushes to GitHub, and installs it automatically. Iterate and repair modes use a partial-tree output contract so large apps don't run into LLM output token limits (powered by Claude or GPT-4)
- **Skill Curriculum System** — The agent's instructions are plain markdown files with live merge tags, not hardcoded prompts — fully extensible by operators
- **Platform Immutability** — The running site is a read-only artifact of a Git ref; all changes flow through PRs via Codespaces or the in-admin proposer
- **10-Page Admin Dashboard** — Apps, Theme, Navigation, Dependencies, Library, Settings, Notifications, System, and Docs
- **Minimal Footprint** — Only 23 MB of PHP vendor dependencies for the full agent stack (six `illuminate/*` sub-packages, no full Laravel)

---

## Quick Start

### Option A: Auto-Install (Recommended)

Drop the loader into your MU plugins directory:

```bash
cp examplepress-mu.php /path/to/wp-content/mu-plugins/
```

On first page load, the loader automatically downloads and extracts the latest kernel release from GitHub.

### Option B: Manual Install

Download the [latest release](../../releases/latest) `.zip` and extract it into `wp-content/mu-plugins/`. The directory structure maps directly — no additional configuration needed.

### Requirements

| Requirement | Version | Notes |
|---|---|---|
| PHP | **8.2+** | Kernel runs on 8.1, but the agent stack requires 8.2 |
| WordPress | **6.4+** | |
| Composer | Latest | PSR-4 fallback autoloader included |
| ext-fileinfo | — | Required by Prism |
| Server-side cron | — | Only needed if the agent is enabled |

---

## How It Works

```
WordPress loads examplepress-mu.php
  └─ Downloads kernel if missing (cold-start)
  └─ bootstrap.php
       └─ Composer autoloader + Action Scheduler
       └─ Kernel::boot()
            ├─ Governance     → PlatformPolicy, AppValidator, EditorGuard
            ├─ Config          → ConfigManager, FeatureRegistry, DependencyManager
            ├─ Infrastructure → Updater, Router, GitHub, Scaffolder, AppRegistry
            ├─ Agent (opt-in) → PrismContainer, LLMClient, SkillRegistry, GenerationJob
            ├─ REST API       → 7 controllers under examplepress-mu/v1
            └─ Admin UI       → 10 Vite-powered pages
```

All PHP lives under the `ExamplePress\MU` namespace. Zero procedural functions in the global namespace.

---

## Architecture

```
mu-plugins/
├── examplepress-mu.php            # Thin loader + cold-start installer
├── examplepress.json              # Master platform config (source of truth)
├── vite.config.js                 # 12 Vite entry points
│
└── examplepress-mu/               # Platform kernel
    ├── bootstrap.php              # Constants, autoloader, Kernel::boot()
    ├── config/prism.php           # LLM provider config
    ├── agent/skills/              # 16 markdown curriculum files (00-95)
    │
    ├── src/
    │   ├── Kernel.php             # Single boot entry point
    │   ├── Config/                # ConfigManager, FeatureRegistry, DependencyManager
    │   ├── Governance/            # PlatformPolicy, AppValidator, EditorGuard
    │   ├── Infrastructure/        # Updater, GitHub, Scaffolder, Router, Helpers...
    │   ├── Agent/                 # LLMClient, GenerationJob, SkillRegistry, MergeTags
    │   ├── API/                   # 7 REST controllers
    │   └── Admin/                 # MenuManager, AssetManager, PageController, Templates/
    │
    ├── assets/src/                # Vite entry points (JS + CSS per page)
    │   ├── apps/, updates/, theme/, navigation/, dependencies/
    │   ├── library/, settings/, notifications/, system/, docs/, editor/
    │   ├── css/                   # Shared styles
    │   ├── lib/                   # Shared JS (api, tabs, modal, datatable)
    │   └── stores/                # Nanostores state management
    │
    ├── dist/                      # Built output (Vite manifest + hashed assets)
    └── vendor/                    # Composer dependencies (PSR-4)
```

---

## Configuration

The platform uses a two-layer config system:

| Layer | File | Purpose |
|---|---|---|
| **MU plugin** | `examplepress.json` (repo root) | Infrastructure baseline |
| **Theme** | `examplepress.json` (theme root) | Design tokens, Blockstudio settings |

These are deep-merged at runtime — **theme values win** for `design`, `blockstudio`, and `features` keys.

### Feature Flags

Features are toggleable with a three-tier resolution order:

```
PHP filter  >  JSON config  >  Registration default
```

```php
// Toggle any feature programmatically
add_filter( 'examplepress_mu_feature_editor-guard', '__return_false' );
```

### Developer Mode

```php
define( 'EP_DEV_MODE', true );
```

Bypasses the FSE EditorGuard and surfaces a notification in the admin UI.

---

## Generative App Agent

The agent lets site owners describe a companion app in natural language and have it scaffolded, validated, pushed to GitHub, tagged as a release, and installed — without writing any code. It produces PHP + Blockstudio source code; it does not render a live visual preview.

### Setup

1. Navigate to **Settings > AI Agent** in the admin dashboard
2. Toggle **Enable Agent**
3. Select a provider (`anthropic` or `openai`), model, and API key
4. Reload — the **Generate with AI** button appears on the Apps page

### Generation pipeline (two async phases)

```
User prompt
  → AppRegistry::openJob() — history entry appended to ep_app post
  → Action Scheduler: ep_agent_generate
      → LLMClient calls Prism (structured JSON, partial-tree output)
      → GeneratedApp::mergeOnto(parentTree) — merge layer
      → AppValidator::validateGenerated() — 20 zero-trust rules
      → auto-repair pass if validation fails
      → AppRegistry::stashDraftPayload() — status=drafted, awaiting review
  → User clicks Push
  → Action Scheduler: ep_agent_commit
      → GitHub::createRepo() → pushFiles() → createRelease()
      → AppRegistry::promoteToPublished() / recordPush()
      → AppUpdateProvider::flush() — installed on next update tick
```

**Partial-tree output contract** — iterate and repair modes return only the files the LLM actually touched (`files_changed`) plus explicit removals (`files_deleted`). The merge layer applies the diff against the parent tree so large apps never hit LLM output token limits.

**Async commit** — the "Push to GitHub" click enqueues `ep_agent_commit` and returns immediately. Slow GitHub responses cannot 504 the REST request.

**State** — every agent job is a history entry on the `ep_app` Custom Post Type. There is no separate `ep_agent_jobs` option. The post is the job record, the audit trail, and the polling source for the UI.

### Skill Curriculum

The agent's system prompt is compiled at generation time from markdown files in [`agent/skills/`](examplepress-mu/agent/skills/), not hardcoded. Each file can contain `{{merge_tag}}` placeholders that resolve against the live install (color palette, font sizes, theme slug, registered blocks, etc.).

**Adding a skill** — drop a `.md` file into the directory.

**Extending from a theme:**

```php
add_filter( 'examplepress_mu_agent_skill_paths', function ( array $paths ): array {
    $paths[] = get_template_directory() . '/agent-skills';
    return $paths;
} );
```

**Adding a merge tag:**

```php
add_filter( 'examplepress_mu_agent_merge_tags', function ( array $tags ): array {
    $tags['client_name'] = fn () => get_option( 'client_name', '' );
    return $tags;
} );
```

**Inspecting** — the Settings > AI Agent panel includes an **Inspect Skills** button that shows loaded files, resolved merge tags, and the full compiled curriculum.

### Agent Configuration

| Option | Default | Description |
|---|---|---|
| `ep_agent_enabled` | `false` | Toggles the agent feature |
| `ep_agent_provider` | `anthropic` | `anthropic` or `openai` |
| `ep_agent_model` | `claude-sonnet-4-6` | Model identifier |
| `ep_agent_api_key` | — | Stored in `wp_options` |

Job state lives on the `ep_app` CPT (per-post history meta) — there
is no separate job options table.

---

## Immutability

The running site is a **read-only artifact of a Git ref**. All app code changes flow through pull requests — never through filesystem writes.

### Enforcement

The `platform-immutability` feature flag (default: **on**) defines `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` at boot. No REST endpoint accepts filesystem writes. The `AppValidator` rejects companion plugins that register write REST routes under `/fs/`. A CI workflow (`no-direct-writes.yml`) fails the build if write surfaces reappear.

### Sanctioned editing paths

| Path | Audience | Mechanism |
|---|---|---|
| **Codespaces** | Developers | Full Git workflow inside a GitHub Codespace; devcontainer bootstraps WordPress + kernel |
| **In-admin proposer** | Non-developers | Monaco editor reads from GitHub at a pinned ref, produces PRs via the GitHub App |

### Disabling for local dev

Define `EP_DEV_MODE` as `true`, or disable the `platform-immutability` feature in `examplepress.json`:

```json
{ "features": { "platform-immutability": false } }
```

Or use the bypass filter: `add_filter('examplepress_mu_bypass_platform_immutability', '__return_true');`

---

## Plugin Governance

Plugins declaring `Theme: examplepress-theme` in their header are treated as ExamplePress apps and must pass validation:

1. An `examplepress.json` manifest at the plugin root
2. Required fields: `name`, `slug`
3. Recommended field: `repository` (GitHub `owner/repo` — required for Codespaces and Propose Change workflows; warns if missing)
4. No banned permissions (configurable via `examplepress_mu_banned_permissions` filter)
5. No REST routes under `/fs/` with write methods (enforced by platform-immutability policy)
6. Final approval via `examplepress_mu_validate_app` filter

Failed plugins are silently removed from the active plugins array before WordPress loads them. Works on both single-site and multisite.

---

## REST API

All endpoints live under `examplepress-mu/v1` and require `manage_options` capability.

| Group | Endpoints |
|---|---|
| **Apps** | CRUD, scaffold, connect, health, destroy |
| **Agent** | `generate`, `iterate/{slug}`, `eject/{slug}`, `jobs/*`, `providers`, `skills`, `test` |
| **Connections** | GitHub/Troy/Agent settings, OAuth callbacks |
| **Editor** | Ref, tree, file (read-only from GitHub), draft (user meta), proposal (creates PR) |
| **Theme Updates** | Theme update lifecycle |
| **Notifications** | Archive/restore system messages |
| **Demo** | Demo plugin install/cleanup |

---

## Development

### Building Assets

```bash
npm install
npm run build          # Production build → examplepress-mu/dist/
npm run dev            # Vite dev server with HMR
```

### PHP Dependencies

```bash
composer install                          # Development
composer install --no-dev --optimize-autoloader   # Production
```

### Combined Build

```bash
composer build         # Runs both PHP and JS builds
```

### WP-CLI

```bash
wp examplepress init          # Generate examplepress.json in current directory
wp examplepress init --force  # Overwrite existing config
```

### Running Agent Jobs Manually

```bash
wp action-scheduler run --hooks=ep_agent_generate,ep_agent_commit
```

---

## Hook Reference

All MU-owned hooks use the `examplepress_mu_` prefix. Theme-owned hooks (`examplepress_route_data`, `examplepress_route_resolved`) keep their unprefixed names.

<details>
<summary><strong>Governance Hooks</strong></summary>

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_enforce_permalinks` | filter | Opt out of `/%postname%/` enforcement |
| `examplepress_mu_disallow_file_edit` | filter | Opt out of the `DISALLOW_FILE_EDIT` define |
| `examplepress_mu_stripped_capabilities` | filter | Caps to strip via `user_has_cap` |
| `examplepress_mu_managed_options` | filter | Lock `wp_options` to specific values |
| `examplepress_mu_permalink_structure` | filter | Override enforced permalink structure |
| `examplepress_mu_validate_app` | filter | Final accept/reject for an app manifest |
| `examplepress_mu_banned_permissions` | filter | Permissions that disqualify a manifest |
| `examplepress_mu_bypass_editor_guard` | filter | Bypass FSE guards programmatically |

</details>

<details>
<summary><strong>Config Hooks</strong></summary>

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_feature_{id}` | filter | Toggle any feature on/off |
| `examplepress_mu_feature_{id}_{key}` | filter | Override a feature option value |
| `examplepress_mu_features` | filter | Bulk-modify the feature registry |
| `examplepress_mu_register_features` | action | Fired before core features register |
| `examplepress_mu_features_booted` | action | Fired after `bootAll()` |
| `examplepress_mu_config_raw` | filter | Filter merged config before normalization |
| `examplepress_mu_config` | filter | Filter final normalized config (cached) |

</details>

<details>
<summary><strong>Infrastructure Hooks</strong></summary>

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_resolved_origin` | filter | Filter the resolved route origin |
| `examplepress_mu_template_prefix` | filter | Override template block prefix |
| `examplepress_mu_template_block_name` | filter | Override assembled block name |
| `examplepress_mu_template_repo` | filter | Override scaffold template repo |
| `examplepress_mu_theme_repo` | filter | Override theme GitHub repo |
| `examplepress_mu_theme_update_channel` | filter | Force theme update channel |
| `examplepress_mu_theme_manifest_url` | filter | Override `updates.json` manifest URL |
| `examplepress_mu_theme_variant` | filter | Pick a package variant from manifest |
| `examplepress_mu_should_update_now` | filter | Per-cron-tick gate on self-updater |
| `examplepress_mu_demo_repo` | filter | Override demo plugin GitHub repo |
| `examplepress_mu_app_scan_excludes` | filter | Skip entries during app discovery |
| `examplepress_mu_discovered_apps` | filter | Filter discovered app list |
| `examplepress_mu_apps_merged` | filter | Filter merged app list |
| `examplepress_mu_apps_query_limit` | filter | Cap on `ep_app` CPT query (default: 500) |
| `examplepress_mu_github_inline_tree_threshold` | filter | Inline-content size threshold for pushes |

</details>

<details>
<summary><strong>Agent Hooks</strong></summary>

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_feature_agent` | filter | Toggle the agent on/off |
| `examplepress_mu_validate_generated_app` | filter | Accept/reject post-validation merged tree |
| `examplepress_mu_agent_default_model` | filter | Default model when `ep_agent_model` option is unset |
| `examplepress_mu_agent_iterate_max_files` | filter | Max repo files fed to LLM during iteration (default: 80) |
| `examplepress_mu_agent_skill_paths` | filter | Directories scanned for skill files |
| `examplepress_mu_agent_skill_files` | filter | Final skill file map |
| `examplepress_mu_agent_skill_body` | filter | Final compiled curriculum body |
| `examplepress_mu_agent_merge_tags` | filter | Add/override merge-tag resolvers |
| `examplepress_mu_agent_escape_allowlist` | filter | Extra function names allowed in the template `echo` allowlist |

</details>

<details>
<summary><strong>Admin Hooks</strong></summary>

| Hook | Type | Description |
|---|---|---|
| `examplepress_mu_register_admin_pages` | action | Register additional admin pages |
| `examplepress_mu_core_page` | filter | Override a core page definition |
| `examplepress_mu_admin_pages` | filter | Modify final admin page registry |
| `examplepress_mu_can_edit_app_files` | filter | Control filesystem editor access |
| `examplepress_mu_data_health` | filter | Health payload before localization |
| `examplepress_mu_data_features` | filter | Features payload before localization |
| `examplepress_mu_data_blocks` | filter | Block-registry payload before localization |

</details>

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.2+, WordPress 6.4+, Composer (PSR-4) |
| **LLM Integration** | [Prism PHP](https://github.com/prism-php/prism) (Anthropic + OpenAI) |
| **Async Jobs** | [Action Scheduler](https://actionscheduler.org/) |
| **Frontend** | Vite 6, vanilla JS, [Nanostores](https://github.com/nanostores/nanostores) |
| **Code Editor** | [Monaco Editor](https://microsoft.github.io/monaco-editor/) (CDN) |
| **Markdown** | [Marked](https://marked.js.org/) + [highlight.js](https://highlightjs.org/) |
| **CI/CD** | GitHub Actions (multi-job release pipeline) |

---

## Production Build

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

The `composer.json` uses a `replace` block (`laravel/framework: 12.99.99`) so only six `illuminate/*` sub-packages are pulled — the full Laravel framework is excluded by design.

---

## License

Proprietary. All rights reserved.
