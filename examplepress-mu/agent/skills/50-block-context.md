# Block context — parent / child communication

## When to use context (decide first)

Three different concepts all share the word "context" in this stack.
Pick the right one:

| Concept | Use for | Setup |
|---|---|---|
| **Block context** (this skill) | Compile-time data passed from a parent block to its children — theme settings, grid columns, currency, locale. Read in PHP via `$context['{namespace}/{block-slug}']`. | Add `usesContext` to the child's `block.json`. |
| **Loop context** | Per-iteration post data inside a `core/query-loop`. Read in PHP via `$block['context']['postId']`. | No setup — auto-injected. See bottom of this skill. |
| **Interactivity context** (skill 85) | Per-instance UI state for client-side reactivity (open/closed, hover, draft input). Read in JS via `getContext()`. | Add `data-wp-context='{...}'` to the root element. |

Use block context when:
- A parent already has the value as one of its block attributes.
- The value is set once at editor time and never changes after the
  page is rendered.
- Multiple children need the same value and you don't want to duplicate
  the attribute on each one.

Do NOT use block context when:
- You want client-side reactivity → use Interactivity context (skill 85).
- The data is per-request server state → compute it inline in the
  child template.
- The data is global to the whole page → use the bootstrap or a
  filter.

## How it works in Blockstudio

WordPress's standard pattern is two-sided: the parent declares
`providesContext` mapping each attribute to a context key, and the
child lists those keys in `usesContext`. Blockstudio removes the
parent half entirely — **every block automatically provides all of
its attributes as context**, keyed by its block name.

Children just opt in:

```json
{
    "name": "{slug}/components-card",
    "usesContext": [ "{slug}/components-card-grid" ],
    "blockstudio": {
        "attributes": [...]
    }
}
```

Then in `index.php` they read `$context['{namespace}/{block-slug}']`:

```php
<?php
$grid       = $context['{slug}/components-card-grid'] ?? [];
$card_style = $grid['cardStyle'] ?? 'elevated';
?>
```

`$context` is auto-injected into every template. The values go
through Blockstudio's resolution pipeline so
files become attachment data, selects respect `returnFormat`, and
repeaters are fully expanded — same shape as `$attributes` in the parent.

## Worked example: card grid + cards

**Parent:** `app/components/card-grid/block.json`

```json
{
    "$schema": "https://blockstudio.dev/schema/block",
    "name": "{slug}/components-card-grid",
    "title": "Card Grid",
    "category": "design",
    "icon": "grid-view",
    "blockstudio": {
        "attributes": [
            { "id": "columns", "type": "range", "label": "Columns", "default": 3, "min": 1, "max": 4, "step": 1 },
            { "id": "cardStyle", "type": "select", "label": "Card Style", "default": "elevated", "options": [
                { "value": "elevated", "label": "Elevated" },
                { "value": "outlined", "label": "Outlined" },
                { "value": "flat",     "label": "Flat" }
            ]}
        ]
    }
}
```

**Parent:** `app/components/card-grid/index.php`

```php
<div useBlockProps class="grid grid-cols-<?php echo (int) $attributes['columns']; ?> gap-6">
    <InnerBlocks
        allowedBlocks='<?php echo esc_attr( wp_json_encode( [ "{slug}/components-card" ] ) ); ?>'
        template='<?php echo esc_attr( wp_json_encode( [
            [ "{slug}/components-card", [] ],
            [ "{slug}/components-card", [] ],
            [ "{slug}/components-card", [] ],
        ] ) ); ?>'
    />
</div>
```

**Child:** `app/components/card/block.json`

```json
{
    "$schema": "https://blockstudio.dev/schema/block",
    "name": "{slug}/components-card",
    "title": "Card",
    "category": "design",
    "icon": "cover-image",
    "usesContext": [ "{slug}/components-card-grid" ],
    "parent": [ "{slug}/components-card-grid" ],
    "blockstudio": {
        "attributes": [
            { "id": "title", "type": "text", "label": "Title", "default": "Card title" },
            { "id": "description", "type": "textarea", "label": "Description" }
        ]
    }
}
```

**Child:** `app/components/card/index.php`

```php
<?php
$grid  = $context['{slug}/components-card-grid'] ?? [];
$style = $grid['cardStyle'] ?? 'elevated';
$class = match ( $style ) {
    'outlined' => 'rounded-xl border border-gray-200 p-6',
    'flat'     => 'rounded-xl bg-gray-50 p-6',
    default    => 'rounded-xl bg-white shadow-md p-6',
};
?>
<div useBlockProps class="<?php echo esc_attr( $class ); ?>">
    <h3 class="text-lg font-semibold"><?php echo esc_html( $attributes['title'] ); ?></h3>
    <p class="mt-2 text-gray-600"><?php echo esc_html( $attributes['description'] ); ?></p>
</div>
```

## The `parent` property

`"parent": ["{slug}/components-card-grid"]` in the child's `block.json`
restricts where the editor will allow the block to be inserted. With
it, Cards only appear in the inserter when the user is inside a Card
Grid. Without it, Cards show up everywhere and your template becomes
responsible for handling missing context defensively.

Use `parent` for blocks that are meaningless outside their container.
Skip it for blocks that work standalone but gain extra styling from a
parent.

## Multiple parents

A child can subscribe to multiple parents:

```json
{ "usesContext": [ "{slug}/components-section", "{slug}/components-card-grid" ] }
```

```php
<?php
$section = $context['{slug}/components-section'] ?? [];
$grid    = $context['{slug}/components-card-grid'] ?? [];
?>
```

If a parent is not in the ancestor tree, its key is absent from
`$context`. Always use `?? []` and `??` defaults.

## Nested context

Context flows through the entire block tree, not just one level. A
heading nested inside `core/columns` inside a section block can still
read the section's context:

```
{slug}/components-section (theme: dark)
└── core/columns
    └── core/column
        └── {slug}/components-section-heading (reads section context)
```

The `usesContext` subscription works at any depth.

## Loop context (different thing, same name)

Inside a `core/query-loop` block, WordPress provides a separate
context containing the current iteration's post ID. Read it via
`$block['context']` (NOT `$context`):

```php
<?php
$loop_post_id = $block['context']['postId'];
?>
<article>
    <h2><?php echo esc_html( get_the_title( $loop_post_id ) ); ?></h2>
    <p><?php echo esc_html( get_the_excerpt( $loop_post_id ) ); ?></p>
    <a href="<?php echo esc_url( get_permalink( $loop_post_id ) ); ?>">Read more</a>
</article>
```

You do NOT need `usesContext` for `postId` / `postType` — Blockstudio
auto-subscribes every block to those keys.

## Defensive coding rules

1. Always default with `?? []` when reading a parent's context bucket.
2. Always default with `??` when reading individual fields inside it.
3. Don't assume the parent is present — a user might insert the
   child outside its expected wrapper, or the parent might not have
   set the field.
4. Context is **read-only**. Children cannot write back to parents.
5. Don't put expensive computation in templates that read context —
   they re-render in the editor every time the parent's attributes
   change.
