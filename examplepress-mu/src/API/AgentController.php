<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Agent\GenerationJob;
use ExamplePress\MU\Agent\LLMClient;
use ExamplePress\MU\Agent\MergeTags;
use ExamplePress\MU\Agent\SkillRegistry;
use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Governance\AppValidator;
use ExamplePress\MU\Infrastructure\PrismContainer;
use ExamplePress\MU\Infrastructure\AppRegistry;

/**
 * REST API for the Agent.
 *
 * All state lives on the ep_app CPT via AppRegistry — there is no
 * ep_agent_jobs option, no per-request wp_options lock, and no
 * "transient worker state" separate from "canonical draft state".
 * The post is the job record.
 *
 * Routes (all require manage_options):
 *   POST   /agent/generate                  { prompt }            → { job_id }
 *   POST   /agent/iterate/{slug}            { prompt }            → { job_id }
 *   POST   /agent/repair/{slug}             { error_message, ... } → { job_id }
 *   POST   /agent/jobs/{id}/commit                                → { ok }
 *   POST   /agent/jobs/{id}/discard                               → { ok }
 *   POST   /agent/jobs/{id}/retry                                 → { job_id }
 *   GET    /agent/jobs/{id}                                       → job snapshot
 *   GET    /agent/jobs/{id}/file?path=...                         → { path, contents }
 *   GET    /agent/jobs                                            → recent jobs
 *   GET    /agent/jobs/by-slug/{slug}                             → chat thread for an app
 *   GET    /agent/providers                                       → provider catalog
 *   POST   /agent/test                                            → { ok, message }
 *   GET    /agent/skills                                          → compiled curriculum + merge tags
 *   GET    /agent/drafts                                          → list pending stashed drafts
 *   GET    /agent/drafts/{slug}                                   → single draft payload + history
 *   DELETE /agent/drafts/{slug}                                   → discard a pending draft
 *   POST   /agent/drafts/{slug}/commit                            → push a stashed draft (async)
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
                'app_name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ($v) {
                        return is_string($v) && mb_strlen(trim($v)) >= 1;
                    },
                ],
                'app_slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => static function ($v) {
                        return is_string($v) && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', trim($v));
                    },
                ],
                'app_description' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
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
     * Verify the app's post exists. The eject one-way door no longer
     * exists, so there is no AI-lock state to gate on. Any app with
     * a CPT row — draft, published, orphan — is iterable.
     * GenerationJob::loadIterationContext is responsible for finding
     * an actual source tree to iterate against and erroring out
     * clearly if neither a stash nor a GitHub repo is available.
     */
    private static function ensureIterable(string $slug): ?\WP_Error
    {
        if (!AppRegistry::getPost($slug)) {
            return new \WP_Error('app_not_found', "App {$slug} not found.", ['status' => 404]);
        }
        return null;
    }

    private static function ensureFeature(): ?\WP_Error
    {
        if (!FeatureRegistry::enabled('agent')) {
            return new \WP_Error('agent_disabled', 'The Agent feature is disabled.', ['status' => 403]);
        }
        if (!PrismContainer::isAvailable()) {
            return new \WP_Error('agent_unavailable', PrismContainer::lastError() ?? 'Agent runtime unavailable.', ['status' => 503]);
        }
        if ($err = LLMClient::selfTest()) {
            return new \WP_Error('agent_unconfigured', $err, ['status' => 400]);
        }
        if (!function_exists('as_enqueue_async_action')) {
            return new \WP_Error(
                'no_scheduler',
                'Action Scheduler is not loaded. The agent requires an async worker to run LLM calls without blocking the REST request. Install or activate Action Scheduler (ships with WooCommerce, or can be installed standalone).',
                ['status' => 503]
            );
        }
        return null;
    }

    public static function generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }

        $slug = (string) $request->get_param('app_slug');

        // Early conflict check for PUBLISHED apps only. A failed draft
        // on the same slug is fine — openJob() will reuse it.
        $existing = AppRegistry::getPost($slug);
        if ($existing && $existing->post_status === 'publish') {
            return new \WP_Error('slug_exists', "A published app with slug \"{$slug}\" already exists. Choose a different slug or use iterate mode.", ['status' => 409]);
        }

        $result = GenerationJob::enqueue([
            'mode'            => 'generate',
            'prompt'          => (string) $request->get_param('prompt'),
            'target_slug'     => $slug,
            'app_name'        => (string) $request->get_param('app_name'),
            'app_description' => (string) $request->get_param('app_description'),
            'user_id'         => get_current_user_id(),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'job_id'  => $result,
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

        $result = GenerationJob::enqueue([
            'mode'        => 'iterate',
            'prompt'      => (string) $request->get_param('prompt'),
            'target_slug' => $slug,
            'user_id'     => get_current_user_id(),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'job_id'  => $result,
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

        $result = GenerationJob::enqueue([
            'mode'          => 'repair',
            'prompt'        => (string) $request->get_param('prompt'),
            'target_slug'   => $slug,
            'user_id'       => get_current_user_id(),
            'error_context' => $errorContext,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'job_id'  => $result,
        ]);
    }

    public static function job(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $job = AppRegistry::getJobSnapshot((string) $request->get_param('id'));
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        return rest_ensure_response(self::summarizeJob($job));
    }

    public static function jobs(): \WP_REST_Response
    {
        $jobs = array_map([self::class, 'summarizeJob'], AppRegistry::recentJobSnapshots(20));
        return rest_ensure_response(['jobs' => $jobs]);
    }

    public static function jobsForSlug(\WP_REST_Request $request): \WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');
        $jobs = array_map([self::class, 'summarizeJob'], AppRegistry::jobSnapshotsForSlug($slug, 20));
        return rest_ensure_response(['slug' => $slug, 'jobs' => $jobs]);
    }

    public static function commitJob(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }
        $jobId = (string) $request->get_param('id');
        $result = GenerationJob::enqueueCommit($jobId);
        if (is_wp_error($result)) {
            return $result;
        }
        $refreshed = AppRegistry::getJobSnapshot($jobId);
        return rest_ensure_response([
            'success' => true,
            'job'     => $refreshed ? self::summarizeJob($refreshed) : null,
        ]);
    }

    public static function discardJob(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $jobId = (string) $request->get_param('id');
        $job = AppRegistry::getJobSnapshot($jobId);
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        if ($job['status'] === AppRegistry::STATUS_RUNNING && $job['step'] === AppRegistry::STEP_PUSHING) {
            return new \WP_Error('discard_blocked', 'Cannot discard a job mid-push.', ['status' => 409]);
        }
        GenerationJob::discard($jobId);
        return rest_ensure_response(['success' => true]);
    }

    /**
     * Lightweight projection of a job snapshot for the UI. Strips
     * full file contents from the draft payload (which can be large)
     * but keeps paths + bytes + change markers so the preview pane
     * can render without bloating the JSON payload. Also attaches
     * a human-readable error summary via AppValidator::humanizeErrors.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function summarizeJob(array $job): array
    {
        if (isset($job['draft']['files']) && is_array($job['draft']['files'])) {
            $job['draft']['files'] = array_map(static fn($f) => [
                'path'   => (string) ($f['path'] ?? ''),
                'bytes'  => (int) ($f['bytes'] ?? 0),
                'change' => isset($f['change']) ? (string) $f['change'] : null,
            ], $job['draft']['files']);
        }
        if (!empty($job['errors']) && is_array($job['errors'])) {
            $job['friendly_errors'] = AppValidator::humanizeErrors($job['errors']);
        }
        return $job;
    }

    public static function retryJob(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($err = self::ensureFeature()) {
            return $err;
        }
        $jobId = (string) $request->get_param('id');
        $job = AppRegistry::getJobSnapshot($jobId);
        if (!$job) {
            return new \WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        // Preserve every field from the original attempt so the user
        // doesn't have to re-type the app name or error context.
        $result = GenerationJob::enqueue([
            'mode'            => (string) $job['mode'],
            'prompt'          => (string) $job['prompt'],
            'target_slug'     => (string) $job['target_slug'],
            'app_name'        => (string) $job['app_name'],
            'app_description' => (string) $job['app_description'],
            'user_id'         => get_current_user_id(),
            'error_context'   => $job['error_context'],
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['success' => true, 'job_id' => $result]);
    }

    public static function jobFile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $jobId = (string) $request->get_param('id');
        $path  = (string) $request->get_param('path');
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return new \WP_Error('invalid_path', 'Invalid file path.', ['status' => 400]);
        }

        // Try the job snapshot first — active jobs have full contents
        // in the draft. If the job has been evicted from history or
        // is a "resume-<slug>" synthetic id from the UI, fall back
        // to reading the stashed post-meta payload directly.
        $job = AppRegistry::getJobSnapshot($jobId);
        if ($job && is_array($job['draft']) && !empty($job['draft']['files'])) {
            foreach ($job['draft']['files'] as $f) {
                if (is_array($f) && ($f['path'] ?? '') === $path) {
                    return rest_ensure_response([
                        'path'     => $path,
                        'contents' => (string) ($f['contents'] ?? ''),
                        'bytes'    => (int) ($f['bytes'] ?? strlen((string) ($f['contents'] ?? ''))),
                    ]);
                }
            }
        }

        // Fallback: the caller may be polling a job whose slug is
        // encoded in the id (e.g. "resume-team-directory"). Try to
        // extract the slug and read the post-meta payload.
        if (str_starts_with($jobId, 'resume-')) {
            $slug = substr($jobId, strlen('resume-'));
            $payload = AppRegistry::getDraftPayload($slug);
            if ($payload && is_array($payload['files'] ?? null)) {
                foreach ($payload['files'] as $f) {
                    if (is_array($f) && ($f['path'] ?? '') === $path) {
                        return rest_ensure_response([
                            'path'     => $path,
                            'contents' => (string) ($f['contents'] ?? ''),
                            'bytes'    => (int) ($f['bytes'] ?? strlen((string) ($f['contents'] ?? ''))),
                        ]);
                    }
                }
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
        $rawErrors = $post ? (json_decode((string) get_post_meta($post->ID, AppRegistry::META_DRAFT_ERRORS, true), true) ?: []) : [];
        return rest_ensure_response([
            'slug'           => $slug,
            'payload'        => $payload,
            'history'        => AppRegistry::getDraftHistory($slug),
            'post_status'    => $post ? $post->post_status : '',
            'draft_status'   => $post ? (string) get_post_meta($post->ID, AppRegistry::META_DRAFT_STATUS, true) : '',
            'draft_step'     => $post ? (string) get_post_meta($post->ID, AppRegistry::META_DRAFT_STEP, true) : '',
            'origin_job_id'  => $post ? (string) get_post_meta($post->ID, AppRegistry::META_DRAFT_ORIGIN_JOB, true) : '',
            'errors'         => is_array($rawErrors) ? $rawErrors : [],
            'friendly_errors' => is_array($rawErrors) && !empty($rawErrors) ? AppValidator::humanizeErrors($rawErrors) : [],
            'updated_at'     => $post ? (int) get_post_meta($post->ID, AppRegistry::META_DRAFT_UPDATED_AT, true) : 0,
        ]);
    }

    /**
     * Enqueue an async push of a stashed draft. Returns immediately —
     * the actual GitHub calls happen inside the HOOK_COMMIT worker,
     * so the REST response can't 504 on a slow GitHub response.
     * The UI polls /agent/drafts/{slug} for completion.
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
        $result = GenerationJob::enqueueCommitFromStash($slug);
        if (is_wp_error($result)) {
            return $result;
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

}
