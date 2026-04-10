# Editing & GitOps

## Sanctioned editing paths

ExamplePress enforces **platform immutability** — the running site is a read-only artifact of a Git ref. All app changes flow through pull requests, never through filesystem writes.

There are exactly two sanctioned editing paths:

### 1. Codespaces (developers)

Open the app's GitHub repo in a Codespace. The template repo (`webmultipliers/examplepress-theme-app`) ships a `.devcontainer/devcontainer.json` that bootstraps WordPress + SQLite, installs the kernel and theme, and symlinks the app plugin. Changes are committed and pushed via normal Git workflows.

### 2. In-admin proposer (non-developers)

The ExamplePress admin includes a Monaco-based editor that reads from GitHub at a pinned ref and produces PRs. Users can:

- Browse the full repo tree
- Edit, create, delete, and rename files
- Stage changes across multiple files into a single draft
- Submit all changes as one PR (one commit, one branch, one PR)

The proposer **never writes to the local filesystem**. All writes go through the GitHub API.

## The `repository` manifest field

Every app manifest (`examplepress.json`) should include a `repository` field:

```json
{
  "repository": "owner/repo-name"
}
```

This field is required for:
- The "Open in Codespaces" button on the Apps page
- The "Propose Change" button on the Apps page
- Ref-pinned reads from GitHub in the proposer

Apps scaffolded via `GitHub::createRepo()` from the `webmultipliers/examplepress-theme-app` template have this populated automatically. The validator warns (non-blocking) if the field is missing.

## Design rule: runtime configuration over code

Anything that needs to be edited without a release cycle must be abstracted out of code into WordPress settings or custom post types. The proposer is for code changes; settings/CPTs are for content and configuration.

When scaffolding new apps:
- User-tunable strings go into WordPress options, not hardcoded PHP
- Content that changes frequently goes into custom post types
- Design tokens use `var(--wp--preset--*)` from theme.json, not hardcoded values
- Feature toggles go into the `examplepress.json` features object or WordPress options

## Platform immutability enforcement

The kernel enforces immutability via the `platform-immutability` feature flag (default: on):

- `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` are defined at boot
- No REST endpoint accepts filesystem writes
- The `AppValidator` rejects companion plugins that register write REST routes under `/fs/`
- CI guards (`no-direct-writes.yml`) fail the build if write surfaces reappear

To bypass for local development: define `EP_DEV_MODE` as `true` or disable the `platform-immutability` feature via `examplepress.json`.

## Template repo

The canonical scaffold template is `webmultipliers/examplepress-theme-app`. It ships:

- `.devcontainer/devcontainer.json` with PHP 8.4, Node, Composer
- Tokenized `examplepress.json` (`__NAME__`, `__SLUG__`, `__DESC__`)
- Tokenized bootstrap PHP file
- `app/templates/` skeleton
- `.github/workflows/release.yml` for Troy publishing

New apps are scaffolded from this template via `GitHub::createRepo()`.
