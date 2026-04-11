<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Persistent record of every app created through the scaffold flow.
 * Stored as a Custom Post Type (ep_app).
 */
final class AppRegistry
{
    public static function init(): void
    {
        add_action('init', [self::class, 'registerCpt']);
    }

    public static function registerCpt(): void
    {
        register_post_type('ep_app', [
            'labels' => [
                'name'          => __('Apps', 'examplepress-mu'),
                'singular_name' => __('App', 'examplepress-mu'),
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'supports'            => ['title'],
            'capability_type'     => 'post',
        ]);
    }

    /**
     * Find the CPT post for an app by its plugin slug.
     */
    public static function getPost(string $slug): ?\WP_Post
    {
        $posts = get_posts([
            'post_type'      => 'ep_app',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_key'       => '_ep_plugin_slug',
            'meta_value'     => $slug,
            'no_found_rows'  => true,
        ]);

        return $posts[0] ?? null;
    }

    /**
     * Convert a CPT post + meta into the record array.
     *
     * @return array<string, mixed>
     */
    public static function toRecord(\WP_Post $post): array
    {
        $slug = get_post_meta($post->ID, '_ep_plugin_slug', true) ?: $post->post_name;

        return [
            'slug'        => $slug,
            'name'        => $post->post_title,
            'description' => get_post_meta($post->ID, '_ep_description', true) ?: '',
            'version'     => get_post_meta($post->ID, '_ep_version', true) ?: '',
            'source'      => get_post_meta($post->ID, '_ep_source', true) ?: 'scaffolded',
            'created_at'  => $post->post_date_gmt !== '0000-00-00 00:00:00' ? gmdate('c', strtotime($post->post_date_gmt)) : '',
            'updated_at'  => $post->post_modified_gmt !== '0000-00-00 00:00:00' ? gmdate('c', strtotime($post->post_modified_gmt)) : '',
            'github'      => [
                'owner_repo' => get_post_meta($post->ID, '_ep_github_owner_repo', true) ?: '',
                'repo_id'    => get_post_meta($post->ID, '_ep_github_repo_id', true) ?: '',
                'html_url'   => get_post_meta($post->ID, '_ep_github_html_url', true) ?: '',
            ],
            'troy'        => [
                'server_url' => get_post_meta($post->ID, '_ep_troy_server_url', true) ?: '',
                'repo'       => get_post_meta($post->ID, '_ep_troy_repo', true) ?: '',
                'repo_id'    => get_post_meta($post->ID, '_ep_troy_repo_id', true) ?: '',
            ],
        ];
    }

    /**
     * Write record data to post meta.
     */
    public static function writeMeta(int $postId, array $data): void
    {
        $flatMap = [
            'description' => '_ep_description',
            'version'     => '_ep_version',
            'source'      => '_ep_source',
        ];

        foreach ($flatMap as $key => $metaKey) {
            if (isset($data[$key])) {
                update_post_meta($postId, $metaKey, $data[$key]);
            }
        }

        if (isset($data['github']) && is_array($data['github'])) {
            $githubMap = [
                'owner_repo' => '_ep_github_owner_repo',
                'repo_id'    => '_ep_github_repo_id',
                'html_url'   => '_ep_github_html_url',
            ];
            foreach ($githubMap as $key => $metaKey) {
                if (isset($data['github'][$key])) {
                    update_post_meta($postId, $metaKey, $data['github'][$key]);
                }
            }
        }

        if (isset($data['troy']) && is_array($data['troy'])) {
            $troyMap = [
                'server_url' => '_ep_troy_server_url',
                'repo'       => '_ep_troy_repo',
                'repo_id'    => '_ep_troy_repo_id',
            ];
            foreach ($troyMap as $key => $metaKey) {
                if (isset($data['troy'][$key])) {
                    update_post_meta($postId, $metaKey, $data['troy'][$key]);
                }
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        /**
         * Filter the maximum number of app records loaded by AppRegistry::all().
         * Defaults to 500 to avoid unbounded queries.
         */
        $limit = (int) apply_filters('examplepress_mu_apps_query_limit', 500);

        $posts = get_posts([
            'post_type'      => 'ep_app',
            'posts_per_page' => $limit,
            'post_status'    => 'any',
            'no_found_rows'  => true,
        ]);

        $registry = [];
        foreach ($posts as $post) {
            $record = self::toRecord($post);
            $registry[$record['slug']] = $record;
        }

        return $registry;
    }

    public static function get(string $slug): ?array
    {
        $post = self::getPost($slug);
        return $post ? self::toRecord($post) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function set(string $slug, array $data): array
    {
        $post = self::getPost($slug);

        if ($post) {
            $updateArgs = ['ID' => $post->ID];
            if (isset($data['name']) && $data['name'] !== $post->post_title) {
                $updateArgs['post_title'] = $data['name'];
            }
            if (count($updateArgs) > 1) {
                wp_update_post($updateArgs);
            }
            self::writeMeta($post->ID, $data);
            return self::toRecord(get_post($post->ID));
        }

        $postId = wp_insert_post([
            'post_type'   => 'ep_app',
            'post_title'  => $data['name'] ?? $slug,
            'post_name'   => $slug,
            // Apps created via the legacy scaffold/connect path are
            // immediately considered "live" — they have a plugin
            // directory on disk and (usually) a GitHub repo. The agent
            // path uses createDraft() / promoteToPublished() instead.
            'post_status' => 'publish',
        ]);

        if (is_wp_error($postId)) {
            return array_merge(['slug' => $slug], $data);
        }

        update_post_meta($postId, '_ep_plugin_slug', $slug);
        self::writeMeta($postId, $data);

        return self::toRecord(get_post($postId));
    }

    // ── Draft-stash API ─────────────────────────────────────────────
    //
    // The agent flow uses these methods to persist generated payloads
    // as ep_app posts BEFORE they're pushed to GitHub. Lifecycle:
    //
    //   createDraft         → wp_insert_post(status=draft) + payload meta
    //   stashDraftPayload   → updateDraftPayload + history append
    //   promoteToPublished  → status=publish + clear payload + write github
    //   clearDraftPayload   → drop the payload meta after a successful push
    //                          (used on iteration/repair pushes where the
    //                          post is already publish)
    //
    // The post is the canonical audit trail. The Action Scheduler job
    // record (ep_agent_jobs) is just transient worker state — if you
    // lose it, the draft is still recoverable from the post meta.

    /**
     * Meta keys used by the draft-stash layer. Centralised so the JS
     * data provider can read the same keys without magic strings.
     */
    public const META_DRAFT_PAYLOAD     = '_ep_draft_payload';
    public const META_DRAFT_STATUS      = '_ep_draft_status';
    public const META_DRAFT_ERRORS      = '_ep_draft_errors';
    public const META_DRAFT_ORIGIN_JOB  = '_ep_draft_origin_job_id';
    public const META_DRAFT_HISTORY     = '_ep_draft_history';
    public const META_DRAFT_UPDATED_AT  = '_ep_draft_updated_at';
    public const META_DRAFT_PROMPT      = '_ep_draft_prompt';
    public const META_DRAFT_LOG         = '_ep_draft_log';

    /**
     * Create a placeholder draft post the MOMENT the user clicks
     * Generate, BEFORE the LLM call runs. The post exists immediately
     * so the user can navigate away, see it in the drafts panel, and
     * come back later — no more "trapped in the modal" experience.
     *
     * The slug, name, and description are provided by the user at
     * generation time so the placeholder immediately reflects the
     * intended app identity.
     *
     * @param array<string,mixed> $jobMeta { mode, prompt }
     * @param array<string,mixed> $appIdentity { slug, name, description }
     */
    public static function createPlaceholderDraft(string $jobId, array $jobMeta = [], array $appIdentity = []): int
    {
        $slug  = (string) ($appIdentity['slug'] ?? '');
        $name  = (string) ($appIdentity['name'] ?? '');
        $desc  = (string) ($appIdentity['description'] ?? '');
        $prompt = (string) ($jobMeta['prompt'] ?? '');

        // Fall back to prompt-based title if no name given.
        if ($slug === '') {
            $shortId = substr(preg_replace('/[^a-z0-9]/i', '', $jobId) ?? '', 0, 8);
            $slug = 'agent-draft-' . $shortId;
        }
        if ($name === '') {
            $name = $prompt !== '' ? mb_substr($prompt, 0, 80) : 'Agent draft (in progress)';
        }

        $postId = wp_insert_post([
            'post_type'   => 'ep_app',
            'post_title'  => $name,
            'post_name'   => $slug,
            'post_status' => 'draft',
        ]);

        if (is_wp_error($postId) || !$postId) {
            return 0;
        }

        update_post_meta($postId, '_ep_plugin_slug', $slug);
        if ($desc !== '') {
            update_post_meta($postId, '_ep_description', $desc);
        }
        update_post_meta($postId, '_ep_source', 'agent');
        update_post_meta($postId, self::META_DRAFT_STATUS, 'drafting');
        update_post_meta($postId, self::META_DRAFT_ORIGIN_JOB, $jobId);
        update_post_meta($postId, self::META_DRAFT_PROMPT, $prompt);
        update_post_meta($postId, self::META_DRAFT_UPDATED_AT, (int) time());
        update_post_meta($postId, self::META_DRAFT_HISTORY, wp_json_encode([
            self::buildHistoryEntry(array_merge($jobMeta, ['job_id' => $jobId]), 'queued'),
        ]));

        return (int) $postId;
    }

    /**
     * Look up a draft post by its originating job ID. Used during the
     * placeholder → finalized handoff: the job runner calls this to
     * find the draft it should populate.
     */
    public static function getPostByJobId(string $jobId): ?\WP_Post
    {
        if ($jobId === '') {
            return null;
        }
        $posts = get_posts([
            'post_type'      => 'ep_app',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_key'       => self::META_DRAFT_ORIGIN_JOB,
            'meta_value'     => $jobId,
            'no_found_rows'  => true,
        ]);
        return $posts[0] ?? null;
    }

    /**
     * Finalize a placeholder draft once the LLM returns the real
     * payload. Renames the post (post_name + meta) to the manifest
     * slug, populates the payload, and updates the title to the
     * manifest name.
     *
     * Returns false if no placeholder exists for this job ID, OR if
     * the target slug collides with a different existing app.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $jobMeta
     */
    public static function finalizePlaceholderDraft(string $jobId, string $realSlug, array $payload, array $jobMeta = []): bool
    {
        $post = self::getPostByJobId($jobId);
        if (!$post) {
            return false;
        }

        // Reject if a DIFFERENT post already owns the real slug.
        $conflict = self::getPost($realSlug);
        if ($conflict && (int) $conflict->ID !== (int) $post->ID) {
            return false;
        }

        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $name = (string) ($manifest['name'] ?? $realSlug);

        wp_update_post([
            'ID'         => $post->ID,
            'post_title' => $name,
            'post_name'  => $realSlug,
        ]);

        update_post_meta($post->ID, '_ep_plugin_slug', $realSlug);
        update_post_meta($post->ID, '_ep_description', (string) ($manifest['description'] ?? ''));
        update_post_meta($post->ID, '_ep_version', (string) ($manifest['version'] ?? '1.0.0'));

        $ok = self::writePayloadMeta($post->ID, $payload);
        if (!$ok) {
            return false;
        }

        update_post_meta($post->ID, self::META_DRAFT_STATUS, 'review');
        update_post_meta($post->ID, self::META_DRAFT_UPDATED_AT, (int) time());

        $history = self::getDraftHistory($realSlug);
        $history[] = self::buildHistoryEntry(array_merge($jobMeta, ['job_id' => $jobId]), 'drafted');
        update_post_meta($post->ID, self::META_DRAFT_HISTORY, wp_json_encode($history));

        return true;
    }

    /**
     * Set the in-flight status on an existing draft post. Used by
     * iterate/repair to mark "LLM call started" without yet having
     * a payload to stash.
     */
    public static function markDraftRunning(string $slug, string $status, array $jobMeta = []): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }
        update_post_meta($post->ID, self::META_DRAFT_STATUS, $status);
        update_post_meta($post->ID, self::META_DRAFT_UPDATED_AT, (int) time());
        $prompt = (string) ($jobMeta['prompt'] ?? '');
        if ($prompt !== '') {
            update_post_meta($post->ID, self::META_DRAFT_PROMPT, $prompt);
        }

        $history = self::getDraftHistory($slug);
        $history[] = self::buildHistoryEntry($jobMeta, $status);
        update_post_meta($post->ID, self::META_DRAFT_HISTORY, wp_json_encode($history));
        return true;
    }

    /**
     * Create a new draft app post with a stashed payload. Used by the
     * agent on initial generation, BEFORE the LLM payload has been
     * pushed to GitHub. Returns the post ID, or 0 on failure.
     *
     * @param array<string,mixed> $payload The {manifest,files,...} payload from GenerationJob::draftPayload().
     * @param array<string,mixed> $jobMeta Optional context: { job_id, mode, prompt }.
     */
    public static function createDraft(string $slug, array $payload, array $jobMeta = []): int
    {
        // If a post already exists for this slug, refuse — the caller
        // should detect the conflict and either iterate (against an
        // existing draft) or fail (against a published app).
        if (self::getPost($slug)) {
            return 0;
        }

        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $name = (string) ($manifest['name'] ?? $slug);

        $postId = wp_insert_post([
            'post_type'   => 'ep_app',
            'post_title'  => $name,
            'post_name'   => $slug,
            'post_status' => 'draft',
        ]);

        if (is_wp_error($postId) || !$postId) {
            return 0;
        }

        update_post_meta($postId, '_ep_plugin_slug', $slug);
        update_post_meta($postId, '_ep_description', (string) ($manifest['description'] ?? ''));
        update_post_meta($postId, '_ep_version', (string) ($manifest['version'] ?? '1.0.0'));
        update_post_meta($postId, '_ep_source', 'agent');

        // Payload + origin job + history seed.
        update_post_meta($postId, self::META_DRAFT_PAYLOAD, wp_json_encode($payload));
        update_post_meta($postId, self::META_DRAFT_STATUS, 'review');
        update_post_meta($postId, self::META_DRAFT_ORIGIN_JOB, (string) ($jobMeta['job_id'] ?? ''));
        update_post_meta($postId, self::META_DRAFT_UPDATED_AT, (int) time());
        update_post_meta($postId, self::META_DRAFT_HISTORY, wp_json_encode([
            self::buildHistoryEntry($jobMeta, 'drafted'),
        ]));

        return (int) $postId;
    }

    /**
     * Stash a new payload on an existing app post. Used by iteration
     * and repair flows. Works on both draft (never-pushed) AND publish
     * (already-pushed) posts — the payload meta is independent of
     * post status.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $jobMeta
     */
    public static function stashDraftPayload(string $slug, array $payload, array $jobMeta = []): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }

        $ok = self::writePayloadMeta($post->ID, $payload);
        if (!$ok) {
            return false;
        }

        update_post_meta($post->ID, self::META_DRAFT_STATUS, 'review');
        update_post_meta($post->ID, self::META_DRAFT_UPDATED_AT, (int) time());

        $history = self::getDraftHistory($slug);
        $history[] = self::buildHistoryEntry($jobMeta, 'drafted');
        update_post_meta($post->ID, self::META_DRAFT_HISTORY, wp_json_encode($history));

        return true;
    }

