# Interactivity API — client-side state and reactivity

## When NOT to reach for this (read first)

Most blocks are static. They render once on the server, the user
reads them, and the page moves on. **Do not add interactivity unless
the block needs client-side reactivity.** Specifically, reach for the
Interactivity API only when:

- The block has UI state that toggles (open/closed, hover, tabs,
  accordions, modals).
- The block reads or writes to a `db.php` table from the browser.
- The block subscribes to realtime polling (via `db.php`'s
  `realtime` config).
- The block needs optimistic updates with rollback on failure.

If none of these apply, skip this skill entirely. A static
`block.json` + `index.php` pair is the right answer for 90% of
blocks.

## No build step

`script.inline.js` is loaded by Blockstudio as a plain JavaScript
module on the page. **There is no `package.json`, no webpack, no
`npm install`, no transpiler.** The `import { ... } from
'@wordpress/interactivity'` line works because WordPress core ships
an import map and Blockstudio loads the module — the import resolves
in the browser.

**Never output `package.json`, `webpack.config.js`, `vite.config.js`,
`.babelrc`, or any build artifact.** Skill 90 makes these a hard
reject.

## State vs context vs block context (CRITICAL)

There are THREE different "context" concepts in a Blockstudio block,
and getting them confused is the #1 full-stack-block bug. Memorize
this table:

| Concept | Where it comes from | What it's for | JS access | PHP access |
|---|---|---|---|---|
| **Global state** | `wp_interactivity_state()` in PHP | Server-seeded data shared across **every instance** of the block on the page (todo list, total count, current user) | `state.todos` | (set on the server) |
| **Local context** | `data-wp-context='{...}'` in HTML | **Per-instance** UI state — one dropdown's `isOpen`, one form's draft text. NEVER shared across instances. | `getContext().isOpen` | n/a |
| **Block context** | `usesContext` in `block.json` (skill 50) | Compile-time data passed from a **parent block** to its children (theme settings, grid columns) | n/a | `$context['{slug}/components-parent']` |

The most common mistake: putting `isOpen` for a dropdown into
`wp_interactivity_state`. Every instance of the dropdown on the page
will then open at once because they all share the same state
namespace. **Per-instance UI state always goes in `data-wp-context`.**

## `index.php` — directives

Server-rendered template plus `data-wp-*` directives that bind the
DOM to your store. Initialize global state at the top with
`wp_interactivity_state()`:

```php
<?php
$db    = \Blockstudio\Db::get( '{slug}/components-todo' );
$todos = $db ? $db->list() : [];

wp_interactivity_state( '{slug}/components-todo', [
    'todos'    => array_values( $todos ),
    'hasTodos' => ! empty( $todos ),
] );
?>
<div
    data-wp-interactive="{slug}/components-todo"
    data-wp-context='<?php echo esc_attr( wp_json_encode( [ "newText" => "" ] ) ); ?>'
    useBlockProps
    class="todo-app"
>
    <input
        type="text"
        data-wp-bind--value="context.newText"
        data-wp-on--input="actions.updateNewText"
    />
    <button data-wp-on--click="actions.addTodo">
        <?php esc_html_e( 'Add', '{slug}' ); ?>
    </button>

    <ul>
        <template data-wp-each--todo="state.todos" data-wp-each-key="context.todo.id">
            <li>
                <input
                    type="checkbox"
                    data-wp-bind--checked="context.todo.done"
                    data-wp-on--change="actions.toggle"
                />
                <span data-wp-text="context.todo.text"></span>
            </li>
        </template>
    </ul>
</div>
```

## The closed directive set

These are the only `data-wp-*` directives you may emit. Do not invent
new ones — anything outside this list is a hard reject.

