# File-based pages — when to use them

Blockstudio includes a "file-based pages" feature that lets you
define WordPress pages as files in your app and have them sync to the
database automatically. **For most generated apps you do NOT need
this** — the routing system from skill 20 already lets your templates
render at any URL without creating WordPress pages.

Use file-based pages only when:
- The user wants a real WordPress page that appears in menus, search,
  and the admin Pages list.
- The user wants to combine your blocks with the standard WordPress
  block editor in a controlled way (locking some sections, leaving
  others open for client edits).

If the user just wants "a homepage that shows our staff directory",
use a route origin (skill 20) — not a file-based page.

## File layout

```
{slug}/
└── app/
    └── pages/
        └── about/
            ├── page.json
            └── index.php
```

## `page.json` shape

```json
{
    "name": "about",
    "title": "About Us",
    "slug": "about",
    "postStatus": "publish",
    "templateLock": "contentOnly"
}
```

| Field | Notes |
|---|---|
| `name` | Internal identifier. Must be unique within the app. |
| `title` | Page title. |
| `slug` | URL slug (defaults to `name`). |
| `postStatus` | `publish` / `draft` / `private`. |
| `templateLock` | See below. |
| `blockEditingMode` | See below. |
| `templateFor` | Set to a post type (e.g. `product`) to use this as the default block structure for all new posts of that type instead of creating a single page. |

## `index.php` — HTML template

This is HTML that Blockstudio's parser converts into WordPress blocks.
Standard tags map directly:

| HTML | Block |
|---|---|
| `<p>` | `core/paragraph` |
| `<h1>` … `<h6>` | `core/heading` |
| `<ul>` / `<ol>` | `core/list` |
| `<img>` | `core/image` |
| `<div>` / `<section>` | `core/group` |
| `<hr>` | `core/separator` |
| `<table>` | `core/table` |
| `<details>` | `core/details` |

For blocks without a direct HTML tag, use `<block name="...">`:

```html
<block name="core/cover" url="https://example.com/hero.jpg">
    <h1>Welcome</h1>
    <p>Subtitle text.</p>
</block>

<block name="core/columns">
    <block name="core/column">
        <p>Left column.</p>
    </block>
    <block name="core/column">
        <p>Right column.</p>
    </block>
</block>

<block name="{slug}/components-card-grid" columns="3">
    <block name="{slug}/components-card" title="First" />
    <block name="{slug}/components-card" title="Second" />
</block>
```

## Locking the editor

Two independent controls govern what clients can edit:

**`templateLock` controls structure** (add/remove/move blocks):

| Value | Effect |
|---|---|
| `"all"` (default) | No edits at all. |
| `"contentOnly"` | Edit text content but cannot add/remove/move blocks. |
| `"insert"` | Cannot add or remove, but can reorder. |
| `false` | Full freedom. |

**`blockEditingMode` controls per-block interaction**:

| Value | Effect |
|---|---|
| `"default"` | Normal editing. |
| `"contentOnly"` | Only text content editable; settings frozen. |
| `"disabled"` | Block cannot be selected at all. |

You can set `blockEditingMode` per-element inside the template:

```html
<block name="core/cover" url="https://example.com/hero.jpg">
    <h1 blockEditingMode="contentOnly">Edit this heading</h1>
    <p blockEditingMode="contentOnly">Edit this paragraph too.</p>
    <block name="core/buttons">
        <block name="core/button" url="/contact">This button is frozen</block>
    </block>
</block>
```

The most common pattern: `templateLock: "all"` + `blockEditingMode:
"disabled"` page-wide, then opt specific elements into `contentOnly`.

## Preserving client edits across template updates

By default, when you update an `index.php`, Blockstudio replaces the
entire page content on the next sync. To protect client edits in
specific sections, add `key="..."` attributes:

```html
<block name="core/cover" key="hero" url="https://example.com/hero.jpg">
    <h1>Default headline</h1>
    <p>Default description.</p>
</block>

<div key="features">
    <h2>Features</h2>
    <p>Feature intro.</p>
</div>
```

Keyed blocks keep client-edited content (text, inner blocks) on
re-sync. Structural attributes from the template (like the cover's
`url`) still update. Unkeyed blocks are replaced wholesale.

Keys must be globally unique within a template. Use descriptive names:
`hero`, `features`, `cta`, `testimonials`.

## When to use file-based pages instead of route templates

| Use a route template (skill 20) when | Use a file-based page when |
|---|---|
| You want code to own the entire view | You want clients to be able to edit text in Gutenberg |
| The URL is dynamic (single posts, archives, search) | The URL is a static slug |
| You don't want a WordPress page to exist in the database | You want the page to appear in menus, search, sitemap |
| The view doesn't need to be editor-composable | The view should mix your blocks with core blocks |

For 90% of generated apps, route templates are the right answer.
File-based pages are powerful but introduce database state, sync
behavior, and key management.
