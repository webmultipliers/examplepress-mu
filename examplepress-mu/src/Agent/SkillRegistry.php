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
     * Compile the skill curriculum for a given mode. The mode filters
     * out skills that are irrelevant to the task at hand:
     *
     *   - generate mode: loads everything. New apps need the full
     *     curriculum because the LLM is designing the architecture.
     *
     *   - iterate mode: skips the ab-initio architecture skills
     *     (10-app-skeleton, 70-pages-and-patterns). The parent tree
     *     already embodies those decisions; the LLM just needs the
     *     output format + iteration contract + the specific rules
     *     it might violate.
     *
     *   - repair mode: tightest slice. Only the output format, the
     *     iteration/repair contract, the security/style guards, and
     *     the self-check. No design-system exploration.
     *
     * The filter runs against skill filenames (e.g. "05-output-format.md")
     * so operators can slot their own skills into any mode by prefix.
     *
     * @param array<string,mixed> $context Optional caller context (mode, manifest, etc.)
     */
    public static function compile(array $context = []): string
    {
        $mode  = (string) ($context['mode'] ?? 'generate');
        $files = self::collectFiles();
        $files = self::filterForMode($files, $mode);

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
     * Filter the skill file map down to the subset relevant for the
     * given mode. See compile() for the rationale.
     *
     * @param array<string,string> $files
     * @return array<string,string>
     */
    private static function filterForMode(array $files, string $mode): array
    {
        // Skills always included, in every mode. Output format and
        // security rules are non-negotiable.
        $always = [
            '00-overview.md',
            '05-output-format.md',
            '90-security-and-style.md',
            '95-self-check.md',
        ];

        $byMode = [
            'generate' => null, // null = load everything
            'iterate'  => [
                '15-iteration-mode.md',
                '20-routing.md',
                '25-editing-and-gitops.md',
                '30-blockstudio-blocks.md',
                '40-block-attributes.md',
                '45-state-and-data-sources.md',
                '50-block-context.md',
                '60-design-tokens.md',
                '65-i18n-and-accessibility.md',
                '80-database-and-rpc.md',
                '85-interactivity-api.md',
            ],
            'repair'   => [
                '15-iteration-mode.md',
                '40-block-attributes.md',
                '60-design-tokens.md',
            ],
        ];

        if (!isset($byMode[$mode]) || $byMode[$mode] === null) {
            return $files; // generate: everything
        }

        $allowed = array_fill_keys(array_merge($always, $byMode[$mode]), true);
        return array_intersect_key($files, $allowed);
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
# ExamplePress Agent — Build Curriculum

You are the ExamplePress Agent. The skills below describe
exactly how to construct a companion app for THIS specific install.
Every double-brace placeholder in the source curriculum has been
resolved against the live site, so the design tokens, theme slug,
available blocks, and CSS variables you see here are CURRENT — not
generic Laravel/WordPress documentation.

OUTPUT CONTRACT (hard requirements — your output is discarded if violated)

- Return STRICT JSON matching the response schema. No prose, no markdown
  fences, no leading/trailing text.
- The "manifest" must be a valid examplepress.json with name, slug,
  description, version (semver), and supports_ai_iteration: true.
- "files_changed" is the list of files to create or replace. In generate
  mode it IS the full tree. In iterate/repair mode it contains ONLY the
  files you actually touched — the merge layer preserves every other
  file byte-identical.
- "files_deleted" is the list of paths to remove. Empty in generate mode.
  Empty in iterate/repair mode unless the user explicitly asked.
- Each PHP file must start with `<?php` and `declare(strict_types=1);`.
- File paths MUST stay under the plugin root. No absolute paths,
  no `..`, no leading slashes.

HARD CAPS (exceeding these is a hard reject)

- Maximum 50 entries in files_changed per generation.
- Maximum 8 files per block folder under app/templates/{name}/ or
  app/components/{name}/.
- If a request requires more, simplify the architecture. Fewer blocks
  with more attributes beats more blocks with fewer attributes.

SECURITY (banned tokens — hard reject)

- NEVER use eval, exec, system, shell_exec, passthru, proc_open, popen,
  backtick operators, or base64_decode on a variable.
- NEVER touch wp_options or wp_posts to store block markup or config.
  All logic and markup is file-based.
- NEVER request banned permissions in the manifest.
HEADER;
    }
}