    /**
     * Encode and persist the draft payload to post meta. Verifies the
     * write actually persisted — wp_json_encode can fail on non-UTF-8
     * data, and update_post_meta can fail silently if the value exceeds
     * MySQL's max_allowed_packet.
     */
    private static function writePayloadMeta(int $postId, array $payload): bool
    {
        $json = wp_json_encode($payload);
        if ($json === false) {
            error_log('ExamplePress agent: wp_json_encode failed for draft payload (post ' . $postId . '). JSON error: ' . json_last_error_msg());
            return false;
        }

        $bytes = strlen($json);
        if ($bytes > 10 * 1024 * 1024) { // 10 MB sanity cap
            error_log('ExamplePress agent: draft payload too large (' . number_format($bytes) . ' bytes) for post ' . $postId);
            return false;
        }

        update_post_meta($postId, self::META_DRAFT_PAYLOAD, $json);

        // Verify the write persisted. WordPress can fail silently on
        // large values or DB packet limits.
        $verify = (string) get_post_meta($postId, self::META_DRAFT_PAYLOAD, true);
        if ($verify === '' || $verify !== $json) {
            error_log('ExamplePress agent: draft payload write failed to persist for post ' . $postId . ' (' . number_format($bytes) . ' bytes). Check MySQL max_allowed_packet.');
            // Clean up the partial/empty meta.
            delete_post_meta($postId, self::META_DRAFT_PAYLOAD);
            return false;
        }

        return true;
    }

