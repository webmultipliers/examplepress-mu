# i18n, accessibility, and semantic HTML

The skill pack's other rules cover security and structure. This one
covers the quality floor — the things a generated app should always
get right but that LLMs forget by default.

## Internationalization (i18n)

**Every user-facing string in PHP must be translatable using the app
slug as the text domain.** Hardcoded English strings are a quality
reject — even if you're convinced no one will translate it, the
discipline forces you to keep markup separate from copy.

| Use this | When |
|---|---|
| `esc_html__( 'Text', '{slug}' )` | Returning text safe for HTML output. |
| `esc_html_e( 'Text', '{slug}' )` | Echoing text safe for HTML output. |
| `esc_attr__( 'Text', '{slug}' )` | Returning text for an HTML attribute. |
| `esc_attr_e( 'Text', '{slug}' )` | Echoing text for an HTML attribute. |
| `__( 'Text', '{slug}' )` | Returning text passed to another function (e.g. `wp_kses_post( __( ..., '{slug}' ) )`). Never echo bare. |
| `_n( 'item', 'items', $count, '{slug}' )` | Singular/plural form. |
| `sprintf( esc_html__( 'Welcome, %s', '{slug}' ), $name )` | Interpolating into a translated string. |

Wrong:
```php
<button>Read more</button>
<input type="text" placeholder="Email address">
```

Right:
```php
<button><?php esc_html_e( 'Read more', '{slug}' ); ?></button>
<input type="text" placeholder="<?php esc_attr_e( 'Email address', '{slug}' ); ?>">
```

The text domain MUST be the manifest slug. The kernel uses this to
verify the bootstrap's `Text Domain:` header matches.

### Translatable strings for PHP fed by JS

For strings that originate in PHP and are then surfaced in
`script.inline.js` (toast text, error messages, button labels),
inject them via `wp_interactivity_state` so the i18n happens in PHP:

```php
wp_interactivity_state( '{slug}/components-todo', [
    'i18n' => [
        'add'      => esc_html__( 'Add', '{slug}' ),
        'empty'    => esc_html__( 'No todos yet', '{slug}' ),
        'failed'   => esc_html__( 'Failed to save', '{slug}' ),
    ],
] );
```

Then in the JS or template:

```html
<button data-wp-text="state.i18n.add"></button>
```

## Accessibility (a11y)

### Images

Every `<img>` tag MUST have an `alt` attribute. When using the
`files` attribute type, fall back to an empty string for decorative
images — never omit the attribute:

```php
<img
    src="<?php echo esc_url( $attributes['image']['url'] ); ?>"
    alt="<?php echo esc_attr( $attributes['image']['alt'] ?? '' ); ?>"
    width="<?php echo (int) ( $attributes['image']['width'] ?? 0 ); ?>"
    height="<?php echo (int) ( $attributes['image']['height'] ?? 0 ); ?>">
```

### Buttons and links

- Use `<button>` for an action that does something (toggle, submit,
  open modal). NEVER `<div onclick>` or `<a>` with no `href`.
- Use `<a href="...">` for navigation that takes the user somewhere.
- A `<div role="button">` is always wrong. Use the real element.
- Buttons that contain only an icon must have an `aria-label`:
  ```html
  <button aria-label="<?php esc_attr_e( 'Close', '{slug}' ); ?>">×</button>
  ```

### Forms

Every `<input>`, `<select>`, `<textarea>` must have a corresponding
`<label>` (preferred) or an `aria-label`:

```php
<label>
    <span><?php esc_html_e( 'Email', '{slug}' ); ?></span>
    <input type="email" name="email" required>
</label>
```

Or with `for`:

```php
<label for="email-field"><?php esc_html_e( 'Email', '{slug}' ); ?></label>
<input id="email-field" type="email" name="email" required>
```

### ARIA — only when semantic HTML can't express the intent

Don't slap `role="article"` on an `<article>` element. Don't add
`aria-label` to a `<button>` that already has visible text. Use ARIA
only when there's no native HTML element that expresses what you
mean — and even then, prefer changing the markup over adding ARIA.

## Semantic HTML

LLMs default to "div soup". You must use the right HTML5 element for
the role:

| Use | For |
|---|---|
| `<main>` | The primary content region of a page. |
| `<header>` | The masthead/banner of the page. |
| `<nav>` | Navigation regions (primary nav, breadcrumbs, pagination). |
| `<footer>` | The page footer. |
| `<article>` | A self-contained item that could stand alone (a card, a blog post preview, a comment, a product card). |
| `<section>` | A thematic grouping of related content. Should have an accessible name (`aria-labelledby` pointing at its heading, or its own `<h2>`). |
| `<aside>` | Sidebars, callouts, ads, related content. |
| `<figure>` / `<figcaption>` | Images with captions. |

### The `<main>` rule (CRITICAL)

**There must be exactly one `<main>` element rendered per page view.**

- A **route template** (`{slug}/template-front`, `{slug}/template-single`,
  etc.) wraps its core content in `<main useBlockProps>`.
- A **component** (`{slug}/components-hero`, `{slug}/components-card`,
  etc.) MUST NOT use `<main>`. Components are composed inside templates;
  if a component used `<main>` you'd end up with multiple `<main>`
  elements per page, which is invalid HTML and breaks screen readers.

Components should use `<section>`, `<article>`, `<div>`, or whatever
matches their semantic role — never `<main>`.

### Void elements — never self-close

HTML5 void elements (`<img>`, `<input>`, `<br>`, `<hr>`, `<meta>`,
`<link>`) must NOT have a self-closing slash. The trailing ` />` is
XHTML legacy and wastes bytes in the JSON output:

```php
<!-- WRONG -->
<img src="..." alt="" />
<input type="text" name="email" />
<br />

<!-- RIGHT -->
<img src="..." alt="">
<input type="text" name="email">
<br>
```

### Heading hierarchy

Headings must form a sensible outline. Don't jump levels:

- Route templates start at `<h1>`. There should be exactly one `<h1>`
  per page.
- Components inside templates start at `<h2>` or lower — they are
  nested inside the template's `<h1>`.
- Within a section, sub-sections use the next heading level. Never
  skip levels (no `<h1>` directly to `<h4>`).

## Empty states

Every list/loop that could be empty needs an empty state branch.
Skill 95's preflight checklist enforces this:

```php
<?php if ( empty( $attributes['posts'] ) ) : ?>
    <p class="ep-empty">
        <?php esc_html_e( 'No posts found yet.', '{slug}' ); ?>
    </p>
<?php else : ?>
    <ul class="ep-list">
        <?php foreach ( $attributes['posts'] as $post ) : ?>
            <li><?php echo esc_html( get_the_title( $post ) ); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
```

The empty message should be specific (`"No staff members yet — add
one in the admin"`) rather than generic (`"No items"`). Specific
messages help users understand what to do next.

## Hard rules

- Every user-facing string is wrapped in `esc_html__()`, `esc_attr__()`,
  `esc_html_e()`, `esc_attr_e()`, or `_n()` with the manifest slug as
  the text domain.
- Every `<img>` has an `alt` attribute.
- Every `<button>` is a `<button>`. Every navigation `<a>` has an
  `href`.
- Exactly one `<main>` element across all route templates the app
  ships. Components never use `<main>`.
- Every list/loop has an empty state.
- Heading levels are sequential — no jumps.
