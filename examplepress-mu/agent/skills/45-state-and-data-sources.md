# State and data sources — the decision tree

When asked to build a feature, you must decide where the data lives.
**Do not guess.** Walk this matrix top-to-bottom and stop at the
first row that matches the user's request:

| User asked for… | Use | Why |
|---|---|---|
| A short, editor-managed list on one block (features grid, FAQ accordion, testimonials, pricing tiers) | `repeater` attribute (skill 40) | No DB, no schema, lives in block markup. The cheapest option. |
| Posts/pages/users/terms already in WordPress | `query` attribute (skill 40) | Uses existing `WP_Query`. The admin gets editor controls for free. |
| A new content type the user wants to manage in `wp-admin` (staff members, products, locations, projects) | Register a Custom Post Type in the bootstrap **and** consume it via the `query` attribute | Clients get the full WordPress admin UI for free; your block just renders. |
| Per-user records (todos, bookmarks, drafts, saved searches) | Full-stack `db.php` with `'userScoped' => true` (skill 80) | Auto-isolates data per logged-in user. |
| Public, shared records (comments, polls, anonymous signups, vote tallies) | Full-stack `db.php` with `'userScoped' => false` (skill 80) | Explicit opt-in to shared state. Public REST endpoint. |
| Site-global config (API keys, feature toggles, tenant IDs) | WordPress Options API, written exclusively from a `rpc.php` callback (skill 80) | Templates can read with `get_option()`, but writes go through an authenticated RPC — never inside `index.php`. |

**Rule:** When in doubt, pick the higher row. The cheapest option
that satisfies the requirement is almost always correct. `db.php`
is the last resort, not the first.

## Decision examples

**"Build a pricing table with three tiers."**
→ Repeater attribute. Three rows of `{ name, price, features[] }`.
Skill 40, type `repeater`.

**"Show the latest 5 blog posts on the homepage."**
→ Query attribute. `{ "type": "query", "post_type": "post", "default": { "per_page": 5 } }`.
Skill 40, type `query`.

**"Add a staff directory."**
→ Register a `staff_member` CPT in the bootstrap, then build a
template that uses a `query` attribute against it. Skill 10
covers the bootstrap pattern; skill 40 covers `query`.

**"A user dashboard where each user sees their own bookmarks."**
→ Full-stack `db.php` with `userScoped: true`. Skill 80.

**"A newsletter signup form anyone can submit."**
→ Full-stack `db.php` with `userScoped: false` and a `validate`
closure on the email field. Skill 80.

**"Site owner needs to set their Stripe API key in the admin."**
→ Don't reach for `db.php`. Use the WordPress Options API: build a
small admin page in the bootstrap that calls `update_option` from
its `admin_post` handler, then read with `get_option()` in templates.
Or — preferred — use a `rpc.php` POST endpoint for the write. Never
write `update_option` from inside `index.php`.

## Hard rules

- **Never** call `update_option`, `update_post_meta`, or `wp_insert_post`
  from a block template (`index.php`). Templates run on every page
  view; database writes belong in `rpc.php`, `cron.php`, or the
  bootstrap. Skill 90 enforces this.
- **Never** create a new `db.php` table when a CPT would work. The CPT
  gives the user free admin UI; `db.php` requires you to build the
  editing surface yourself.
- **Never** use `db.php` for read-only seed data. If the data ships
  with the app and never changes, bake it into the template as a PHP
  array literal.