    /**
     * Read the stashed draft payload off a post.
     *
     * @return array<string,mixed>|null
     */
    public static function getDraftPayload(string $slug): ?array
    {
        $post = self::getPost($slug);
        if (!$post) {
            return null;
        }
        $raw = (string) get_post_meta($post->ID, self::META_DRAFT_PAYLOAD, true);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Has this app got a pending stashed revision waiting for review?
     */
    public static function hasDraftPayload(string $slug): bool
    {
        return self::getDraftPayload($slug) !== null;
    }

    /**
     * Promote a never-pushed draft to publish status after a successful
     * GitHub push. Sets the github coordinates, bumps version, and
     * clears the draft payload meta.
     *
     * @param array<string,mixed> $githubData { owner_repo, repo_id, html_url }
     * @param array<string,mixed> $jobMeta
     */
    public static function promoteToPublished(string $slug, array $githubData, array $jobMeta = []): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }

        $updateArgs = ['ID' => $post->ID];
        if ($post->post_status !== 'publish') {
            $updateArgs['post_status'] = 'publish';
        }
        if (count($updateArgs) > 1) {
            wp_update_post($updateArgs);
        }

        // Pull the version from the stashed payload before clearing it.
        $payload = self::getDraftPayload($slug) ?? [];
        if (!empty($payload['manifest']['version'])) {
            update_post_meta($post->ID, '_ep_version', (string) $payload['manifest']['version']);
        }

