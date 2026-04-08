<?php

declare(strict_types=1);

namespace ExamplePress\MU\Agent;

use ExamplePress\MU\Infrastructure\PrismContainer;
use Prism\Prism\Facades\Prism;

/**
 * Thin wrapper around Prism. The rest of the kernel imports LLMClient,
 * never Prism directly, so the underlying LLM SDK stays swappable.
 *
 * Provider, model, and API key are read at call-time from WP options:
 *  - ep_agent_provider  (anthropic | openai)
 *  - ep_agent_model     (e.g. claude-sonnet-4-6, gpt-4o)
 *  - ep_agent_api_key   (string)
 */
final class LLMClient
{
    /**
     * Generate a brand-new app from a natural-language prompt.
     *
     * @param array<string,mixed> $context Optional context (e.g. blockstudio primitives, schema).
     */
    public static function generateApp(string $prompt, array $context = []): GeneratedApp
    {
        $system = self::systemPrompt($context);
        $user   = "Generate a new ExamplePress companion app based on the following request:\n\n{$prompt}";

        $payload = self::callStructured($system, $user);

        return GeneratedApp::fromArray($payload);
    }

    /**
     * Iterate on an existing app — the LLM receives the full current
     * file tree plus a follow-up prompt and must return the FULL new
     * file tree (not a diff).
     *
     * @param array<int,array{path:string,contents:string}> $repoFiles
     * @param array<string,mixed>                            $manifest
     */
    public static function iterateApp(string $prompt, array $repoFiles, array $manifest): GeneratedApp
    {
        $system = self::systemPrompt(['mode' => 'iterate', 'manifest' => $manifest]);

        $tree = '';
        foreach ($repoFiles as $f) {
            $tree .= "\n\n--- FILE: {$f['path']} ---\n{$f['contents']}";
        }

        $user = "Here is the current app codebase:\n{$tree}\n\nApply the following change and return the COMPLETE updated file tree:\n\n{$prompt}";

        $payload = self::callStructured($system, $user);

        return GeneratedApp::fromArray($payload);
    }

    /**
     * Test whether the configured provider/model/key are usable.
     * Returns null on success, error string on failure.
     */
    public static function selfTest(): ?string
    {
        if (!PrismContainer::isAvailable()) {
            return PrismContainer::lastError() ?? 'Prism container is not available.';
        }
        if (!get_option('ep_agent_api_key', '')) {
            return 'No API key configured.';
        }
        return null;
    }

