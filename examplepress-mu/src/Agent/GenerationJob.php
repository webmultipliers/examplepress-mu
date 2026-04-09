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
            'id'            => $jobId,
            'mode'          => (string) ($args['mode'] ?? 'generate'),
            'prompt'        => (string) ($args['prompt'] ?? ''),
            'target_slug'   => (string) ($args['target_slug'] ?? ''),
            'user_id'       => (int) ($args['user_id'] ?? get_current_user_id()),
            'auto_commit'   => (bool) ($args['auto_commit'] ?? false),
            'error_context' => is_array($args['error_context'] ?? null) ? $args['error_context'] : null,
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
            AppRegistry::createPlaceholderDraft($jobId, $jobMeta);
        } elseif ($job['target_slug'] !== '') {
            $statusMap = [
                'iterate' => 'iterating',
                'repair'  => 'repairing',
            ];
            $running = $statusMap[$job['mode']] ?? 'drafting';
            AppRegistry::markDraftRunning($job['target_slug'], $running, $jobMeta);
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$jobId], 'examplepress-agent');
        } else {
            // Fallback: synchronous run if Action Scheduler isn't loaded.
            wp_schedule_single_event(time() + 1, self::HOOK, [$jobId]);
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

        $jobs = (array) get_option(self::OPTION_JOBS, []);
        unset($jobs[$jobId]);
        update_option(self::OPTION_JOBS, $jobs, false);
        return true;
    }

    // ── Phase 1: Draft ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $job
     */
    private static function draftGenerate(array $job): void
    {
        $jobId = (string) $job['id'];

        $generated = LLMClient::generateApp($job['prompt']);

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);

        $slug = (string) ($generated->manifest['slug'] ?? '');
        if ($slug === '') {
            self::fail($jobId, 'Generated manifest is missing slug.');
            return;
        }

        $payload = self::draftPayload($generated);
        $jobMeta = [
            'job_id' => $jobId,
            'mode'   => 'generate',
            'prompt' => (string) ($job['prompt'] ?? ''),
        ];

        // Finalize the placeholder draft post created in enqueue().
        // Renames it to the real slug from the manifest, populates the
        // payload meta. Returns false if the slug collides with a
        // different existing app — in which case we mark the
        // placeholder failed and the user can rename or discard it
        // from the panel.
        $finalized = AppRegistry::finalizePlaceholderDraft($jobId, $slug, $payload, $jobMeta);
        if (!$finalized) {
            // Find the placeholder by job ID so we can record the failure
            // against it (the slug lookup won't work — we never finalized).
            $placeholder = AppRegistry::getPostByJobId($jobId);
            if ($placeholder) {
                $placeholderSlug = (string) get_post_meta($placeholder->ID, '_ep_plugin_slug', true);
                AppRegistry::recordDraftFailure($placeholderSlug, [
                    "Slug \"{$slug}\" collides with an existing app. Discard this draft or use iterate mode.",
                ], $jobMeta);
            }
            self::fail($jobId, "Slug \"{$slug}\" collides with an existing app.");
            return;
        }

        self::updateJob($jobId, [
            'target_slug' => $slug,
            'draft'       => $payload,
        ]);

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if (!$validation['ok']) {
            AppRegistry::recordDraftFailure($slug, $validation['errors'], $jobMeta);
            self::fail($jobId, 'Validation failed: ' . implode(' ', $validation['errors']));
            return;
        }

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

        $context = self::loadIterationContext($slug, $jobId);
        if ($context === null) {
            return; // failed already inside loadIterationContext
        }
        ['files' => $files, 'manifest' => $manifest, 'parent_sha' => $parentSha, 'owner_repo' => $ownerRepo, 'source' => $source] = $context;

        if (empty($manifest['supports_ai_iteration'])) {
            self::fail($jobId, "App {$slug} does not support AI iteration (ejected to developer mode).");
            return;
        }

        $generated = LLMClient::iterateApp((string) ($job['prompt'] ?? ''), $files, $manifest);

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);

        $newVersion = $generated->version ?: self::bumpPatch((string) ($manifest['version'] ?? '1.0.0'));

        $payload = self::draftPayload($generated);
        $payload['version']    = $newVersion;
        $payload['parent_sha'] = $parentSha;
        $payload['owner_repo'] = $ownerRepo;
        $payload['source']     = $source; // 'stash' or 'github' — UI hint

        // Stash on the post BEFORE validating so the user can repair
        // a bad iteration without losing it.
        AppRegistry::stashDraftPayload($slug, $payload, $jobMeta);

        self::updateJob($jobId, ['draft' => $payload]);

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if (!$validation['ok']) {
            AppRegistry::recordDraftFailure($slug, $validation['errors'], $jobMeta);
            self::fail($jobId, 'Validation failed: ' . implode(' ', $validation['errors']));
            return;
        }

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

        $context = self::loadIterationContext($slug, $jobId);
        if ($context === null) {
            return;
        }
        ['files' => $files, 'manifest' => $manifest, 'parent_sha' => $parentSha, 'owner_repo' => $ownerRepo, 'source' => $source] = $context;

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
        AppRegistry::stashDraftPayload($slug, $payload, $jobMeta);

        self::updateJob($jobId, ['draft' => $payload]);

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if (!$validation['ok']) {
            AppRegistry::recordDraftFailure($slug, $validation['errors'], $jobMeta);
            self::fail($jobId, 'Validation failed: ' . implode(' ', $validation['errors']));
            return;
        }

        self::updateJob($jobId, [
            'status' => 'drafted',
            'step'   => self::STEP_REVIEW,
        ]);
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
        $draft = $job['draft'];

        $manifest    = $draft['manifest'];
        $slug        = (string) $manifest['slug'];
        $name        = (string) $manifest['name'];
        $description = (string) ($manifest['description'] ?? '');
        $files       = self::draftFilesForPush($draft['files']);

        $repo = GitHub::createRepo($slug, $description);
        if (is_wp_error($repo)) {
            self::fail($jobId, 'GitHub repo: ' . $repo->get_error_message());
            return;
        }

        $push = GitHub::pushFiles(
            ownerRepo: $repo['owner_repo'],
            files: $files,
            message: $draft['commit_message'],
        );
        if (is_wp_error($push)) {
            self::fail($jobId, 'GitHub push: ' . $push->get_error_message());
            return;
        }

        $release = GitHub::createRelease(
            ownerRepo: $repo['owner_repo'],
            tag: 'v' . $draft['version'],
            name: 'v' . $draft['version'],
            body: $draft['commit_message'],
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
            'owner_repo' => $repo['owner_repo'],
            'repo_id'    => (string) ($repo['repo_id'] ?? ''),
            'html_url'   => $repo['html_url'] ?? '',
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
                'version'    => $draft['version'],
                'owner_repo' => $repo['owner_repo'],
                'html_url'   => $repo['html_url'] ?? '',
                'commit_sha' => $push['commit_sha'],
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function commitIterate(array $job): void
    {
        $jobId      = (string) $job['id'];
        $draft      = $job['draft'];
        $slug       = (string) $job['target_slug'];
        $ownerRepo  = (string) $draft['owner_repo'];
        $newVersion = (string) $draft['version'];
        $files      = self::draftFilesForPush($draft['files']);

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
            $ownerRepo = (string) $repo['owner_repo'];

            $push = GitHub::pushFiles(
                ownerRepo: $ownerRepo,
                files: $files,
                message: (string) $draft['commit_message'],
            );
            if (is_wp_error($push)) {
                self::fail($jobId, 'GitHub push: ' . $push->get_error_message());
                return;
            }

            $release = GitHub::createRelease(
                ownerRepo: $ownerRepo,
                tag: 'v' . $newVersion,
                name: 'v' . $newVersion,
                body: (string) $draft['commit_message'],
            );
            if (is_wp_error($release)) {
                self::fail($jobId, 'GitHub release: ' . $release->get_error_message());
                return;
            }

            AppRegistry::promoteToPublished($slug, [
                'owner_repo' => $ownerRepo,
                'repo_id'    => (string) ($repo['repo_id'] ?? ''),
                'html_url'   => $repo['html_url'] ?? '',
            ], [
                'job_id' => $jobId,
                'mode'   => (string) ($job['mode'] ?? 'iterate'),
            ]);
            AppUpdateProvider::flush();

            self::updateJob($jobId, [
                'status' => 'success',
                'step'   => self::STEP_DONE,
                'result' => [
                    'slug'       => $slug,
                    'version'    => $newVersion,
                    'owner_repo' => $ownerRepo,
                    'commit_sha' => (string) $push['commit_sha'],
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
            message: $draft['commit_message'],
            parentSha: (string) ($draft['parent_sha'] ?? ''),
        );
        if (is_wp_error($push)) {
            self::fail($jobId, 'GitHub push: ' . $push->get_error_message());
            return;
        }

        $release = GitHub::createRelease(
            ownerRepo: $ownerRepo,
            tag: 'v' . $newVersion,
            name: 'v' . $newVersion,
            body: $draft['commit_message'],
        );
        if (is_wp_error($release)) {
            self::fail($jobId, 'GitHub release: ' . $release->get_error_message());
            return;
        }

        // Bump the version on the post + record a 'pushed' history entry,
        // then clear the pending stash. The live app IS the canonical
        // version now; the next iteration starts from a clean slate.
        $post = AppRegistry::getPost($slug);
        if ($post) {
            update_post_meta($post->ID, '_ep_version', $newVersion);
            $history = AppRegistry::getDraftHistory($slug);
            $history[] = [
                'event'      => 'pushed',
                'job_id'     => $jobId,
                'mode'       => (string) ($job['mode'] ?? 'iterate'),
                'created_at' => time(),
                'version'    => $newVersion,
            ];
            update_post_meta($post->ID, AppRegistry::META_DRAFT_HISTORY, wp_json_encode($history));
        }
        AppRegistry::clearDraftPayload($slug);
        AppUpdateProvider::flush();

        self::updateJob($jobId, [
            'status' => 'success',
            'step'   => self::STEP_DONE,
            'result' => [
                'slug'       => $slug,
                'version'    => $newVersion,
                'owner_repo' => $ownerRepo,
                'commit_sha' => $push['commit_sha'],
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
     * @param array<string,mixed> $job
     */
    private static function saveJob(array $job): void
    {
        $jobs = (array) get_option(self::OPTION_JOBS, []);
        $jobs[$job['id']] = $job;

        // FIFO cap: drop oldest if we exceed the limit.
        if (count($jobs) > self::MAX_JOBS) {
            uasort($jobs, static fn($a, $b) => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));
            $jobs = array_slice($jobs, -self::MAX_JOBS, null, true);
        }

        update_option(self::OPTION_JOBS, $jobs, false);
    }

    /**
     * @param array<string,mixed> $patch
     */
    private static function updateJob(string $jobId, array $patch): void
    {
        $job = self::getJob($jobId);
        if (!$job) {
            return;
        }
        $job = array_merge($job, $patch);
        $job['updated_at'] = time();
        self::saveJob($job);
    }

    private static function fail(string $jobId, string $error): void
    {
        $job = self::getJob($jobId);
        if (!$job) {
            return;
        }
        $job['status']     = 'failed';
        $job['step']       = self::STEP_FAILED;
        $job['errors'][]   = $error;
        $job['updated_at'] = time();
        self::saveJob($job);
        error_log("ExamplePress agent job {$jobId} failed: {$error}");
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