        // Stamp GitHub coords.
        if (!empty($githubData['owner_repo'])) {
            update_post_meta($post->ID, '_ep_github_owner_repo', (string) $githubData['owner_repo']);
        }
        if (isset($githubData['repo_id'])) {
            update_post_meta($post->ID, '_ep_github_repo_id', (string) $githubData['repo_id']);
        }
        if (!empty($githubData['html_url'])) {
            update_post_meta($post->ID, '_ep_github_html_url', (string) $githubData['html_url']);
        }

        // Append a history entry recording the push BEFORE we clear the payload.
        $history = self::getDraftHistory($slug);
        $history[] = self::buildHistoryEntry($jobMeta, 'pushed');
        update_post_meta($post->ID, self::META_DRAFT_HISTORY, wp_json_encode($history));

        self::clearDraftPayload($slug);
        return true;
    }

    /**
     * Drop the stashed payload + status meta. Called after a successful
     * push (the live state IS the canonical version now) or when the
     * user explicitly discards a pending revision.
     */
    public static function clearDraftPayload(string $slug): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }
        delete_post_meta($post->ID, self::META_DRAFT_PAYLOAD);
        delete_post_meta($post->ID, self::META_DRAFT_STATUS);
        delete_post_meta($post->ID, self::META_DRAFT_ERRORS);
        delete_post_meta($post->ID, self::META_DRAFT_PROMPT);
        delete_post_meta($post->ID, self::META_DRAFT_LOG);
        delete_post_meta($post->ID, self::META_DRAFT_UPDATED_AT);
        return true;
    }

    /**
     * Record a draft failure on the post (validator errors, push errors,
     * etc.) so the UI can show what went wrong without consulting the
     * Action Scheduler job state.
     *
     * @param array<int,string>   $errors
     * @param array<string,mixed> $jobMeta
     */
    public static function recordDraftFailure(string $slug, array $errors, array $jobMeta = []): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }
        update_post_meta($post->ID, self::META_DRAFT_STATUS, 'failed');
        update_post_meta($post->ID, self::META_DRAFT_ERRORS, wp_json_encode(array_values($errors)));
        update_post_meta($post->ID, self::META_DRAFT_UPDATED_AT, (int) time());

        $history = self::getDraftHistory($slug);
        $entry = self::buildHistoryEntry($jobMeta, 'failed');
        $entry['errors'] = array_values($errors);
        $history[] = $entry;
        update_post_meta($post->ID, self::META_DRAFT_HISTORY, wp_json_encode($history));

        return true;
    }

    /**
     * Append a timestamped entry to the draft's activity log.
     * The log is a lightweight timeline that the UI polls to show
     * live progress during in-flight jobs.
     */
    public static function appendLog(string $slug, string $message): void
    {
        $post = self::getPost($slug);
        if (!$post) {
            return;
        }
        $raw = (string) get_post_meta($post->ID, self::META_DRAFT_LOG, true);
        $log = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        $log[] = [
            'ts'  => time(),
            'msg' => $message,
        ];
        // Cap at 50 entries so the meta doesn't bloat.
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        update_post_meta($post->ID, self::META_DRAFT_LOG, wp_json_encode($log));
    }

    /**
     * Read the draft activity log.
     *
     * @return array<int,array{ts:int,msg:string}>
     */
    public static function getDraftLog(string $slug): array
    {
        $post = self::getPost($slug);
        if (!$post) {
            return [];
        }
        $raw = (string) get_post_meta($post->ID, self::META_DRAFT_LOG, true);
        return $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * Discard a draft entirely. If the post has never been pushed
     * (status = draft), the post is deleted. If it's already been
     * pushed once (status = publish), only the pending payload is
     * dropped — the live app stays.
     */
    public static function discardDraft(string $slug): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }
        if ($post->post_status === 'draft') {
            wp_delete_post($post->ID, true);
            return true;
        }
        return self::clearDraftPayload($slug);
    }

    /**
     * Read the audit trail of every draft action against this app.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getDraftHistory(string $slug): array
    {
        $post = self::getPost($slug);
        if (!$post) {
            return [];
        }
        $raw = (string) get_post_meta($post->ID, self::META_DRAFT_HISTORY, true);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * List every app that has a stashed pending payload (regardless of
     * post status). Used by the Drafts surface in the admin.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listDraftsPending(): array
    {
        // Find every post that is either:
        // 1. A never-pushed draft (post_status=draft) — always show so
        //    orphaned placeholders are visible and can be discarded.
        // 2. A published app with a stashed payload or draft status
        //    (pending iteration/repair).
        // Single query: every ep_app post that is either an unpushed
        // draft OR a published app with pending draft work. Using 'any'
        // post_status catches orphans regardless of status.
        $posts = get_posts([
            'post_type'      => 'ep_app',
            'post_status'    => 'any',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
        ]);
        // Filter down to posts that belong in the drafts panel:
        // - Never-pushed drafts (post_status !== publish)
        // - Published apps with a stashed payload or draft status
        $posts = array_filter($posts, static function (\WP_Post $post): bool {
            if ($post->post_status !== 'publish') {
                return true; // All non-published posts show (draft, trash, etc.)
            }
            // Published: only show if pending draft work exists.
            $hasPayload = metadata_exists('post', $post->ID, self::META_DRAFT_PAYLOAD);
            $hasStatus  = metadata_exists('post', $post->ID, self::META_DRAFT_STATUS);
            return $hasPayload || $hasStatus;
        });

        $out = [];
        foreach ($posts as $post) {
            $slug = (string) get_post_meta($post->ID, '_ep_plugin_slug', true);
            if ($slug === '') {
                continue;
            }
            $payload      = self::getDraftPayload($slug) ?? [];
            $files        = is_array($payload['files'] ?? null) ? $payload['files'] : [];
            $draftStatus  = (string) get_post_meta($post->ID, self::META_DRAFT_STATUS, true);
            $errorsRaw    = (string) get_post_meta($post->ID, self::META_DRAFT_ERRORS, true);
            $errors       = $errorsRaw !== '' ? (json_decode($errorsRaw, true) ?: []) : [];
            $prompt       = (string) get_post_meta($post->ID, self::META_DRAFT_PROMPT, true);

            $out[] = [
                'slug'           => $slug,
                'name'           => $post->post_title,
                'post_status'    => $post->post_status,
                'draft_status'   => $draftStatus,
                'prompt'         => $prompt,
                'has_payload'    => !empty($files),
                'in_flight'      => in_array($draftStatus, ['queued', 'drafting', 'iterating', 'repairing', 'pushing'], true),
                'stalled'        => in_array($draftStatus, ['queued', 'drafting', 'iterating', 'repairing'], true)
                                    && ((int) get_post_meta($post->ID, self::META_DRAFT_UPDATED_AT, true)) < (time() - 300),
                'updated_at'     => (int) get_post_meta($post->ID, self::META_DRAFT_UPDATED_AT, true),
                'version'        => (string) ($payload['manifest']['version'] ?? ''),
                'files_count'    => count($files),
                'change_summary' => is_array($payload['change_summary'] ?? null) ? $payload['change_summary'] : null,
                'origin_job_id'  => (string) get_post_meta($post->ID, self::META_DRAFT_ORIGIN_JOB, true),
                'errors'         => is_array($errors) ? $errors : [],
                'log'            => self::getDraftLog($slug),
            ];
        }

        // Newest first so the in-flight job is at the top.
        usort($out, static fn($a, $b) => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
        return $out;
    }

    /**
     * @param array<string,mixed> $jobMeta
     * @return array<string,mixed>
     */
    private static function buildHistoryEntry(array $jobMeta, string $event): array
    {
        return [
            'event'      => $event,
            'job_id'     => (string) ($jobMeta['job_id'] ?? ''),
            'mode'       => (string) ($jobMeta['mode'] ?? ''),
            'prompt'     => (string) ($jobMeta['prompt'] ?? ''),
            'created_at' => (int) time(),
        ];
    }

    public static function forget(string $slug): bool
    {
        $post = self::getPost($slug);
        if (!$post) {
            return false;
        }
        wp_delete_post($post->ID, true);
        return true;
    }

    /**
     * Merge persistent registry with live filesystem state.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listMerged(): array
    {
        $registry = self::all();
        $localApps = AppDiscovery::scan();

        $localBySlug = [];
        foreach ($localApps as $app) {
            $localBySlug[$app['slug']] = $app;
        }

        $merged = [];

        foreach ($registry as $slug => $record) {
            $local = $localBySlug[$slug] ?? null;
            $merged[] = self::mergeRecord($record, $local);
            unset($localBySlug[$slug]);
        }

        foreach ($localBySlug as $slug => $local) {
            $adopted = [
                'slug'        => $slug,
                'name'        => $local['name'],
                'description' => $local['description'],
                'version'     => $local['version'] ?? '',
                'source'      => 'discovered',
            ];

            if (!empty($local['troy']['server_url'])) {
                $adopted['troy'] = [
                    'server_url' => $local['troy']['server_url'],
                    'repo'       => $local['troy']['repo'] ?? '',
                    'repo_id'    => $local['troy']['repo_id'] ?? '',
                ];
            }

            self::set($slug, $adopted);
            $merged[] = self::mergeRecord($adopted, $local);
        }

        /**
         * Filter the merged registry/filesystem app list.
         *
         * @param array $merged Merged app records.
         */
        return (array) apply_filters('examplepress_mu_apps_merged', $merged);
    }

    /**
     * @return array<string, mixed>
     */
    private static function mergeRecord(array $record, ?array $local): array
    {
        $hasLocal = $local !== null;

        $troyServer = $hasLocal
            ? ($local['troy']['server_url'] ?? $record['troy']['server_url'] ?? '')
            : ($record['troy']['server_url'] ?? '');
        $troyRepo = $hasLocal
            ? ($local['troy']['repo'] ?? $record['troy']['repo'] ?? '')
            : ($record['troy']['repo'] ?? '');
        $troyRepoId = $hasLocal
            ? ($local['troy']['repo_id'] ?? $record['troy']['repo_id'] ?? '')
            : ($record['troy']['repo_id'] ?? '');

        $githubRepo = $record['github']['owner_repo'] ?? '';
        $githubRepoId = $record['github']['repo_id'] ?? '';
        $githubUrl = $record['github']['html_url'] ?? '';

        $hasGithub = !empty($githubRepo);
        $hasTroy = !empty($troyServer);

        return [
            'slug'        => $record['slug'],
            'name'        => $hasLocal ? $local['name'] : ($record['name'] ?? $record['slug']),
            'description' => $hasLocal ? $local['description'] : ($record['description'] ?? ''),
            'version'     => $hasLocal ? $local['version'] : ($record['version'] ?? ''),
            'created_at'  => $record['created_at'] ?? '',
            'source'      => $record['source'] ?? 'scaffolded',
            'local'       => [
                'installed'   => $hasLocal,
                'active'      => $hasLocal && $local['active'],
                'plugin_file' => $hasLocal ? $local['plugin_file'] : '',
            ],
            'github' => [
                'owner_repo' => $githubRepo,
                'repo_id'    => $githubRepoId,
                'html_url'   => $githubUrl,
            ],
            'troy' => [
                'server_url' => $troyServer,
                'repo'       => $troyRepo,
                'repo_id'    => $troyRepoId,
            ],
            'status' => $hasLocal && $hasGithub
                ? 'connected'
                : ($hasLocal ? 'disconnected' : 'orphan'),
            'active'  => $hasLocal && $local['active'],
            'routing' => $hasLocal ? ($local['routing'] ?? []) : [],
            'orphan'  => !$hasLocal && ($hasGithub || $hasTroy),
            'exists'  => [
                'local'  => $hasLocal,
                'github' => $hasGithub,
                'troy'   => $hasTroy,
            ],
        ];
    }

    /**
     * Delete an app everywhere: local plugin, GitHub repo, Troy registration.
     *
     * @return array{deleted: string[], failed: string[], warnings: string[]}
     */
    public static function destroy(string $slug): array
    {
        $record = self::get($slug);
        $deleted = [];
        $failed = [];
        $warnings = [];

        $pluginDir = WP_PLUGIN_DIR . '/' . $slug;

        // 1. Deactivate + delete local plugin.
        if (is_dir($pluginDir)) {
            $pluginFile = $slug . '/' . $slug . '.php';

            if (is_plugin_active($pluginFile)) {
                deactivate_plugins($pluginFile);
            }

            $fs = Helpers::filesystem();
            if ($fs && $fs->delete($pluginDir, true)) {
                $deleted[] = 'local';
            } else {
                $failed[] = 'local';
                $warnings[] = 'Could not delete plugin directory.';
            }
        }

        // 2. Delete GitHub repo.
        $ownerRepo = $record['github']['owner_repo'] ?? '';

        if ($ownerRepo) {
            $pat = GitHub::writeToken();

            if ($pat) {
                $response = wp_remote_request("https://api.github.com/repos/{$ownerRepo}", [
                    'method'  => 'DELETE',
                    'headers' => [
                        'Authorization' => "Bearer {$pat}",
                        'Accept'        => 'application/vnd.github.v3+json',
                        'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                    ],
                    'timeout' => 15,
                ]);

                if (is_wp_error($response)) {
                    $failed[] = 'github';
                    $warnings[] = 'GitHub delete: ' . $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code($response);

                    if ($code === 204 || $code === 404) {
                        $deleted[] = 'github';
                    } else {
                        $failed[] = 'github';
                        $body = json_decode((string) wp_remote_retrieve_body($response), true);
                        $message = (is_array($body) && isset($body['message']) && is_string($body['message']))
                            ? $body['message']
                            : "HTTP {$code}";
                        $warnings[] = 'GitHub delete: ' . $message;
                    }
                }
            } else {
                $failed[] = 'github';
                $warnings[] = 'No GitHub write token — cannot delete repo.';
            }
        }

        // 3. Unregister from Troy.
        $troyServer = $record['troy']['server_url'] ?? '';

        if ($troyServer) {
            $troyUrl = 'https://' . $troyServer;
            $troyAuth = get_option('ep_troy_credentials', '');

            if ($troyAuth) {
                $response = wp_remote_request(
                    "{$troyUrl}/wp-json/troy-server/v1/plugins/manage/unregister",
                    [
                        'method'  => 'POST',
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode($troyAuth),
                            'Content-Type'  => 'application/json',
                        ],
                        'body'    => wp_json_encode(['slug' => $slug]),
                        'timeout' => 15,
                    ]
                );

                if (is_wp_error($response)) {
                    $failed[] = 'troy';
                    $warnings[] = 'Troy unregister: ' . $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code($response);

                    if (($code >= 200 && $code < 300) || $code === 404) {
                        $deleted[] = 'troy';
                    } else {
                        $failed[] = 'troy';
                        $body = json_decode((string) wp_remote_retrieve_body($response), true);
                        $message = (is_array($body) && isset($body['message']) && is_string($body['message']))
                            ? $body['message']
                            : "HTTP {$code}";
                        $warnings[] = 'Troy unregister: ' . $message;
                    }
                }
            } else {
                $failed[] = 'troy';
                $warnings[] = 'No Troy credentials — cannot unregister.';
            }
        }

        // 4. Remove from registry.
        self::forget($slug);

        // 5. Invalidate the AppDiscovery cache so the next admin read
        //    doesn't serve a ghost entry for the just-destroyed plugin.
        //    The mtime fingerprint would eventually catch this, but an
        //    explicit flush guarantees the next REST hit is fresh.
        AppDiscovery::flushCache();

        return [
            'deleted'  => $deleted,
            'failed'   => $failed,
            'warnings' => $warnings,
        ];
    }
}
