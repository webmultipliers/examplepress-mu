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
        $posts = get_posts([
            'post_type'      => 'ep_app',
            'posts_per_page' => -1,
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
            'post_status' => 'draft',
        ]);

        if (is_wp_error($postId)) {
            return array_merge(['slug' => $slug], $data);
        }

        update_post_meta($postId, '_ep_plugin_slug', $slug);
        self::writeMeta($postId, $data);

        return self::toRecord(get_post($postId));
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

        return $merged;
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

                $code = wp_remote_retrieve_response_code($response);

                if ($code === 204 || $code === 404) {
                    $deleted[] = 'github';
                } else {
                    $failed[] = 'github';
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $warnings[] = 'GitHub delete: ' . ($body['message'] ?? "HTTP {$code}");
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

                $code = wp_remote_retrieve_response_code($response);

                if ($code >= 200 && $code < 300 || $code === 404) {
                    $deleted[] = 'troy';
                } else {
                    $failed[] = 'troy';
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $warnings[] = 'Troy unregister: ' . ($body['message'] ?? "HTTP {$code}");
                }
            } else {
                $failed[] = 'troy';
                $warnings[] = 'No Troy credentials — cannot unregister.';
            }
        }

        // 4. Remove from registry.
        self::forget($slug);

        return [
            'deleted'  => $deleted,
            'failed'   => $failed,
            'warnings' => $warnings,
        ];
    }
}
