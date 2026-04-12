# Blockstudio blocks — the file pattern

## Templates vs components (decide first)

Every block you generate falls into exactly one of two categories.
Decide before you write any code:

| Category | Folder | Block name format | Used for |
|---|---|---|---|
| **Template** | `app/templates/{slug}/` | `{app-slug}/template-{slug}` | Routed by the kernel router. One per route slug registered in the bootstrap (skill 20). The router dispatches to it via `bs_block()`. |
| **Component** | `app/components/{slug}/` | `{app-slug}/components-{slug}` | Reusable building blocks composed inside templates via `bs_block()` calls or inserted in the editor. NEVER routed directly. |

Hard rule: **the kernel router only dispatches to `template-*`
blocks.** A block under `app/components/` will never be reached by a
URL — if you put your homepage logic in `app/components/home/`, it
won't render. Templates are the entry points; components are internal.

## No React, no JSX, no build step

DO NOT output `block.js`. DO NOT output JSX. DO NOT use
`@wordpress/element`, `@wordpress/blocks`, or `@wordpress/block-editor`.
DO NOT define `edit()` or `save()` functions. DO NOT call
`registerBlockType()`. The view layer is strictly PHP templates.

The only JavaScript file allowed in a block folder is
`script.inline.js`, and it uses the WordPress Interactivity API
(skill 85). No build artifacts of any kind.

## The two-file pattern

A Blockstudio block is a folder containing **two files**:

```
app/templates/front/
├── block.json    ← block metadata + Blockstudio attributes
└── index.php     ← server-rendered template
```

That's the entire API. There is no PHP class to extend, no
`Build::block([...])` call, no `register_block_type` invocation. When
your bootstrap calls `Blockstudio\Build::init(['dir' => 'app'])`,
Blockstudio recursively walks the directory and registers every
folder containing a `block.json`.

## `block.json` — block definition

```json
{
    "$schema": "https://blockstudio.dev/schema/block",
    "name": "{slug}/template-front",
    "title": "Front Page",
    "description": "Homepage template for the app.",
    "category": "theme",
    "icon": "admin-home",
    "blockstudio": true
}
```

The mandatory fields:

| Field | Notes |
|---|---|
| `$schema` | Always `https://blockstudio.dev/schema/block`. Gives operators IDE autocomplete. |
| `name` | `{your-app-slug}/{block-slug}`. For route templates, the block slug is `template-{route-slug}`. |
| `title` | Shown in the block inserter. |
| `category` | `theme` for templates, `design` or `widgets` for components, or any registered category. |
| `blockstudio` | `true` to enable, OR an object containing `attributes`, `interactivity`, etc. |

## Adding fields (Blockstudio attributes)

Set `blockstudio` to an object and add an `attributes` array. Each
entry becomes a Gutenberg-editable field AND is automatically passed
into your template as `$attributes['{id}']`.

```json
{
    "$schema": "https://blockstudio.dev/schema/block",
    "name": "{slug}/components-hero",
    "title": "Hero",
    "category": "design",
    "icon": "cover-image",
    "blockstudio": {
        "attributes": [
            {
                "id": "title",
                "type": "text",
                "label": "Headline",
                "default": "Welcome"
            },
            {
                "id": "image",
                "type": "files",
                "label": "Background image",
                "multiple": false
            }
        ]
    }
}
```

The next skill (40) is the full attribute-type reference.

## `index.php` — the template

Templates are plain PHP. They run server-side. Three variables are
auto-injected into every template:

| Variable | Contains |
|---|---|
| `$attributes` | The block's own resolved attribute values. Files become full attachment data, selects respect `returnFormat`, repeaters are expanded. |
| `$context` | Parent block context — see skill 50. |
| `$block` | Block metadata: `$block['data']` (router payload), `$block['context']` (Query loop context), `$block['postId']` (current page post ID). |

**Use the full names. Never use the `$a` / `$c` / `$b` short aliases.**
They look like loop variables and the agent will mix them up. Skill 90
makes them a hard reject.

A minimal template:

```php
<?php
/**
 * Hero component.
 * @package {slug}
 */
?>
<section useBlockProps class="hero" style="background-color: var(--wp--preset--color--primary);">
    <div class="hero-inner">
        <h1 class="hero-title"><?php echo esc_html( $attributes['title'] ); ?></h1>
        <?php if ( ! empty( $attributes['image']['url'] ) ) : ?>
            <img
                src="<?php echo esc_url( $attributes['image']['url'] ); ?>"
                alt="<?php echo esc_attr( $attributes['image']['alt'] ?? '' ); ?>"
                class="hero-image">
        <?php endif; ?>
    </div>
</section>
```

### Two non-obvious rules

**1. `useBlockProps` must appear on the root element.** It is a
Blockstudio pseudo-attribute that gets rewritten into the correct
WordPress block-wrapper attributes (class names, anchors, alignment,
etc.) at render time. Always put it on the outermost element of the
template.

**2. Templates can output directly with `<?php ?>` tags.** No
`ob_start()` / `ob_get_clean()` wrapper is needed. Blockstudio captures
the output of the file. Just write HTML interleaved with PHP the way
you would in a regular WordPress template.

## Composing other Blockstudio blocks

Templates can render other Blockstudio blocks via `bs_block()`:

```php
<main useBlockProps>
    <?php echo bs_block( [ 'id' => '{slug}/components-hero', 'data' => [
        'title' => 'Welcome to the staff directory',
    ] ] ); ?>

    <?php echo bs_block( [ 'id' => '{slug}/components-card-grid' ] ); ?>
</main>
```

This is how you build a template from reusable components: define each
piece as its own block under `app/components/`, then compose them
inside `app/templates/{slug}/index.php` with `bs_block()`.

## InnerBlocks for editor-composable templates

If a block should accept arbitrary child blocks in the editor, use the
`<InnerBlocks />` pseudo-element:

```php
<section useBlockProps class="section">
    <InnerBlocks
        allowedBlocks='<?php echo esc_attr( wp_json_encode( [
            "{slug}/components-card",
            "core/heading",
            "core/paragraph",
        ] ) ); ?>'
        template='<?php echo esc_attr( wp_json_encode( [
            [ "core/heading", [ "level" => 2, "content" => "Section title" ] ],
            [ "{slug}/components-card", [] ],
        ] ) ); ?>'
    />
</section>
```

`allowedBlocks` (optional) restricts what the editor can insert.
`template` (optional) pre-populates the inner blocks when a user
inserts the parent. Both attributes take JSON-encoded strings.

## Naming convention

Use folder names that match the block slug, and put templates and
components in separate subdirectories:

```
app/
├── templates/
│   ├── front/             → name: {slug}/template-front
│   ├── archive/           → name: {slug}/template-archive
│   └── single/            → name: {slug}/template-single
└── components/
    ├── hero/              → name: {slug}/components-hero
    ├── card/              → name: {slug}/components-card
    └── card-grid/         → name: {slug}/components-card-grid
```

The router only dispatches to `template-*` blocks. Components are
internal — they exist to be composed inside templates.
