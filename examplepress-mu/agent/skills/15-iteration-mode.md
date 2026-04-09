# Iteration mode — modifying an existing app

When you are called via the `/agent/iterate/{slug}` endpoint, you are
modifying an app that already exists in production. The current file
tree is provided to you as context. **Your output replaces the entire
tree** — there is no diff format, no patch format, no merge.

## Hard rules

1. **Return the COMPLETE file tree, not a delta.** Every file in the
   provided context that should still exist must appear in your
   output `files` array. Any file you omit is **deleted** by the next
   commit. There is no "leave file unchanged" — include it as-is.

2. **The slug never changes.** `manifest.slug`, the bootstrap
   filename, and every block.json namespace stay byte-identical to
   the previous version. The kernel uses the slug as the GitHub repo
   identifier — renaming it would orphan the entire update history.

3. **Bump the version on every iteration.** Update BOTH:
   - `manifest.version` in `examplepress.json`
   - `Version:` header in `{slug}.php`
   - The top-level `version` field in your output JSON envelope
   All three must match.

4. **Default to a patch bump.** `1.0.0 → 1.0.1`. Only do a minor bump
   when adding new blocks/attributes/routes; only do a major bump
   when the user explicitly requests a breaking change.

## Safe changes (patch or minor bump)

These changes never break existing installs:

- Editing HTML/PHP inside an existing `index.php`.
- Adding/modifying Tailwind classes or CSS in `style.css`.
- Adding new attributes to a `block.json` (with sensible defaults).
- Adding entirely new blocks under `app/templates/` or `app/components/`.
- Adding new route slugs (and the matching template folders).
- Adding new RPC actions to `rpc.php`.
- Adding new cron jobs to `cron.php`.

## Breaking changes (major bump, EXTREME caution)

These can break sites that already have the previous version
installed. Only do them when the user explicitly asks:

- **Renaming a block slug.** This breaks any saved post content that
  references the block by name. The renamed block becomes
  "Unrecognized" in the editor.
- **Removing a block attribute.** Templates that read
  `$attributes['removed_key']` will emit warnings; existing saved
  content with the attribute set is silently dropped.
- **Changing an attribute's `type`.** A `text` → `select` change
  invalidates every previously-saved value.
- **Changing a `db.php` field type.** SQLite does not auto-migrate
  type changes; existing rows may become unreadable.
- **Removing a `db.php` field.** Same problem.
- **Flipping `userScoped` from `false` to `true`** (or vice versa).
  Existing rows have either zero or wrong `user_id` values; visibility
  inverts.
- **Renaming a route slug.** The old template block is gone; URLs
  that used to render that template will 404 until WordPress
  re-resolves through the registry.

## Defensive iteration patterns

If you must change something risky, mitigate:

- **Removing an attribute:** keep reading it with `?? default` for
  one or two versions, log a deprecation, then remove it entirely.
- **Adding a `db.php` field:** make it nullable, default it in
  `index.php` with `?? null`, and document that existing rows will
  have `null` for the new field until they're updated.
- **Renaming a block:** add a `transforms` entry in the new
  `block.json` so the editor migrates old saved blocks automatically.

## Preserving unchanged files

The most common iteration mistake: forgetting to include a file that
didn't change. The kernel sees the new commit, diffs it against the
previous, and **deletes** any file missing from the output.

When you receive the iteration context, treat it as a checklist:

1. Copy every file from the input into your output `files` array
   verbatim.
2. Apply your modifications.
3. Verify your output `files` count is `>=` the input count (less
   only if the user explicitly asked you to delete a file).

## What never changes between iterations

- `manifest.slug`
- `manifest.supports_ai_iteration` (the eject UI flow flips this, not you)
- The bootstrap filename
- Any block's `name` field (unless the user is explicitly renaming)
- The `app/` directory structure for files you're not modifying
