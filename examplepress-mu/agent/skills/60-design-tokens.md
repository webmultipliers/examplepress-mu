# Design tokens — use the live install's values

This install has a specific palette, typography, and spacing scale
configured at the kernel level. **You must use these values, not
invent your own.** WordPress emits each token as a CSS custom
property under the `--wp--preset--*` namespace, so referencing them
in markup is as simple as `var(--wp--preset--color--{slug})`.

The values below are **resolved against this exact install** at
generation time. They are NOT generic WordPress documentation. If a
token is not listed, it does not exist on this site and you should
not invent one.

## Color palette

Live palette JSON:

```json
{{color_palette}}
```

Slugs available:

{{color_slugs}}

CSS variables emitted by the theme:

```css
{{wp_color_vars}}
```

## Typography

Font families:

```json
{{font_families}}
```

Font sizes:

```json
{{font_sizes}}
```

Font size CSS variables:

```css
{{wp_font_size_vars}}
```

## Layout

- `--wp--style--global--wide-size: {{wide_size}}`
- `--wp--style--global--content-size: {{content_size}}`

The block theme already enforces content-flow alignment for elements
with the `alignwide` and `alignfull` class names. Use those instead of
inventing your own max-width logic.

## Spacing

Spacing scale (slug → size):

```json
{{spacing_sizes}}
```

CSS variables follow the pattern `--wp--preset--spacing--{slug}`.

## Using tokens in templates

**In inline styles** (when the value is computed from an attribute):

```php
<div style="background-color: var(--wp--preset--color--<?php echo esc_attr( $attributes['bg'] ); ?>);">
```

**In a static class on a Blockstudio block**:

```php
<section useBlockProps style="background-color: var(--wp--preset--color--primary);">
```

**In a per-block stylesheet** (`app/components/{name}/style.css`):

```css
.my-card {
    background-color: var(--wp--preset--color--surface);
    color:            var(--wp--preset--color--ink);
    padding:          var(--wp--preset--spacing--md);
    font-size:        var(--wp--preset--font-size--lg);
    font-family:      var(--wp--preset--font-family--body);
}
```

## Tailwind — what's allowed and what isn't

**Tailwind IS available** on this install via Blockstudio's Tailwind
integration. You may use Tailwind utility classes, but only for the
specific subsets listed below. Anything else is rejected.

### Allowed: layout primitives

| Category | Allowed classes |
|---|---|
| Display | `block`, `inline-block`, `inline`, `flex`, `inline-flex`, `grid`, `inline-grid`, `hidden`, `contents` |
| Flexbox / grid | `flex-row`, `flex-col`, `flex-wrap`, `flex-1`, `flex-auto`, `flex-none`, `items-*`, `justify-*`, `content-*`, `place-*`, `gap-*`, `grid-cols-*`, `grid-rows-*`, `col-span-*`, `row-span-*` |
| Spacing | `p-*`, `px-*`, `py-*`, `pt-*`, `pr-*`, `pb-*`, `pl-*`, `m-*`, `mx-*`, `my-*`, `mt-*`, `mr-*`, `mb-*`, `ml-*`, `space-x-*`, `space-y-*` |
| Sizing | `w-full`, `w-auto`, `w-fit`, `h-full`, `h-auto`, `h-screen`, `min-w-*`, `min-h-*`, `max-w-*`, `max-h-*` |
| Position | `static`, `relative`, `absolute`, `fixed`, `sticky`, `top-*`, `right-*`, `bottom-*`, `left-*`, `inset-*`, `z-*` |
| Overflow | `overflow-*`, `overflow-x-*`, `overflow-y-*` |
| Text alignment | `text-left`, `text-center`, `text-right`, `text-justify` |
| Cursor / pointer | `cursor-*`, `pointer-events-*` |
| Responsive prefixes | `sm:`, `md:`, `lg:`, `xl:`, `2xl:` on any of the above |

### FORBIDDEN: design-token classes

These classes have baked-in design values that conflict with the
install's palette and typography. They are a hard reject:

- **Color classes:** `text-red-500`, `text-blue-600`, `bg-gray-100`,
  `bg-slate-900`, `border-zinc-300`, etc. ANY class matching
  `(text|bg|border|ring|fill|stroke)-(red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone|black|white)-\d+` is rejected.
- **Font size classes:** `text-xs`, `text-sm`, `text-base`, `text-lg`,
  `text-xl`, `text-2xl`, `text-3xl`, etc.
- **Font family classes:** `font-sans`, `font-serif`, `font-mono`.
- **Font weight named classes are allowed** (`font-bold`, `font-semibold`, etc.) — they don't conflict with the palette.

### How to apply colors / typography with Tailwind

Use Tailwind's arbitrary-value syntax referencing WP preset variables.
This is the ONLY way to use Tailwind for color and typography on this
install:

```html
<!-- WRONG -->
<div class="bg-gray-100 text-blue-600 text-lg">

<!-- RIGHT -->
<div class="bg-[var(--wp--preset--color--surface)] text-[var(--wp--preset--color--primary)] text-[var(--wp--preset--font-size--lg)]">
```

The arbitrary-value syntax works because Tailwind's JIT engine resolves
`[var(...)]` at runtime — it does not need to know the value at build
time.

## Token fallbacks (when merge tags fail)

If the merge tags above (`{{color_palette}}`, `{{color_slugs}}`,
`{{wp_color_vars}}`, `{{font_sizes}}`, etc.) appear as literal
double-brace text in the prompt you receive, the live install data
failed to inject. **DO NOT hallucinate hex codes.** Restrict yourself
to these universally safe presets that every ExamplePress install
guarantees:

- `var(--wp--preset--color--primary)`
- `var(--wp--preset--color--secondary)`
- `var(--wp--preset--color--surface)`
- `var(--wp--preset--color--surface-alt)`
- `var(--wp--preset--color--text)`
- `var(--wp--preset--color--text-faint)`
- `var(--wp--preset--font-size--sm)`
- `var(--wp--preset--font-size--md)`
- `var(--wp--preset--font-size--lg)`
- `var(--wp--preset--font-size--xl)`
- `var(--wp--preset--font-size--2xl)`

## Hard rules

- **Never hardcode hex colors.** Skill 90 makes any `#RRGGBB` in
  generated `.php`, `.css`, or `.json` a hard reject.
- **Never hardcode pixel font sizes.** Skill 90 makes any `\d+px`
  in a `font-size:` context a hard reject.
- **Never define your own custom properties for design tokens.** They
  must come from the kernel's design-tokens features so the user can
  edit them centrally.
- **Never use a Tailwind class with a baked-in palette value.** See the
  forbidden list above.
