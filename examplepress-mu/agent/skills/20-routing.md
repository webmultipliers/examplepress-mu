# Routing — how URLs become templates

The ExamplePress theme has exactly one block in its templates: a
**router block** that runs on every page request. The router asks the
kernel "which route origin claims this URL?" and dispatches to the
matching template block. Your app participates by registering a route
origin in its bootstrap (skill 10).

## The dispatch chain

```
1. WordPress resolves the request to a query (is_singular, is_archive, etc.)
2. The theme's router block runs:
       $resolved        = Router::resolveRoute();
       // → ['namespace' => 'staff-directory', 'slug' => 'front']
       $template_prefix = Router::templatePrefix();
       // → '{{template_prefix}}'
       $full_block_name = Router::templateBlockName($slug, $prefix, $namespace);
       // → 'staff-directory/template-front'
3. bs_block(['id' => $full_block_name, 'data' => $route_data]) renders
   the file at app/templates/front/index.php inside your plugin.
```

For your app to be reachable, three things must align:

1. The route origin's namespace string equals your manifest slug.
2. A condition in the origin returns `true` for the request.
3. A block exists at `app/templates/{slug}/` with the matching
   `block.json` name.

## Registering route conditions

Inside your bootstrap (skill 10), call:

```php
examplepress_register_route_origin( '{slug}', [
    'front'   => fn() => is_front_page() || is_home(),
    'single'  => fn() => is_singular( 'post' ),
    'archive' => fn() => is_archive(),
    '404'     => fn() => is_404(),
], 10 );
```

The first argument is your namespace (always your plugin slug). The
second is a map of route slug → callable. The router walks them in
declaration order; the first callable that returns `true` wins.

The third argument is **priority** — lower numbers win when multiple
apps claim the same condition.

### Priority selection guide

| Priority | Use for |
|---|---|
| `5` (or lower) | Aggressive overrides — completely hijacking the homepage, taking over the entire admin. Only when the user explicitly asks. |
| `10` (default) | Standard priority for specific Custom Post Types or specific URL matches. **Use this by default.** |
| `20` (or higher) | Generic fallbacks — a catch-all archive, a 404 page, anything that should only kick in when nothing else matched. |

**Tie-break rule.** When two origins claim the same condition at the
same priority, the resolver walks them in registration order, and
registration order is determined by WordPress plugin load order
(filesystem-alphabetical by directory name within `wp-content/plugins`).
**Do not rely on this** — it is brittle. Use priority to disambiguate
explicitly.

**Assume other apps exist.** Register with the priority that reflects
how specifically your app matches the URL, not how much you "want to
win." Aggressive overrides at priority `5` should be rare and
explicit. Default to `10`.

## Common route patterns

**Replace the homepage:**
```php
examplepress_register_route_origin( '{slug}', [
    'front' => fn() => is_front_page() || is_home(),
], 5 );
```

**Custom post type detail page:**
```php
examplepress_register_route_origin( '{slug}', [
    'single' => fn() => is_singular( 'staff_member' ),
], 10 );
```

**Specific URL only:**
```php
examplepress_register_route_origin( '{slug}', [
    'directory' => fn() => is_page( 'staff' ),
], 10 );
```

**Multiple slugs from one app:**
```php
examplepress_register_route_origin( '{slug}', [
    'list'   => fn() => is_post_type_archive( 'staff_member' ),
    'detail' => fn() => is_singular( 'staff_member' ),
    'search' => fn() => is_search() && get_query_var( 'post_type' ) === 'staff_member',
], 10 );
```

Each slug becomes a separate template block at
`app/templates/{slug}/`. The router resolves the right one per request.

## The template block name format

By default, the kernel expects template blocks to be named
`{namespace}/template-{slug}`. The `template-` prefix is filterable.

Your `block.json` MUST match the pattern. For an app with slug
`staff-directory` registering a route slug `list`, the block name is:

```json
{ "name": "staff-directory/template-list" }
```

## Filters you can use to override the dispatch

The kernel exposes three filters for advanced apps. None are required
for normal use:

| Filter | Purpose |
|---|---|
| `examplepress_template_prefix` | Change `template-` to something else (e.g. `view-`). Returns the new prefix string. |
| `examplepress_resolved_origin` | Mutate the resolved `['namespace' => ..., 'slug' => ...]` array after the registry walk but before block name assembly. |
| `examplepress_template_block_name` | Take full control of the dispatched block name. Receives `($block_name, $slug, $prefix, $namespace)`. |
| `examplepress_route_data` | Inject data into the `bs_block` call's `data` array. The template receives it via `$block['data']`. |

For 95% of apps you do not need any of these. They exist for things
like multi-tenant overrides and migrating between block-name conventions.

## Passing data into the template

The router calls `bs_block(['id' => $name, 'data' => $route_data])`.
The data array is whatever the `examplepress_route_data` filter
returns. Inside your template you read it as `$block['data']`:

```php
<?php $current_user = $block['data']['user'] ?? null; ?>
```

If you want to pass per-request data without using the filter, the
cleanest pattern is to compute it inside the template's `index.php`
itself — `$_SERVER`, query vars, post objects, etc., are all
available because the template runs as part of the page render.
