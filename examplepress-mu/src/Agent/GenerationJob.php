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

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if (!$validation['ok']) {
            self::fail($jobId, 'Validation failed: ' . implode(' ', $validation['errors']));
            return;
        }

        $slug = (string) $generated->manifest['slug'];

        // Conflict guard: refuse to draft a generate-mode job whose slug
        // collides with an existing app. The user should iterate instead.
        if (AppRegistry::get($slug)) {
            self::fail($jobId, "An app with slug \"{$slug}\" already exists. Use iterate mode to modify it.");
            return;
        }

        self::updateJob($jobId, [
            'status'      => 'drafted',
            'step'        => self::STEP_REVIEW,
            'target_slug' => $slug,
            'draft'       => self::draftPayload($generated),
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

        $record = AppRegistry::get($slug);
        if (!$record || empty($record['github']['owner_repo'])) {
            self::fail($jobId, "App {$slug} is not registered or has no GitHub repo.");
            return;
        }
        $ownerRepo = (string) $record['github']['owner_repo'];

        $manifestPath = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';
        $manifest = is_readable($manifestPath)
            ? (array) json_decode((string) file_get_contents($manifestPath), true)
            : [];

        if (empty($manifest['supports_ai_iteration'])) {
            self::fail($jobId, "App {$slug} does not support AI iteration (ejected to developer mode).");
            return;
        }

        $tree = GitHub::fetchRepoTree($ownerRepo);
        if (is_wp_error($tree)) {
            self::fail($jobId, 'Fetch repo tree: ' . $tree->get_error_message());
            return;
        }

        $generated = LLMClient::iterateApp($job['prompt'], $tree['files'], $manifest);

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if (!$validation['ok']) {
            self::fail($jobId, 'Validation failed: ' . implode(' ', $validation['errors']));
            return;
        }

        $newVersion = $generated->version ?: self::bumpPatch((string) ($manifest['version'] ?? '1.0.0'));

        $draft = self::draftPayload($generated);
        $draft['version']    = $newVersion;
        $draft['parent_sha'] = $tree['sha'];
        $draft['owner_repo'] = $ownerRepo;

        self::updateJob($jobId, [
            'status' => 'drafted',
            'step'   => self::STEP_REVIEW,
            'draft'  => $draft,
        ]);
    }

    /**
     * Repair mode: targeted fix for a reported error.
     *
     * Same shape as draftIterate (loads repo tree, drafts via LLM,
     * stores draft for review) but uses LLMClient::repairApp() with
     * a strict minimum-change system prompt and computes a per-file
     * change summary so the preview UI can highlight which files
     * were touched.
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

        $record = AppRegistry::get($slug);
        if (!$record || empty($record['github']['owner_repo'])) {
            self::fail($jobId, "App {$slug} is not registered or has no GitHub repo.");
            return;
        }
        $ownerRepo = (string) $record['github']['owner_repo'];

        $manifestPath = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';
        $manifest = is_readable($manifestPath)
            ? (array) json_decode((string) file_get_contents($manifestPath), true)
            : [];

        if (empty($manifest['supports_ai_iteration'])) {
            self::fail($jobId, "App {$slug} does not support AI iteration (ejected to developer mode).");
            return;
        }

        $tree = GitHub::fetchRepoTree($ownerRepo);
        if (is_wp_error($tree)) {
            self::fail($jobId, 'Fetch repo tree: ' . $tree->get_error_message());
            return;
        }

        $generated = LLMClient::repairApp(
            (string) ($job['prompt'] ?? ''),
            $tree['files'],
            $manifest,
            $errorContext
        );

        self::updateJob($jobId, ['step' => self::STEP_WRITING]);

        $validation = AppValidator::validateGenerated($generated->manifest, $generated->files);
        if (!$validation['ok']) {
            self::fail($jobId, 'Validation failed: ' . implode(' ', $validation['errors']));
            return;
        }

        $newVersion = $generated->version ?: self::bumpPatch((string) ($manifest['version'] ?? '1.0.0'));

        $draft = self::draftPayload($generated);
        $draft['version']    = $newVersion;
        $draft['parent_sha'] = $tree['sha'];
        $draft['owner_repo'] = $ownerRepo;

        // Compute per-file change summary so the preview UI can show
        // "X files modified, Y unchanged" — the surgical contract for
        // repair mode is "touch as little as possible". This is the
        // single most important UX signal for the user reviewing.
        $previousByPath = [];
        foreach ($tree['files'] as $f) {
            $previousByPath[(string) $f['path']] = (string) $f['contents'];
        }
        $modified = 0;
        $added    = 0;
        $unchanged = 0;
        foreach ($draft['files'] as &$file) {
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
        $draft['change_summary'] = [
            'modified'  => $modified,
            'added'     => $added,
            'unchanged' => $unchanged,
            'removed'   => max(0, $removed),
        ];

        self::updateJob($jobId, [
            'status' => 'drafted',
            'step'   => self::STEP_REVIEW,
            'draft'  => $draft,
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

        AppRegistry::set($slug, [
            'name'        => $name,
            'description' => $description,
            'version'     => $draft['version'],
            'source'      => 'agent',
            'github'      => [
                'owner_repo' => $repo['owner_repo'],
                'repo_id'    => (string) ($repo['repo_id'] ?? ''),
                'html_url'   => $repo['html_url'] ?? '',
            ],
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

        AppRegistry::set($slug, ['version' => $newVersion]);
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
