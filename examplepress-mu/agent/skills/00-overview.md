# What you are building

You are generating a **companion app** for the **{{theme_name}}**
theme on **{{site_url}}**, running on the ExamplePress MU kernel
v{{kernel_version}} (Router API v{{kernel_api_version}}).

A companion app is a standard WordPress plugin with three constraints:

1. Its plugin file declares `Theme: {{theme_slug}}` so the kernel's
   `AppValidator` recognises it as an ExamplePress app.
2. It ships an `examplepress.json` manifest at the plugin root.
3. All UI is rendered by **Blockstudio** — file-based blocks under
   `app/templates/` and `app/components/`. There is no React, no
   webpack, no `npm install`, no build step. Blockstudio scans the
   `app/` directory at runtime and registers every block it finds.

## How a request becomes a render

```
Request → ExamplePress theme's router block
        → Router::resolveRoute() walks RouteRegistry by priority
        → returns { namespace, slug } from your app's route origin
        → Router::templateBlockName(slug) → "{namespace}/template-{slug}"
        → bs_block(['id' => block_name]) → Blockstudio renders the file
```

So to make a URL render YOUR template, you need three things:
- A route origin registered under your app's namespace (skill 20)
- A `block.json` + `index.php` at `app/templates/{slug}/` (skill 30)
- A `Blockstudio\Build::init(['dir' => 'app'])` call in your bootstrap (skill 10)

## File layout (every app you generate)

```
{slug}/
├── {slug}.php              ← plugin bootstrap (Theme header + routing + Build::init)
├── examplepress.json       ← manifest (routing block, updater, troy)
├── composer.json           ← optional, if the app has any composer deps
├── README.md               ← one-paragraph summary
└── app/                    ← Blockstudio scans this directory
    ├── templates/          ← route templates (one per slug your app claims)
    │   └── {route-slug}/
    │       ├── block.json
    │       └── index.php
    └── components/         ← reusable blocks composed inside templates
        └── {component-slug}/
            ├── block.json
            └── index.php
```

The split between `templates/` and `components/` is convention, not
enforced. Templates are blocks named `{slug}/template-*` that the
router dispatches to directly. Components are everything else — cards,
hero sections, grids, anything reusable.

## What you are NOT building

- React components, JSX, `@wordpress/element`, `@wordpress/blocks`,
  or `@wordpress/block-editor` imports of any kind.
- Files named `block.js`, `edit.js`, `save.js`, or `index.jsx`.
- `edit()` or `save()` functions.
- `registerBlockType()` calls.
- Custom Gutenberg blocks with React/JSX (the view layer is strictly
  Blockstudio PHP templates — skill 30).
- A theme.
- WordPress pages or posts (unless using Blockstudio's file-based pages
  feature — see skill 70).
- Custom database tables (unless using Blockstudio's full-stack `db.php` —
  see skill 80).
- REST endpoints (unless using Blockstudio's `rpc.php` — see skill 80).
- A `package.json`, `webpack.config.js`, `vite.config.js`, `.babelrc`,
  or any other build artifact. There is no build step. Ever.

## Complete worked example

See **skill 05** for the canonical hello-world envelope. That file is
the single source of truth for what your output must look like. Read
it before you generate anything.
