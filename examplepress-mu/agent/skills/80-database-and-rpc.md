# Database and RPC — when you need server state

## When NOT to reach for full-stack (read this first)

Most blocks should never have a `db.php`. Reach for it ONLY when the
user asks for something that requires persistent application state.
Walk this list top-to-bottom and stop at the first match:

| User asked for… | Use | Why |
|---|---|---|
| A short, editor-managed list (features, testimonials, FAQs) | `repeater` attribute (skill 40) | No DB, no schema, lives in block markup |
| Posts/pages/terms/users already in WordPress | `query` attribute (skill 40) | Uses existing `WP_Query` |
| A CPT with custom fields | Register CPT in bootstrap + `query` attribute | Clients get admin UI for free |
| Per-user records (todos, bookmarks, drafts) | `db.php` with `'userScoped' => true` | Isolated per user |
| Public, shared records (comments, votes, signups) | `db.php` with `'userScoped' => false` | Explicit opt-in to shared state |

If `db.php` is the right answer, the rest of this skill applies.
Skill 85 covers the JS/Interactivity side once the data model exists.

## File layout

A full-stack block lives entirely inside its own folder:

```
app/components/{block-slug}/
├── block.json        ← block definition (skill 30)
├── index.php         ← template (skill 30)
├── style.css         ← optional styles
├── script.inline.js  ← optional client-side JS (skill 85)
├── db.php            ← data model + storage
├── rpc.php           ← optional: custom endpoints beyond CRUD
└── cron.php          ← optional: scheduled tasks
```

## `db.php` — the data model

```php
<?php
return [
    'storage'    => 'sqlite',   // file lives in the block folder
    'userScoped' => true,       // REQUIRED — see rule below
    'realtime'   => [
        'key'      => 'todos',
        'interval' => 3000,
    ],
    'capability' => [
        'create' => true,
        'read'   => true,
        'update' => true,
        'delete' => true,
    ],
    'fields' => [
        'text' => [
            'type'      => 'string',
            'required'  => true,
            'minLength' => 1,
            'maxLength' => 500,
        ],
        'done' => [
            'type'    => 'boolean',
            'default' => false,
        ],
    ],
    'hooks' => [
        'after_create' => function ( array $record ) {
            // Side effects: wp_mail, transient invalidation, etc.
        },
    ],
];
```

| Field | Notes |
|---|---|
| `storage` | `sqlite` (default; SQLite file in the block folder) or `wp_options` (small datasets only). |
| `userScoped` | **Required, no default.** Omitting it is a HARD REJECT. Document below. |
| `realtime.key` / `interval` | Polling config — clients re-fetch and re-render automatically. Interval in milliseconds. |
| `capability` | Booleans gating each CRUD verb. Set `create: false` for read-only feeds. |
| `fields` | Map of column name → type spec. Supported types: `string`, `integer`, `boolean`, `json`. |
| `hooks` | Lifecycle hooks: `before_create`, `after_create`, `before_update`, `after_update`, `before_delete`, `after_delete`. |

Blockstudio auto-generates a REST API at
`/wp-json/blockstudio/v1/db/{block-name}` covering list, create,
update, delete. Nonces are handled automatically. The client wrapper
`bs.db()` is auto-injected into every page that loads the block.

## The `userScoped` rule (HARD)

You MUST explicitly declare `userScoped`. There is no default.

- `'userScoped' => true` — adds a `user_id` column, sets it on create,
  filters all reads/writes to the current user. Use for **private,
  per-user data**: todos, bookmarks, drafts, settings.
- `'userScoped' => false` — single shared table; every record is
  visible to everyone the capabilities allow. Use for **public, shared
  data**: anonymous polls, public signup forms, vote counters.

**Logged-out handling.** If `userScoped: true` is set and an
unauthenticated visitor hits the block, the auto-generated REST
endpoints return `401 Unauthorized`. Your `index.php` MUST handle
this case explicitly — render a login prompt, an empty state, or
gate the entire block:

```php
<?php
if ( ! is_user_logged_in() ) : ?>
    <div useBlockProps class="ep-card">
        <p><?php esc_html_e( 'Sign in to use this feature.', '{slug}' ); ?></p>
        <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
            <?php esc_html_e( 'Sign in', '{slug}' ); ?>
        </a>
    </div>
<?php return; endif; ?>
```

Without this guard, logged-out users will see frontend JavaScript
crashes when the page mounts. Skill 90 makes the missing guard a
hard reject.

