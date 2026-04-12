# Output format — read this first, always

You are an automated agent communicating directly with an API that
parses your output. **Read this skill before generating anything.**

## Hard rules

1. Return a single, strictly valid JSON object matching the schema below.
2. **DO NOT** wrap the response in markdown code fences (no ` ```json `).
3. **DO NOT** include preambles, greetings, explanations, or trailing text.
4. **ANY character outside the JSON object is a HARD REJECT.**
5. File paths are relative to the plugin root. Never absolute, never
   contain `..`, never start with `/`.
6. `contents` is the full file body as a string.

## The JSON envelope

```json
{
    "manifest": {
        "name": "App Name",
        "slug": "app-slug",
        "description": "One sentence describing the app.",
        "version": "1.0.0",
        "supports_ai_iteration": true
    },
    "files_changed": [
        {
            "path": "app-slug.php",
            "contents": "<?php\n..."
        }
    ],
    "files_deleted": [],
    "commit_message": "feat: initial scaffold",
    "version": "1.0.0"
}
```

| Field | Notes |
|---|---|
| `manifest.name` | Human-readable. |
| `manifest.slug` | Lowercase, `[a-z0-9-]+`. Must equal the plugin directory name, the bootstrap filename (without `.php`), the `Text Domain:` header, and the namespace prefix in every `block.json` `name`. |
| `manifest.description` | One sentence. |
| `manifest.version` | Semver. |
| `manifest.supports_ai_iteration` | Always `true` for your generations. |
| `files_changed[].path` | Relative path from the plugin root. In generate mode include at minimum `{slug}.php` and `examplepress.json`. |
| `files_changed[].contents` | Full file body. Never a diff, never a partial write. |
| `files_deleted[]` | Paths to remove. Empty `[]` in generate mode; empty unless you're explicitly deleting a file in iterate/repair mode. |
| `commit_message` | Conventional commit string. |
| `version` | Same as `manifest.version`. The kernel uses this for the GitHub release tag. |

## Generate mode vs iterate/repair mode

The same envelope serves both modes, but the semantics of
`files_changed` differ:

- **Generate mode** (new app from scratch): `files_changed` IS the
  entire file tree. `files_deleted` must be `[]`.

- **Iterate/repair mode** (modifying an existing app): `files_changed`
  contains ONLY the files you actually touched. Every file you don't
  mention is preserved byte-identical by the kernel's merge layer.
  Use `files_deleted` for explicit removals.

This difference matters because iterate/repair output can run into
token limits if you re-emit dozens of unchanged files. **Don't.**
Return only the files you changed.

## Hard caps

- **Maximum 50 files in `files_changed` per generation.** Exceeding
  this cap is a hard reject.
- **Maximum 8 files per block folder** (under `app/templates/{name}/`
  or `app/components/{name}/`). Exceeding this cap is a hard reject.

If a request requires more, simplify the architecture. The agent's
job is to find the minimum viable structure, not the maximum.

## Complete worked example: "hello world" (generate mode)

If asked to "build a simple hello world app", your ENTIRE output is
exactly this — nothing before, nothing after, no fences:

```json
{
    "manifest": {
        "name": "Hello World",
        "slug": "hello-world",
        "description": "A simple hello world application.",
        "version": "1.0.0",
        "supports_ai_iteration": true
    },
    "files_changed": [
        {
            "path": "hello-world.php",
            "contents": "<?php\n/**\n * Plugin Name: Hello World\n * Description: A simple hello world application.\n * Version:     1.0.0\n * Theme:       {{theme_slug}}\n * Text Domain: hello-world\n * Requires at least: 6.9\n * Requires PHP: 8.4\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n    exit;\n}\n\nif ( function_exists( 'examplepress_register_route_origin' ) ) {\n    examplepress_register_route_origin( 'hello-world', [\n        'front' => fn() => is_front_page() || is_home(),\n    ], 10 );\n}\n\nadd_action( 'init', function () {\n    if ( ! class_exists( 'Blockstudio\\\\Build' ) ) {\n        return;\n    }\n    Blockstudio\\\\Build::init( [\n        'dir' => plugin_dir_path( __FILE__ ) . 'app',\n    ] );\n} );\n"
        },
        {
            "path": "examplepress.json",
            "contents": "{\n    \"$schema\": \"https://www.examplepress.com/schema/app\",\n    \"name\": \"Hello World\",\n    \"slug\": \"hello-world\",\n    \"description\": \"A simple hello world application.\",\n    \"version\": \"1.0.0\",\n    \"supports_ai_iteration\": true,\n    \"updater\": {\n        \"asset_filename\": \"hello-world.zip\",\n        \"default_channel\": \"stable\",\n        \"requires_wp\": \"6.9\",\n        \"requires_php\": \"8.4\"\n    },\n    \"routing\": {\n        \"priority\": 10,\n        \"routes\": {\n            \"front\": {\n                \"urls\": [\"/\"],\n                \"desc\": \"The homepage\"\n            }\n        }\n    }\n}\n"
        },
        {
            "path": "app/templates/front/block.json",
            "contents": "{\n    \"$schema\": \"https://blockstudio.dev/schema/block\",\n    \"name\": \"hello-world/template-front\",\n    \"title\": \"Front Page\",\n    \"category\": \"theme\",\n    \"blockstudio\": {\n        \"attributes\": [\n            { \"id\": \"heading\", \"type\": \"text\", \"label\": \"Heading\", \"default\": \"Hello World\" }\n        ]\n    }\n}\n"
        },
        {
            "path": "app/templates/front/index.php",
            "contents": "<?php\n/**\n * Front page template.\n * @package hello-world\n */\n?>\n<main useBlockProps class=\"mx-auto p-6\">\n    <h1 class=\"text-[var(--wp--preset--font-size--xl)] text-[var(--wp--preset--color--primary)]\">\n        <?php echo esc_html( $attributes['heading'] ); ?>\n    </h1>\n</main>\n"
        }
    ],
    "files_deleted": [],
    "commit_message": "feat: initial hello world scaffold",
    "version": "1.0.0"
}
```

Pattern-match on this. Every new app has the same shape:
bootstrap → manifest → at least one `app/templates/{slug}/block.json`
+ `index.php`.

## Iterate example (partial tree)

If the user says "make the heading red", the entire output is:

```json
{
    "manifest": {
        "name": "Hello World",
        "slug": "hello-world",
        "description": "A simple hello world application.",
        "version": "1.0.1",
        "supports_ai_iteration": true
    },
    "files_changed": [
        {
            "path": "app/templates/front/index.php",
            "contents": "<?php\n/**\n * Front page template.\n * @package hello-world\n */\n?>\n<main useBlockProps class=\"mx-auto p-6\">\n    <h1 class=\"text-[var(--wp--preset--font-size--xl)] text-[var(--wp--preset--color--red)]\">\n        <?php echo esc_html( $attributes['heading'] ); ?>\n    </h1>\n</main>\n"
        },
        {
            "path": "examplepress.json",
            "contents": "{...version bumped to 1.0.1...}"
        },
        {
            "path": "hello-world.php",
            "contents": "<?php\n/**\n * Plugin Name: Hello World\n * Version:     1.0.1\n...}"
        }
    ],
    "files_deleted": [],
    "commit_message": "feat: change heading color to red",
    "version": "1.0.1"
}
```

Three files in `files_changed`, not the whole tree. The `block.json`
was not touched, so it's omitted — the merge layer will keep the
previous version exactly as it was.
