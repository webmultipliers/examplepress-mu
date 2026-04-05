<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * ExamplePress Scaffolder — GitHub Template Repository.
 *
 * Creates companion plugins from a GitHub template repository using
 * the "Generate from template" API. The template repo is configurable
 * via the `ep_app_template_repo` option — agencies can point this at
 * their own template to scaffold apps with custom boilerplate.
 *
 * Falls back to local scaffold (downloading from the template repo)
 * when no GitHub write token is available.
 *
 * Flow:
 *   1. Create repo from template via GitHub API
 *   2. Replace __SLUG__, __NAME__, __DESC__ placeholders in the new repo
 *   3. Create initial release (v0.0.0)
 *   4. Return repo URL + Codespaces link
 */
final class Scaffolder
{
    /**
     * Default template repository for scaffold operations.
     *
     * Override via the `ep_app_template_repo` option in the admin
     * Connections tab, or filter with `examplepress_template_repo`.
     */
    public const EP_DEFAULT_TEMPLATE_REPO = 'webmultipliers/examplepress-theme-app';

    /**
     * Placeholder tokens used in the template repository files.
     */
    public const EP_SCAFFOLD_PLACEHOLDERS = ['__NAME__', '__SLUG__', '__DESC__'];

    /**
     * File extensions eligible for placeholder replacement.
     */
    private const PROCESSABLE_EXTENSIONS = [
        'php', 'json', 'md', 'yml', 'yaml', 'txt', 'xml', 'css', 'js', 'html',
    ];

    /**
     * Get the configured template repository (owner/repo).
     *
     * Resolution order:
     *   1. PHP filter `examplepress_template_repo`
     *   2. Database option `ep_app_template_repo`
     *   3. Constant EP_DEFAULT_TEMPLATE_REPO
     *
     * @return string GitHub owner/repo string.
     */
    public static function getTemplateRepo(): string
    {
        $repo = get_option('ep_app_template_repo', self::EP_DEFAULT_TEMPLATE_REPO);

        if (! $repo) {
            $repo = self::EP_DEFAULT_TEMPLATE_REPO;
        }

        return apply_filters('examplepress_template_repo', $repo);
    }