| Directive | Purpose |
|---|---|
| `data-wp-interactive="{namespace}"` | Marks the element as the root of an Interactivity store. Required on the outermost element. |
| `data-wp-context='{...}'` | JSON-encoded per-instance context. Read in JS via `getContext()`. |
| `data-wp-bind--{attr}="..."` | Bind any DOM attribute (`value`, `checked`, `hidden`, `disabled`, `class`, `aria-*`) to a state expression. |
| `data-wp-class--{className}="..."` | Toggle a CSS class based on a state expression. |
| `data-wp-style--{property}="..."` | Set a CSS property from a state expression. |
| `data-wp-text="..."` | Set element text content from a state expression. |
| `data-wp-on--{event}="..."` | Bind a DOM event (`click`, `input`, `change`, `keydown`, `submit`) to an action. |
| `data-wp-on-window--{event}` | Bind a window-level event. |
| `data-wp-on-document--{event}` | Bind a document-level event. |
| `data-wp-each--{name}="..."` | Loop over an array, exposing each item as `context.{name}`. Goes on a `<template>` element. |
| `data-wp-each-key="..."` | Stable key for `data-wp-each` items. Required for correct re-rendering. |
| `data-wp-watch="..."` | Run an action whenever a state expression changes. |
| `data-wp-init="..."` | Run an action once when the element mounts. |

## `script.inline.js` — the store

```js
import { store, getContext, getElement } from '@wordpress/interactivity';

const { state } = store( '{slug}/components-todo', {
    state: {
        get hasTodos() {
            return state.todos.length > 0;
        },
    },
    actions: {
        updateNewText( event ) {
            getContext().newText = event.target.value;
        },
        *addTodo() {
            const ctx  = getContext();
            const text = ctx.newText.trim();
            if ( ! text ) return;

            yield bs.mutate( {
                state,
                find:  ( /* nothing — append */ ) => false,
                merge: { text, done: false },
                rpc:   { action: 'create', body: { text } },
            } );
            ctx.newText = '';
        },
        *toggle( event ) {
            const ctx = getContext();
            yield bs.mutate( {
                state,
                find:  t => t.id === ctx.todo.id,
                merge: { done: event.target.checked },
                rpc:   { action: 'toggle', body: { id: ctx.todo.id } },
            } );
        },
    },
} );
```

### Auto-injected globals

Blockstudio injects these into every `script.inline.js` automatically.
You do not import them — they exist on `globalThis`:

| Global | Purpose |
|---|---|
| `bs.db('{block-name}')` | Wrapper around the auto-generated CRUD endpoints. Methods: `list()`, `get(id)`, `create(data)`, `update(id, data)`, `delete(id)`. Returns promises. |
| `bs.rpc('{block-name}', '{action}', body)` | Call a custom endpoint defined in `rpc.php`. |
| `bs.mutate({ state, find, merge, rpc })` | Optimistic-update helper. Applies the merge locally, calls the RPC, rolls back on failure. |

### `bs.mutate()` shape

The most useful mutation helper. Two forms:

**Form 1 — declarative (preferred):**

```js
yield bs.mutate( {
    state,                                  // your store's state object
    find:  t => t.id === targetId,          // predicate to locate the row in state.todos (or whichever key)
    merge: { done: true },                  // shallow merge applied locally first
    rpc:   { action: 'toggle', body: {...} }, // RPC to call after the optimistic update
} );
```

**Form 2 — functional (for complex cases):**

```js
yield bs.mutate( {
    fn:         () => bs.db( '{slug}/components-todo' ).create( { text } ),
    optimistic: { text, done: false },
    state,
    key:        'todos',
    action:     'create',
} );
```

In both forms, `bs.mutate` rolls back the local change automatically
if the RPC fails. You do not need to write try/catch around it.

## Hard rules

- **No `package.json`.** No build artifacts of any kind. Skill 90
  rejects them.
- **No React, no JSX, no `@wordpress/element` imports.** The only
  WordPress JS package you may import is `@wordpress/interactivity`.
- **Per-instance state goes in `data-wp-context`.** Global state goes
  in `wp_interactivity_state`. Mixing them up is the #1 bug.
- **`data-wp-each` requires `data-wp-each-key`.** Without a stable key,
  the re-render loses focus and selection state.
- **Never invent `data-wp-*` directives.** Stick to the closed set.
- **Never read `$_GET` / `$_POST` in PHP that hydrates state.** Read
  from `bs.db` results or block attributes.
