# Self-check — read this last, every time

Before emitting your JSON payload, walk this checklist top-to-bottom.
**If any item is NO, fix the payload before emitting.** The kernel's
`AppValidator` enforces most of these as hard rejects — failing here
means your generation is discarded with no commit, no release, no
install.

## Output envelope

- [ ] Output is a single JSON object matching the schema in skill 05.
- [ ] No markdown fences. No preamble. No trailing text.
- [ ] `manifest.slug` matches `[a-z0-9-]+`.
- [ ] `manifest.supports_ai_iteration` is `true`.
- [ ] `commit_message` is set and follows conventional-commit format.
- [ ] `version` matches `manifest.version`.

## File structure

- [ ] The bootstrap file `{slug}.php` is in the `files` array.
- [ ] `examplepress.json` is in the `files` array.
- [ ] No file path is absolute, contains `..`, or starts with `/`.
- [ ] No app exceeds 50 files total.
- [ ] No single block folder exceeds 8 files.

## Slug consistency

- [ ] `manifest.slug` === bootstrap filename without `.php`.
- [ ] `manifest.slug` === `Text Domain:` header in the bootstrap.
- [ ] Every `block.json` `name` field starts with `{slug}/`.
- [ ] Every `usesContext` and `parent` value references a block name
  that exists in the same payload (or a `core/*` block).

## Routing correspondence

- [ ] Every slug registered via `examplepress_register_route_origin()`
  has a matching `app/templates/{slug}/block.json` AND
  `app/templates/{slug}/index.php` in the payload.
- [ ] Every `app/templates/X/block.json` has `"name": "{slug}/template-X"`.
- [ ] Every `app/components/X/block.json` has `"name": "{slug}/components-X"`.

## Templates

- [ ] Every `index.php` under `app/` has `useBlockProps` on its root
  HTML element.
- [ ] Every template has exactly one root element. No leading/trailing
  whitespace before `<?php`.
- [ ] No template uses `$a`, `$c`, or `$b` aliases. Use `$attributes`,
  `$context`, `$block`.

## Escaping (validator-enforced)

- [ ] Every `<?php echo $...` is wrapped in an allowlisted escape:
  `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `esc_js`,
  `wp_kses_post`, `wp_kses`, `absint`, `intval`, `(int)`, `(float)`,
  `(bool)`, `(string)`, `number_format`, `number_format_i18n`.
- [ ] Every `<?= $... ?>` short echo is wrapped the same way.
- [ ] No literal hex color anywhere in `.php`, `.css`, or `.json`.
- [ ] No literal pixel font size in any `font-size:` declaration.

## Bans

- [ ] No banned PHP tokens: `eval`, `exec`, `system`, `shell_exec`,
  `passthru`, `proc_open`, `popen`, backticks, `base64_decode($var)`,
  variable-path `include`/`require`, `assert($string)`.
- [ ] No outbound HTTP in templates: `curl_*`, `fsockopen`,
  `file_get_contents` with a URL, `wp_remote_*`. (Allowed in `rpc.php`
  and `cron.php`.)
- [ ] No superglobals in any `app/**/*.php`: `$_GET`, `$_POST`,
  `$_REQUEST`, `$_COOKIE`, `$_SERVER`.
- [ ] No write APIs in templates: `update_option`, `add_option`,
  `delete_option`, `update_post_meta`, `add_post_meta`,
  `delete_post_meta`, `wp_insert_post`, `wp_update_post`,
  `wp_delete_post`, `update_user_meta`. (Allowed in `rpc.php` and
  `cron.php`.)
- [ ] No React, no JSX, no `@wordpress/element`, no
  `@wordpress/blocks`, no `@wordpress/block-editor` imports.
- [ ] No `save()` or `edit()` functions. No `registerBlockType()`.
- [ ] No `package.json`, no `webpack.config.*`, no build artifacts.
- [ ] No inline `<script>` or `<style>` tags inside any `index.php`.

## Data sources (skill 45)

- [ ] If the request fits a `repeater` or `query` attribute, that's
  what you used — not `db.php`.
- [ ] If `db.php` exists, `userScoped` is explicitly set (no default).
- [ ] If `userScoped: true`, the template wraps its UI in
  `is_user_logged_in()` and renders a login prompt for visitors.
- [ ] If `rpc.php` defines a write endpoint, it has `'public' => false`
  unless the user explicitly asked for an unauthenticated public write.
- [ ] Every `db.php` field with `required: true` has bounds (`minLength`,
  `maxLength`, `min`, `max`, or `pattern`).

## Quality floor (skill 65)

- [ ] Every user-facing string is wrapped in `esc_html__()`,
  `esc_attr__()`, or `esc_html_e()` with the app slug as the text
  domain.
- [ ] Every `<img>` has an `alt` attribute (use `''` explicitly if
  decorative).
- [ ] Every list/query/repeater path renders a non-empty empty state
  (e.g. "No staff members found yet").
- [ ] Buttons are `<button>`. Links are `<a>`. Never `<div onclick>`.
- [ ] At most one `<main>` across all route templates this app ships.
  Templates use `<main>`; components do not.
- [ ] Heading levels are sequential — no jumps from `<h1>` to `<h4>`.

## Design tokens (skill 60)

- [ ] No Tailwind class with a baked-in palette value
  (`text-blue-500`, `bg-gray-100`, `text-xl`, `font-sans`).
- [ ] Color and typography use `var(--wp--preset--*)` either inline
  or via Tailwind arbitrary-value syntax `[var(--wp--preset--...)]`.
- [ ] No invented CSS custom properties for design tokens.

## Defensive coding

- [ ] Every `$context['{namespace}/{block}'] ?? []` defaults to an empty
  array.
- [ ] Every read of an individual context field has a `?? default`.
- [ ] Every `$attributes['key']` access for an optional field uses
  `?? default`.
- [ ] Every `foreach` over a `repeater` or `query` field has an empty
  state branch.

## Code simplicity

- [ ] No unnecessary intermediate variables. If a value is used once,
  inline it.
- [ ] No class definitions. Closures and procedural code only
  (skill 35).
- [ ] Every function and conditional earns its place. If removing it
  doesn't change behavior, remove it.
- [ ] No obvious comments restating what the code does. Comments
  explain "why," not "what." No TODO/FIXME/HACK. No flattery.
- [ ] Simplest possible implementation: `match` over `if/elseif`
  chains, ternary over 4-line conditionals, `??` over null-check
  blocks, `sprintf` over concatenation chains.
- [ ] Single quotes unless interpolating. Short array syntax.
  Trailing commas on multiline constructs.

If every box is checked, emit the JSON. If any box is not checked,
fix the payload first. **Do not emit a partially-correct generation
hoping the validator will catch the rest.**
