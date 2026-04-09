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
     * Repair an existing app — surgical fix for a reported error.
     *
     * Identical I/O contract to iterateApp() (full file tree in,
     * full file tree out) but uses a strict minimum-change system
     * prompt that biases the model toward touching only the files
     * necessary to fix the reported error.
     *
     * @param array<int,array{path:string,contents:string}> $repoFiles
     * @param array<string,mixed>                            $manifest
     * @param array<string,mixed>                            $errorContext
     */
    public static function repairApp(string $prompt, array $repoFiles, array $manifest, array $errorContext): GeneratedApp
    {
        $system = self::systemPrompt([
            'mode'          => 'repair',
            'manifest'      => $manifest,
            'error_context' => $errorContext,
        ]);

        $tree = '';
        foreach ($repoFiles as $f) {
            $tree .= "\n\n--- FILE: {$f['path']} ---\n{$f['contents']}";
        }

        // Build a structured error block. Optional fields (file, line,
        // stack trace) are only included when present so the LLM
        // doesn't see empty noise.
        $errorBlock = "## REPORTED ERROR\n\n" . trim((string) ($errorContext['error_message'] ?? ''));
        if (!empty($errorContext['error_file'])) {
            $errorBlock .= "\n\nFile: " . $errorContext['error_file'];
        }
        if (!empty($errorContext['error_line'])) {
            $errorBlock .= "\nLine: " . $errorContext['error_line'];
        }
        if (!empty($errorContext['stack_trace'])) {
            $errorBlock .= "\n\nStack trace:\n" . $errorContext['stack_trace'];
        }
        if (!empty($prompt)) {
            $errorBlock .= "\n\n## ADDITIONAL USER NOTES\n\n" . $prompt;
        }

        $user = "Here is the current app codebase:\n{$tree}\n\n{$errorBlock}\n\n"
            . "Produce a SURGICAL fix for the reported error. Return the COMPLETE "
            . "updated file tree. Modify only the files necessary to resolve the "
            . "error. Every other file MUST be returned byte-identical to its "
            . "current contents. Do not refactor, do not 'improve' unrelated code, "
            . "do not rename anything. Bump the patch version in examplepress.json "
            . "and the bootstrap header.";

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
     * Build the system prompt by compiling the agent skill curriculum.
     * SkillRegistry loads markdown files from agent/skills/, MergeTags
     * resolves {{tag}} placeholders against the live install, and the
     * compiled body becomes the LLM system prompt.
     *
     * @param array<string,mixed> $context
     */
    private static function systemPrompt(array $context): string
    {
        $base = SkillRegistry::compile($context);

        $mode = (string) ($context['mode'] ?? '');
        $slug = (string) ($context['manifest']['slug'] ?? '');

        if ($mode === 'iterate' && $slug !== '') {
            $base .= "\n\n## ITERATION MODE\n\n- You are editing the existing app \"{$slug}\".";
            $base .= "\n- Preserve the slug. Bump the version (patch level by default).";
            $base .= "\n- Return the COMPLETE new file tree, not a diff.";
        }

        if ($mode === 'repair' && $slug !== '') {
            $base .= "\n\n## REPAIR MODE — SURGICAL FIX\n\n";
            $base .= "- You are repairing the existing app \"{$slug}\".\n";
            $base .= "- A specific error has been reported (provided in the user message).\n";
            $base .= "- Your job is to fix THAT error and ONLY that error.\n";
            $base .= "- Modify the **minimum number of files** required.\n";
            $base .= "- Every file you do NOT need to change MUST be returned byte-identical.\n";
            $base .= "- Do not refactor, rename, restyle, reformat, or 'improve' anything.\n";
            $base .= "- Do not add new features. Do not add new dependencies.\n";
            $base .= "- If the fix is unclear or the error is ambiguous, prefer a small,\n";
            $base .= "  defensive change (add a guard, add a default, add a type cast)\n";
            $base .= "  over a large speculative rewrite.\n";
            $base .= "- Bump the patch version in examplepress.json and the bootstrap header.\n";
            $base .= "- Use the commit message to explain what was fixed in one sentence.\n";
            $base .= "- Return the COMPLETE file tree. Omitting a file deletes it.\n";
        }

        return $base;
    }
}
