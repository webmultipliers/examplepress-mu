<?php

declare(strict_types=1);

namespace ExamplePress\MU\Agent;

use ExamplePress\MU\Governance\AppValidator;
use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\GitHub;

/**
 * Async LLM generation job runner backed by Action Scheduler.
 *
 * Two-phase pipeline:
 *   Phase 1 (draft) — call LLM, validate, store result on the job, pause.
 *   Phase 2 (commit) — push to GitHub, tag release, register in AppRegistry.
 *
 * The pause between phases lets the user review the proposed file tree
 * before any code touches GitHub. The user clicks Push (commit) or
 * Discard (drop the job, no side effects).
 *
 * Job state lives in a single capped option (ep_agent_jobs) — never
 * in wp_posts and never as serialized markup. Capped to 50 entries
 * with FIFO eviction so we don't bloat wp_options.
 */
final class GenerationJob
{
    public const HOOK         = 'ep_agent_generate';
    public const OPTION_JOBS  = 'ep_agent_jobs';
    public const MAX_JOBS     = 50;

    /**
     * Advisory lock for the jobs option. Closes the read-modify-write
     * race between concurrent Action Scheduler workers (or a cron tick
     * racing with a user-initiated commit). Backed by wp_options'
     * UNIQUE index via add_option() which gives us genuine atomic
     * compare-and-swap on every host, including native MySQL.
     */
    private const JOBS_LOCK_KEY          = 'ep_agent_jobs_lock';
    private const JOBS_LOCK_MAX_AGE      = 30;
    private const JOBS_LOCK_MAX_ATTEMPTS = 5;

    /** Re-entrant flag — PHP is single-threaded per-request so a
     *  process-local bool is all we need to prevent self-deadlock. */
    private static bool $holdingJobsLock = false;

    /**
     * Steps surfaced to the UI for the progress pill.
     */
    public const STEP_QUEUED   = 'queued';
    public const STEP_DRAFTING = 'drafting';
    public const STEP_WRITING  = 'writing_code';
    public const STEP_REVIEW   = 'awaiting_review';
    public const STEP_PUSHING  = 'pushing';
    public const STEP_DONE     = 'done';
    public const STEP_FAILED   = 'failed';

