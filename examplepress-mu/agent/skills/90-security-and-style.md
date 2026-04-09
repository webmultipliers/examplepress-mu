# Security and style guide

These rules are enforced by `AppValidator::validateGenerated()` BEFORE
your output ever touches GitHub. The validator runs at **generation
time**, not install time — failures discard the entire payload before
any commit, release, or install.

## LLM anti-patterns — DO NOT DO THESE

The most common ways generations fail. Each item is enforced by a
specific validator rule:

- **DO NOT write a `save()` or `edit()` function.** Blockstudio
  templates are pure PHP. There is no React. Skill 30, rule 13.
- **DO NOT import from `@wordpress/element`, `@wordpress/blocks`,
  or `@wordpress/block-editor`.** The only allowed JS import is
  `@wordpress/interactivity`. Skill 85, rule 13.
- **DO NOT use `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` /
  `$_SERVER`.** Read data from `$attributes`, `$context`, or WP query
  functions. Rule 9.
- **DO NOT call `update_option`, `update_post_meta`, or
  `wp_insert_post` from a template.** Writes belong in `rpc.php` or
  `cron.php`. Rule 10.
- **DO NOT use Tailwind color classes** (`text-blue-500`, `bg-gray-100`)
  or **size classes** (`text-xl`, `font-sans`). Use the
  `[var(--wp--preset--*)]` arbitrary-value syntax. Skill 60, rule 16.
- **DO NOT use the `$a` / `$c` / `$b` aliases.** Use `$attributes`,
  `$context`, `$block`. They look like loop variables and the model
  mixes them up.
- **DO NOT invent block attribute types.** The closed set is in skill
  40. `email`, `url`, `datetime`, `icon`, `password` do not exist.
  Rule 19.
- **DO NOT omit `useBlockProps`** from a template's root element.
  Rule 17.
- **DO NOT use `<div onclick>` instead of `<button>`.** See skill 65.
- **DO NOT output `package.json`, `webpack.config.js`,
  `vite.config.js`, or any build artifact.** Rule 13.

## Banned PHP tokens (hard reject)

Your generated PHP files must not contain ANY of:

- `eval(`
- `exec(`
- `system(`
- `shell_exec(`
- `passthru(`
- `proc_open(`
- `popen(`
- Backtick operators (`` `command` ``)
- `base64_decode(` invoked on a variable
- `create_function(`
- `assert(` with a string argument
- `include` / `require` with a variable path
- `fsockopen(`
- `stream_socket_client(`

Literal-string `base64_decode` is allowed but you should never need it
in a Blockstudio block.

## Banned in templates (allowed in `rpc.php` and `cron.php`)

These are allowed in REST/cron contexts but rejected in any other
file under `app/` because templates run on every page view:

- **Write APIs:** `update_option`, `add_option`, `delete_option`,
  `update_post_meta`, `add_post_meta`, `delete_post_meta`,
  `wp_insert_post`, `wp_update_post`, `wp_delete_post`,
  `add_user_meta`, `update_user_meta`.
- **Outbound HTTP:** `curl_*`, `wp_remote_get`/`wp_remote_post`,
  `file_get_contents` with a URL argument, `fsockopen`,
  `stream_socket_client`. Templates never make outbound HTTP requests.
- **Superglobals:** `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`,
  `$_SERVER`. Read from `$attributes`, `$context`, or WP query
  helpers (`get_query_var`, `get_the_*`, `is_*`).

## Banned file paths

- Absolute paths (anything starting with `/` or `C:\`)
- `..` traversal segments
- Paths outside the plugin root

## Output escaping — always

Every dynamic value rendered to HTML must be escaped at the point of
output. Blockstudio templates run server-side, so the standard
WordPress escaping API applies.

| Use this | When the value goes into |
|---|---|
| `esc_html()` | Plain text inside `<p>`, `<h1>`, `<span>`, etc. |
| `esc_attr()` | An HTML attribute (`<div class="<?php echo esc_attr( ... ); ?>">`) |
| `esc_url()` | `href`, `src`, `action` |
| `wp_kses_post()` | Trusted rich text (e.g. a `richtext` attribute) |
| `absint()` | Integer attributes |
| `(int)` | Cast for inline numeric output |
| `sanitize_key()` | Slug-like strings |

Never echo `$attributes['anything']` directly. Even if you think the value is
safe, escape it. Common mistakes:

```php
// WRONG
<h1><?php echo $attributes['title']; ?></h1>
<a href="<?php echo $attributes['cta']['url']; ?>">

// RIGHT
<h1><?php echo esc_html( $attributes['title'] ); ?></h1>
<a href="<?php echo esc_url( $attributes['cta']['url'] ); ?>">
```

## Never write to the database from a template

Templates are renderers. They execute on every page view. Calling
`update_option`, `update_post_meta`, `wp_insert_post`, or any other
write API from inside `index.php` is forbidden — it would silently
mutate state on every page load.

If your block needs to write data, use the full-stack pattern from
skill 80 (`db.php` + `rpc.php`). All writes go through Blockstudio's
REST endpoints with capability checks and field validation.

## Never use superglobals in a template

`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER` are forbidden
inside block templates. They bypass the WordPress sanitization layer
and they smuggle untrusted input into your render path.

If you need request data, the only safe sources are:
- `$attributes[*]` — block attributes (sanitized by Blockstudio)
- `$context[*]` — parent block context
- `$block['data'][*]` — data passed by the router via `examplepress_route_data`
- WordPress query functions (`get_query_var`, `is_*`, `get_the_*`)

## Style hierarchy

Use, in this order:

1. **WordPress preset CSS variables** — `var(--wp--preset--color--{slug})`,
   `var(--wp--preset--font-size--{slug})`, etc. See skill 60 for the
   live values on this install.
2. **A per-block stylesheet** — `app/components/{block}/style.css`,
   loaded automatically by Blockstudio when the block is rendered.
3. **Tailwind utility classes** for layout and spacing primitives only
   (`flex`, `grid`, `gap-4`, `p-6`, `mx-auto`). For color, typography,
   and sizing, use Tailwind's arbitrary-value syntax to reference
   preset variables: `bg-[var(--wp--preset--color--primary)]`.
4. **Inline `style="..."` attributes** only when the value is computed
   from a block attribute and there is no static CSS path.

## Never use inline `<script>` or `<style>` tags

If a block needs interactivity, use Blockstudio's `script.inline.js`
file (auto-loaded into the page) and the WordPress Interactivity API
directives (`data-wp-*`). See skill 80.

If a block needs styles, use `style.css` in the block folder.

Inline `<script>` and `<style>` tags inside block output are blocked
by the validator's pattern checks (and they break the block editor's
preview anyway).

## Hooks: register at file load, not in templates

Hook registration with `add_action` / `add_filter` should happen at
the top of a block's `index.php`, OUTSIDE the template body, OR in
the plugin bootstrap. Never inside the rendered HTML — the file is
re-included on every block render and you would register the same
hook multiple times per request.

```php
<?php
// Top of file: hook registration runs once per request.
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        '{slug}-extras',
        plugin_dir_url( __FILE__ ) . 'extras.css',
        [],
        '1.0.0'
    );
} );
?>

<!-- Template body: pure rendering. -->
<section useBlockProps>
    <h1><?php echo esc_html( $attributes['title'] ); ?></h1>
</section>
```

## Final pre-flight checklist

See **skill 95** — the canonical pre-flight checklist lives there as
the last skill in the curriculum so it gets the most attention weight
right before you emit JSON.
