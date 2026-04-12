<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * GitHub API integration — static utility class.
 *
 * Provides GitHub App JWT signing, installation tokens, repo creation,
 * scaffold pushing, Troy registration, and write/read token resolution.
 */
final class GitHub
{
    // ── GitHub App Authentication ───────────────────────────────────

    /**
     * Whether the GitHub App credentials are defined.
     */
    public static function appIsConfigured(): bool
    {
        return defined('EP_GITHUB_APP_ID') && defined('EP_GITHUB_APP_PEM');
    }

    /**
     * Whether the GitHub App is configured AND installed on an org.
     */
    public static function appIsInstalled(): bool
    {
        return self::appIsConfigured()
            && (bool) get_option('ep_github_app_installation_id', '');
    }

    /**
     * Generate a signed JWT for the GitHub App.
     *
     * @return string|\WP_Error
     */
    public static function appGetJwt(): string|\WP_Error
    {
        if (!self::appIsConfigured()) {
            return new \WP_Error('no_app_config', 'GitHub App credentials are not configured.');
        }

        $app_id = EP_GITHUB_APP_ID;
        $pem    = EP_GITHUB_APP_PEM;
        $now    = time();

        $header = Helpers::base64urlEncode(wp_json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $payload = Helpers::base64urlEncode(wp_json_encode([
            'iat' => $now - 60,
            'exp' => $now + (10 * 60),
            'iss' => (string) $app_id,
        ]));

        $signature = '';
        $key       = openssl_pkey_get_private($pem);

        if (!$key) {
            return new \WP_Error('bad_pem', 'Failed to parse GitHub App private key.');
        }

        $signed = openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

        if (!$signed) {
            return new \WP_Error('sign_failed', 'Failed to sign JWT.');
        }

        return "{$header}.{$payload}." . Helpers::base64urlEncode($signature);
    }

    /**
     * Obtain an installation access token for the GitHub App.
     *
     * @return string|\WP_Error
     */
    public static function appGetInstallationToken(): string|\WP_Error
    {
        $installation_id = get_option('ep_github_app_installation_id', '');

        if (!$installation_id) {
            return new \WP_Error('no_installation', 'GitHub App is not installed on any organization.');
        }

        $cached = get_transient('ep_github_app_token');
        if ($cached) {
            return $cached;
        }

        $jwt = self::appGetJwt();

        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $response = wp_remote_post(
            "https://api.github.com/app/installations/{$installation_id}/access_tokens",
            [
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 201 || empty($body['token'])) {
            $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
            return new \WP_Error('token_failed', $msg);
        }

        $token = $body['token'];
        set_transient('ep_github_app_token', $token, 55 * MINUTE_IN_SECONDS);

        return $token;
    }

    // ── Token Resolution ───────────────────────────────────────────

    /**
     * Resolve the best available write token (App installation token preferred, PAT fallback).
     */
    public static function writeToken(): string
    {
        if (self::appIsInstalled()) {
            $token = self::appGetInstallationToken();

            if (!is_wp_error($token)) {
                return $token;
            }
        }

        return get_option('ep_github_pat', '');
    }

    /**
     * Resolve the best available read token (Troy PAT preferred, general PAT fallback).
     */
    public static function readToken(): string
    {
        return get_option('ep_troy_github_pat', '')
            ?: get_option('ep_github_pat', '');
    }

    /**
     * Token helper for the updater/demo bootstrap.
     *
     * Checks Troy PAT, then general PAT, then GitHub App installation token.
     */
    public static function bootstrapGetToken(): ?string
    {
        $token = get_option('ep_troy_github_pat', '');
        if ($token) {
            return $token;
        }

        $token = get_option('ep_github_pat', '');
        if ($token) {
            return $token;
        }

        $token = self::appGetInstallationToken();
        if ($token && !is_wp_error($token)) {
            return $token;
        }

        return null;
    }

    // ── Repo Creation & Push ───────────────────────────────────────

    /**
     * Create a new repository in the configured GitHub org.
     *
     * @return array|\WP_Error
     */
    public static function createRepo(string $slug, string $description): array|\WP_Error
    {
        $pat = self::writeToken();
        $org = get_option('ep_github_org', 'webmultipliers');

        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available. Install the GitHub App or configure a write access token.');
        }

        $response = wp_remote_post("https://api.github.com/orgs/{$org}/repos", [
            'headers' => [
                'Authorization' => "Bearer {$pat}",
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            ],
            'body'    => wp_json_encode([
                'name'        => $slug,
                'description' => $description,
                'private'     => false,
                'auto_init'   => false,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 422 && is_array($body) && !empty($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $err) {
                if (is_array($err) && ($err['message'] ?? '') === 'name already exists on this account') {
                    return new \WP_Error('repo_exists', "GitHub repo \"{$org}/{$slug}\" already exists.");
                }
            }
        }

        if ($code !== 201) {
            $msg = (is_array($body) && isset($body['message']) && is_string($body['message']))
                ? $body['message']
                : "GitHub API returned HTTP {$code}.";
            return new \WP_Error('github_api_error', $msg);
        }

        // 201 is not sufficient — GitHub could return 201 with a malformed
        // body if the request was proxied through a broken intermediary.
        // Verify the fields this method is contractually obligated to return.
        if (!is_array($body) || empty($body['full_name']) || empty($body['id']) || empty($body['html_url'])) {
            return new \WP_Error(
                'github_api_error',
                'GitHub returned HTTP 201 but the response body is missing required fields (full_name, id, html_url).'
            );
        }

        return [
            'owner_repo' => (string) $body['full_name'],
            'repo_id'    => (int) $body['id'],
            'html_url'   => (string) $body['html_url'],
        ];
    }

    /**
     * Push scaffold files to a repo using the Git Database API.
     *
     * Create Blobs -> Create Tree -> Create Commit -> Create Ref.
     *
     * @return true|\WP_Error
     */
    public static function pushScaffold(string $ownerRepo, string $pluginPath): true|\WP_Error
    {
        $pat = self::writeToken();

        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $headers = [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            'Content-Type'  => 'application/json',
        ];

        $base_url = "https://api.github.com/repos/{$ownerRepo}";
        $files    = self::collectFiles($pluginPath, $pluginPath);

        if (empty($files)) {
            return new \WP_Error('no_files', 'No files found in scaffold directory.');
        }

        // Inline-content threshold: small UTF-8 text files go straight into the
        // tree request to avoid N sequential blob POSTs. Larger or binary files
        // fall back to the per-file Blob API (uploaded as base64).
        /** Filter the inline-tree size threshold (bytes). Default 1 MB. */
        $inlineThreshold = (int) apply_filters('examplepress_mu_github_inline_tree_threshold', 1048576);

        $tree_items = [];
        foreach ($files as $relative_path => $absolute_path) {
            $content = file_get_contents($absolute_path);
            if ($content === false) {
                continue;
            }

            $isInlineSafe = strlen($content) <= $inlineThreshold
                && mb_check_encoding($content, 'UTF-8');

            if ($isInlineSafe) {
                $tree_items[] = [
                    'path'    => $relative_path,
                    'mode'    => '100644',
                    'type'    => 'blob',
                    'content' => $content,
                ];
                continue;
            }

            // Binary or oversized — upload as a blob then reference its SHA.
            $blob_response = wp_remote_post("{$base_url}/git/blobs", [
                'headers' => $headers,
                'body'    => wp_json_encode([
                    'content'  => base64_encode($content),
                    'encoding' => 'base64',
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($blob_response)) {
                return $blob_response;
            }

            $blob = json_decode(wp_remote_retrieve_body($blob_response), true);

            if (empty($blob['sha'])) {
                $blob_code = wp_remote_retrieve_response_code($blob_response);
                return new \WP_Error('blob_failed', "Failed to create blob for {$relative_path}: " . ($blob['message'] ?? "HTTP {$blob_code}"));
            }

            $tree_items[] = [
                'path' => $relative_path,
                'mode' => '100644',
                'type' => 'blob',
                'sha'  => $blob['sha'],
            ];
        }

        $tree_response = wp_remote_post("{$base_url}/git/trees", [
            'headers' => $headers,
            'body'    => wp_json_encode(['tree' => $tree_items]),
            'timeout' => 30,
        ]);

        if (is_wp_error($tree_response)) {
            return $tree_response;
        }

        $tree = json_decode(wp_remote_retrieve_body($tree_response), true);

        if (empty($tree['sha'])) {
            $tree_code = wp_remote_retrieve_response_code($tree_response);
            return new \WP_Error('tree_failed', "Failed to create git tree: " . ($tree['message'] ?? "HTTP {$tree_code}"));
        }

        $commit_response = wp_remote_post("{$base_url}/git/commits", [
            'headers' => $headers,
            'body'    => wp_json_encode([
                'message' => 'Initial scaffold from ExamplePress',
                'tree'    => $tree['sha'],
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($commit_response)) {
            return $commit_response;
        }

        $commit = json_decode(wp_remote_retrieve_body($commit_response), true);

        if (empty($commit['sha'])) {
            $commit_code = wp_remote_retrieve_response_code($commit_response);
            return new \WP_Error('commit_failed', "Failed to create initial commit: " . ($commit['message'] ?? "HTTP {$commit_code}"));
        }

        $ref_response = wp_remote_post("{$base_url}/git/refs", [
            'headers' => $headers,
            'body'    => wp_json_encode([
                'ref' => 'refs/heads/main',
                'sha' => $commit['sha'],
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($ref_response)) {
            return $ref_response;
        }

        $ref_code = wp_remote_retrieve_response_code($ref_response);

        if ($ref_code !== 201) {
            $ref_body = json_decode(wp_remote_retrieve_body($ref_response), true);
            return new \WP_Error('ref_failed', $ref_body['message'] ?? 'Failed to create main branch ref.');
        }

        return true;
    }

    // ── Generic File Push ──────────────────────────────────────────

    /**
     * Push an arbitrary in-memory file list as a new commit.
     *
     * Used by the Agent for both initial generation
     * (parentSha = null, creates main branch) and iteration
     * (parentSha = current HEAD, advances main).
     *
     * Deletion semantics: when parentSha is set, any path in
     * $filesDeleted is sent to GitHub's tree API with sha=null,
     * which is how the tree API encodes "remove this file from
     * the base tree." Omitting a path is NOT deletion — with a
     * base_tree, omitted paths are preserved byte-identical.
     *
     * @param array<int,array{path:string,contents:string}> $files
     * @param array<int,string>                              $filesDeleted
     * @return array{commit_sha:string}|\WP_Error
     */
    public static function pushFiles(
        string $ownerRepo,
        array $files,
        string $message,
        ?string $parentSha = null,
        string $branch = 'main',
        array $filesDeleted = []
    ): array|\WP_Error {
        $pat = self::writeToken();
        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $headers = [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            'Content-Type'  => 'application/json',
        ];
        $base = "https://api.github.com/repos/{$ownerRepo}";

        $tree = [];
        foreach ($files as $file) {
            $path     = (string) ($file['path'] ?? '');
            $contents = (string) ($file['contents'] ?? '');
            if ($path === '') {
                continue;
            }
            $tree[] = [
                'path'    => $path,
                'mode'    => '100644',
                'type'    => 'blob',
                'content' => $contents,
            ];
        }

        // Explicit deletions. Only meaningful when a base_tree is in
        // play — on an empty/first-commit repo there is nothing to
        // delete, and GitHub rejects null-sha tree entries without a
        // base_tree anyway.
        if ($parentSha && !empty($filesDeleted)) {
            foreach ($filesDeleted as $deletedPath) {
                if (!is_string($deletedPath) || $deletedPath === '') {
                    continue;
                }
                $tree[] = [
                    'path' => $deletedPath,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha'  => null,
                ];
            }
        }

        $treePayload = ['tree' => $tree];
        if ($parentSha) {
            // Base the new tree on the existing tree so unchanged files persist.
            $treePayload['base_tree'] = $parentSha;
        }

        $treeResp = wp_remote_post("{$base}/git/trees", [
            'headers' => $headers,
            'body'    => wp_json_encode($treePayload),
            'timeout' => 30,
        ]);
        if (is_wp_error($treeResp)) {
            return $treeResp;
        }
        $treeBody = json_decode(wp_remote_retrieve_body($treeResp), true);
        if (empty($treeBody['sha'])) {
            return new \WP_Error('tree_failed', $treeBody['message'] ?? 'Failed to create git tree.');
        }

        $commitPayload = [
            'message' => $message,
            'tree'    => $treeBody['sha'],
        ];
        if ($parentSha) {
            $commitPayload['parents'] = [$parentSha];
        }

        $commitResp = wp_remote_post("{$base}/git/commits", [
            'headers' => $headers,
            'body'    => wp_json_encode($commitPayload),
            'timeout' => 30,
        ]);
        if (is_wp_error($commitResp)) {
            return $commitResp;
        }
        $commitBody = json_decode(wp_remote_retrieve_body($commitResp), true);
        if (empty($commitBody['sha'])) {
            return new \WP_Error('commit_failed', $commitBody['message'] ?? 'Failed to create commit.');
        }

        // Create or update the branch ref.
        if (!$parentSha) {
            $refResp = wp_remote_post("{$base}/git/refs", [
                'headers' => $headers,
                'body'    => wp_json_encode([
                    'ref' => "refs/heads/{$branch}",
                    'sha' => $commitBody['sha'],
                ]),
                'timeout' => 30,
            ]);
        } else {
            $refResp = wp_remote_request("{$base}/git/refs/heads/{$branch}", [
                'method'  => 'PATCH',
                'headers' => $headers,
                'body'    => wp_json_encode([
                    'sha'   => $commitBody['sha'],
                    'force' => false,
                ]),
                'timeout' => 30,
            ]);
        }

        if (is_wp_error($refResp)) {
            return $refResp;
        }
        $refCode = wp_remote_retrieve_response_code($refResp);
        if ($refCode < 200 || $refCode >= 300) {
            $refBody = json_decode(wp_remote_retrieve_body($refResp), true);
            return new \WP_Error('ref_failed', $refBody['message'] ?? "Failed to update branch ref (HTTP {$refCode}).");
        }

        return ['commit_sha' => (string) $commitBody['sha']];
    }

    /**
     * Create a new branch from a given SHA.
     *
     * @return array{ref:string,sha:string}|\WP_Error
     */
    public static function createBranch(string $ownerRepo, string $name, string $fromSha): array|\WP_Error
    {
        $pat = self::writeToken();
        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $headers = [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            'Content-Type'  => 'application/json',
        ];
        $base = "https://api.github.com/repos/{$ownerRepo}";

        $resp = wp_remote_post("{$base}/git/refs", [
            'headers' => $headers,
            'body'    => wp_json_encode([
                'ref' => "refs/heads/{$name}",
                'sha' => $fromSha,
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 201) {
            $msg = (is_array($body) && isset($body['message']) && is_string($body['message']))
                ? $body['message']
                : "Failed to create branch (HTTP {$code}).";
            return new \WP_Error('branch_failed', $msg);
        }

        if (!is_array($body) || empty($body['ref'])) {
            return new \WP_Error(
                'branch_failed',
                'GitHub returned HTTP 201 but the response body is missing the ref field.'
            );
        }

        return ['ref' => (string) $body['ref'], 'sha' => $fromSha];
    }

    /**
     * Commit a multi-file changeset to an existing branch.
     *
     * Each file entry: {path, content, blob_sha (optional), op: create|update|delete|rename, from (optional, for renames)}
     * All files land in a single commit. Blob SHAs enable conflict detection.
     *
     * @param array $author  {name: string, email: string}
     * @return array{commit_sha:string}|\WP_Error
     */
    public static function commitChangeset(
        string $ownerRepo,
        string $branch,
        array $files,
        string $message,
        array $author = []
    ): array|\WP_Error {
        $pat = self::writeToken();
        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $headers = [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            'Content-Type'  => 'application/json',
        ];
        $base = "https://api.github.com/repos/{$ownerRepo}";

        // Get current branch HEAD.
        $refResp = wp_remote_get("{$base}/git/refs/heads/{$branch}", [
            'headers' => $headers,
            'timeout' => 30,
        ]);
        if (is_wp_error($refResp)) {
            return $refResp;
        }
        $refBody = json_decode(wp_remote_retrieve_body($refResp), true);
        if (empty($refBody['object']['sha'])) {
            return new \WP_Error('ref_lookup_failed', $refBody['message'] ?? 'Failed to get branch HEAD.');
        }
        $parentSha = $refBody['object']['sha'];

        // Build tree items from files.
        $treeItems = [];
        foreach ($files as $file) {
            $op      = $file['op'] ?? 'create';
            $path    = $file['path'] ?? '';
            $content = $file['content'] ?? '';

            if ($op === 'delete') {
                $treeItems[] = [
                    'path' => $path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha'  => null,
                ];
            } elseif ($op === 'rename') {
                // Delete the old path.
                $treeItems[] = [
                    'path' => $file['from'] ?? '',
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha'  => null,
                ];
                // Create at the new path.
                $treeItems[] = [
                    'path'    => $path,
                    'mode'    => '100644',
                    'type'    => 'blob',
                    'content' => $content,
                ];
            } else {
                // create or update
                $treeItems[] = [
                    'path'    => $path,
                    'mode'    => '100644',
                    'type'    => 'blob',
                    'content' => $content,
                ];
            }
        }

        // Create tree.
        $treeResp = wp_remote_post("{$base}/git/trees", [
            'headers' => $headers,
            'body'    => wp_json_encode([
                'base_tree' => $parentSha,
                'tree'      => $treeItems,
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($treeResp)) {
            return $treeResp;
        }
        $treeBody = json_decode(wp_remote_retrieve_body($treeResp), true);
        if (empty($treeBody['sha'])) {
            return new \WP_Error('tree_failed', $treeBody['message'] ?? 'Failed to create git tree.');
        }

        // Create commit.
        $commitPayload = [
            'message' => $message,
            'tree'    => $treeBody['sha'],
            'parents' => [$parentSha],
        ];
        if (!empty($author)) {
            $commitPayload['author'] = $author;
        }

        $commitResp = wp_remote_post("{$base}/git/commits", [
            'headers' => $headers,
            'body'    => wp_json_encode($commitPayload),
            'timeout' => 30,
        ]);
        if (is_wp_error($commitResp)) {
            return $commitResp;
        }
        $commitBody = json_decode(wp_remote_retrieve_body($commitResp), true);
        if (empty($commitBody['sha'])) {
            return new \WP_Error('commit_failed', $commitBody['message'] ?? 'Failed to create commit.');
        }
        $commitSha = (string) $commitBody['sha'];

        // Update branch ref.
        $updateResp = wp_remote_request("{$base}/git/refs/heads/{$branch}", [
            'method'  => 'PATCH',
            'headers' => $headers,
            'body'    => wp_json_encode([
                'sha'   => $commitSha,
                'force' => false,
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($updateResp)) {
            return $updateResp;
        }
        $updateCode = wp_remote_retrieve_response_code($updateResp);
        if ($updateCode < 200 || $updateCode >= 300) {
            $updateBody = json_decode(wp_remote_retrieve_body($updateResp), true);
            return new \WP_Error('ref_failed', $updateBody['message'] ?? "Failed to update branch ref (HTTP {$updateCode}).");
        }

        return ['commit_sha' => $commitSha];
    }

    /**
     * Open a pull request.
     *
     * @return array{number:int,html_url:string}|\WP_Error
     */
    public static function createPullRequest(
        string $ownerRepo,
        string $head,
        string $base,
        string $title,
        string $body
    ): array|\WP_Error {
        $pat = self::writeToken();
        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $headers = [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            'Content-Type'  => 'application/json',
        ];
        $apiBase = "https://api.github.com/repos/{$ownerRepo}";

        $resp = wp_remote_post("{$apiBase}/pulls", [
            'headers' => $headers,
            'body'    => wp_json_encode([
                'title' => $title,
                'body'  => $body,
                'head'  => $head,
                'base'  => $base,
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code    = wp_remote_retrieve_response_code($resp);
        $respBody = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 201) {
            $msg = (is_array($respBody) && isset($respBody['message']) && is_string($respBody['message']))
                ? $respBody['message']
                : "Failed to create pull request (HTTP {$code}).";
            return new \WP_Error('pr_failed', $msg);
        }

        if (!is_array($respBody) || !isset($respBody['number'], $respBody['html_url'])) {
            return new \WP_Error(
                'pr_failed',
                'GitHub returned HTTP 201 but the response body is missing required fields (number, html_url).'
            );
        }

        return [
            'number'   => (int) $respBody['number'],
            'html_url' => (string) $respBody['html_url'],
        ];
    }

    // ── Releases ───────────────────────────────────────────────────

    /**
     * Create a GitHub release on a repo. Used by the Agent
     * to tag immutable versions after each generation/iteration.
     *
     * @return array{tag_name:string,html_url:string,id:int}|\WP_Error
     */
    public static function createRelease(
        string $ownerRepo,
        string $tag,
        string $name = '',
        string $body = '',
        string $targetCommitish = 'main'
    ): array|\WP_Error {
        $pat = self::writeToken();

        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $response = wp_remote_post("https://api.github.com/repos/{$ownerRepo}/releases", [
            'headers' => [
                'Authorization' => "Bearer {$pat}",
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'tag_name'         => $tag,
                'target_commitish' => $targetCommitish,
                'name'             => $name ?: $tag,
                'body'             => $body,
                'draft'            => false,
                'prerelease'       => false,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 201 || empty($body['tag_name'])) {
            $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
            return new \WP_Error('release_failed', $msg);
        }

        return [
            'tag_name' => (string) $body['tag_name'],
            'html_url' => (string) ($body['html_url'] ?? ''),
            'id'       => (int) ($body['id'] ?? 0),
        ];
    }

    /**
     * Fetch the full file tree of a repo at HEAD of the default branch.
     * Used by the Agent to feed current code as context
     * to the LLM during iteration.
     *
     * @return array{files:array<int,array{path:string,contents:string}>,sha:string}|\WP_Error
     */
    public static function fetchRepoTree(string $ownerRepo, string $branch = 'main'): array|\WP_Error
    {
        $pat = self::writeToken();
        if (!$pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token available.');
        }

        $headers = [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        ];

        // 1. Resolve the branch tip SHA.
        $ref = wp_remote_get("https://api.github.com/repos/{$ownerRepo}/git/ref/heads/{$branch}", [
            'headers' => $headers,
            'timeout' => 15,
        ]);
        if (is_wp_error($ref)) {
            return $ref;
        }
        $refBody = json_decode(wp_remote_retrieve_body($ref), true);
        $sha     = $refBody['object']['sha'] ?? '';
        if (!$sha) {
            return new \WP_Error('no_branch', "Branch {$branch} not found on {$ownerRepo}.");
        }

        // 2. Fetch the recursive tree.
        $tree = wp_remote_get("https://api.github.com/repos/{$ownerRepo}/git/trees/{$sha}?recursive=1", [
            'headers' => $headers,
            'timeout' => 30,
        ]);
        if (is_wp_error($tree)) {
            return $tree;
        }
        $treeBody = json_decode(wp_remote_retrieve_body($tree), true);
        if (empty($treeBody['tree']) || !is_array($treeBody['tree'])) {
            return new \WP_Error('no_tree', 'Empty or malformed tree response.');
        }

        // 3. Fetch each blob's content.
        $files = [];
        $maxFiles = (int) apply_filters('examplepress_mu_agent_iterate_max_files', 80);

        foreach ($treeBody['tree'] as $entry) {
            // Per-entry shape guard — GitHub's tree API usually returns
            // well-formed entries, but a malformed item would otherwise
            // produce PHP 8 warnings on offset access below.
            if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $entryPath = $entry['path'] ?? '';
            $entrySha  = $entry['sha'] ?? '';
            if (!is_string($entryPath) || $entryPath === '' || !is_string($entrySha) || $entrySha === '') {
                continue;
            }
            if (count($files) >= $maxFiles) {
                break;
            }
            $blob = wp_remote_get("https://api.github.com/repos/{$ownerRepo}/git/blobs/{$entrySha}", [
                'headers' => $headers,
                'timeout' => 15,
            ]);
            if (is_wp_error($blob)) {
                continue;
            }
            $blobBody = json_decode(wp_remote_retrieve_body($blob), true);
            if (!is_array($blobBody) || empty($blobBody['content'])) {
                continue;
            }
            $contents = ($blobBody['encoding'] ?? 'base64') === 'base64'
                ? (string) base64_decode((string) $blobBody['content'])
                : (string) $blobBody['content'];

            // Skip binary files for context.
            if (!mb_check_encoding($contents, 'UTF-8')) {
                continue;
            }

            $files[] = ['path' => $entryPath, 'contents' => $contents];
        }

        return ['files' => $files, 'sha' => $sha];
    }

    // ── Troy Integration ───────────────────────────────────────────

    /**
     * Register a plugin on Troy and connect it to the GitHub repo.
     *
     * @return array|\WP_Error
     */
    public static function troyRegisterAndConnect(
        string $slug,
        string $name,
        string $description,
        string $ownerRepo
    ): array|\WP_Error {
        $troy_url  = get_option('ep_troy_server_url', '');
        $troy_auth = get_option('ep_troy_credentials', '');

        if (!$troy_url || !$troy_auth) {
            return new \WP_Error('no_troy_config', 'Troy Server URL or credentials are not configured.');
        }

        $troy_url = rtrim($troy_url, '/');

        $response = wp_remote_post("{$troy_url}/wp-json/troy-server/v1/plugins/manage/provision", [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($troy_auth),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(array_filter([
                'name'        => $name,
                'slug'        => $slug,
                'description' => $description,
                'owner_repo'  => $ownerRepo,
                'github_pat'  => self::readToken(),
            ])),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 409) {
            $msg = (is_array($body) && isset($body['message']) && is_string($body['message']))
                ? $body['message']
                : 'Slug already registered on Troy.';
            return new \WP_Error('troy_slug_exists', $msg);
        }

        if ($code < 200 || $code >= 300) {
            $msg = (is_array($body) && isset($body['message']) && is_string($body['message']))
                ? $body['message']
                : "Troy API returned HTTP {$code}.";
            return new \WP_Error('troy_api_error', $msg);
        }

        // Contract: the method declares array|\WP_Error, so callers expect
        // an array on success. A 2xx response with a non-array body (e.g.
        // Troy behind a misconfigured proxy that returns an empty string)
        // would otherwise satisfy PHP's return type coercion and silently
        // hand back null, breaking downstream array access.
        if (!is_array($body)) {
            return new \WP_Error(
                'troy_api_error',
                sprintf('Troy returned HTTP %d but the response body was not a JSON object.', $code)
            );
        }

        return $body;
    }

    /**
     * Merge Troy registration data into the plugin's examplepress.json.
     *
     * This is a write primitive — it overwrites an existing JSON file
     * under WP_PLUGIN_DIR using $slug as a path segment. Callers that
     * reach this method via the REST layer have their slug validated
     * up front, but the method is public and could be called from
     * WP-CLI or other trusted-but-unvalidating contexts, so we gate
     * here as defense in depth.
     */
    public static function updateAppTroyData(string $slug, array $troyData): bool
    {
        if (!Helpers::isSafeRelativePath($slug) || str_contains($slug, '/')) {
            return false;
        }

        $json_path = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';

        if (!file_exists($json_path)) {
            return false;
        }

        $raw = @file_get_contents($json_path);
        if (!is_string($raw)) {
            return false;
        }
        $config = json_decode($raw, true);

        if (!is_array($config)) {
            return false;
        }

        $existingTroy    = is_array($config['troy'] ?? null) ? $config['troy'] : [];
        $config['troy']  = array_merge($existingTroy, $troyData);

        $encoded = wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        return (bool) @file_put_contents($json_path, $encoded . "\n");
    }

    // ── File Collection ────────────────────────────────────────────

    /**
     * Recursively collect all files in a directory, returning relative => absolute path pairs.
     *
     * @return array<string, string>
     */
    public static function collectFiles(string $dir, string $base): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = ltrim(str_replace($base, '', $absolute), '/');
            $files[$relative] = $absolute;
        }

        return $files;
    }
}
