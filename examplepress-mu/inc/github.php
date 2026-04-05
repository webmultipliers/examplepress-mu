<?php
/**
 * ExamplePress MU — GitHub Integration
 *
 * GitHub App JWT signing, installation tokens, repo creation,
 * scaffold pushing, Troy registration, and write/read token resolution.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load App credentials from theme if available.
$ep_github_app_key_file = get_template_directory() . '/inc/github-app-key.php';
if ( file_exists( $ep_github_app_key_file ) ) {
    require_once $ep_github_app_key_file;
}

// ── GitHub App Authentication ───────────────────────────────────

function examplepress_github_app_is_configured(): bool {
    return defined( 'EP_GITHUB_APP_ID' ) && defined( 'EP_GITHUB_APP_PEM' );
}

function examplepress_github_app_is_installed(): bool {
    return examplepress_github_app_is_configured()
        && (bool) get_option( 'ep_github_app_installation_id', '' );
}

function examplepress_github_app_get_jwt() {
    if ( ! examplepress_github_app_is_configured() ) {
        return new WP_Error( 'no_app_config', 'GitHub App credentials are not configured.' );
    }

    $app_id = EP_GITHUB_APP_ID;
    $pem    = EP_GITHUB_APP_PEM;
    $now    = time();

    $header = examplepress_base64url_encode( wp_json_encode( [
        'alg' => 'RS256',
        'typ' => 'JWT',
    ] ) );

    $payload = examplepress_base64url_encode( wp_json_encode( [
        'iat' => $now - 60,
        'exp' => $now + ( 10 * 60 ),
        'iss' => (string) $app_id,
    ] ) );

    $signature = '';
    $key       = openssl_pkey_get_private( $pem );

    if ( ! $key ) {
        return new WP_Error( 'bad_pem', 'Failed to parse GitHub App private key.' );
    }

    $signed = openssl_sign( "{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256 );

    if ( ! $signed ) {
        return new WP_Error( 'sign_failed', 'Failed to sign JWT.' );
    }

    return "{$header}.{$payload}." . examplepress_base64url_encode( $signature );
}

function examplepress_github_app_get_installation_token() {
    $installation_id = get_option( 'ep_github_app_installation_id', '' );

    if ( ! $installation_id ) {
        return new WP_Error( 'no_installation', 'GitHub App is not installed on any organization.' );
    }

    $cached = get_transient( 'ep_github_app_token' );
    if ( $cached ) {
        return $cached;
    }

    $jwt = examplepress_github_app_get_jwt();

    if ( is_wp_error( $jwt ) ) {
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

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 201 || empty( $body['token'] ) ) {
        $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
        return new WP_Error( 'token_failed', $msg );
    }

    $token = $body['token'];
    set_transient( 'ep_github_app_token', $token, 55 * MINUTE_IN_SECONDS );

    return $token;
}

function examplepress_github_get_write_token(): string {
    if ( examplepress_github_app_is_installed() ) {
        $token = examplepress_github_app_get_installation_token();

        if ( ! is_wp_error( $token ) ) {
            return $token;
        }
    }

    return get_option( 'ep_github_pat', '' );
}

function examplepress_get_troy_read_token(): string {
    return get_option( 'ep_troy_github_pat', '' )
        ?: get_option( 'ep_github_pat', '' );
}

function examplepress_base64url_encode( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

// ── Repo Creation & Push ────────────────────────────────────────

function examplepress_github_create_repo( string $slug, string $description ) {
    $pat = examplepress_github_get_write_token();
    $org = get_option( 'ep_github_org', 'webmultipliers' );

    if ( ! $pat ) {
        return new WP_Error( 'no_github_token', 'No GitHub write token available. Install the GitHub App or configure a write access token.' );
    }

    $response = wp_remote_post( "https://api.github.com/orgs/{$org}/repos", [
        'headers' => [
            'Authorization' => "Bearer {$pat}",
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        ],
        'body'    => wp_json_encode( [
            'name'        => $slug,
            'description' => $description,
            'private'     => false,
            'auto_init'   => false,
        ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 422 && ! empty( $body['errors'] ) ) {
        foreach ( $body['errors'] as $err ) {
            if ( ( $err['message'] ?? '' ) === 'name already exists on this account' ) {
                return new WP_Error( 'repo_exists', "GitHub repo \"{$org}/{$slug}\" already exists." );
            }
        }
    }

    if ( $code !== 201 ) {
        $msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
        return new WP_Error( 'github_api_error', $msg );
    }

    return [
        'owner_repo' => $body['full_name'],
        'repo_id'    => (int) $body['id'],
        'html_url'   => $body['html_url'],
    ];
}

function examplepress_github_push_scaffold( string $owner_repo, string $plugin_path ) {
    $pat = examplepress_github_get_write_token();

    if ( ! $pat ) {
        return new WP_Error( 'no_github_token', 'No GitHub write token available.' );
    }

    $headers = [
        'Authorization' => "Bearer {$pat}",
        'Accept'        => 'application/vnd.github.v3+json',
        'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        'Content-Type'  => 'application/json',
    ];

    $base_url = "https://api.github.com/repos/{$owner_repo}";
    $files = examplepress_github_collect_files( $plugin_path, $plugin_path );

    if ( empty( $files ) ) {
        return new WP_Error( 'no_files', 'No files found in scaffold directory.' );
    }

    $tree_items = [];
    foreach ( $files as $relative_path => $absolute_path ) {
        $content = file_get_contents( $absolute_path );
        if ( $content === false ) {
            continue;
        }

        $blob_response = wp_remote_post( "{$base_url}/git/blobs", [
            'headers' => $headers,
            'body'    => wp_json_encode( [
                'content'  => base64_encode( $content ),
                'encoding' => 'base64',
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $blob_response ) ) {
            return $blob_response;
        }

        $blob = json_decode( wp_remote_retrieve_body( $blob_response ), true );

        if ( empty( $blob['sha'] ) ) {
            $blob_code = wp_remote_retrieve_response_code( $blob_response );
            return new WP_Error( 'blob_failed', "Failed to create blob for {$relative_path}: " . ( $blob['message'] ?? "HTTP {$blob_code}" ) );
        }

        $tree_items[] = [
            'path' => $relative_path,
            'mode' => '100644',
            'type' => 'blob',
            'sha'  => $blob['sha'],
        ];
    }

    $tree_response = wp_remote_post( "{$base_url}/git/trees", [
        'headers' => $headers,
        'body'    => wp_json_encode( [ 'tree' => $tree_items ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $tree_response ) ) {
        return $tree_response;
    }

    $tree = json_decode( wp_remote_retrieve_body( $tree_response ), true );

    if ( empty( $tree['sha'] ) ) {
        $tree_code = wp_remote_retrieve_response_code( $tree_response );
        return new WP_Error( 'tree_failed', "Failed to create git tree: " . ( $tree['message'] ?? "HTTP {$tree_code}" ) );
    }

    $commit_response = wp_remote_post( "{$base_url}/git/commits", [
        'headers' => $headers,
        'body'    => wp_json_encode( [
            'message' => 'Initial scaffold from ExamplePress',
            'tree'    => $tree['sha'],
        ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $commit_response ) ) {
        return $commit_response;
    }

    $commit = json_decode( wp_remote_retrieve_body( $commit_response ), true );

    if ( empty( $commit['sha'] ) ) {
        $commit_code = wp_remote_retrieve_response_code( $commit_response );
        return new WP_Error( 'commit_failed', "Failed to create initial commit: " . ( $commit['message'] ?? "HTTP {$commit_code}" ) );
    }

    $ref_response = wp_remote_post( "{$base_url}/git/refs", [
        'headers' => $headers,
        'body'    => wp_json_encode( [
            'ref' => 'refs/heads/main',
            'sha' => $commit['sha'],
        ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $ref_response ) ) {
        return $ref_response;
    }

    $ref_code = wp_remote_retrieve_response_code( $ref_response );

    if ( $ref_code !== 201 ) {
        $ref_body = json_decode( wp_remote_retrieve_body( $ref_response ), true );
        return new WP_Error( 'ref_failed', $ref_body['message'] ?? 'Failed to create main branch ref.' );
    }

    return true;
}

// ── Troy Integration ────────────────────────────────────────────

function examplepress_troy_register_and_connect(
    string $slug,
    string $name,
    string $description,
    string $owner_repo
) {
    $troy_url  = get_option( 'ep_troy_server_url', '' );
    $troy_auth = get_option( 'ep_troy_credentials', '' );

    if ( ! $troy_url || ! $troy_auth ) {
        return new WP_Error( 'no_troy_config', 'Troy Server URL or credentials are not configured.' );
    }

    $troy_url = rtrim( $troy_url, '/' );

    $response = wp_remote_post( "{$troy_url}/wp-json/troy-server/v1/plugins/manage/provision", [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( $troy_auth ),
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( array_filter( [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'owner_repo'  => $owner_repo,
            'github_pat'  => examplepress_get_troy_read_token(),
        ] ) ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 409 ) {
        $msg = $body['message'] ?? 'Slug already registered on Troy.';
        return new WP_Error( 'troy_slug_exists', $msg );
    }

    if ( $code < 200 || $code >= 300 ) {
        $msg = $body['message'] ?? "Troy API returned HTTP {$code}.";
        return new WP_Error( 'troy_api_error', $msg );
    }

    return $body;
}

function examplepress_update_app_troy_data( string $slug, array $troy_data ): bool {
    $json_path = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';

    if ( ! file_exists( $json_path ) ) {
        return false;
    }

    $config = json_decode( file_get_contents( $json_path ), true );

    if ( ! is_array( $config ) ) {
        return false;
    }

    $config['troy'] = array_merge( $config['troy'] ?? [], $troy_data );

    return (bool) file_put_contents(
        $json_path,
        wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
    );
}

function examplepress_github_collect_files( string $dir, string $base ): array {
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $file ) {
        if ( $file->isDir() ) {
            continue;
        }

        $absolute = $file->getPathname();
        $relative = ltrim( str_replace( $base, '', $absolute ), '/' );
        $files[ $relative ] = $absolute;
    }

    return $files;
}
