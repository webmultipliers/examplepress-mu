<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\GitHub;
use ExamplePress\MU\Infrastructure\PrismContainer;

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
                'agent_provider' => [
                    'type'              => 'string',
                    'description'       => 'Generative UI Agent provider (anthropic|openai).',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'agent_model' => [
                    'type'              => 'string',
                    'description'       => 'Generative UI Agent model id.',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'agent_api_key' => [
                    'type'              => 'string',
                    'description'       => 'Generative UI Agent API key.',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'agent_enabled' => [
                    'type'              => 'boolean',
                    'description'       => 'Whether the Generative UI Agent feature is enabled.',
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

        // CSRF protection for the Troy auth popup round-trip. The admin UI
        // calls this BEFORE opening the popup to mint a one-shot state
        // token, passes the token to Troy as ?state=..., and Troy echoes
        // it back on the callback URL. handleTroyAuthCallback then
        // verifies + consumes it. See the block comment above
        // handleTroyAuthCallback for the full threat model.
        register_rest_route('examplepress-mu/v1', '/connections/troy/prepare-auth', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'prepareTroyAuth'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        // Same CSRF protection for the GitHub App installation callback.
        // GitHub App install URLs support a `state` parameter that GitHub
        // echoes back to the callback, so the same round-trip works:
        //   https://github.com/apps/{app}/installations/new?state={token}
        register_rest_route('examplepress-mu/v1', '/connections/github/prepare-auth', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'prepareGitHubAuth'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  OAuth-state CSRF protection
    // ─────────────────────────────────────────────────────────────────

    /** Transient key prefix for one-shot popup-flow state tokens. */
    private const AUTH_STATE_KEY = 'ep_oauth_state';

    /** Scope identifiers (appended to the transient key for isolation). */
    private const TROY_AUTH_STATE_KEY   = self::AUTH_STATE_KEY . ':troy';
    private const GITHUB_AUTH_STATE_KEY = self::AUTH_STATE_KEY . ':github';

    /** TTL of the state token. Short because the popup flow is synchronous. */
    private const AUTH_STATE_TTL = 5 * 60;

    /**
     * Mint a one-shot state token for an OAuth popup round-trip.
     * Generic over the scope so Troy and GitHub App callbacks share
     * one implementation without the risk of a scope mix-up.
     *
     * @param string $scopeKey One of the *_AUTH_STATE_KEY constants.
     */
    private static function mintAuthState(string $scopeKey): string
    {
        $state  = bin2hex(random_bytes(16));
        $userId = get_current_user_id();

        set_transient(
            $scopeKey . ':' . $userId,
            $state,
            self::AUTH_STATE_TTL
        );

        return $state;
    }

    /**
     * POST /connections/troy/prepare-auth
     *
     * Generate a one-shot state token for the Troy OAuth popup round-trip.
     * The admin UI calls this immediately before opening the Troy auth
     * popup and passes the returned token to Troy as a `state` query
     * parameter. Troy echoes it back on the callback URL and the kernel
     * verifies + consumes it before accepting any credentials.
     *
     * @return \WP_REST_Response
     */
    public static function prepareTroyAuth(): \WP_REST_Response
    {
        return rest_ensure_response([
            'state'      => self::mintAuthState(self::TROY_AUTH_STATE_KEY),
            'expires_in' => self::AUTH_STATE_TTL,
        ]);
    }

    /**
     * POST /connections/github/prepare-auth
     *
     * Generate a one-shot state token for the GitHub App installation
     * popup round-trip. Symmetric with prepareTroyAuth — see the block
     * comment above handleGitHubAppCallback for the threat model.
     *
     * @return \WP_REST_Response
     */
    public static function prepareGitHubAuth(): \WP_REST_Response
    {
        return rest_ensure_response([
            'state'      => self::mintAuthState(self::GITHUB_AUTH_STATE_KEY),
            'expires_in' => self::AUTH_STATE_TTL,
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
            'ep_agent_provider'    => 'agent_provider',
            'ep_agent_model'       => 'agent_model',
            'ep_agent_api_key'     => 'agent_api_key',
        ];

        // Boolean toggle for the agent feature flag (stored as a
        // namespaced option that the FeatureRegistry filter consults).
        $agentEnabled = $request->get_param('agent_enabled');
        if ($agentEnabled !== null) {
            update_option('ep_agent_enabled', (bool) $agentEnabled);
        }

        // Any save of the agent form is an explicit operator "retry" —
        // clear any active cooldown so the next request re-attempts the
        // PrismContainer boot instead of short-circuiting on the stale
        // failure. Agent-form saves are detected by the presence of any
        // agent_* param in the request.
        $agentTouched = $agentEnabled !== null
            || $request->get_param('agent_provider') !== null
            || $request->get_param('agent_model') !== null
            || $request->get_param('agent_api_key') !== null;
        if ($agentTouched) {
            PrismContainer::clearDisabled();
        }

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
    //
    // THREAT MODEL:
    //   This handler is an admin_init endpoint that writes
    //   `ep_troy_credentials`. An attacker who can trick an authenticated
    //   administrator into visiting a URL of the form
    //       /wp-admin/?ep_troy_auth_cb=success&user_login=evil&password=evil
    //   (via a phishing link, an img tag, a meta refresh on an external
    //   site, etc.) would otherwise have the kernel happily overwrite
    //   Troy credentials with attacker-controlled values. That is a
    //   classic CSRF, and "current_user_can" alone doesn't stop it
    //   because the session cookie is sent on any cross-site GET.
    //
    //   We defend by requiring a one-shot state token that must have
    //   been minted via POST /connections/troy/prepare-auth before the
    //   popup was opened. The token is per-user, short-lived (5 min),
    //   and deleted after a single successful consumption, so a crafted
    //   URL with no state (or a stale state) is rejected outright.
    //
    //   SEPARATE ARCHITECTURAL ISSUE not fixed here: passwords arrive
    //   as query-string parameters and end up in web server access
    //   logs, upstream proxies, browser history, and Referer headers.
    //   The only fix is a Troy-side change to POST credentials back
    //   instead of redirecting with them in the URL. Flagged for the
    //   Troy OAuth flow redesign — cannot be fixed from the kernel.

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

        // CSRF: verify + consume the one-shot state token. See the
        // block comment above for the threat model.
        $providedState = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!self::verifyAndConsumeTroyAuthState($providedState)) {
            error_log(sprintf(
                '[ExamplePress ConnectionsController] Troy auth callback rejected — invalid or missing state token (user_id=%d).',
                get_current_user_id()
            ));
            self::closePopup(false, 'Authorization state token is invalid, expired, or missing. Restart the connection flow.');
            return;
        }

        $user_login = sanitize_text_field(wp_unslash($_GET['user_login'] ?? ''));
        $password   = sanitize_text_field(wp_unslash($_GET['password'] ?? ''));

        if (!$user_login || !$password) {
            self::closePopup(false, 'Missing credentials in callback.');
            return;
        }

        update_option('ep_troy_credentials', $user_login . ':' . $password);

        self::closePopup(true, 'Connected to Troy.');
    }

    /**
     * Verify a callback's state token against the per-user + per-scope
     * transient minted by the corresponding prepare*() method. Always
     * deletes the transient on lookup (one-shot semantics) so a replay
     * of the same state fails.
     *
     * A scope-specific legacy-compat filter can accept state-less
     * callbacks during the one-release transition window before the
     * admin UI has been updated to call prepare*. Filters default to
     * FALSE (secure) and should only be flipped with a loud comment
     * about the CSRF risk.
     *
     * @param string $scopeKey   One of the *_AUTH_STATE_KEY constants.
     * @param string $legacyFilter Filter name to allow unverified fallback.
     */
    private static function verifyAndConsumeAuthState(string $scopeKey, string $legacyFilter, string $provided): bool
    {
        $userId       = get_current_user_id();
        $transientKey = $scopeKey . ':' . $userId;
        $expected     = get_transient($transientKey);

        // Always delete — we never want a state to be reusable, even
        // when the provided value didn't match.
        delete_transient($transientKey);

        if (!is_string($expected) || $expected === '') {
            // No pending auth attempt. Legacy fallback is the only way
            // through — intentionally a filter that defaults to false.
            return (bool) apply_filters($legacyFilter, false);
        }

        if ($provided === '') {
            return false;
        }

        // Constant-time compare to defeat timing side-channels. The
        // entropy is 128 bits so practical timing attacks are unlikely,
        // but hash_equals is a cheap defensive habit.
        return hash_equals($expected, $provided);
    }

    /**
     * Back-compat shim — the Troy callback uses the generic helper
     * but keeps its own filter identifier for the legacy fallback.
     */
    private static function verifyAndConsumeTroyAuthState(string $provided): bool
    {
        return self::verifyAndConsumeAuthState(
            self::TROY_AUTH_STATE_KEY,
            'examplepress_mu_allow_unverified_troy_auth',
            $provided
        );
    }

    /**
     * Same for the GitHub App installation callback.
     */
    private static function verifyAndConsumeGitHubAuthState(string $provided): bool
    {
        return self::verifyAndConsumeAuthState(
            self::GITHUB_AUTH_STATE_KEY,
            'examplepress_mu_allow_unverified_github_auth',
            $provided
        );
    }

    // ── GitHub App Callback ─────────────────────────────────────────

    /**
     * Threat model (see handleTroyAuthCallback for the parallel Troy flow):
     *
     * Storing `ep_github_app_installation_id` on a GET callback means an
     * attacker who tricks an admin into visiting
     *   /wp-admin/?ep_github_app_cb=1&installation_id=attacker_controlled
     * can overwrite the kernel's GitHub App pointer. The direct damage
     * is limited — the attacker's installation id won't grant them write
     * access because the kernel generates its own JWT against its own
     * App private key — but it does DoS the integration until an admin
     * re-installs the App. The CSRF fix is the same state-token
     * round-trip as Troy.
     */
    public static function handleGitHubAppCallback(): void
    {
        if (!isset($_GET['ep_github_app_cb'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 403);
        }

        // CSRF: verify + consume the one-shot state token. When the admin
        // UI opens the GitHub App install URL it should include
        // `state=<token>` from POST /connections/github/prepare-auth.
        $providedState = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!self::verifyAndConsumeGitHubAuthState($providedState)) {
            error_log(sprintf(
                '[ExamplePress ConnectionsController] GitHub App callback rejected — invalid or missing state token (user_id=%d).',
                get_current_user_id()
            ));
            self::closePopup(false, 'Authorization state token is invalid, expired, or missing. Restart the connection flow.');
            return;
        }

        $installation_id = sanitize_text_field(wp_unslash($_GET['installation_id'] ?? ''));

        // GitHub installation ids are numeric. Reject anything that
        // isn't — prevents an attacker from storing a non-numeric
        // payload even on the legacy path.
        if (!$installation_id || !ctype_digit($installation_id)) {
            self::closePopup(false, 'No valid installation ID received from GitHub.');
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
