<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Agent\GenerationJob;
use ExamplePress\MU\Agent\LLMClient;
use ExamplePress\MU\Agent\MergeTags;
use ExamplePress\MU\Agent\SkillRegistry;
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
 *   POST   /agent/repair/{slug}             { error_message, error_file?, error_line?, prompt? } → { job_id }
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
 *   GET    /agent/skills                                          → compiled curriculum + resolved merge tags
 *   GET    /agent/drafts                                          → list pending stashed drafts
 *   GET    /agent/drafts/{slug}                                   → fetch a single draft payload + history
 *   DELETE /agent/drafts/{slug}                                   → discard a pending draft
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

        register_rest_route('examplepress-mu/v1', '/agent/repair/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'repair'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'slug' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
                'error_message' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'validate_callback' => static function ($v) {
                        if (!is_string($v) || strlen(trim($v)) < 3) {
                            return new \WP_Error('invalid_error', 'error_message must be at least 3 characters.');
                        }
                        return true;
                    },
                ],
                'error_file' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'error_line' => [
                    'type'              => 'integer',
                    'default'           => 0,
                ],
                'stack_trace' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'default'           => '',
                ],
                'prompt' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'default'           => '',
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

        register_rest_route('examplepress-mu/v1', '/agent/skills', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'skills'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/drafts', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'listDrafts'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/drafts/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getDraft'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'slug' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/drafts/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'deleteDraft'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'slug' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/agent/drafts/(?P<slug>[a-z0-9-]+)/commit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'commitDraft'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'slug' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            ],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Verify the app exists and is iterable (not ejected). Allows
     * draft-only apps that have a stashed payload but no plugin on disk.
     */
    private static function ensureIterable(string $slug): ?\WP_Error
    {
        $post = AppRegistry::getPost($slug);
        if (!$post) {
            return new \WP_Error('app_not_found', "App {$slug} not found.", ['status' => 404]);
        }

        // Draft-only apps (never pushed) won't have a manifest on disk.
        // They iterate/repair against their stashed payload, so skip the
        // disk check when a stash exists.
        if (AppRegistry::hasDraftPayload($slug)) {
            return null;
        }

        $manifestPath = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';
        if (!is_readable($manifestPath)) {
            return new \WP_Error('manifest_missing', 'App manifest missing on disk and no draft payload stashed.', ['status' => 404]);
        }
        $manifest = (array) json_decode((string) file_get_contents($manifestPath), true);
        if (empty($manifest['supports_ai_iteration'])) {
            return new \WP_Error('not_iterable', 'This app has been ejected from AI iteration.', ['status' => 409]);
        }

        return null;
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
        if ($err = self::ensureIterable($slug)) {
            return $err;
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

    public static function repair(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }

        $slug = (string) $request->get_param('slug');
        if ($err = self::ensureIterable($slug)) {
            return $err;
        }

        $errorContext = [
            'error_message' => (string) $request->get_param('error_message'),
            'error_file'    => (string) $request->get_param('error_file'),
            'error_line'    => (int) $request->get_param('error_line'),
            'stack_trace'   => (string) $request->get_param('stack_trace'),
            'reported_at'   => time(),
        ];

        $jobId = GenerationJob::enqueue([
            'mode'          => 'repair',
            'prompt'        => (string) $request->get_param('prompt'),
            'target_slug'   => $slug,
            'user_id'       => get_current_user_id(),
            'error_context' => $errorContext,
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

    /**
     * List every app with a pending stashed draft payload. Used by the
     * apps page to render the "Drafts pending review" surface.
     */
    public static function listDrafts(): \WP_REST_Response
    {
        return rest_ensure_response([
            'drafts' => AppRegistry::listDraftsPending(),
        ]);
    }

    /**
     * Fetch a single draft's payload + audit history. The payload is
     * returned with file CONTENTS included so the review pane can show
     * the full diff client-side. The history is the chronological
     * audit trail of every draft/iterate/repair/push action against
     * this app.
     */
    public static function getDraft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = (string) $request->get_param('slug');
        $payload = AppRegistry::getDraftPayload($slug);
        if (!$payload) {
            return new \WP_Error('no_draft', "No pending draft for {$slug}.", ['status' => 404]);
        }

        $post = AppRegistry::getPost($slug);
        return rest_ensure_response([
            'slug'        => $slug,
            'payload'     => $payload,
            'history'     => AppRegistry::getDraftHistory($slug),
            'post_status' => $post ? $post->post_status : '',
            'draft_status' => $post ? (string) get_post_meta($post->ID, AppRegistry::META_DRAFT_STATUS, true) : '',
            'errors'      => $post ? json_decode((string) get_post_meta($post->ID, AppRegistry::META_DRAFT_ERRORS, true), true) ?: [] : [],
            'updated_at'  => $post ? (int) get_post_meta($post->ID, AppRegistry::META_DRAFT_UPDATED_AT, true) : 0,
        ]);
    }

    /**
     * Push a stashed draft directly to GitHub. Used when the user
     * resumes a draft from the panel after the originating job has
     * been GC'd from the ep_agent_jobs option. The post-meta payload
     * is the source of truth.
     */
    public static function commitDraft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }
        $slug = (string) $request->get_param('slug');
        if (!AppRegistry::hasDraftPayload($slug)) {
            return new \WP_Error('no_draft', "No pending draft for {$slug}.", ['status' => 404]);
        }
        $ok = GenerationJob::commitFromStash($slug);
        if (!$ok) {
            return new \WP_Error('commit_failed', 'Push failed. Check the agent logs.', ['status' => 500]);
        }
        return rest_ensure_response(['success' => true, 'slug' => $slug]);
    }

    /**
     * Discard a pending draft. Never-pushed draft posts are deleted
     * entirely; published apps just lose their pending payload (the
     * live version stays).
     */
    public static function deleteDraft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = (string) $request->get_param('slug');
        if (!AppRegistry::getPost($slug)) {
            return new \WP_Error('app_not_found', "App {$slug} not found.", ['status' => 404]);
        }
        AppRegistry::discardDraft($slug);
        return rest_ensure_response(['success' => true, 'slug' => $slug]);
    }

    /**
     * Preview the compiled skill curriculum + resolved merge tags.
     * Used by the Settings → AI Agent skills inspector so operators
     * can see exactly what the LLM is seeing.
     */
    public static function skills(): \WP_REST_Response
    {
        $files = SkillRegistry::collectFiles();
        $fileSummaries = [];
        foreach ($files as $relPath => $absPath) {
            $bytes = @filesize($absPath);
            $fileSummaries[] = [
                'name'  => $relPath,
                'bytes' => is_int($bytes) ? $bytes : 0,
            ];
        }

        return rest_ensure_response([
            'files'      => $fileSummaries,
            'tags'       => MergeTags::all(),
            'compiled'   => SkillRegistry::compile([]),
        ]);
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