    /**
     * Make a structured call to Prism. Returns the decoded JSON payload.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    private static function callStructured(string $system, string $user): array
    {
        if (!PrismContainer::isAvailable()) {
            throw new \RuntimeException(PrismContainer::lastError() ?? 'Prism container unavailable.');
        }

        $providerKey = (string) get_option('ep_agent_provider', 'anthropic');
        $model       = (string) get_option('ep_agent_model', 'claude-sonnet-4-6');
        $apiKey      = (string) get_option('ep_agent_api_key', '');

        if (!$apiKey) {
            throw new \RuntimeException('No agent API key configured.');
        }
        if (!class_exists('\\Prism\\Prism\\Prism')) {
            throw new \RuntimeException('Prism is not installed.');
        }

        // Resolve the Prism provider enum.
        $provider = self::resolveProvider($providerKey);

        try {
            $schema = self::responseSchema();

            $response = Prism::structured()
                ->using($provider, $model)
                ->withSchema($schema)
                ->withSystemPrompt($system)
                ->withPrompt($user)
                ->withClientOptions(['timeout' => 120])
                ->asStructured();

            // Prism\Structured\Response::$structured is a public readonly
            // array — the structured-output decoder already ran. Prefer it.
            if (isset($response->structured) && is_array($response->structured)) {
                return $response->structured;
            }

            // Fallback: some providers (or schemas they reject) round-trip
            // the JSON through the text channel instead. Strip any markdown
            // code fences before decoding.
            $text = isset($response->text) && is_string($response->text) ? $response->text : '';
            $decoded = self::decodeJsonLoose($text);
            if (is_array($decoded)) {
                return $decoded;
            }

            $preview = $text === '' ? '(empty response)' : substr($text, 0, 200);
            throw new \RuntimeException('LLM did not return parseable JSON. Preview: ' . $preview);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Prism call failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Tolerant JSON extractor for the text-channel fallback path. Handles:
     *   - Plain JSON
     *   - ```json fenced blocks
     *   - ``` fenced blocks (no language tag)
     *   - JSON wrapped in surrounding prose (extracts the first {…})
     *
     * Returns null when nothing parses.
     *
     * @return array<string,mixed>|null
     */
    private static function decodeJsonLoose(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Try the raw payload first.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Strip markdown code fences (```json ... ``` or ``` ... ```).
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Last resort: extract the largest balanced top-level object.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Resolve a Prism provider enum from a settings string.
     */
    private static function resolveProvider(string $key): mixed
    {
        $map = [
            'anthropic' => '\\Prism\\Prism\\Enums\\Provider::Anthropic',
            'openai'    => '\\Prism\\Prism\\Enums\\Provider::OpenAI',
        ];
        $ref = $map[$key] ?? $map['anthropic'];
        return constant($ref);
    }

    /**
     * Build the JSON schema enforced on the LLM response.
     *
     * Returns a Prism\Schema object via factory calls so we never have
     * to import the class names — keeps this file safe to load even
     * when Prism isn't installed yet.
     */
    private static function responseSchema(): mixed
    {
        $stringSchema = '\\Prism\\Prism\\Schema\\StringSchema';
        $objectSchema = '\\Prism\\Prism\\Schema\\ObjectSchema';
        $arraySchema  = '\\Prism\\Prism\\Schema\\ArraySchema';
        $boolSchema   = '\\Prism\\Prism\\Schema\\BooleanSchema';

        $fileObject = new $objectSchema(
            name: 'file',
            description: 'A single file in the app, path relative to plugin root.',
            properties: [
                new $stringSchema('path', 'File path relative to the plugin root, e.g. blocks/staff-card/index.php.'),
                new $stringSchema('contents', 'Full UTF-8 contents of the file.'),
            ],
            requiredFields: ['path', 'contents'],
        );

        $manifestObject = new $objectSchema(
            name: 'manifest',
            description: 'Decoded examplepress.json contents for this app.',
            properties: [
                new $stringSchema('name', 'Human-readable app name.'),
                new $stringSchema('slug', 'Lowercase slug, [a-z0-9-]+.'),
                new $stringSchema('description', 'Short description (one sentence).'),
                new $stringSchema('version', 'Semver string, e.g. 1.0.0.'),
                new $boolSchema('supports_ai_iteration', 'Always true for AI-generated apps.'),
            ],
            requiredFields: ['name', 'slug', 'description', 'version', 'supports_ai_iteration'],
        );

        return new $objectSchema(
            name: 'generated_app',
            description: 'Complete ExamplePress companion app payload.',
            properties: [
                $manifestObject,
                new $arraySchema('files', 'Full list of files comprising the app.', $fileObject),
                new $stringSchema('commit_message', 'Conventional commit message.'),
                new $stringSchema('version', 'Semver tag for this generation, e.g. 1.0.0.'),
            ],
            requiredFields: ['manifest', 'files', 'commit_message', 'version'],
        );
    }

    /**
     * Build the system prompt. Embeds the manifest schema, available
     * Blockstudio primitives (when available), and a short style guide.
     *
     * @param array<string,mixed> $context
     */
    private static function systemPrompt(array $context): string
    {
        $isIterate = ($context['mode'] ?? '') === 'iterate';

        $base = <<<PROMPT
You are the ExamplePress Generative UI Agent. You produce production-ready
WordPress companion apps that target the ExamplePress MU kernel and
Blockstudio (PHP + HTML + Tailwind utility classes — NO React, NO Webpack,
NO Node build step).

OUTPUT CONTRACT
- Return STRICT JSON matching the provided schema. No prose.
- The "manifest" must be a valid examplepress.json with name, slug,
  description, version (semver), and supports_ai_iteration: true.
- The "files" array contains every file in the app, paths relative to
  the plugin root. Always include the plugin bootstrap PHP file and
  examplepress.json itself.
- Each PHP file must start with <?php and a strict_types declare.
- File paths MUST stay under the plugin root. No absolute paths,
  no `..`, no leading slashes.

SECURITY (HARD REJECT — your output will be discarded if violated)
- NEVER use eval, exec, system, shell_exec, passthru, proc_open, popen,
  backtick operators, or base64_decode on a variable.
- NEVER touch wp_options or wp_posts to store block markup or config.
  All logic and markup is file-based.
- NEVER request banned permissions in the manifest.

STYLE
- Use Blockstudio block conventions: each block lives under blocks/{slug}/
  with an index.php that registers the block via Blockstudio's API.
- Use Tailwind utility classes for styling. No inline <script> tags,
  no inline <style> blocks.
- Escape all output via esc_html / esc_attr / wp_kses_post.
- Prefer small, composable blocks over monolithic templates.
PROMPT;

        if ($isIterate && !empty($context['manifest']['slug'])) {
            $slug = (string) $context['manifest']['slug'];
            $base .= "\n\nITERATION MODE\n- You are editing the existing app \"{$slug}\".";
            $base .= "\n- Preserve the slug. Bump the version (patch level by default).";
            $base .= "\n- Return the COMPLETE new file tree, not a diff.";
        }

        return $base;
    }
}
