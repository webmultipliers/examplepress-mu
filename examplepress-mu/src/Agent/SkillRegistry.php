<?php

declare(strict_types=1);

namespace ExamplePress\MU\Agent;

/**
 * Loads the agent skill curriculum from disk and compiles it into the
 * system prompt.
 *
 * Skills are plain markdown files under `examplepress-mu/agent/skills/`,
 * loaded in lexicographic order so operators can interleave additions
 * by prefix (10-, 20-, 99-overrides-, etc.). Each file may contain
 * {{merge_tag}} placeholders that MergeTags resolves at compile time
 * against the live install.
 *
 * Operator extension points:
 *   - examplepress_mu_agent_skill_paths   filter — add extra directories
 *   - examplepress_mu_agent_skill_files   filter — add/replace specific files
 *   - examplepress_mu_agent_skill_body    filter — final compiled body
 */
final class SkillRegistry
{
    /**
     * Compile the full curriculum into a single string suitable for
     * use as an LLM system-prompt prefix. Includes a stable header
     * and per-skill dividers so the model can see the boundaries.
     *
     * @param array<string,mixed> $context Optional caller context (mode, manifest, etc.)
     */
    public static function compile(array $context = []): string
    {
        $files = self::collectFiles();

        $sections = [];
        foreach ($files as $relPath => $absPath) {
            $raw = @file_get_contents($absPath);
            if ($raw === false || $raw === '') {
                continue;
            }
            $body = MergeTags::apply($raw);
            $sections[] = "## SKILL: {$relPath}\n\n" . trim($body);
        }

        $header = self::header();
        $body = $header . "\n\n" . implode("\n\n---\n\n", $sections);

        /**
         * Filter the final compiled skill body before it's handed to
         * the LLM. Use to inject site-specific guidance, strip sections,
         * or wrap the body in additional framing.
         *
         * @param string                $body     Compiled curriculum.
         * @param array<string,mixed>   $context  Caller context.
         * @param array<string,string>  $files    Loaded skill files (relPath => absPath).
         */
        return (string) apply_filters('examplepress_mu_agent_skill_body', $body, $context, $files);
    }

    /**
     * Discover all skill files. Returns relPath => absPath sorted
     * lexicographically by relPath.
     *
     * @return array<string, string>
     */
    public static function collectFiles(): array
    {
        $defaultDir = defined('EXAMPLEPRESS_MU_DIR')
            ? EXAMPLEPRESS_MU_DIR . '/agent/skills'
            : __DIR__ . '/../../agent/skills';

        /** @var array<int, string> $paths */
        $paths = (array) apply_filters('examplepress_mu_agent_skill_paths', [$defaultDir]);

        $files = [];
        foreach ($paths as $dir) {
            if (!is_string($dir) || !is_dir($dir)) {
                continue;
            }
            $entries = @scandir($dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!str_ends_with($entry, '.md')) {
                    continue;
                }
                $abs = $dir . '/' . $entry;
                if (is_file($abs)) {
                    $files[$entry] = $abs;
                }
            }
        }

        /**
         * Filter the loaded skill file map. Operators can add files
         * (key = display name, value = absolute path) or remove
         * existing entries by unsetting them.
         *
         * @param array<string,string> $files
         */
        $files = (array) apply_filters('examplepress_mu_agent_skill_files', $files);

        ksort($files);
        return $files;
    }

    /**
     * Stable header that frames the curriculum for the LLM. Includes
     * the hard output contract and the merge-tag header so the model
     * knows what to expect from the rest of the body.
     */
    private static function header(): string
    {
        return <<<HEADER
# ExamplePress Generative UI Agent — Build Curriculum

You are the ExamplePress Generative UI Agent. The skills below describe
exactly how to construct a companion app for THIS specific install.
Every double-brace placeholder in the source curriculum has been
resolved against the live site, so the design tokens, theme slug,
available blocks, and CSS variables you see here are CURRENT — not
generic Laravel/WordPress documentation.

OUTPUT CONTRACT (hard requirements — your output is discarded if violated)

- Return STRICT JSON matching the response schema provided alongside this
  prompt. No prose, no markdown fences, no leading/trailing text.
- The "manifest" must be a valid examplepress.json with name, slug,
  description, version (semver), and supports_ai_iteration: true.
- The "files" array contains every file in the app, paths relative to
  the plugin root. Always include the plugin bootstrap PHP file and
  examplepress.json itself.
- Each PHP file must start with `<?php` and `declare(strict_types=1);`.
- File paths MUST stay under the plugin root. No absolute paths,
  no `..`, no leading slashes.

SECURITY (banned tokens — hard reject)

- NEVER use eval, exec, system, shell_exec, passthru, proc_open, popen,
  backtick operators, or base64_decode on a variable.
- NEVER touch wp_options or wp_posts to store block markup or config.
  All logic and markup is file-based.
- NEVER request banned permissions in the manifest.
HEADER;
    }
}
