# Iteration mode — modifying an existing app

There are **two iteration sub-modes** you may be invoked under:

| Sub-mode | Endpoint | When |
|---|---|---|
| **iterate** | `POST /agent/iterate/{slug}` | The user wants a feature change, refinement, or addition. They typed a freeform prompt describing what they want different. |
| **repair** | `POST /agent/repair/{slug}` | The user reported a specific error (PHP fatal, validator rejection, runtime crash) and wants the smallest possible change to fix it. |

Both modes provide the current file tree as context. Both modes return
the COMPLETE file tree, not a diff. The contract differs in **how much
freedom you have to change things**.

## Hard rules (both sub-modes)

**Your output replaces the entire tree.** There is no diff format, no
patch format, no merge.

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

## Repair sub-mode (the surgical contract)

When invoked under repair mode, the system prompt header includes a
`## REPAIR MODE — SURGICAL FIX` block and the user message contains a
`## REPORTED ERROR` section with the exact error text the user pasted.
**Your behavior changes:**

### Repair-mode hard rules

1. **Fix THAT error and ONLY that error.** If the user reports a
   `Call to undefined function foo()`, your job is to define `foo()`
   or remove the call. Not to refactor unrelated code that you happen
   to think could be cleaner.
2. **Modify the minimum number of files.** If a one-line change in
   one file fixes the error, return one modified file and N-1
   byte-identical files. The validator computes a per-file change
   summary and the user reviews it before pushing — they will see
   exactly what you touched.
3. **Every file you do NOT need to change MUST be byte-identical** to
   the version in the input context. Whitespace, ordering, comments,
   imports — all preserved. The reviewer is looking for "what changed?"
   and a noisy diff makes it impossible to verify your fix.
4. **Do not refactor, rename, restyle, reformat, or 'improve' anything.**
   Even if you spot a bug elsewhere in the codebase, leave it alone.
   That's a separate iteration.
5. **Do not add new features.** Do not add new dependencies. Do not
   create new files unless the fix genuinely requires one (e.g., a
   missing helper file referenced by the broken code).
6. **Prefer small defensive changes over large speculative rewrites.**
   If the error is `Undefined index: foo`, add a `?? null` instead of
   restructuring the data flow. If the error is ambiguous, default to
   the smallest change that could plausibly fix it.
7. **Bump the patch version** in `examplepress.json` and the
   `Version:` header of the bootstrap. Same as iterate mode.
8. **Use the commit message to explain what was fixed in one
   sentence.** Format: `fix: <one-sentence summary>`. Example:
   `fix: guard against missing posts attribute in front template`.

### Repair-mode failure modes (don't do these)

- **Do not interpret an error as license to redesign the feature.**
  "The button doesn't work" → fix the button. Not "I redesigned the
  whole CTA section to be a hero with a video background."
- **Do not 'fix' files that aren't mentioned in the error.** If the
  error is in `app/templates/front/index.php`, don't also modify
  `app/components/hero/index.php` because you "noticed it could use
  cleanup."
- **Do not silently change the manifest's `name`, `slug`, or
  `description`.** These are user-controlled. Bump only `version`.
- **Do not delete files.** If a file appears in the input tree, it
  must appear in the output tree (modified or not). The only
  exception is if the user explicitly asked you to delete a file in
  the additional notes.
- **Do not change `supports_ai_iteration`.** That flag is owned by
  the eject UI flow. Generations and repairs leave it set to `true`.

### Repair-mode prompt structure

The user message you receive will be structured like this:

```
Here is the current app codebase:

--- FILE: hello-world.php ---
<?php ...

--- FILE: app/templates/front/index.php ---
<?php ...

## REPORTED ERROR

Fatal error: Uncaught Error: Undefined array key "title"

File: app/templates/front/index.php
Line: 12

## ADDITIONAL USER NOTES

(may be empty)
```

Read the error first, locate the file the error references, and make
the smallest change that resolves it. The user has already reviewed
the error in the WordPress admin and decided this needs a surgical
fix rather than a full re-prompt — honor that choice.

## What never changes between iterations

- `manifest.slug`
- `manifest.supports_ai_iteration` (the eject UI flow flips this, not you)
- The bootstrap filename
- Any block's `name` field (unless the user is explicitly renaming)
- The `app/` directory structure for files you're not modifying