## Field validation

Every field with `required: true` must also declare its constraints.
The validator rejects `db.php` files that publish a public REST
endpoint without bounds:

| Property | Notes |
|---|---|
| `type` | `string` / `integer` / `boolean` / `json`. Required. |
| `required` | Bool. Server-side check. |
| `minLength` / `maxLength` | String length bounds. Required when `type: string` and `required: true`. |
| `min` / `max` | Numeric bounds. Required when `type: integer` and `required: true`. |
| `pattern` | Regex string the value must match. |
| `default` | Used when the client omits the field. |
| `validate` | A closure receiving `$value`, returning `true` on success or a string error message. Run after type and bounds checks. |

A custom validator example:

```php
'email' => [
    'type'     => 'string',
    'required' => true,
    'maxLength' => 254,
    'validate' => function ( $value ) {
        if ( ! is_email( $value ) ) {
            return 'Invalid email format.';
        }
        if ( str_ends_with( $value, '@example.com' ) ) {
            return 'Disposable addresses are not accepted.';
        }
        return true;
    },
],
```

## `rpc.php` — custom endpoints

For operations beyond standard CRUD (toggling a flag, bulk delete,
custom search, processing payments). Each entry registers a REST
route at `/wp-json/blockstudio/v1/rpc/{block-name}/{action}`.

```php
<?php
use Blockstudio\Db;

return [
    'toggle' => [
        'callback' => function ( array $params ) {
            $id = (int) ( $params['id'] ?? 0 );
            if ( ! $id ) {
                return new \WP_Error( 'missing_id', 'ID is required.', [ 'status' => 400 ] );
            }
            $db   = Db::get( '{slug}/components-todo' );
            $todo = $db->get_record( $id );
            if ( ! $todo ) {
                return new \WP_Error( 'not_found', 'Not found.', [ 'status' => 404 ] );
            }
            return $db->update( $id, [ 'done' => ! $todo['done'] ] );
        },
        'public'  => false,
        'methods' => [ 'POST' ],
    ],
];
```

### Security defaults

- **`'public' => false` is the default.** Mutating endpoints REQUIRE
  authentication. Only set `'public' => true` when explicitly building
  a feature for unauthenticated users (anonymous polls, public signup
  forms). Read-only public endpoints are fine; public writes are not.
- **Nonces are automatic.** Blockstudio adds an `X-WP-Nonce` header
  to every `bs.db()` and `bs.rpc()` call. You do not need to issue or
  verify nonces yourself unless you are calling the endpoint from
  outside the auto-injected JS wrapper.
- **Input is `$params`.** Read all data from the `$params` array
  passed to the callback. Never read from `$_GET` / `$_POST` / `$_REQUEST`.
- **Always validate.** Sanitize and bound-check every value before
  passing it to `Db::update`/`create`. Type-cast integers with
  `(int)` and validate string lengths against your `db.php` schema.

## `cron.php` — scheduled tasks

```php
<?php
use Blockstudio\Db;

return [
    'cleanup_old_todos' => [
        'schedule' => 'daily',
        'callback' => function () {
            $db    = Db::get( '{slug}/components-todo' );
            $todos = $db->list();
            foreach ( $todos as $todo ) {
                if ( $todo['done'] && strtotime( $todo['updated_at'] ) < strtotime( '-30 days' ) ) {
                    $db->delete( (int) $todo['id'] );
                }
            }
        },
    ],
];
```

Schedules: `hourly`, `twicedaily`, `daily`, `weekly`. The kernel's
WP-Cron handler picks these up automatically. Real production
installs use server-side cron — see the kernel docs.

## Hard rules

- **Never** define `db.php` for a block that doesn't actually need
  storage. The CRUD endpoints get auto-registered and become attack
  surface.
- **`userScoped` is required.** No default. Omitting it is a hard reject.
- **`'public' => true` on a write endpoint is a code smell.** Default
  to `false` and only flip when the user explicitly asks for an
  unauthenticated public write.
- **Never** disable validation on `fields` — the `required`,
  `minLength`, `maxLength`, `type` constraints are your only protection
  against malformed writes from the public REST API.
- **Never** call `update_option`, `update_post_meta`, `wp_insert_post`,
  or any other write API from the block template. Writes belong in
  `rpc.php` callbacks or `cron.php` callbacks. Skill 90 enforces this.
