<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Agent\GenerationJob;
use ExamplePress\MU\Agent\LLMClient;
use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Infrastructure\PrismContainer;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\GitHub;

/**
 * REST API for the Generative UI Agent.
 *
 * Routes (all require manage_options):
 *   POST   /agent/generate                  { prompt }            → { job_id }
 *   POST   /agent/iterate/{slug}            { prompt }            → { job_id }
 *   POST   /agent/eject/{slug}                                    → { ok, version }
 *   POST   /agent/jobs/{id}/commit                                → { ok }
 *   POST   /agent/jobs/{id}/discard                               → { ok }
 *   POST   /agent/jobs/{id}/retry                                 → { job_id }  (new job, same prompt)
 *   GET    /agent/jobs/{id}                                       → job state (summarized)
 *   GET    /agent/jobs/{id}/file?path=...                         → { path, contents }
 *   GET    /agent/jobs                                            → recent jobs
 *   GET    /agent/jobs/by-slug/{slug}                             → all jobs for an app (chat thread)
 *   GET    /agent/providers                                       → provider catalog
 *   POST   /agent/test                                            → { ok, message }
 */
final class AgentController
{
    public static function register(): void
    {
        register_rest_route('examplepress-mu/v1', '/agent/generate', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'generate'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'prompt' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'validate_callback' => static function ($v) {
                        if (!is_string($v) || strlen($v) < 5 || strlen($v) > 5000) {
                            return new \WP_Error('invalid_prompt', 'Prompt must be 5–5000 characters.');
                        }
                        return true;
                    },
                ],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/iterate/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'iterate'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'slug'   => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
                'prompt' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/eject/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'eject'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'slug' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs/(?P<id>[a-f0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'job'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs/(?P<id>[a-f0-9-]+)/commit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'commitJob'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs/(?P<id>[a-f0-9-]+)/discard', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'discardJob'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs/(?P<id>[a-f0-9-]+)/retry', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'retryJob'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs/(?P<id>[a-f0-9-]+)/file', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'jobFile'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'jobs'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/jobs/by-slug/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'jobsForSlug'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/providers', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'providers'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/test', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'test'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    private static function ensureFeature(): ?\WP_Error
    {
        if (!FeatureRegistry::enabled('agent')) {
            return new \WP_Error('agent_disabled', 'The Generative UI Agent feature is disabled.', ['status' => 403]);
        }
        if (!PrismContainer::isAvailable()) {
            return new \WP_Error('agent_unavailable', PrismContainer::lastError() ?? 'Agent runtime unavailable.', ['status' => 503]);
        }
        if ($err = LLMClient::selfTest()) {
            return new \WP_Error('agent_unconfigured', $err, ['status' => 400]);
        }
        return null;
    }

    public static function generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }

        $jobId = GenerationJob::enqueue([
            'mode'    => 'generate',
            'prompt'  => (string) $request->get_param('prompt'),
            'user_id' => get_current_user_id(),
        ]);

        return rest_ensure_response([
            'success' => true,
            'job_id'  => $jobId,
        ]);
    }

    public static function iterate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }

        $slug = (string) $request->get_param('slug');
        $record = AppRegistry::get($slug);
        if (!$record) {
            return new \WP_Error('app_not_found', "App {$slug} not found.", ['status' => 404]);
        }

        $manifestPath = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';
        if (!is_readable($manifestPath)) {
            return new \WP_Error('manifest_missing', 'App manifest missing on disk.', ['status' => 404]);
        }
        $manifest = (array) json_decode((string) file_get_contents($manifestPath), true);
        if (empty($manifest['supports_ai_iteration'])) {
            return new \WP_Error('not_iterable', 'This app has been ejected from AI iteration.', ['status' => 409]);
        }

        $jobId = GenerationJob::enqueue([
            'mode'        => 'iterate',
            'prompt'      => (string) $request->get_param('prompt'),
            'target_slug' => $slug,
            'user_id'     => get_current_user_id(),
        ]);

