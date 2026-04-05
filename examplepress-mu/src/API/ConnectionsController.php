<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\GitHub;

/**
 * Connection settings REST API — save/test GitHub and Troy connections,
 * plus OAuth callback handlers for Troy auth and GitHub App installation.
 */
final class ConnectionsController
{
    public static function register(): void
    {
        register_rest_route('examplepress-mu/v1', '/settings/connections', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'saveConnections'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'github_pat' => [
                    'type'              => 'string',
                    'description'       => 'GitHub personal access token.',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'github_org' => [
                    'type'              => 'string',
                    'description'       => 'GitHub organization slug.',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        if ($value !== null && (!is_string($value) || strlen($value) > 100)) {
                            return new \WP_Error('invalid_org', 'Organization must be 100 characters or fewer.');
                        }
                        return true;
                    },
                ],
                'app_template_repo' => [
                    'type'              => 'string',
                    'description'       => 'GitHub template repository (owner/repo) used for scaffolding new apps.',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'troy_server_url' => [
                    'type'              => 'string',
                    'description'       => 'Troy Server URL.',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'troy_github_pat' => [
                    'type'              => 'string',
                    'description'       => 'GitHub read token for Troy.',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('examplepress-mu/v1', '/settings/test-github', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'testGitHub'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/settings/test-troy', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'testTroy'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    /**
     * Hook admin_init callbacks for Troy auth and GitHub App installation popups.
     */
    public static function initCallbacks(): void
    {
        add_action('admin_init', [self::class, 'handleTroyAuthCallback']);
        add_action('admin_init', [self::class, 'handleGitHubAppCallback']);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    // ── Save Connections ────────────────────────────────────────────

    public static function saveConnections(\WP_REST_Request $request): \WP_REST_Response
    {
        $fields = [
            'ep_github_pat'        => 'github_pat',
            'ep_github_org'        => 'github_org',
            'ep_app_template_repo' => 'app_template_repo',
            'ep_troy_server_url'   => 'troy_server_url',
            'ep_troy_github_pat'   => 'troy_github_pat',
        ];

        $updated = [];

        foreach ($fields as $option_key => $param_key) {
            $value = $request->get_param($param_key);

            if ($value === null) {
                continue;
            }

            $value = sanitize_text_field($value);

            // Normalize Troy URL to always have https://.
            if ($option_key === 'ep_troy_server_url' && $value) {
                if (!str_starts_with($value, 'http')) {
                    $value = 'https://' . $value;
                }
                $value = rtrim($value, '/');
            }

            update_option($option_key, $value);
            $updated[] = $param_key;
        }

        return rest_ensure_response([
            'success' => true,
            'updated' => $updated,
            'message' => 'Connection settings saved.',
        ]);
    }

    // ── Test GitHub ─────────────────────────────────────────────────

    public static function testGitHub(\WP_REST_Request $request): \WP_REST_Response
    {
        $org    = get_option('ep_github_org', 'webmultipliers');
        $checks = [];

        // Test write token.
        $write_token = GitHub::writeToken();

        if (!$write_token) {
            $checks['write'] = [
                'ok'      => false,
                'message' => 'No write token. Install the GitHub App or enter a write access token.',
            ];
        } else {
            // Step 1: Check token identity.
            $user_resp = wp_remote_get('https://api.github.com/user', [
                'headers' => [
                    'Authorization' => "Bearer {$write_token}",
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 10,
            ]);

            if (is_wp_error($user_resp)) {
                $checks['write'] = ['ok' => false, 'message' => 'Network error: ' . $user_resp->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($user_resp);

                if ($code !== 200) {
                    $body = json_decode(wp_remote_retrieve_body($user_resp), true);
                    $checks['write'] = ['ok' => false, 'message' => 'Auth failed: ' . ($body['message'] ?? "HTTP {$code}")];
                } else {
                    $user_body = json_decode(wp_remote_retrieve_body($user_resp), true);
                    $login     = $user_body['login'] ?? '?';

                    // Step 2: Check org membership + role.
                    $mem_resp = wp_remote_get("https://api.github.com/orgs/{$org}/memberships/{$login}", [
                        'headers' => [
                            'Authorization' => "Bearer {$write_token}",
                            'Accept'        => 'application/vnd.github.v3+json',
                            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                        ],
                        'timeout' => 10,
                    ]);

                    $mem_info = '';
                    if (!is_wp_error($mem_resp) && wp_remote_retrieve_response_code($mem_resp) === 200) {
                        $mem_body = json_decode(wp_remote_retrieve_body($mem_resp), true);
                        $role     = $mem_body['role'] ?? 'unknown';
                        $state    = $mem_body['state'] ?? 'unknown';
                        $mem_info = " Role: {$role}. State: {$state}.";
                    }

                    // Step 3: Try listing org repos.
                    $repo_resp = wp_remote_get("https://api.github.com/orgs/{$org}/repos?per_page=1&type=all", [
                        'headers' => [
                            'Authorization' => "Bearer {$write_token}",
                            'Accept'        => 'application/vnd.github.v3+json',
                            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                        ],
                        'timeout' => 10,
                    ]);

                    $repo_info = '';
                    if (!is_wp_error($repo_resp)) {
                        $repo_code = wp_remote_retrieve_response_code($repo_resp);
                        if ($repo_code === 200) {
                            $repo_info = ' Can list repos.';
                        } else {
                            $rbody     = json_decode(wp_remote_retrieve_body($repo_resp), true);
                            $repo_info = ' Repo list: ' . ($rbody['message'] ?? "HTTP {$repo_code}");
                        }
                    }

                    // Step 4: Test repo creation with a dry-run-like approach.
                    $create_resp = wp_remote_post("https://api.github.com/orgs/{$org}/repos", [
                        'headers' => [
                            'Authorization' => "Bearer {$write_token}",
                            'Accept'        => 'application/vnd.github.v3+json',
                            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                            'Content-Type'  => 'application/json',
                        ],
                        'body'    => wp_json_encode([
                            'name'        => '.ep-test-' . wp_rand(1000, 9999),
                            'description' => 'ExamplePress connection test — will be deleted.',
                            'private'     => true,
                            'auto_init'   => false,
                        ]),
                        'timeout' => 15,
                    ]);

                    $create_info = '';
                    $can_create  = false;

                    if (!is_wp_error($create_resp)) {
                        $create_code = wp_remote_retrieve_response_code($create_resp);
                        $create_body = json_decode(wp_remote_retrieve_body($create_resp), true);

                        if ($create_code === 201) {
                            $can_create  = true;
                            $create_info = ' Can create repos.';
                            $test_repo = $create_body['full_name'] ?? '';
                            if ($test_repo) {
                                wp_remote_request("https://api.github.com/repos/{$test_repo}", [
                                    'method'  => 'DELETE',
                                    'headers' => [
                                        'Authorization' => "Bearer {$write_token}",
                                        'Accept'        => 'application/vnd.github.v3+json',
                                        'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                                    ],
                                    'timeout' => 10,
                                ]);
                            }
                        } elseif ($create_code === 403) {
                            $create_info = ' CANNOT create repos: ' . ($create_body['message'] ?? 'Forbidden.');
                        } elseif ($create_code === 422) {
                            $can_create  = true;
                            $create_info = ' Can create repos (name validation confirmed access).';
                        } else {
                            $create_info = ' Repo create test: ' . ($create_body['message'] ?? "HTTP {$create_code}");
                        }
                    }

                    $checks['write'] = [
                        'ok'      => $can_create,
                        'message' => "User: {$login}.{$mem_info}{$repo_info}{$create_info}",
                    ];
                }
            }
        }

        // Test read token (for Troy).
        $read_token = get_option('ep_troy_github_pat', '');

        if (!$read_token) {
            $checks['read'] = ['ok' => null, 'message' => 'No Troy read token. Will fall back to write token.'];
        } else {
            $response = wp_remote_get('https://api.github.com/user', [
                'headers' => [
                    'Authorization' => "Bearer {$read_token}",
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                $checks['read'] = ['ok' => false, 'message' => 'Network error: ' . $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200) {
                    $login = $body['login'] ?? '?';
                    $checks['read'] = ['ok' => true, 'message' => "Authenticated as {$login}."];
                } else {
                    $msg = $body['message'] ?? "HTTP {$code}";
                    $checks['read'] = ['ok' => false, 'message' => $msg];
                }
            }
        }

        $all_ok = !empty($checks['write']['ok']);

        return rest_ensure_response([
            'success' => $all_ok,
            'checks'  => $checks,
            'message' => $all_ok ? 'GitHub connection OK.' : 'GitHub connection has issues.',
        ]);
    }

    // ── Test Troy ───────────────────────────────────────────────────

    public static function testTroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $troy_url  = get_option('ep_troy_server_url', '');
        $troy_auth = get_option('ep_troy_credentials', '');
        $checks    = [];

        if (!$troy_url) {
            return rest_ensure_response([
                'success' => false,
                'checks'  => ['url' => ['ok' => false, 'message' => 'No Troy Server URL configured.']],
                'message' => 'No Troy Server URL configured.',
            ]);
        }

        $troy_url = rtrim($troy_url, '/');

        // Check if the REST API is reachable.
        $discovery = wp_remote_get("{$troy_url}/wp-json/", [
            'timeout' => 15,
            'headers' => ['User-Agent' => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION],
        ]);

        if (is_wp_error($discovery)) {
            $checks['url'] = ['ok' => false, 'message' => 'Cannot reach server: ' . $discovery->get_error_message()];
        } else {
            $code = wp_remote_retrieve_response_code($discovery);
            if ($code === 200) {
                $body = json_decode(wp_remote_retrieve_body($discovery), true);
                $name = $body['name'] ?? 'Unknown';
                $checks['url'] = ['ok' => true, 'message' => "Reachable. Site: {$name}."];
            } else {
                $checks['url'] = ['ok' => false, 'message' => "Server returned HTTP {$code}."];
            }
        }

        // Check authentication.
        if (!$troy_auth) {
            $checks['auth'] = ['ok' => false, 'message' => 'No credentials. Click "Authorize with Troy".'];
        } else {
            $auth_resp = wp_remote_get("{$troy_url}/wp-json/wp/v2/users/me?context=edit", [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($troy_auth),
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($auth_resp)) {
                $checks['auth'] = ['ok' => false, 'message' => 'Network error: ' . $auth_resp->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($auth_resp);
                $body = json_decode(wp_remote_retrieve_body($auth_resp), true);

                if ($code === 200 && !empty($body['id'])) {
                    $user = $body['name'] ?? $body['slug'] ?? '?';
                    $checks['auth'] = ['ok' => true, 'message' => "Authenticated as {$user}."];
                } elseif ($code === 401) {
                    $checks['auth'] = ['ok' => false, 'message' => 'Authentication failed. Re-authorize with Troy.'];
                } else {
                    $msg = $body['message'] ?? "HTTP {$code}";
                    $checks['auth'] = ['ok' => false, 'message' => $msg];
                }
            }
        }

        $all_ok = !empty($checks['url']['ok']) && !empty($checks['auth']['ok']);

        return rest_ensure_response([
            'success' => $all_ok,
            'checks'  => $checks,
            'message' => $all_ok ? 'Troy connection OK.' : 'Troy connection has issues.',
        ]);
    }

    // ── Troy Auth Callback ──────────────────────────────────────────

    public static function handleTroyAuthCallback(): void
    {
        if (!isset($_GET['ep_troy_auth_cb'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 403);
        }

        if ($_GET['ep_troy_auth_cb'] === 'rejected') {
            self::closePopup(false, 'Authorization was rejected.');
            return;
        }

        $user_login = sanitize_text_field($_GET['user_login'] ?? '');
        $password   = sanitize_text_field($_GET['password'] ?? '');

        if (!$user_login || !$password) {
            self::closePopup(false, 'Missing credentials in callback.');
            return;
        }

        update_option('ep_troy_credentials', $user_login . ':' . $password);

        self::closePopup(true, 'Connected to Troy.');
    }

    // ── GitHub App Callback ─────────────────────────────────────────

    public static function handleGitHubAppCallback(): void
    {
        if (!isset($_GET['ep_github_app_cb'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 403);
        }

        $installation_id = sanitize_text_field($_GET['installation_id'] ?? '');

        if (!$installation_id) {
            self::closePopup(false, 'No installation ID received from GitHub.');
            return;
        }

        update_option('ep_github_app_installation_id', $installation_id);
        delete_transient('ep_github_app_token');

        self::closePopup(true, 'GitHub App installed.');
    }

    // ── Private: Close Popup ────────────────────────────────────────

    private static function closePopup(bool $success, string $message): void
    {
        $data = wp_json_encode([
            'success' => $success,
            'message' => $message,
        ]);

        $origin = home_url('', 'https');
        $parsed = wp_parse_url($origin);
        $target_origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (!empty($parsed['port'])) {
            $target_origin .= ':' . $parsed['port'];
        }

        ?>
        <!DOCTYPE html>
        <html>
        <head><title>ExamplePress Authorization</title></head>
        <body>
        <script>
            if ( window.opener ) {
                window.opener.postMessage(<?php echo $data; ?>, <?php echo wp_json_encode($target_origin); ?>);
            }
            window.close();
        </script>
        <p><?php echo esc_html($message); ?></p>
        <p><small>This window should close automatically. If it doesn't, you can close it manually.</small></p>
        </body>
        </html>
        <?php
        exit;
    }
}