    /**
     * Create a new repo from the GitHub template repository.
     *
     * Uses the "Generate from template" API which creates a clean repo
     * (no fork relationship, no template commit history).
     *
     * @param string $slug        Repo/plugin slug.
     * @param string $description Repo description.
     * @param string $owner       GitHub org or user to create under.
     * @return array{full_name: string, id: int, html_url: string}|\WP_Error
     */
    public static function fromTemplate(string $slug, string $description, string $owner): array|\WP_Error
    {
        $pat = GitHub::writeToken();

        if (! $pat) {
            return new \WP_Error(
                'no_github_token',
                'No GitHub write token available. Install the GitHub App or configure a write access token.'
            );
        }

        $response = wp_remote_post(
            'https://api.github.com/repos/' . self::getTemplateRepo() . '/generate',
            [
                'headers' => self::headers($pat),
                'body'    => wp_json_encode([
                    'owner'       => $owner,
                    'name'        => $slug,
                    'description' => $description,
                    'private'     => true,
                ]),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 422) {
            $msg = $body['message'] ?? 'Validation failed';
            if (str_contains($msg, 'already exists') || str_contains($msg, 'name already exists')) {
                return new \WP_Error('repo_exists', "Repository \"{$owner}/{$slug}\" already exists.");
            }
            return new \WP_Error('github_validation', $msg);
        }

        if ($code !== 201) {
            $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
            return new \WP_Error('github_api_error', $msg);
        }

        return [
            'full_name' => $body['full_name'] ?? "{$owner}/{$slug}",
            'id'        => (int) ($body['id'] ?? 0),
            'html_url'  => $body['html_url'] ?? '',
        ];
    }

    /**
     * Replace placeholder tokens in files within a GitHub repository.
     *
     * Uses the Git Database API (blob + tree + commit + ref update) to
     * apply all changes in a single atomic commit. This avoids the
     * rate-limiting, timeout errors, and API-abuse issues caused by
     * sequential PUT requests via the Contents API.
     *
     * @param string $fullName    GitHub "owner/repo" string.
     * @param string $slug        Plugin slug.
     * @param string $name        Plugin display name.
     * @param string $description Plugin description.
     * @return true|\WP_Error
     */
    public static function replaceRemotePlaceholders(
        string $fullName,
        string $slug,
        string $name,
        string $description
    ): true|\WP_Error {
        $pat = GitHub::writeToken();

        if (! $pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token.');
        }

        $headers  = self::headers($pat);
        $baseUrl  = "https://api.github.com/repos/{$fullName}";

        // Resolve the default branch — template repos may use 'development', not 'main'.
        $repoResponse = wp_remote_get($baseUrl, [
            'headers' => $headers,
            'timeout' => 10,
        ]);

        if (is_wp_error($repoResponse)) {
            return $repoResponse;
        }

        $repoData      = json_decode(wp_remote_retrieve_body($repoResponse), true);
        $defaultBranch  = $repoData['default_branch'] ?? 'development';

        // --- Step 1: Fetch the tree (with retry for async template generation). ---
        $tree       = [];
        $baseSha    = '';
        $retries    = 5;

        for ($attempt = 0; $attempt < $retries; $attempt++) {
            if ($attempt > 0) {
                sleep(2);
            }

            // Get the branch ref to find the current commit SHA.
            $refResponse = wp_remote_get("{$baseUrl}/git/ref/heads/{$defaultBranch}", [
                'headers' => $headers,
                'timeout' => 15,
            ]);

            if (is_wp_error($refResponse)) {
                return $refResponse;
            }

            $refData = json_decode(wp_remote_retrieve_body($refResponse), true);
            $baseSha = $refData['object']['sha'] ?? '';

            if (! $baseSha) {
                continue;
            }

            // Get the commit to find the tree SHA.
            $commitResponse = wp_remote_get("{$baseUrl}/git/commits/{$baseSha}", [
                'headers' => $headers,
                'timeout' => 15,
            ]);

            if (is_wp_error($commitResponse)) {
                return $commitResponse;
            }

            $commitData  = json_decode(wp_remote_retrieve_body($commitResponse), true);
            $baseTreeSha = $commitData['tree']['sha'] ?? '';

            if (! $baseTreeSha) {
                continue;
            }

            // Fetch the full recursive tree.
            $treeResponse = wp_remote_get("{$baseUrl}/git/trees/{$baseTreeSha}?recursive=1", [
                'headers' => $headers,
                'timeout' => 15,
            ]);

            if (is_wp_error($treeResponse)) {
                return $treeResponse;
            }

            $treeBody = json_decode(wp_remote_retrieve_body($treeResponse), true);
            $tree     = $treeBody['tree'] ?? [];

            if (! empty($tree)) {
                break;
            }
        }

        if (empty($tree)) {
            return new \WP_Error(
                'empty_tree',
                'Repository tree is still empty after waiting. GitHub may be slow — try the Connect button in a moment.'
            );
        }

        $replacements = [
            '__NAME__' => $name,
            '__SLUG__' => $slug,
            '__DESC__' => $description,
        ];

        // --- Step 2: Identify files needing changes and create blobs. ---
        $newTreeEntries = [];

        foreach ($tree as $entry) {
            if ($entry['type'] !== 'blob') {
                continue;
            }

            $filePath = $entry['path'];
            $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            // Determine the output path (handle __SLUG__.php rename).
            $outputPath = $filePath;
            if ($filePath === '__SLUG__.php') {
                $outputPath = $slug . '.php';
            }

            // Non-processable files: keep as-is in the tree (no blob fetch needed).
            if (! in_array($ext, self::PROCESSABLE_EXTENSIONS, true)) {
                // Only add a tree entry if the path changed (rename).
                if ($outputPath !== $filePath) {
                    // Fetch content for the renamed binary file.
                    $newTreeEntries[] = [
                        'path' => $outputPath,
                        'mode' => $entry['mode'],
                        'type' => 'blob',
                        'sha'  => $entry['sha'],
                    ];
                }
                continue;
            }

            // Fetch the blob content for processable files.
            $blobResponse = wp_remote_get("{$baseUrl}/git/blobs/{$entry['sha']}", [
                'headers' => $headers,
                'timeout' => 10,
            ]);

            if (is_wp_error($blobResponse) || wp_remote_retrieve_response_code($blobResponse) !== 200) {
                continue;
            }

            $blobData = json_decode(wp_remote_retrieve_body($blobResponse), true);
            $content  = base64_decode($blobData['content'] ?? '');

            // Apply placeholder replacements.
            $replaced = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $content
            );

            // Skip files with no changes and no rename.
            if ($replaced === $content && $outputPath === $filePath) {
                continue;
            }

            // Create a new blob with the replaced content.
            $createBlobResponse = wp_remote_post("{$baseUrl}/git/blobs", [
                'headers' => $headers,
                'body'    => wp_json_encode([
                    'content'  => $replaced,
                    'encoding' => 'utf-8',
                ]),
                'timeout' => 15,
            ]);

            if (is_wp_error($createBlobResponse)) {
                continue;
            }

            $newBlobData = json_decode(wp_remote_retrieve_body($createBlobResponse), true);
            $newBlobSha  = $newBlobData['sha'] ?? '';

            if (! $newBlobSha) {
                continue;
            }

            $newTreeEntries[] = [
                'path' => $outputPath,
                'mode' => $entry['mode'],
                'type' => 'blob',
                'sha'  => $newBlobSha,
            ];
        }

        // If __SLUG__.php was renamed, we must also delete the original path.
        // In the Git tree API, we do this by omitting it from the base_tree overlay —
        // but since base_tree carries forward unchanged entries, we need to explicitly
        // null-out the old path by not including it. The Git Database API handles this
        // via the tree entries: any path listed in tree entries overrides base_tree,
        // and we remove __SLUG__.php by adding a null-sha entry (or we omit base_tree
        // and list everything). The simplest approach: include a deletion entry.
        $hasSlugFile = false;
        foreach ($tree as $entry) {
            if ($entry['type'] === 'blob' && $entry['path'] === '__SLUG__.php') {
                $hasSlugFile = true;
                break;
            }
        }

        // If nothing changed, we're done.
        if (empty($newTreeEntries) && ! $hasSlugFile) {
            return true;
        }

        // --- Step 3: Create a new tree. ---
        // Use base_tree so unchanged files carry forward. For the __SLUG__.php deletion,
        // we build the complete list: base_tree entries minus __SLUG__.php, plus our overrides.
        if ($hasSlugFile) {
            // Build the full tree manually to omit __SLUG__.php.
            $fullTreeEntries = [];
            $overriddenPaths = [];

            foreach ($newTreeEntries as $entry) {
                $overriddenPaths[$entry['path']] = true;
            }

            foreach ($tree as $entry) {
                // Skip the old __SLUG__.php (it's been renamed).
                if ($entry['path'] === '__SLUG__.php') {
                    continue;
                }

                // Skip entries we're overriding with new blobs.
                if (isset($overriddenPaths[$entry['path']])) {
                    continue;
                }

                // Skip subtree entries — the API reconstructs them from blob paths.
                if ($entry['type'] !== 'blob') {
                    continue;
                }

                $fullTreeEntries[] = [
                    'path' => $entry['path'],
                    'mode' => $entry['mode'],
                    'type' => 'blob',
                    'sha'  => $entry['sha'],
                ];
            }

            // Merge in our new/modified entries.
            $fullTreeEntries = array_merge($fullTreeEntries, $newTreeEntries);

            $treePayload = ['tree' => $fullTreeEntries];
        } else {
            // No deletion needed — use base_tree for efficiency.
            $treePayload = [
                'base_tree' => $baseTreeSha,
                'tree'      => $newTreeEntries,
            ];
        }

        $createTreeResponse = wp_remote_post("{$baseUrl}/git/trees", [
            'headers' => $headers,
            'body'    => wp_json_encode($treePayload),
            'timeout' => 30,
        ]);

        if (is_wp_error($createTreeResponse)) {
            return $createTreeResponse;
        }

        $createTreeCode = wp_remote_retrieve_response_code($createTreeResponse);

        if ($createTreeCode !== 201) {
            $treeError = json_decode(wp_remote_retrieve_body($createTreeResponse), true);
            return new \WP_Error(
                'tree_create_failed',
                $treeError['message'] ?? "GitHub API returned HTTP {$createTreeCode} creating tree."
            );
        }

        $newTreeData = json_decode(wp_remote_retrieve_body($createTreeResponse), true);
        $newTreeSha  = $newTreeData['sha'] ?? '';

        // --- Step 4: Create a commit pointing to the new tree. ---
        $createCommitResponse = wp_remote_post("{$baseUrl}/git/commits", [
            'headers' => $headers,
            'body'    => wp_json_encode([
                'message' => 'Replace scaffold placeholders',
                'tree'    => $newTreeSha,
                'parents' => [$baseSha],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($createCommitResponse)) {
            return $createCommitResponse;
        }

        $createCommitCode = wp_remote_retrieve_response_code($createCommitResponse);

        if ($createCommitCode !== 201) {
            $commitError = json_decode(wp_remote_retrieve_body($createCommitResponse), true);
            return new \WP_Error(
                'commit_create_failed',
                $commitError['message'] ?? "GitHub API returned HTTP {$createCommitCode} creating commit."
            );
        }

        $newCommitData = json_decode(wp_remote_retrieve_body($createCommitResponse), true);
        $newCommitSha  = $newCommitData['sha'] ?? '';

        // --- Step 5: Update the branch ref to point to the new commit. ---
        $updateRefResponse = wp_remote_request("{$baseUrl}/git/refs/heads/{$defaultBranch}", [
            'method'  => 'PATCH',
            'headers' => $headers,
            'body'    => wp_json_encode([
                'sha' => $newCommitSha,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($updateRefResponse)) {
            return $updateRefResponse;
        }

        $updateRefCode = wp_remote_retrieve_response_code($updateRefResponse);

        if ($updateRefCode !== 200) {
            $refError = json_decode(wp_remote_retrieve_body($updateRefResponse), true);
            return new \WP_Error(
                'ref_update_failed',
                $refError['message'] ?? "GitHub API returned HTTP {$updateRefCode} updating ref."
            );
        }

        return true;
    }

    /**
     * Create an initial v0.0.0 release on a newly scaffolded repo.
     *
     * Marks the repo as deployment-ready. GitHub auto-attaches source
     * archives (zip/tar) to every release, so no build step is needed
     * for the initial tag. The companion app updater will pick this up.
     *
     * @param string $fullName GitHub "owner/repo" string.
     * @return true|\WP_Error
     */
    public static function createInitialRelease(string $fullName): true|\WP_Error
    {
        $pat = GitHub::writeToken();

        if (! $pat) {
            return new \WP_Error('no_github_token', 'No GitHub write token.');
        }

        $response = wp_remote_post(
            "https://api.github.com/repos/{$fullName}/releases",
            [
                'headers' => array_merge(self::headers($pat), [
                    'Content-Type' => 'application/json',
                ]),
                'body'    => wp_json_encode([
                    'tag_name'               => 'v0.0.0',
                    'name'                   => 'v0.0.0',
                    'body'                   => 'Initial scaffold release.',
                    'draft'                  => false,
                    'prerelease'             => false,
                    'generate_release_notes' => false,
                ]),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 201) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return new \WP_Error(
                'release_failed',
                $body['message'] ?? "GitHub API returned HTTP {$code} creating release."
            );
        }

        return true;
    }

    /**
     * Download the template repo into a local directory and replace placeholders.
     *
     * Used by the local scaffold mode to ensure local files match the
     * canonical template repo exactly. Uses a read token when available
     * for higher rate limits and access to private template repos.
     *
     * @param string $dest        Local destination directory.
     * @param string $slug        Plugin slug.
     * @param string $name        Plugin display name.
     * @param string $description Plugin description.
     * @return true|\WP_Error
     */
    public static function downloadTemplate(
        string $dest,
        string $slug,
        string $name,
        string $description
    ): true|\WP_Error {
        $headers = [
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        ];

        // Add auth if available (higher rate limits, private template repos).
        $pat = GitHub::readToken();
        if ($pat) {
            $headers['Authorization'] = "Bearer {$pat}";
        }

        $baseUrl = 'https://api.github.com/repos/' . self::getTemplateRepo();

        // Resolve default branch.
        $repoResponse = wp_remote_get($baseUrl, [
            'headers' => $headers,
            'timeout' => 10,
        ]);

        if (is_wp_error($repoResponse)) {
            return $repoResponse;
        }

        $repoData      = json_decode(wp_remote_retrieve_body($repoResponse), true);
        $defaultBranch  = $repoData['default_branch'] ?? 'development';

        // Get the file tree.
        $treeResponse = wp_remote_get("{$baseUrl}/git/trees/{$defaultBranch}?recursive=1", [
            'headers' => $headers,
            'timeout' => 15,
        ]);

        if (is_wp_error($treeResponse)) {
            return $treeResponse;
        }

        $treeBody = json_decode(wp_remote_retrieve_body($treeResponse), true);
        $tree     = $treeBody['tree'] ?? [];

        if (empty($tree)) {
            return new \WP_Error('empty_tree', 'Template repository tree is empty.');
        }

        $replacements = [
            '__NAME__' => $name,
            '__SLUG__' => $slug,
            '__DESC__' => $description,
        ];

        $fs = Helpers::filesystem();

        if (! $fs) {
            return new \WP_Error('filesystem_error', 'Could not initialise the WordPress filesystem.');
        }

        // Create the destination directory.
        if (! $fs->is_dir($dest)) {
            $fs->mkdir($dest);
        }

        foreach ($tree as $entry) {
            if ($entry['type'] === 'tree') {
                // Create subdirectory.
                $dirPath = $dest . '/' . $entry['path'];
                if (! $fs->is_dir($dirPath)) {
                    wp_mkdir_p($dirPath);
                }
                continue;
            }

            if ($entry['type'] !== 'blob') {
                continue;
            }

            $filePath = $entry['path'];

            // Download file content.
            $fileResponse = wp_remote_get("{$baseUrl}/contents/{$filePath}?ref={$defaultBranch}", [
                'headers' => $headers,
                'timeout' => 10,
            ]);

            if (is_wp_error($fileResponse) || wp_remote_retrieve_response_code($fileResponse) !== 200) {
                continue;
            }

            $fileData = json_decode(wp_remote_retrieve_body($fileResponse), true);

            if (empty($fileData['content'])) {
                continue;
            }

            $content = base64_decode($fileData['content']);

            // Replace placeholders in text files.
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($ext, self::PROCESSABLE_EXTENSIONS, true)) {
                $content = str_replace(
                    array_keys($replacements),
                    array_values($replacements),
                    $content
                );
            }

            // Handle __SLUG__.php -> {slug}.php rename.
            $localPath = $filePath;
            if ($filePath === '__SLUG__.php') {
                $localPath = $slug . '.php';
            }

            $fs->put_contents($dest . '/' . $localPath, $content);
        }

        return true;
    }

    /**
     * Build a Codespaces launch URL for a repository.
     *
     * @param string $fullName GitHub "owner/repo" string.
     * @param int    $repoId   GitHub repo ID (preferred when available).
     * @return string URL to open Codespaces for this repo.
     */
    public static function codespacesUrl(string $fullName, int $repoId = 0): string
    {
        if ($repoId) {
            return 'https://github.com/codespaces/new?repo=' . $repoId;
        }

        return 'https://github.com/codespaces/new?repo=' . urlencode($fullName);
    }

    /**
     * Build standard GitHub API request headers.
     *
     * @param string $token Bearer token for authentication.
     * @return array<string, string>
     */
    private static function headers(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        ];
    }
}