        return rest_ensure_response([
            'success' => true,
            'job_id'  => $jobId,
        ]);
    }

    public static function eject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = (string) $request->get_param('slug');
        $record = AppRegistry::get($slug);
        if (!$record || empty($record['github']['owner_repo'])) {
            return new \WP_Error('app_not_found', "App {$slug} not found or has no repo.", ['status' => 404]);
        }
        $ownerRepo = (string) $record['github']['owner_repo'];

        $tree = GitHub::fetchRepoTree($ownerRepo);
        if (is_wp_error($tree)) {
            return $tree;
        }

        // Patch the manifest in-place inside the file list.
        $patched = false;
        $newVersion = '';
        foreach ($tree['files'] as &$file) {
            if ($file['path'] === 'examplepress.json') {
                $manifest = (array) json_decode($file['contents'], true);
                $manifest['supports_ai_iteration'] = false;
                $newVersion = self::bumpPatch((string) ($manifest['version'] ?? '1.0.0'));
                $manifest['version'] = $newVersion;
                $file['contents'] = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                $patched = true;
                break;
            }
        }
        unset($file);

        if (!$patched) {
            return new \WP_Error('no_manifest_in_repo', 'examplepress.json not found in repo root.', ['status' => 500]);
        }

        $push = GitHub::pushFiles(
            ownerRepo: $ownerRepo,
            files: $tree['files'],
            message: 'chore: eject from AI iteration (handoff to developer mode)',
            parentSha: $tree['sha'],
        );
        if (is_wp_error($push)) {
            return $push;
        }

        $release = GitHub::createRelease(
            ownerRepo: $ownerRepo,
            tag: 'v' . $newVersion,
            name: 'v' . $newVersion,
            body: 'Ejected from AI iteration.',
        );
        if (is_wp_error($release)) {
            return $release;
        }

        AppRegistry::set($slug, ['version' => $newVersion]);
        AppUpdateProvider::flush();

        return rest_ensure_response([
            'success' => true,
            'version' => $newVersion,
            'message' => "App {$slug} ejected. AI iteration is now locked.",
        ]);
    }

    public static function job(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $job = GenerationJob::getJob((string) $request->get_param('id'));
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        return rest_ensure_response(GenerationJob::summarize($job));
    }

    public static function jobs(): \WP_REST_Response
    {
        $jobs = array_map([GenerationJob::class, 'summarize'], GenerationJob::recent(20));
        return rest_ensure_response(['jobs' => $jobs]);
    }

    public static function jobsForSlug(\WP_REST_Request $request): \WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');
        $jobs = array_map([GenerationJob::class, 'summarize'], GenerationJob::forSlug($slug, 20));
        return rest_ensure_response(['slug' => $slug, 'jobs' => $jobs]);
    }

    public static function commitJob(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }
        $jobId = (string) $request->get_param('id');
        $job = GenerationJob::getJob($jobId);
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        if (($job['status'] ?? '') !== 'drafted') {
            return new \WP_Error('not_drafted', 'Job is not in a drafted state and cannot be committed.', ['status' => 409]);
        }
        $ok = GenerationJob::commit($jobId);
        if (!$ok) {
            $job = GenerationJob::getJob($jobId);
            return new \WP_Error('commit_failed', $job['errors'][0] ?? 'Commit failed.', ['status' => 500]);
        }
        return rest_ensure_response([
            'success' => true,
            'job'     => GenerationJob::summarize(GenerationJob::getJob($jobId) ?? []),
        ]);
    }

    public static function discardJob(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $jobId = (string) $request->get_param('id');
        $job = GenerationJob::getJob($jobId);
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        // Discard is allowed at any stage except mid-commit (status = running with step = pushing).
        if (($job['status'] ?? '') === 'running' && ($job['step'] ?? '') === GenerationJob::STEP_PUSHING) {
            return new \WP_Error('discard_blocked', 'Cannot discard a job mid-push.', ['status' => 409]);
        }
        GenerationJob::discard($jobId);
        return rest_ensure_response(['success' => true]);
    }

    public static function retryJob(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }
        $jobId = (string) $request->get_param('id');
        $job = GenerationJob::getJob($jobId);
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        $newId = GenerationJob::enqueue([
            'mode'        => (string) ($job['mode'] ?? 'generate'),
            'prompt'      => (string) ($job['prompt'] ?? ''),
            'target_slug' => (string) ($job['target_slug'] ?? ''),
            'user_id'     => get_current_user_id(),
        ]);
        return rest_ensure_response(['success' => true, 'job_id' => $newId]);
    }

    public static function jobFile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $jobId = (string) $request->get_param('id');
        $path  = (string) $request->get_param('path');
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return new \WP_Error('invalid_path', 'Invalid file path.', ['status' => 400]);
        }
        $job = GenerationJob::getJob($jobId);
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        $files = $job['draft']['files'] ?? [];
        foreach ((array) $files as $f) {
            if (($f['path'] ?? '') === $path) {
                return rest_ensure_response([
                    'path'     => $path,
                    'contents' => (string) ($f['contents'] ?? ''),
                    'bytes'    => (int) ($f['bytes'] ?? strlen((string) ($f['contents'] ?? ''))),
                ]);
            }
        }
        return new \WP_Error('file_not_found', "File {$path} not in draft.", ['status' => 404]);
    }

    public static function test(): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }
        // selfTest only validates configuration; a real round-trip would
        // burn tokens. Surface configuration validation as success.
        return rest_ensure_response([
            'success' => true,
            'message' => 'Agent runtime ready. Provider: ' . get_option('ep_agent_provider', 'anthropic')
                . ', model: ' . get_option('ep_agent_model', '(default)') . '.',
        ]);
    }

    public static function providers(): \WP_REST_Response
    {
        return rest_ensure_response([
            'providers' => [
                [
                    'id'      => 'anthropic',
                    'label'   => 'Anthropic Claude',
                    'default' => 'claude-sonnet-4-6',
                    'models'  => [
                        ['id' => 'claude-opus-4-6',     'label' => 'Claude Opus 4.6'],
                        ['id' => 'claude-sonnet-4-6',   'label' => 'Claude Sonnet 4.6 (recommended)'],
                        ['id' => 'claude-haiku-4-5',    'label' => 'Claude Haiku 4.5'],
                    ],
                ],
                [
                    'id'      => 'openai',
                    'label'   => 'OpenAI',
                    'default' => 'gpt-4o',
                    'models'  => [
                        ['id' => 'gpt-4o',      'label' => 'GPT-4o'],
                        ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini'],
                        ['id' => 'gpt-4-turbo', 'label' => 'GPT-4 Turbo'],
                    ],
                ],
            ],
            'configured' => [
                'provider' => (string) get_option('ep_agent_provider', 'anthropic'),
                'model'    => (string) get_option('ep_agent_model', 'claude-sonnet-4-6'),
                'has_key'  => (bool) get_option('ep_agent_api_key', ''),
            ],
            'feature_enabled' => FeatureRegistry::enabled('agent'),
            'runtime_ready'   => PrismContainer::isAvailable(),
            'runtime_error'   => PrismContainer::lastError(),
        ]);
    }

    private static function bumpPatch(string $version): string
    {
        $parts = array_map('intval', explode('.', ltrim($version, 'v')));
        $parts = array_pad($parts, 3, 0);
        $parts[2]++;
        return implode('.', $parts);
    }
}
