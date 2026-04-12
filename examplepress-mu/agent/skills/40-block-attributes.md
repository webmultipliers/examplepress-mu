# Block attributes — the field types

Blockstudio attributes are declared in `block.json` under
`blockstudio.attributes`. Each one becomes:
- An editor field in the block sidebar (auto-generated UI).
- A value injected into your template as `$attributes['{id}']`.

## Closed type list (HARD RULE)

**These are the only supported attribute types.** Skill 90's
validator (rule 19) rejects any `block.json` whose attributes use a
type outside this list:

`text`, `textarea`, `richtext`, `number`, `range`, `toggle`, `select`,
`color`, `files`, `link`, `repeater`, `query`

Do not invent types like `email`, `url`, `datetime`, `password`,
`date`, `tel`, `phone`, `address`, `icon`. They do not exist. If you
need an email field, use `text` and validate server-side. If you
need a date field, use `text` with a `pattern`. If you need an icon
picker, use `select` with the icon names as `value`/`label` pairs.

## Common attribute properties

Every attribute supports:

| Property | Notes |
|---|---|
| `id` | The variable key used in `$attributes['{id}']`. Required. |
| `type` | Field type (see table below). Required. |
| `label` | Human label shown in the sidebar. |
| `default` | Default value. Type-coerced to match the field type. |
| `help` | Subtext rendered under the label. |
| `width` | `25` / `33` / `50` / `66` / `75` / `100` — column width in the sidebar. |

## Field types

### `text`

Single-line text input.

```json
{ "id": "title", "type": "text", "label": "Title", "default": "Hello" }
```

In template: `<?php echo esc_html( $attributes['title'] ); ?>`

### `textarea`

Multi-line text input. Plain text only — no rich text.

```json
{ "id": "description", "type": "textarea", "label": "Description", "default": "" }
```

### `richtext`

Rich text editor with inline formatting (bold, italic, links).
Must be output via `wp_kses_post`:

```json
{ "id": "body", "type": "richtext", "label": "Body" }
```

```php
<?php echo wp_kses_post( $attributes['body'] ); ?>
```

### `number`

Numeric input.

```json
{ "id": "count", "type": "number", "label": "Count", "default": 3, "min": 1, "max": 12 }
```

### `range`

Slider for numeric values.

```json
{ "id": "columns", "type": "range", "label": "Columns", "default": 3, "min": 1, "max": 4, "step": 1 }
```

### `toggle`

Boolean switch.

```json
{ "id": "highlighted", "type": "toggle", "label": "Highlighted", "default": false }
```

In template: `<?php if ( $attributes['highlighted'] ) : ?>...<?php endif; ?>`

### `select`

Dropdown.

```json
{
    "id": "size",
    "type": "select",
    "label": "Size",
    "default": "md",
    "options": [
        { "value": "sm", "label": "Small" },
        { "value": "md", "label": "Medium" },
        { "value": "lg", "label": "Large" }
    ]
}
```

### `color`

Color picker. Defaults to the install's preset palette
({{color_slugs}}). Returns a slug or a raw hex depending on user choice.

```json
{ "id": "background", "type": "color", "label": "Background" }
```

### `files`

Media library picker. Returns full attachment data.

```json
{ "id": "image", "type": "files", "label": "Image", "multiple": false }
```

In template:
```php
<?php if ( ! empty( $attributes['image']['url'] ) ) : ?>
    <img
        src="<?php echo esc_url( $attributes['image']['url'] ); ?>"
        alt="<?php echo esc_attr( $attributes['image']['alt'] ?? '' ); ?>"
        width="<?php echo (int) ( $attributes['image']['width'] ?? 0 ); ?>"
        height="<?php echo (int) ( $attributes['image']['height'] ?? 0 ); ?>">
<?php endif; ?>
```

For `multiple: true`, `$attributes['image']` is an array of attachment objects;
loop with `foreach`.

### `link`

Link picker (WordPress link UI). Returns `{ url, title, opensInNewTab }`.

```json
{ "id": "cta", "type": "link", "label": "Call to action" }
```

```php
<?php if ( ! empty( $attributes['cta']['url'] ) ) : ?>
    <a href="<?php echo esc_url( $attributes['cta']['url'] ); ?>"
       <?php echo ! empty( $attributes['cta']['opensInNewTab'] ) ? 'target="_blank" rel="noopener"' : ''; ?>>
        <?php echo esc_html( $attributes['cta']['title'] ?: 'Learn more' ); ?>
    </a>
<?php endif; ?>
```

### `repeater`

Variable-length list of grouped fields.

```json
{
    "id": "features",
    "type": "repeater",
    "label": "Features",
    "attributes": [
        { "id": "title", "type": "text", "label": "Title" },
        { "id": "description", "type": "textarea", "label": "Description" }
    ]
}
```

In template:
```php
<?php if ( ! empty( $attributes['features'] ) ) : ?>
    <ul class="features">
        <?php foreach ( $attributes['features'] as $feature ) : ?>
            <li>
                <h3><?php echo esc_html( $feature['title'] ); ?></h3>
                <p><?php echo esc_html( $feature['description'] ); ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
```

Repeaters can nest — a repeater field can contain another repeater
inside its `attributes` array.

### `query`

A WordPress query result. Returns `WP_Post[]`.

```json
{
    "id": "posts",
    "type": "query",
    "label": "Posts",
    "post_type": "post",
    "default": { "per_page": 5, "orderby": "date", "order": "DESC" }
}
```

```php
<?php foreach ( $attributes['posts'] as $post ) : setup_postdata( $post ); ?>
    <article>
        <h2><?php the_title(); ?></h2>
        <?php the_excerpt(); ?>
    </article>
<?php endforeach; wp_reset_postdata(); ?>
```

## Width and grouping

Use `width` to lay out fields side-by-side in the sidebar:

```json
"attributes": [
    { "id": "title", "type": "text", "label": "Title", "width": 100 },
    { "id": "size",  "type": "select", "label": "Size", "width": 50, "options": [...] },
    { "id": "color", "type": "color",  "label": "Color", "width": 50 }
]
```

## Defaults always win on first insert

Every attribute with a `default` will be populated with that value
the first time the block is inserted. Always provide sensible defaults
so blocks render something usable on insertion — never assume the user
will fill in fields before previewing.