    /**
     * Enqueue a new generation job and return its id.
     *
     * @param array{
     *   prompt:string,
     *   mode:string,
     *   target_slug?:string,
     *   user_id?:int,
     *   auto_commit?:bool,
     *   error_context?:array<string,mixed>
     * } $args
     */
    public static function enqueue(array $args): string
    {
        $jobId = wp_generate_uuid4();

        $job = [
            'id'              => $jobId,
            'mode'            => (string) ($args['mode'] ?? 'generate'),
            'prompt'          => (string) ($args['prompt'] ?? ''),
            'target_slug'     => (string) ($args['target_slug'] ?? ''),
            'app_name'        => (string) ($args['app_name'] ?? ''),
            'app_description' => (string) ($args['app_description'] ?? ''),
            'user_id'         => (int) ($args['user_id'] ?? get_current_user_id()),
            'auto_commit'     => (bool) ($args['auto_commit'] ?? false),
            'error_context'   => is_array($args['error_context'] ?? null) ? $args['error_context'] : null,
            'status'        => 'pending',
            'step'          => self::STEP_QUEUED,
            'errors'        => [],
            'draft'         => null,   // populated after Phase 1
            'result'        => null,   // populated after Phase 2
            'provider'      => (string) get_option('ep_agent_provider', 'anthropic'),
            'model'         => (string) get_option('ep_agent_model', ''),
            'created_at'    => time(),
            'updated_at'    => time(),
        ];

        self::saveJob($job);

        // Pre-create the draft post BEFORE the LLM call so the user
        // can see "draft in progress" in the panel and navigate away
        // without losing the work. For generate-mode jobs we create a
        // placeholder (real slug isn't known until the LLM returns).
        // For iterate/repair jobs we just bump the existing post's
        // status meta — the post already exists.
        $jobMeta = [
            'job_id' => $jobId,
            'mode'   => $job['mode'],
            'prompt' => $job['prompt'],
        ];
        if ($job['mode'] === 'generate') {
            AppRegistry::createPlaceholderDraft($jobId, $jobMeta, [
                'slug'        => $job['target_slug'],
                'name'        => $job['app_name'],
                'description' => $job['app_description'],
            ]);
            self::log($job, 'Job queued — generating new app "' . $job['app_name'] . '" (' . $job['target_slug'] . ')');
        } elseif ($job['target_slug'] !== '') {
            $statusMap = [
                'iterate' => 'iterating',
                'repair'  => 'repairing',
            ];
            $running = $statusMap[$job['mode']] ?? 'drafting';
            AppRegistry::markDraftRunning($job['target_slug'], $running, $jobMeta);
            self::log($job, 'Job queued — ' . $job['mode'] . ' on ' . $job['target_slug']);
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$jobId], 'examplepress-agent');
        } else {
            // Action Scheduler not available — run inline. This blocks
            // the current request but is the only reliable fallback.
            // Bump limits so a long LLM call doesn't get killed.
            if ((int) ini_get('max_execution_time') < 300) {
                @set_time_limit(300);
            }
            self::handle($jobId);
        }

        return $jobId;
    }

    /**
     * Action Scheduler callback. Registered in Kernel::boot().
     */
    public static function handle(string $jobId): void
    {
        $job = self::getJob($jobId);
        if (!$job) {
            return;
        }

        try {
            self::updateJob($jobId, ['status' => 'running', 'step' => self::STEP_DRAFTING]);
            self::log($job, 'Sending prompt to LLM (' . ($job['provider'] ?? 'unknown') . '/' . ($job['model'] ?? 'default') . ')…');

            // Phase 1: draft only.
            switch ($job['mode']) {
                case 'iterate':
                    self::draftIterate($job);
                    break;
                case 'repair':
                    self::draftRepair($job);
                    break;
                default:
                    self::draftGenerate($job);
                    break;
            }

            // After drafting, only proceed to commit if auto_commit is on.
            // Otherwise pause at STEP_REVIEW for the user.
            $job = self::getJob($jobId);
            if ($job && $job['status'] === 'drafted' && !empty($job['auto_commit'])) {
                self::commit($jobId);
            }
        } catch (\Throwable $e) {
            self::fail($jobId, $e->getMessage());
        }
    }

    /**
     * Phase 2 entry point: push the drafted files to GitHub. Called by
     * AgentController::commit() after the user clicks "Push".
     */
    /**
     * Commit a stashed draft directly from the post (no job state
     * required). Used when the user resumes a draft from the panel
     * after the originating job has been GC'd from ep_agent_jobs.
     * Synthesizes a one-shot job record from the post-meta payload
     * and routes through the existing commit handlers.
     */
    public static function commitFromStash(string $slug): bool
    {
        $payload = AppRegistry::getDraftPayload($slug);
        if (!$payload) {
            return false;
        }

        $post = AppRegistry::getPost($slug);
        if (!$post) {
            return false;
        }

        $jobId = wp_generate_uuid4();
        $mode  = $post->post_status === 'draft' ? 'generate' : 'iterate';

        $job = [
            'id'          => $jobId,
            'mode'        => $mode,
            'prompt'      => '',
            'target_slug' => $slug,
            'user_id'     => get_current_user_id(),
            'auto_commit' => false,
            'status'      => 'running',
            'step'        => self::STEP_PUSHING,
            'errors'      => [],
            'draft'       => $payload,
            'result'      => null,
            'provider'    => '',
            'model'       => '',
            'created_at'  => time(),
            'updated_at'  => time(),
        ];
        self::saveJob($job);

        try {
            if ($mode === 'generate') {
                self::commitGenerate($job);
            } else {
                self::commitIterate($job);
            }
            return true;
        } catch (\Throwable $e) {
            self::fail($jobId, $e->getMessage());
            return false;
        }
    }

    public static function commit(string $jobId): bool
    {
        $job = self::getJob($jobId);
        if (!$job || $job['status'] !== 'drafted' || empty($job['draft'])) {
            return false;
        }

        try {
            self::updateJob($jobId, ['status' => 'running', 'step' => self::STEP_PUSHING]);
            // Repair commits go through the iterate path — same parent SHA,
            // same patch-bump release, same AppRegistry update.
            if ($job['mode'] === 'iterate' || $job['mode'] === 'repair') {
                self::commitIterate($job);
            } else {
                self::commitGenerate($job);
            }
            return true;
        } catch (\Throwable $e) {
            self::fail($jobId, $e->getMessage());
            return false;
        }
    }

    /**
     * Discard a drafted job without committing. Removes the job from
     * the store entirely so it doesn't clutter the history with
     * abandoned drafts.
     */
    public static function discard(string $jobId): bool
    {
        $job = self::getJob($jobId);
        if (!$job) {
            return false;
        }

        // Drop the matching draft post / pending stash so the audit
        // trail matches the user's intent. Prefer the job's target_slug
        // (set after finalization) but fall back to a job-ID lookup
        // for placeholders that never got finalized — those still have
        // their generated agent-draft-* slug.
        $slug = (string) ($job['target_slug'] ?? '');
        if ($slug === '') {
            $placeholder = AppRegistry::getPostByJobId($jobId);
            if ($placeholder) {
                $slug = (string) get_post_meta($placeholder->ID, '_ep_plugin_slug', true);
            }
        }
        if ($slug !== '') {
            AppRegistry::discardDraft($slug);
        }

        self::withJobsLock(static function () use ($jobId): void {
            $jobs = (array) get_option(self::OPTION_JOBS, []);
            unset($jobs[$jobId]);
            update_option(self::OPTION_JOBS, $jobs, false);
        });
        return true;
    }

    // ── Phase 1: Draft ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $job
     */
    private static function draftGenerate(array $job): void
    {
        $jobId = (string) $job['id'];
        $slug  = (string) $job['target_slug'];
        $appName = (string) ($job['app_name'] ?? '');
        $appDesc = (string) ($job['app_description'] ?? '');

        // Tell the LLM what slug/name/description to use.
        $appContext = '';
        if ($appName !== '') {
            $appContext .= "\n\nApp Name: {$appName}";
        }
        if ($slug !== '') {
            $appContext .= "\nApp Slug: {$slug}";
        }
        if ($appDesc !== '') {
            $appContext .= "\nApp Description: {$appDesc}";
        }
        $fullPrompt = $job['prompt'] . $appContext;

        $generated = LLMClient::generateApp($fullPrompt);

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);
        self::log($job, 'LLM returned ' . count($generated->files) . ' files — processing…');

        // Override the manifest slug/name/description with the user's
        // values so the LLM can't deviate from what was specified.
        $generated->manifest['slug'] = $slug;
        if ($appName !== '') {
            $generated->manifest['name'] = $appName;
        }
        if ($appDesc !== '') {
            $generated->manifest['description'] = $appDesc;
        }

        $payload = self::draftPayload($generated);
        $jobMeta = [
            'job_id' => $jobId,
            'mode'   => 'generate',
            'prompt' => (string) ($job['prompt'] ?? ''),
        ];

        // The placeholder was already created with the correct slug in
        // enqueue(), so finalizePlaceholderDraft just writes the payload.
        $finalized = AppRegistry::finalizePlaceholderDraft($jobId, $slug, $payload, $jobMeta);
        if (!$finalized) {
            $reason = "Failed to persist draft payload for \"{$slug}\". The generated output may be too large for the database — check MySQL max_allowed_packet and the error log.";
            AppRegistry::recordDraftFailure($slug, [$reason], $jobMeta);
            self::fail($jobId, $reason);
            return;
        }

        self::updateJob($jobId, ['draft' => $payload]);
        self::log($job, 'Draft persisted for "' . $slug . '" — running validation…');

        $result = self::validateWithAutoRepair($generated, $job, $slug, $jobMeta);
        if (!$result['validation']['ok']) {
            // Update the stashed payload with the (possibly partially repaired) version.
            $repairedPayload = self::draftPayload($result['generated']);
            AppRegistry::stashDraftPayload($slug, $repairedPayload, $jobMeta);
            self::updateJob($jobId, ['draft' => $repairedPayload]);
            AppRegistry::recordDraftFailure($slug, $result['validation']['errors'], $jobMeta);
            self::fail($jobId, 'Validation failed: ' . implode(' ', $result['validation']['errors']));
            return;
        }

        // If auto-repair produced a different version, update the stashed payload.
        if ($result['generated'] !== $generated) {
            $payload = self::draftPayload($result['generated']);
            AppRegistry::stashDraftPayload($slug, $payload, $jobMeta);
            self::updateJob($jobId, ['draft' => $payload]);
        }

        self::log($job, '✓ Validation passed — ready for review');
        self::updateJob($jobId, [
            'status' => 'drafted',
            'step'   => self::STEP_REVIEW,
        ]);
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function draftIterate(array $job): void
    {
        $jobId = (string) $job['id'];
        $slug  = (string) $job['target_slug'];

        if (!$slug) {
            self::fail($jobId, 'Iteration job missing target_slug.');
            return;
        }

        $post = AppRegistry::getPost($slug);
        if (!$post) {
            self::fail($jobId, "App {$slug} is not registered.");
            return;
        }

        $jobMeta = [
            'job_id' => $jobId,
            'mode'   => 'iterate',
            'prompt' => (string) ($job['prompt'] ?? ''),
        ];

        self::log($job, 'Loading source files for iteration…');
        $context = self::loadIterationContext($slug, $jobId);
        if ($context === null) {
            return; // failed already inside loadIterationContext
        }
        ['files' => $files, 'manifest' => $manifest, 'parent_sha' => $parentSha, 'owner_repo' => $ownerRepo, 'source' => $source] = $context;
        self::log($job, 'Loaded ' . count($files) . ' files from ' . $source);

        if (empty($manifest['supports_ai_iteration'])) {
            self::fail($jobId, "App {$slug} does not support AI iteration (ejected to developer mode).");
            return;
        }

        $generated = LLMClient::iterateApp((string) ($job['prompt'] ?? ''), $files, $manifest);

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);
        self::log($job, 'LLM returned ' . count($generated->files) . ' files — processing…');

        $newVersion = $generated->version ?: self::bumpPatch((string) ($manifest['version'] ?? '1.0.0'));

        $payload = self::draftPayload($generated);
        $payload['version']    = $newVersion;
        $payload['parent_sha'] = $parentSha;
        $payload['owner_repo'] = $ownerRepo;
        $payload['source']     = $source;

        if (!AppRegistry::stashDraftPayload($slug, $payload, $jobMeta)) {
            self::fail($jobId, "Failed to persist draft payload for \"{$slug}\". The generated output may be too large — check the error log.");
            return;
        }
        self::log($job, 'Draft stashed (v' . $newVersion . ') — running validation…');

        self::updateJob($jobId, ['draft' => $payload]);

        $result = self::validateWithAutoRepair($generated, $job, $slug, $jobMeta);
        if (!$result['validation']['ok']) {
            $repairedPayload = self::draftPayload($result['generated']);
            $repairedPayload['version']    = $newVersion;
            $repairedPayload['parent_sha'] = $parentSha;
            $repairedPayload['owner_repo'] = $ownerRepo;
            $repairedPayload['source']     = $source;
            AppRegistry::stashDraftPayload($slug, $repairedPayload, $jobMeta);
            self::updateJob($jobId, ['draft' => $repairedPayload]);
            AppRegistry::recordDraftFailure($slug, $result['validation']['errors'], $jobMeta);
            self::fail($jobId, 'Validation failed: ' . implode(' ', $result['validation']['errors']));
            return;
        }

        if ($result['generated'] !== $generated) {
            $repairedPayload = self::draftPayload($result['generated']);
            $repairedPayload['version']    = $newVersion;
            $repairedPayload['parent_sha'] = $parentSha;
            $repairedPayload['owner_repo'] = $ownerRepo;
            $repairedPayload['source']     = $source;
            AppRegistry::stashDraftPayload($slug, $repairedPayload, $jobMeta);
            self::updateJob($jobId, ['draft' => $repairedPayload]);
        }

        self::log($job, '✓ Validation passed — ready for review');
        self::updateJob($jobId, [
            'status' => 'drafted',
            'step'   => self::STEP_REVIEW,
        ]);
    }

    /**
     * Resolve the iteration source for a slug. Prefers any pending
     * stashed draft payload (so iterating against an unpushed draft
     * works); falls back to the live GitHub tree for published apps.
     *
     * Returns null after calling self::fail() if no source is available.
     *
     * @return array{
     *   files:array<int,array{path:string,contents:string}>,
     *   manifest:array<string,mixed>,
     *   parent_sha:string,
     *   owner_repo:string,
     *   source:string
     * }|null
     */
    private static function loadIterationContext(string $slug, string $jobId): ?array
    {
        $stashed = AppRegistry::getDraftPayload($slug);
        if (is_array($stashed) && !empty($stashed['files'])) {
            // Iterate against the in-memory stash. parent_sha + owner_repo
            // come from the previous stash if present (so iterations chain
            // correctly when committed); otherwise blank means "first push".
            return [
                'files'      => array_map(static fn($f) => [
                    'path'     => (string) ($f['path'] ?? ''),
                    'contents' => (string) ($f['contents'] ?? ''),
                ], $stashed['files']),
                'manifest'   => is_array($stashed['manifest'] ?? null) ? $stashed['manifest'] : [],
                'parent_sha' => (string) ($stashed['parent_sha'] ?? ''),
                'owner_repo' => (string) ($stashed['owner_repo'] ?? ''),
                'source'     => 'stash',
            ];
        }

        // No stash — fall back to GitHub. Requires the app to be published.
        $record = AppRegistry::get($slug);
        if (!$record || empty($record['github']['owner_repo'])) {
            self::fail($jobId, "App {$slug} has no stashed draft and no GitHub repo to iterate against.");
            return null;
        }
        $ownerRepo = (string) $record['github']['owner_repo'];

        $manifestPath = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';
        $manifest = is_readable($manifestPath)
            ? (array) json_decode((string) file_get_contents($manifestPath), true)
            : [];

        $tree = GitHub::fetchRepoTree($ownerRepo);
        if (is_wp_error($tree)) {
            self::fail($jobId, 'Fetch repo tree: ' . $tree->get_error_message());
            return null;
        }

        return [
            'files'      => $tree['files'],
            'manifest'   => $manifest,
            'parent_sha' => (string) $tree['sha'],
            'owner_repo' => $ownerRepo,
            'source'     => 'github',
        ];
    }

    /**
     * Repair mode: targeted fix for a reported error.
     *
     * Same shape as draftIterate (loads source, drafts via LLM, stashes
     * for review) but uses LLMClient::repairApp() with a strict
     * minimum-change system prompt and computes a per-file change
     * summary so the preview UI can highlight which files were touched.
     *
     * Repair can run against EITHER a stashed draft (the user is
     * fixing a never-pushed draft that the validator rejected) OR a
     * live GitHub tree (the user is fixing an installed app that
     * crashed at runtime). Both flow through loadIterationContext().
     *
     * @param array<string,mixed> $job
     */
    private static function draftRepair(array $job): void
    {
        $jobId = (string) $job['id'];
        $slug  = (string) $job['target_slug'];

        if (!$slug) {
            self::fail($jobId, 'Repair job missing target_slug.');
            return;
        }

        $errorContext = is_array($job['error_context'] ?? null) ? $job['error_context'] : [];
        if (empty($errorContext['error_message'])) {
            self::fail($jobId, 'Repair job missing error_message in error_context.');
            return;
        }

        $jobMeta = [
            'job_id' => $jobId,
            'mode'   => 'repair',
            'prompt' => (string) ($job['prompt'] ?? ''),
        ];

        self::log($job, 'Loading source files for repair…');
        $context = self::loadIterationContext($slug, $jobId);
        if ($context === null) {
            return;
        }
        ['files' => $files, 'manifest' => $manifest, 'parent_sha' => $parentSha, 'owner_repo' => $ownerRepo, 'source' => $source] = $context;
        self::log($job, 'Loaded ' . count($files) . ' files from ' . $source . ' — sending repair prompt…');

        if (empty($manifest['supports_ai_iteration'])) {
            self::fail($jobId, "App {$slug} does not support AI iteration (ejected to developer mode).");
            return;
        }

        $generated = LLMClient::repairApp(
            (string) ($job['prompt'] ?? ''),
            $files,
            $manifest,
            $errorContext
        );

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);
        self::log($job, 'LLM returned ' . count($generated->files) . ' files — computing diff…');

        $newVersion = $generated->version ?: self::bumpPatch((string) ($manifest['version'] ?? '1.0.0'));

        $payload = self::draftPayload($generated);
        $payload['version']    = $newVersion;
        $payload['parent_sha'] = $parentSha;
        $payload['owner_repo'] = $ownerRepo;
        $payload['source']     = $source;

        // Per-file change summary — repair's surgical contract is "touch
        // as little as possible". The user reviews the badges in the UI
        // and rejects any repair that touched too many files.
        $previousByPath = [];
        foreach ($files as $f) {
            $previousByPath[(string) $f['path']] = (string) $f['contents'];
        }
        $modified = 0; $added = 0; $unchanged = 0;
        foreach ($payload['files'] as &$file) {
            $path = (string) ($file['path'] ?? '');
            if (!isset($previousByPath[$path])) {
                $file['change'] = 'added';
                $added++;
            } elseif ($previousByPath[$path] !== (string) ($file['contents'] ?? '')) {
                $file['change'] = 'modified';
                $modified++;
            } else {
                $file['change'] = 'unchanged';
                $unchanged++;
            }
        }
        unset($file);
        $removed = count($previousByPath) - $unchanged - $modified;
        $payload['change_summary'] = [
            'modified'  => $modified,
            'added'     => $added,
            'unchanged' => $unchanged,
            'removed'   => max(0, $removed),
        ];

        // Stash on the post BEFORE validating.
        if (!AppRegistry::stashDraftPayload($slug, $payload, $jobMeta)) {
            self::fail($jobId, "Failed to persist repair payload for \"{$slug}\". The generated output may be too large — check the error log.");
            return;
        }
        self::log($job, 'Repair stashed (' . $modified . ' modified, ' . $added . ' added, ' . max(0, $removed) . ' removed) — validating…');

        self::updateJob($jobId, ['draft' => $payload]);

        $result = self::validateWithAutoRepair($generated, $job, $slug, $jobMeta);
        if (!$result['validation']['ok']) {
            $repairedPayload = self::draftPayload($result['generated']);
            $repairedPayload['version']    = $newVersion;
            $repairedPayload['parent_sha'] = $parentSha;
            $repairedPayload['owner_repo'] = $ownerRepo;
            $repairedPayload['source']     = $source;
            AppRegistry::stashDraftPayload($slug, $repairedPayload, $jobMeta);
            self::updateJob($jobId, ['draft' => $repairedPayload]);
            AppRegistry::recordDraftFailure($slug, $result['validation']['errors'], $jobMeta);
            self::fail($jobId, 'Validation failed: ' . implode(' ', $result['validation']['errors']));
            return;
        }

        if ($result['generated'] !== $generated) {
            $repairedPayload = self::draftPayload($result['generated']);
            $repairedPayload['version']    = $newVersion;
            $repairedPayload['parent_sha'] = $parentSha;
            $repairedPayload['owner_repo'] = $ownerRepo;
            $repairedPayload['source']     = $source;
            AppRegistry::stashDraftPayload($slug, $repairedPayload, $jobMeta);
            self::updateJob($jobId, ['draft' => $repairedPayload]);
        }

        self::log($job, '✓ Validation passed — ready for review');
        self::updateJob($jobId, [
            'status' => 'drafted',
            'step'   => self::STEP_REVIEW,
        ]);
    }

    /**
     * Validate a generated app. If validation fails, attempt one
     * automatic repair pass before giving up. Returns the (possibly
     * repaired) GeneratedApp and the validation result.
     *
     * @return array{generated:\ExamplePress\MU\Agent\GeneratedApp,validation:array{ok:bool,errors:array<int,string>}}
     */
    private static function validateWithAutoRepair(
        GeneratedApp $generated,
        array $job,
        string $slug,
        array $jobMeta
    ): array {
        $jobId = (string) $job['id'];

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if ($validation['ok']) {
            return ['generated' => $generated, 'validation' => $validation];
        }

        // One auto-repair attempt before bothering the user.
        $errorCount = count($validation['errors']);
        self::log($job, "⚠ Validation found {$errorCount} error(s) — attempting auto-repair…");

        $errorMessage = implode("\n", $validation['errors']);
        $repoFiles = [];
        foreach ($generated->files as $f) {
            $repoFiles[] = [
                'path'     => (string) ($f['path'] ?? ''),
                'contents' => (string) ($f['contents'] ?? ''),
            ];
        }

        try {
            $repaired = LLMClient::repairApp(
                '',
                $repoFiles,
                $generated->manifest,
                [
                    'error_message' => "The following validation errors must be fixed:\n\n" . $errorMessage,
                    'reported_at'   => time(),
                ]
            );

            $validation2 = AppValidator::validateGenerated($repaired->manifest, $repaired->files);
            if ($validation2['ok']) {
                self::log($job, '✓ Auto-repair fixed all ' . $errorCount . ' error(s)');
                return ['generated' => $repaired, 'validation' => $validation2];
            }

            // Repair tried but still failing — return the repaired version
            // (may have fixed some errors) with the remaining failures.
            $remaining = count($validation2['errors']);
            self::log($job, "⚠ Auto-repair resolved " . ($errorCount - $remaining) . " of {$errorCount} error(s), {$remaining} remain");
            return ['generated' => $repaired, 'validation' => $validation2];
        } catch (\Throwable $e) {
            self::log($job, '⚠ Auto-repair failed: ' . $e->getMessage());
            // Return original validation errors.
            return ['generated' => $generated, 'validation' => $validation];
        }
    }

    /**
     * Build a UI-safe summary of a generated payload. We persist file
     * paths + sizes for the preview pane plus the FULL contents (capped)
     * so the commit phase can push without re-running the LLM.
     *
     * @return array<string,mixed>
     */
    private static function draftPayload(GeneratedApp $g): array
    {
        $files = [];
        foreach ($g->files as $f) {
            $files[] = [
                'path'     => (string) ($f['path'] ?? ''),
                'contents' => (string) ($f['contents'] ?? ''),
                'bytes'    => strlen((string) ($f['contents'] ?? '')),
            ];
        }
        return [
            'manifest'       => $g->manifest,
            'files'          => $files,
            'commit_message' => $g->commitMessage,
            'version'        => $g->version,
        ];
    }

    // ── Phase 2: Commit ────────────────────────────────────────────

    /**
     * @param array<string,mixed> $job
     */
    private static function commitGenerate(array $job): void
    {
        $jobId = (string) $job['id'];

        // Draft shape guard. The caller (commit() / commitFromStash()) has
        // already checked !empty($job['draft']) but not that it's an
        // array with the keys we're about to read. A corrupted payload
        // would otherwise trigger PHP 8 warnings on offset access and
        // then fail deeper in the pipeline with a confusing error.
        $draft = $job['draft'] ?? null;
        if (!is_array($draft)) {
            self::fail($jobId, 'Internal error: draft payload is missing or not an array.');
            return;
        }

        $manifest = is_array($draft['manifest'] ?? null) ? $draft['manifest'] : [];
        $slug     = (string) ($manifest['slug'] ?? '');
        $name     = (string) ($manifest['name'] ?? '');
        if ($slug === '' || $name === '') {
            self::fail($jobId, 'Internal error: draft manifest is missing slug or name.');
            return;
        }
        $description    = (string) ($manifest['description'] ?? '');
        $commitMessage  = (string) ($draft['commit_message'] ?? 'Generated by ExamplePress Agent');
        $draftVersion   = (string) ($draft['version'] ?? '1.0.0');
        $draftFilesRaw  = is_array($draft['files'] ?? null) ? $draft['files'] : [];
        $files          = self::draftFilesForPush($draftFilesRaw);

        $repo = GitHub::createRepo($slug, $description);
        if (is_wp_error($repo)) {
            self::fail($jobId, 'GitHub repo: ' . $repo->get_error_message());
            return;
        }

        $ownerRepo = (string) ($repo['owner_repo'] ?? '');
        $htmlUrl   = (string) ($repo['html_url'] ?? '');
        $repoId    = (string) ($repo['repo_id'] ?? '');

        $push = GitHub::pushFiles(
            ownerRepo: $ownerRepo,
            files: $files,
            message: $commitMessage,
        );
        if (is_wp_error($push)) {
            self::fail($jobId, 'GitHub push: ' . $push->get_error_message());
            return;
        }

        $release = GitHub::createRelease(
            ownerRepo: $ownerRepo,
            tag: 'v' . $draftVersion,
            name: 'v' . $draftVersion,
            body: $commitMessage,
        );
        if (is_wp_error($release)) {
            self::fail($jobId, 'GitHub release: ' . $release->get_error_message());
            return;
        }

        // Promote the draft post to publish + stamp GitHub coordinates.
        // The draft post was created during draftGenerate() and contains
        // the canonical payload + history. promoteToPublished() also
        // clears the pending payload meta as the live state IS the
        // canonical version now.
        AppRegistry::promoteToPublished($slug, [
            'owner_repo' => $ownerRepo,
            'repo_id'    => $repoId,
            'html_url'   => $htmlUrl,
        ], [
            'job_id' => $jobId,
            'mode'   => 'generate',
        ]);
        AppUpdateProvider::flush();

        self::updateJob($jobId, [
            'status' => 'success',
            'step'   => self::STEP_DONE,
            'result' => [
                'slug'       => $slug,
                'name'       => $name,
                'version'    => $draftVersion,
                'owner_repo' => $ownerRepo,
                'html_url'   => $htmlUrl,
                'commit_sha' => (string) ($push['commit_sha'] ?? ''),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function commitIterate(array $job): void
    {
        $jobId = (string) $job['id'];
        $slug  = (string) ($job['target_slug'] ?? '');
        $mode  = (string) ($job['mode'] ?? 'iterate');

        if ($slug === '') {
            self::fail($jobId, 'Internal error: iterate job has no target_slug.');
            return;
        }

        // Draft shape guard — see commitGenerate for the rationale.
        $draft = $job['draft'] ?? null;
        if (!is_array($draft)) {
            self::fail($jobId, 'Internal error: draft payload is missing or not an array.');
            return;
        }

        $ownerRepo     = (string) ($draft['owner_repo'] ?? '');
        $newVersion    = (string) ($draft['version'] ?? '');
        $commitMessage = (string) ($draft['commit_message'] ?? '');
        $parentSha     = (string) ($draft['parent_sha'] ?? '');
        $draftFilesRaw = is_array($draft['files'] ?? null) ? $draft['files'] : [];

        if ($newVersion === '') {
            self::fail($jobId, 'Internal error: draft payload missing version.');
            return;
        }
        if ($commitMessage === '') {
            $commitMessage = "{$mode} v{$newVersion}";
        }

        $files = self::draftFilesForPush($draftFilesRaw);

        // Iteration/repair against a never-pushed draft: no parent SHA,
        // no GitHub repo yet. Create the repo + initial commit, same as
        // commitGenerate. The pending stash is what we're pushing.
        if ($ownerRepo === '') {
            $manifest    = is_array($draft['manifest'] ?? null) ? $draft['manifest'] : [];
            $description = (string) ($manifest['description'] ?? '');

            $repo = GitHub::createRepo($slug, $description);
            if (is_wp_error($repo)) {
                self::fail($jobId, 'GitHub repo: ' . $repo->get_error_message());
                return;
            }
            $ownerRepo = (string) ($repo['owner_repo'] ?? '');
            $repoId    = (string) ($repo['repo_id'] ?? '');
            $htmlUrl   = (string) ($repo['html_url'] ?? '');

            $push = GitHub::pushFiles(
                ownerRepo: $ownerRepo,
                files: $files,
                message: $commitMessage,
            );
            if (is_wp_error($push)) {
                self::fail($jobId, 'GitHub push: ' . $push->get_error_message());
                return;
            }

            $release = GitHub::createRelease(
                ownerRepo: $ownerRepo,
                tag: 'v' . $newVersion,
                name: 'v' . $newVersion,
                body: $commitMessage,
            );
            if (is_wp_error($release)) {
                self::fail($jobId, 'GitHub release: ' . $release->get_error_message());
                return;
            }

            AppRegistry::promoteToPublished($slug, [
                'owner_repo' => $ownerRepo,
                'repo_id'    => $repoId,
                'html_url'   => $htmlUrl,
            ], [
                'job_id' => $jobId,
                'mode'   => $mode,
            ]);
            AppUpdateProvider::flush();

            self::updateJob($jobId, [
                'status' => 'success',
                'step'   => self::STEP_DONE,
                'result' => [
                    'slug'       => $slug,
                    'version'    => $newVersion,
                    'owner_repo' => $ownerRepo,
                    'commit_sha' => (string) ($push['commit_sha'] ?? ''),
                ],
            ]);
            return;
        }

        // Standard iteration/repair against an already-published app:
        // chain a new commit on top of the parent SHA, tag a release,
        // bump the version on the post, and clear the pending stash.
        $push = GitHub::pushFiles(
            ownerRepo: $ownerRepo,
            files: $files,
            message: $commitMessage,
            parentSha: $parentSha,
        );
        if (is_wp_error($push)) {
            self::fail($jobId, 'GitHub push: ' . $push->get_error_message());
            return;
        }

        $release = GitHub::createRelease(
            ownerRepo: $ownerRepo,
            tag: 'v' . $newVersion,
            name: 'v' . $newVersion,
            body: $commitMessage,
        );
        if (is_wp_error($release)) {
            self::fail($jobId, 'GitHub release: ' . $release->get_error_message());
            return;
        }

        // Atomic "we pushed version X" record. Replaces the former inline
        // read-modify-write cycle on _ep_draft_history + _ep_version that
        // bypassed the per-post lock.
        AppRegistry::recordPush($slug, $newVersion, [
            'job_id' => $jobId,
            'mode'   => $mode,
        ]);
        AppUpdateProvider::flush();

        self::updateJob($jobId, [
            'status' => 'success',
            'step'   => self::STEP_DONE,
            'result' => [
                'slug'       => $slug,
                'version'    => $newVersion,
                'owner_repo' => $ownerRepo,
                'commit_sha' => (string) ($push['commit_sha'] ?? ''),
            ],
        ]);
    }

    /**
     * Convert the draft file array (path/contents/bytes) into the shape
     * GitHub::pushFiles expects (path/contents).
     *
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array{path:string,contents:string}>
     */
    private static function draftFilesForPush(array $files): array
    {
        return array_values(array_map(static fn(array $f): array => [
            'path'     => (string) ($f['path'] ?? ''),
            'contents' => (string) ($f['contents'] ?? ''),
        ], $files));
    }

    private static function bumpPatch(string $version): string
    {
        $parts = array_map('intval', explode('.', ltrim($version, 'v')));
        $parts = array_pad($parts, 3, 0);
        $parts[2]++;
        return implode('.', $parts);
    }

    // ── Job state store ────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public static function getJob(string $jobId): ?array
    {
        $jobs = (array) get_option(self::OPTION_JOBS, []);
        return isset($jobs[$jobId]) && is_array($jobs[$jobId]) ? $jobs[$jobId] : null;
    }

    /**
     * Run a callable while holding the jobs-option advisory lock.
     *
     * Two threat models:
     *   (a) Concurrent writers in separate PHP processes — closed by
     *       the atomic add_option() / delete_option() pair.
     *   (b) Re-entrant calls from inside the same process (e.g.
     *       updateJob() → saveJob()) — closed by the $holdingJobsLock
     *       static bool, which skips re-acquisition to prevent
     *       self-deadlock.
     *
     * Stale-lock recovery: if an existing lock is older than
     * JOBS_LOCK_MAX_AGE seconds, it's assumed orphaned (process died
     * between add_option and delete_option) and forcibly cleared.
     *
     * Failure mode: if the lock can't be acquired after
     * JOBS_LOCK_MAX_ATTEMPTS, we log and fall through unlocked —
     * preferring to risk one lost write over silently dropping a
     * state change that the user is waiting on.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private static function withJobsLock(callable $fn): mixed
    {
        if (self::$holdingJobsLock) {
            return $fn();
        }

        for ($attempt = 0; $attempt < self::JOBS_LOCK_MAX_ATTEMPTS; $attempt++) {
            $now = time();

            // add_option returns false if the option already exists —
            // that's the UNIQUE-index CAS we're relying on.
            if (add_option(self::JOBS_LOCK_KEY, (string) $now, '', 'no')) {
                self::$holdingJobsLock = true;
                try {
                    return $fn();
                } finally {
                    self::$holdingJobsLock = false;
                    delete_option(self::JOBS_LOCK_KEY);
                }
            }

            // Existing lock — check for staleness.
            $existing = (int) get_option(self::JOBS_LOCK_KEY, 0);
            if ($existing > 0 && ($now - $existing) > self::JOBS_LOCK_MAX_AGE) {
                delete_option(self::JOBS_LOCK_KEY);
                continue;
            }

            // Back off linearly. PHP usleep is in microseconds.
            usleep(20000 * ($attempt + 1));
        }

        error_log(sprintf(
            '[ExamplePress GenerationJob] Could not acquire jobs lock after %d attempts — proceeding unlocked. Job state writes may race.',
            self::JOBS_LOCK_MAX_ATTEMPTS
        ));
        return $fn();
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function saveJob(array $job): void
    {
        self::withJobsLock(static function () use ($job): void {
            $jobs = (array) get_option(self::OPTION_JOBS, []);
            $jobs[$job['id']] = $job;

            // FIFO cap: drop oldest if we exceed the limit.
            if (count($jobs) > self::MAX_JOBS) {
                uasort($jobs, static fn($a, $b) => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));
                $jobs = array_slice($jobs, -self::MAX_JOBS, null, true);
            }

            update_option(self::OPTION_JOBS, $jobs, false);
        });
    }

    /**
     * @param array<string,mixed> $patch
     */
    private static function updateJob(string $jobId, array $patch): void
    {
        self::withJobsLock(static function () use ($jobId, $patch): void {
            $job = self::getJob($jobId);
            if (!$job) {
                return;
            }
            $job = array_merge($job, $patch);
            $job['updated_at'] = time();
            self::saveJob($job);
        });
    }

    private static function fail(string $jobId, string $error): void
    {
        $loggedJob = null;
        self::withJobsLock(static function () use ($jobId, $error, &$loggedJob): void {
            $job = self::getJob($jobId);
            if (!$job) {
                return;
            }
            $job['status']     = 'failed';
            $job['step']       = self::STEP_FAILED;
            $job['errors'][]   = $error;
            $job['updated_at'] = time();
            self::saveJob($job);
            $loggedJob = $job;
        });
        if ($loggedJob !== null) {
            self::log($loggedJob, '✗ ' . $error);
        }
        error_log("ExamplePress agent job {$jobId} failed: {$error}");
    }

    /**
     * Append a line to the draft's activity log. Resolves the slug
     * from the job's target_slug or placeholder lookup.
     */
    private static function log(array $job, string $message): void
    {
        $slug = (string) ($job['target_slug'] ?? '');
        if ($slug === '') {
            $post = AppRegistry::getPostByJobId((string) $job['id']);
            if ($post) {
                $slug = (string) get_post_meta($post->ID, '_ep_plugin_slug', true);
            }
        }
        if ($slug !== '') {
            AppRegistry::appendLog($slug, $message);
        }
    }

    /**
     * Recent jobs sorted newest-first. Used by the UI.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        $jobs = array_values((array) get_option(self::OPTION_JOBS, []));
        usort($jobs, static fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        return array_slice($jobs, 0, $limit);
    }

    /**
     * All jobs whose target_slug matches. Newest first. Used by the
     * iterate modal to render the chat thread for a single app.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forSlug(string $slug, int $limit = 20): array
    {
        $jobs = array_values((array) get_option(self::OPTION_JOBS, []));
        $filtered = array_filter($jobs, static fn($j) => isset($j['target_slug']) && $j['target_slug'] === $slug);
        usort($filtered, static fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        return array_slice(array_values($filtered), 0, $limit);
    }

    /**
     * Lightweight projection of a job for the UI — drops the full file
     * contents (which can be large) but keeps paths + bytes so the
     * preview pane can render without bloating the JSON payload.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function summarize(array $job): array
    {
        $summary = $job;
        if (isset($summary['draft']['files']) && is_array($summary['draft']['files'])) {
            $summary['draft']['files'] = array_map(static fn($f) => [
                'path'   => (string) ($f['path'] ?? ''),
                'bytes'  => (int) ($f['bytes'] ?? 0),
                'change' => isset($f['change']) ? (string) $f['change'] : null,
            ], $summary['draft']['files']);
        }
        return $summary;
    }
}
