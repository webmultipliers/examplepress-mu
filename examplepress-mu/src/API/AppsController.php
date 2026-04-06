<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\GitHub;
use ExamplePress\MU\Infrastructure\Helpers;
use ExamplePress\MU\Infrastructure\Scaffolder;
use ExamplePress\MU\Infrastructure\AppUpdateProvider;

/**
 * App management REST API — listing, scaffolding, Troy binding,
 * deactivation, connection, health checks, and destruction.
 */
final class AppsController
{
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

        register_rest_route('examplepress-mu/v1', '/apps', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'listApps'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/apps/scaffold', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'scaffold'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => 'Human-readable app name.',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        if (!is_string($value) || strlen($value) < 2 || strlen($value) > 100) {
                            return new \WP_Error('invalid_name', 'Name must be between 2 and 100 characters.');
                        }
                        return true;
                    },
                ],
                'description' => [
                    'type'              => 'string',
                    'description'       => 'Short description for the app.',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        if (!is_string($value) || strlen($value) > 500) {
                            return new \WP_Error('invalid_description', 'Description must be 500 characters or fewer.');
                        }
                        return true;
                    },
                ],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/apps/(?P<slug>[a-z0-9-]+)/troy-bind', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'troyBind'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => array_merge($slug_args, [
                'troy_type' => [
                    'type'              => 'string',
                    'description'       => 'Troy server type.',
                    'default'           => 'cloud',
                    'enum'              => ['cloud', 'custom'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'custom_url' => [
                    'type'              => 'string',
                    'description'       => 'Custom Troy server URL (required when troy_type is custom).',
                    'default'           => '',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ]),
        ]);

        register_rest_route('examplepress-mu/v1', '/apps/(?P<slug>[a-z0-9-]+)/deactivate', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'deactivate'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => $slug_args,
        ]);

        register_rest_route('examplepress-mu/v1', '/apps/(?P<slug>[a-z0-9-]+)/connect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'connect'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => $slug_args,
        ]);

        register_rest_route('examplepress-mu/v1', '/apps/(?P<slug>[a-z0-9-]+)/health', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'health'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => $slug_args,
        ]);

        register_rest_route('examplepress-mu/v1', '/apps/(?P<slug>[a-z0-9-]+)/destroy', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'destroy'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => array_merge($slug_args, [
                'confirm' => [
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => 'Confirmation nonce (wp_create_nonce "ep_destroy_{slug}").',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]),
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    // ── List ────────────────────────────────────────────────────────

    public static function listApps(): \WP_REST_Response
    {
        return rest_ensure_response(AppRegistry::listMerged());
    }

    // ── Scaffold ────────────────────────────────────────────────────

    public static function scaffold(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $name = sanitize_text_field($request->get_param('name') ?? '');
        $desc = sanitize_text_field($request->get_param('description') ?? '');

        if (!$name) {
            return new \WP_Error('missing_name', 'App name is required.', ['status' => 400]);
        }

        $slug = AppDiscovery::slugify($name);

        if (!$slug) {
            return new \WP_Error('invalid_name', 'Could not generate a valid slug from the provided name.', ['status' => 400]);
        }

        if (!GitHub::writeToken()) {
            return self::scaffoldLocalMode($slug, $name, $desc);
        }

        return self::scaffoldTemplateMode($slug, $name, $desc);
    }

    // ── Troy Bind ───────────────────────────────────────────────────

    public static function troyBind(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug       = $request->get_param('slug');
        $troy_type  = sanitize_text_field($request->get_param('troy_type') ?? 'cloud');
        $custom_url = esc_url_raw($request->get_param('custom_url') ?? '');

        $json_path = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';

        if (!file_exists($json_path)) {
            return new \WP_Error('app_not_found', 'App not found or missing examplepress.json.', ['status' => 404]);
        }

        $cloud_url   = get_option('ep_troy_server_url', '');
        $troy_server = 'cloud' === $troy_type
            ? str_replace(['https://', 'http://'], '', rtrim($cloud_url, '/'))
            : rtrim(str_replace(['https://', 'http://'], '', $custom_url), '/');

        if (empty($troy_server)) {
            return new \WP_Error('missing_troy_url', 'Troy server URL is required for custom server binding.', ['status' => 400]);
        }

        $redirect_url = 'https://' . $troy_server . '/scaffold?' . http_build_query([
            'slug'  => $slug,
            'theme' => 'examplepress-theme',
        ]);

        return rest_ensure_response([
            'success'      => true,
            'troy_server'  => $troy_server,
            'redirect_url' => $redirect_url,
        ]);
    }

    // ── Deactivate ──────────────────────────────────────────────────

    public static function deactivate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $apps = AppDiscovery::scan();
        $app  = null;

        foreach ($apps as $a) {
            if ($a['slug'] === $slug) {
                $app = $a;
                break;
            }
        }

        if (!$app) {
            return new \WP_Error('app_not_found', 'App not found.', ['status' => 404]);
        }

        if (!$app['active']) {
            return rest_ensure_response([
                'success' => true,
                'message' => 'App is already inactive.',
            ]);
        }

        deactivate_plugins($app['plugin_file']);

        return rest_ensure_response([
            'success' => true,
            'message' => "App \"{$app['name']}\" deactivated.",
        ]);
    }

    // ── Connect ─────────────────────────────────────────────────────

    public static function connect(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $apps = AppDiscovery::scan();
        $app  = null;

        foreach ($apps as $a) {
            if ($a['slug'] === $slug) {
                $app = $a;
                break;
            }
        }

        if (!$app) {
            return new \WP_Error('app_not_found', 'App not found.', ['status' => 404]);
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;
        $name        = $app['name'];
        $description = $app['description'] ?: 'A companion plugin.';
        $org         = get_option('ep_github_org', 'webmultipliers');
        $owner_repo  = $org . '/' . $slug;
        $warnings    = [];
        $github_data = [];

        // Step 1: Create GitHub repo.
        $write_token = GitHub::writeToken();

        if ($write_token) {
            $repo_result = GitHub::createRepo($slug, $description);

            if (is_wp_error($repo_result)) {
                if ($repo_result->get_error_code() === 'repo_exists') {
                    $github_data = ['owner_repo' => $owner_repo];
                    $warnings[]  = 'GitHub repo already exists — skipped creation.';
                } else {
                    $warnings[] = 'GitHub repo: ' . $repo_result->get_error_message();
                }
            } else {
                $github_data = $repo_result;
                $owner_repo  = $repo_result['owner_repo'];

                // Step 2: Push code to repo.
                $push_result = GitHub::pushScaffold($repo_result['owner_repo'], $plugin_path);

                if (is_wp_error($push_result)) {
                    $warnings[] = 'GitHub push: ' . $push_result->get_error_message();
                }
            }
        } else {
            $warnings[] = 'No GitHub write token. Install the GitHub App or configure a write access token.';
        }

        // Step 3: Register on Troy (only if app has Troy configured).
        $app_troy_server = $app['troy']['server_url'] ?? '';

        if ($app_troy_server && get_option('ep_troy_credentials', '')) {
            $troy_result = GitHub::troyRegisterAndConnect($slug, $name, $description, $owner_repo);

            if (is_wp_error($troy_result)) {
                if ($troy_result->get_error_code() !== 'troy_slug_exists') {
                    $warnings[] = 'Troy: ' . $troy_result->get_error_message();
                }
            }
        }

        // Update the persistent registry with connection data.
        $registry_update = [];
        if (!empty($github_data['owner_repo'])) {
            $registry_update['github'] = [
                'owner_repo' => $github_data['owner_repo'],
                'repo_id'    => (string) ($github_data['repo_id'] ?? ''),
                'html_url'   => $github_data['html_url'] ?? '',
            ];
        }
        if (!empty($registry_update)) {
            AppRegistry::set($slug, $registry_update);
        }
        AppUpdateProvider::flush();

        $updated_app = AppDiscovery::parseApp($slug, $plugin_path . '/examplepress.json', $plugin_path);

        return rest_ensure_response([
            'success'  => true,
            'message'  => empty($warnings) ? "App \"{$name}\" connected to GitHub." : "App \"{$name}\" partially connected.",
            'app'      => $updated_app,
            'warnings' => $warnings,
            'github'   => $github_data,
        ]);
    }

    // ── Health ───────────────────────────────────────────────────────

    public static function health(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $apps = AppDiscovery::scan();
        $app  = null;

        foreach ($apps as $a) {
            if ($a['slug'] === $slug) {
                $app = $a;
                break;
            }
        }

        if (!$app) {
            return new \WP_Error('app_not_found', 'App not found.', ['status' => 404]);
        }

        $health = [
            'slug'   => $slug,
            'name'   => $app['name'],
            'local'  => [
                'active'      => $app['active'],
                'version'     => $app['version'],
                'status'      => $app['status'],
                'plugin_file' => $app['plugin_file'],
            ],
            'troy'   => null,
            'github' => null,
        ];

        // Troy health (only when app has Troy configured).
        $troy_server = $app['troy']['server_url'] ?? '';

        if ($troy_server) {
            $troy_auth = get_option('ep_troy_credentials', '');

            if ($troy_auth) {
                $troy_url = 'https://' . rtrim($troy_server, '/');

                $troy_response = wp_remote_get(
                    $troy_url . '/wp-json/troy-server/v1/plugins/manage/health?' . http_build_query(['slug' => $slug]),
                    [
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode($troy_auth),
                            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                            'Accept'        => 'application/json',
                        ],
                        'timeout' => 15,
                    ]
                );

                if (is_wp_error($troy_response)) {
                    $health['troy'] = [
                        'reachable' => false,
                        'error'     => $troy_response->get_error_message(),
                    ];
                } else {
                    $code = wp_remote_retrieve_response_code($troy_response);
                    $body = json_decode(wp_remote_retrieve_body($troy_response), true);

                    if ($code === 200 && is_array($body)) {
                        $health['troy'] = array_merge(['reachable' => true], $body);
                    } else {
                        $health['troy'] = [
                            'reachable' => false,
                            'error'     => $body['message'] ?? "HTTP {$code}",
                        ];
                    }
                }
            } else {
                $health['troy'] = [
                    'reachable' => false,
                    'error'     => 'No Troy credentials stored.',
                ];
            }
        }

        // GitHub health.
        $owner_repo = $app['troy']['repo'] ?? '';

        if (!$owner_repo) {
            $org        = get_option('ep_github_org', '');
            $owner_repo = $org ? $org . '/' . $slug : '';
        }

        if ($owner_repo) {
            $token = GitHub::writeToken();

            if (!$token) {
                $token = get_option('ep_troy_github_pat', '');
            }

            if ($token) {
                $gh_response = wp_remote_get(
                    "https://api.github.com/repos/{$owner_repo}",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                            'Accept'        => 'application/vnd.github+json',
                            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                        ],
                        'timeout' => 10,
                    ]
                );

                if (is_wp_error($gh_response)) {
                    $health['github'] = [
                        'reachable'  => false,
                        'owner_repo' => $owner_repo,
                        'error'      => $gh_response->get_error_message(),
                    ];
                } else {
                    $code = wp_remote_retrieve_response_code($gh_response);
                    $body = json_decode(wp_remote_retrieve_body($gh_response), true);

                    if ($code === 200) {
                        $release_resp = wp_remote_get(
                            "https://api.github.com/repos/{$owner_repo}/releases/latest",
                            [
                                'headers' => [
                                    'Authorization' => "Bearer {$token}",
                                    'Accept'        => 'application/vnd.github+json',
                                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                                ],
                                'timeout' => 10,
                            ]
                        );

                        $latest_tag = null;
                        if (!is_wp_error($release_resp) && wp_remote_retrieve_response_code($release_resp) === 200) {
                            $rel_body   = json_decode(wp_remote_retrieve_body($release_resp), true);
                            $latest_tag = $rel_body['tag_name'] ?? null;
                        }

                        $health['github'] = [
                            'reachable'      => true,
                            'owner_repo'     => $owner_repo,
                            'private'        => $body['private'] ?? false,
                            'default_branch' => $body['default_branch'] ?? '',
                            'latest_release' => $latest_tag,
                            'html_url'       => $body['html_url'] ?? '',
                            'created_at'     => $body['created_at'] ?? '',
                            'updated_at'     => $body['pushed_at'] ?? '',
                        ];
                    } else {
                        $health['github'] = [
                            'reachable'  => false,
                            'owner_repo' => $owner_repo,
                            'error'      => $body['message'] ?? "HTTP {$code}",
                        ];
                    }
                }
            } else {
                $health['github'] = [
                    'reachable'  => false,
                    'owner_repo' => $owner_repo,
                    'error'      => 'No GitHub token available.',
                ];
            }
        } else {
            $health['github'] = [
                'reachable'  => false,
                'owner_repo' => '',
                'error'      => 'No repository configured.',
            ];
        }

        return rest_ensure_response($health);
    }

    // ── Destroy ─────────────────────────────────────────────────────

    public static function destroy(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug    = $request->get_param('slug');
        $confirm = $request->get_param('confirm');

        // Verify the destruction nonce to confirm explicit user intent.
        if (!wp_verify_nonce($confirm, 'ep_destroy_' . $slug)) {
            return new \WP_Error(
                'invalid_nonce',
                'Confirmation nonce is invalid or expired. Please refresh and try again.',
                ['status' => 403]
            );
        }

        $result = AppRegistry::destroy($slug);
        AppUpdateProvider::flush();

        $all_deleted = empty($result['failed']);

        return rest_ensure_response([
            'success'  => true,
            'message'  => $all_deleted
                ? "App \"{$slug}\" deleted everywhere."
                : "App \"{$slug}\" partially deleted.",
            'deleted'  => $result['deleted'],
            'failed'   => $result['failed'],
            'warnings' => $result['warnings'],
        ]);
    }

    // ── Private: Template Mode Scaffold ─────────────────────────────

    private static function scaffoldTemplateMode(string $slug, string $name, string $desc): \WP_REST_Response|\WP_Error
    {
        $description = $desc ?: 'A companion plugin.';
        $org         = get_option('ep_github_org', 'webmultipliers');
        $warnings    = [];
        $steps       = [];
        $full_name   = $org . '/' . $slug;
        $html_url    = '';
        $repo_id     = 0;

        // Step 1: Create local plugin files.
        $local_result = self::scaffoldLocalMode($slug, $name, $desc);

        if (is_wp_error($local_result)) {
            $warnings[]        = 'Local scaffold: ' . $local_result->get_error_message();
            $steps['scaffold'] = false;
        } else {
            $local_data = $local_result->get_data();
            if (!empty($local_data['success'])) {
                $steps['scaffold'] = true;
            } else {
                $warnings[]        = 'Local scaffold: ' . ($local_data['message'] ?? 'Failed.');
                $steps['scaffold'] = false;
            }
        }

        // Step 2: Create GitHub repo from template.
        $repo_result = Scaffolder::fromTemplate($slug, $description, $org);

        if (is_wp_error($repo_result)) {
            if ($repo_result->get_error_code() === 'repo_exists') {
                $steps['template_create'] = true;
                $warnings[] = $repo_result->get_error_message();
            } else {
                $steps['template_create'] = false;
                $warnings[] = 'GitHub repo: ' . $repo_result->get_error_message();
            }
        } else {
            $steps['template_create'] = true;
            $full_name = $repo_result['full_name'];
            $repo_id   = $repo_result['id'];
            $html_url  = $repo_result['html_url'];
        }

        // Step 3: Replace placeholders in the new repo.
        if (!empty($steps['template_create'])) {
            $replace_result = Scaffolder::replaceRemotePlaceholders($full_name, $slug, $name, $description);

            if (is_wp_error($replace_result)) {
                $warnings[]                   = 'Placeholder replacement: ' . $replace_result->get_error_message();
                $steps['placeholder_replace'] = false;
            } else {
                $steps['placeholder_replace'] = true;
            }
        }

        // Step 4: Create initial release (v0.0.0).
        if (!empty($steps['template_create'])) {
            $release_result = Scaffolder::createInitialRelease($full_name);

            if (is_wp_error($release_result)) {
                $warnings[]               = 'Initial release: ' . $release_result->get_error_message();
                $steps['initial_release'] = false;
            } else {
                $steps['initial_release'] = true;
            }
        }

        // Step 5: Register in persistent app registry.
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

        $registry_data = [
            'name'        => $name,
            'description' => $description,
            'version'     => '0.1.0',
            'source'      => 'scaffolded',
        ];

        if ($html_url || $repo_id) {
            $registry_data['github'] = [
                'owner_repo' => $full_name,
                'repo_id'    => (string) $repo_id,
                'html_url'   => $html_url,
            ];
        }

        AppRegistry::set($slug, $registry_data);
        AppUpdateProvider::flush();

        $app = AppDiscovery::parseApp($slug, $plugin_dir . '/examplepress.json', $plugin_dir);

        return rest_ensure_response([
            'success'        => true,
            'mode'           => 'template',
            'message'        => "App \"{$name}\" created.",
            'app'            => $app,
            'repo_url'       => $html_url,
            'codespaces_url' => $repo_id ? Scaffolder::codespacesUrl($full_name, $repo_id) : '',
            'owner_repo'     => $full_name,
            'warnings'       => $warnings,
            'steps'          => $steps,
        ]);
    }

    // ── Private: Local Mode Scaffold ────────────────────────────────

    private static function scaffoldLocalMode(string $slug, string $name, string $desc): \WP_REST_Response|\WP_Error
    {
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

        $fs = Helpers::filesystem();
        if (!$fs) {
            return new \WP_Error('filesystem_error', 'Could not initialise the WordPress filesystem.', ['status' => 500]);
        }

        if ($fs->is_dir($plugin_dir)) {
            return new \WP_Error('plugin_exists', "A plugin directory \"{$slug}\" already exists.", ['status' => 409]);
        }

        $description = $desc ?: 'A companion plugin.';

        $result = Scaffolder::downloadTemplate($plugin_dir, $slug, $name, $description);

        if (is_wp_error($result)) {
            if ($fs->is_dir($plugin_dir)) {
                $fs->delete($plugin_dir, true);
            }
            return $result;
        }

        AppRegistry::set($slug, [
            'name'        => $name,
            'description' => $description,
            'version'     => '0.1.0',
            'source'      => 'scaffolded',
        ]);

        $app = AppDiscovery::parseApp($slug, $plugin_dir . '/examplepress.json', $plugin_dir);

        return rest_ensure_response([
            'success' => true,
            'mode'    => 'local',
            'message' => "App \"{$name}\" scaffolded. Activate it after adding your routes and template blocks.",
            'app'     => $app,
        ]);
    }
}
