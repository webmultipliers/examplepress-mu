<?php

declare(strict_types=1);

namespace ExamplePress\MU\Editor;

use ExamplePress\MU\Infrastructure\GitHub;

/**
 * REST controller for the in-admin proposer.
 *
 * Reads from GitHub at a pinned ref, manages per-user draft state in
 * user meta, and produces PRs via the GitHub App — never touches the
 * local filesystem.
 */
final class RepoController
{
    private const DRAFT_META_PREFIX = 'ep_proposer_draft_';

    public static function register(): void
    {
        $slug_args = [
            'slug' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
                'validate_callback' => function ($value): bool {
                    return (bool) preg_match('/^[a-z0-9-]+$/', $value);
                },
            ],
        ];

        // ── Ref ────────────────────────────────────────────────────
        register_rest_route('examplepress-mu/v1', '/editor/(?P<slug>[a-z0-9-]+)/ref', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'ref'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => $slug_args,
        ]);

        // ── Tree ───────────────────────────────────────────────────
        register_rest_route('examplepress-mu/v1', '/editor/(?P<slug>[a-z0-9-]+)/tree', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'tree'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => array_merge($slug_args, [
                'ref' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]),
        ]);

        // ── File ───────────────────────────────────────────────────
        register_rest_route('examplepress-mu/v1', '/editor/(?P<slug>[a-z0-9-]+)/file', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'file'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => array_merge($slug_args, [
                'ref' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'path' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]),
        ]);

        // ── Draft (GET / PUT / DELETE) ─────────────────────────────
        register_rest_route('examplepress-mu/v1', '/editor/(?P<slug>[a-z0-9-]+)/draft', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getDraft'],
                'permission_callback' => [self::class, 'permissionCheck'],
                'args'                => $slug_args,
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'putDraft'],
                'permission_callback' => [self::class, 'permissionCheck'],
                'args'                => $slug_args,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'deleteDraft'],
                'permission_callback' => [self::class, 'permissionCheck'],
                'args'                => $slug_args,
            ],
        ]);

        // ── Proposal ───────────────────────────────────────────────
        register_rest_route('examplepress-mu/v1', '/editor/(?P<slug>[a-z0-9-]+)/proposal', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'createProposal'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => $slug_args,
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    // ── Ref ────────────────────────────────────────────────────────

    public static function ref(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug     = $request->get_param('slug');
        $resolved = self::resolveRepo($slug);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $manifest = $resolved['manifest'];

        return rest_ensure_response([
            'slug'       => $slug,
            'repository' => $resolved['owner_repo'],
            'version'    => $manifest['version'] ?? null,
            'ref'        => $manifest['troy']['repo'] ?? $manifest['github']['owner_repo'] ?? null,
        ]);
    }

    // ── Tree ───────────────────────────────────────────────────────

    public static function tree(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $ref  = (string) $request->get_param('ref');

        $resolved = self::resolveRepo($slug);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $ownerRepo = $resolved['owner_repo'];
        $token     = GitHub::readToken();

        if (!$token) {
            return new \WP_Error('no_token', 'No GitHub read token available.', ['status' => 500]);
        }

        // Encode the ref segment. sanitize_text_field permits characters
        // that would corrupt the URL if interpolated raw (`?`, `#`, `&`).
        $encodedRef = rawurlencode($ref);

        $response = wp_remote_get(
            "https://api.github.com/repos/{$ownerRepo}/git/trees/{$encodedRef}?recursive=1",
            [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
            return new \WP_Error('github_api_error', $msg, ['status' => $code]);
        }

        return rest_ensure_response($body['tree'] ?? []);
    }

    // ── File ───────────────────────────────────────────────────────

    public static function file(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $ref  = (string) $request->get_param('ref');
        $path = (string) $request->get_param('path');

        // Reject obvious path traversal. GitHub's Contents API will
        // happily serve the same file via a relative path, but allowing
        // `..` lets a caller side-step intent — e.g. reading a file
        // that the proposer UI wasn't meant to show.
        if (!\ExamplePress\MU\Infrastructure\Helpers::isSafeRelativePath($path)) {
            return new \WP_Error('unsafe_path', 'Path must be a repository-relative path without traversal.', ['status' => 400]);
        }

        $resolved = self::resolveRepo($slug);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $ownerRepo = $resolved['owner_repo'];
        $token     = GitHub::readToken();

        if (!$token) {
            return new \WP_Error('no_token', 'No GitHub read token available.', ['status' => 500]);
        }

        // URL-encode each path segment AND the ref. sanitize_text_field
        // permits characters that would corrupt the URL if interpolated
        // raw (`?`, `#`, `&`). Encoding per-segment preserves `/` as a
        // path delimiter, which the Contents API expects.
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $encodedRef  = rawurlencode($ref);

        $response = wp_remote_get(
            "https://api.github.com/repos/{$ownerRepo}/contents/{$encodedPath}?ref={$encodedRef}",
            [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
            return new \WP_Error('github_api_error', $msg, ['status' => $code]);
        }

        return rest_ensure_response([
            'content'  => $body['content'] ?? '',
            'sha'      => $body['sha'] ?? '',
            'encoding' => $body['encoding'] ?? 'base64',
            'path'     => $body['path'] ?? $path,
            'size'     => $body['size'] ?? 0,
        ]);
    }

    // ── Draft: GET ─────────────────────────────────────────────────

    public static function getDraft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug  = $request->get_param('slug');
        $draft = get_user_meta(get_current_user_id(), self::DRAFT_META_PREFIX . $slug, true);

        return rest_ensure_response($draft ?: (object) []);
    }

    // ── Draft: PUT ─────────────────────────────────────────────────

    public static function putDraft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $body = $request->get_json_params();

        if (empty($body['base_ref']) || empty($body['files'])) {
            return new \WP_Error('invalid_draft', 'Draft must include base_ref and files.', ['status' => 400]);
        }

        $data = [
            'base_ref' => sanitize_text_field($body['base_ref']),
            'files'    => $body['files'],
        ];

        update_user_meta(get_current_user_id(), self::DRAFT_META_PREFIX . $slug, $data);

        return rest_ensure_response($data);
    }

    // ── Draft: DELETE ──────────────────────────────────────────────

    public static function deleteDraft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');

        delete_user_meta(get_current_user_id(), self::DRAFT_META_PREFIX . $slug);

        return rest_ensure_response(['deleted' => true]);
    }

    // ── Proposal ───────────────────────────────────────────────────

    public static function createProposal(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $body = $request->get_json_params();

        $baseRef = $body['base_ref'] ?? '';
        $files   = $body['files'] ?? [];
        $title   = $body['title'] ?? '';
        $prDesc  = $body['body'] ?? '';

        if (!$baseRef || empty($files) || !$title) {
            return new \WP_Error('invalid_proposal', 'Proposal requires base_ref, files, and title.', ['status' => 400]);
        }

        // 1. Resolve repo from manifest.
        $resolved = self::resolveRepo($slug);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $ownerRepo = $resolved['owner_repo'];
        $manifest  = $resolved['manifest'];

        // 2. Generate branch name.
        $user       = wp_get_current_user();
        $branchName = 'proposals/' . sanitize_user($user->user_login, true) . '-' . time();

        // 3. Create branch from base ref.
        $branch = GitHub::createBranch($ownerRepo, $branchName, $baseRef);

        if (is_wp_error($branch)) {
            return $branch;
        }

        // 4. Commit the changeset.
        $author = [
            'name'  => $user->display_name,
            'email' => $user->user_email,
        ];

        $commit = GitHub::commitChangeset($ownerRepo, $branchName, $files, $title, $author);

        if (is_wp_error($commit)) {
            return $commit;
        }

        // 5. Build PR body.
        $fileCount     = count($files);
        $defaultBranch = $manifest['troy']['default_branch'] ?? $manifest['github']['default_branch'] ?? 'main';
        $siteUrl       = get_site_url();

        $prBody = <<<MD
        ## Proposal from ExamplePress

        **Site:** {$siteUrl}
        **Author:** {$user->display_name} ({$user->user_email})
        **Base SHA:** `{$baseRef}`
        **Files changed:** {$fileCount}

        ---

        {$prDesc}
        MD;

        // Remove leading indentation from heredoc.
        $prBody = preg_replace('/^        /m', '', $prBody);

        // 6. Create pull request.
        $pr = GitHub::createPullRequest($ownerRepo, $branchName, $defaultBranch, $title, $prBody);

        if (is_wp_error($pr)) {
            return $pr;
        }

        // 7. Clear the draft from user meta.
        delete_user_meta(get_current_user_id(), self::DRAFT_META_PREFIX . $slug);

        // 8. Return result.
        return rest_ensure_response([
            'number'     => $pr['number'],
            'html_url'   => $pr['html_url'],
            'branch'     => $branchName,
            'commit_sha' => $commit['commit_sha'],
        ]);
    }

    // ── Private: Repo Resolution ───────────────────────────────────

    /**
     * Read the plugin's examplepress.json manifest and resolve the GitHub owner/repo.
     *
     * @return array{owner_repo:string,manifest:array}|\WP_Error
     */
    private static function resolveRepo(string $slug): array|\WP_Error
    {
        // Defense in depth: the REST layer validates slug against
        // /^[a-z0-9-]+$/ but this method is private, could be reached
        // from a non-REST caller in the future.
        if (!\ExamplePress\MU\Infrastructure\Helpers::isSafeRelativePath($slug) || str_contains($slug, '/')) {
            return new \WP_Error('unsafe_slug', 'Slug is not a safe directory name.', ['status' => 400]);
        }

        $json_path = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';

        if (!file_exists($json_path)) {
            return new \WP_Error('no_manifest', "Manifest not found for plugin \"{$slug}\".", ['status' => 404]);
        }

        $content = file_get_contents($json_path);

        if ($content === false) {
            return new \WP_Error('read_error', 'Could not read plugin manifest.', ['status' => 500]);
        }

        $manifest = json_decode($content, true);

        if (!is_array($manifest)) {
            return new \WP_Error('invalid_manifest', 'Plugin manifest is not valid JSON.', ['status' => 500]);
        }

        $ownerRepo = $manifest['repository']
            ?? $manifest['troy']['repo']
            ?? $manifest['github']['owner_repo']
            ?? '';

        if (!$ownerRepo) {
            return new \WP_Error('no_repository', 'Manifest does not specify a repository.', ['status' => 422]);
        }

        return [
            'owner_repo' => $ownerRepo,
            'manifest'   => $manifest,
        ];
    }
}
