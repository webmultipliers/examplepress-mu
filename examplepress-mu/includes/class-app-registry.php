<?php
/**
 * ExamplePress MU — App Registry + CPT
 *
 * Persistent record of every app created through the scaffold flow.
 * Stored as a Custom Post Type (ep_app). The registry is the source
 * of truth for "what apps have been created." Filesystem discovery
 * (class-app-discovery.php) provides the live local state.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── CPT Registration ────────────────────────────────────────────

add_action( 'init', 'examplepress_register_app_cpt' );

function examplepress_register_app_cpt(): void {
    register_post_type( 'ep_app', [
        'labels' => [
            'name'          => __( 'Apps', 'examplepress-mu' ),
            'singular_name' => __( 'App', 'examplepress-mu' ),
        ],
        'public'              => false,
        'show_ui'             => false,
        'show_in_rest'        => false,
        'exclude_from_search' => true,
        'supports'            => [ 'title' ],
        'capability_type'     => 'post',
    ] );
}

// ── CPT Helpers ─────────────────────────────────────────────────

/**
 * Find the CPT post for an app by its plugin slug.
 */
function examplepress_registry_get_post( string $slug ): ?\WP_Post {
    $posts = get_posts( [
        'post_type'      => 'ep_app',
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_key'       => '_ep_plugin_slug',
        'meta_value'     => $slug,
        'no_found_rows'  => true,
    ] );

    return $posts[0] ?? null;
}

/**
 * Convert a CPT post + meta into the record array.
 */
function examplepress_cpt_to_record( \WP_Post $post ): array {
    $slug = get_post_meta( $post->ID, '_ep_plugin_slug', true ) ?: $post->post_name;

    return [
        'slug'        => $slug,
        'name'        => $post->post_title,
        'description' => get_post_meta( $post->ID, '_ep_description', true ) ?: '',
        'version'     => get_post_meta( $post->ID, '_ep_version', true ) ?: '',
        'source'      => get_post_meta( $post->ID, '_ep_source', true ) ?: 'scaffolded',
        'created_at'  => $post->post_date_gmt !== '0000-00-00 00:00:00' ? gmdate( 'c', strtotime( $post->post_date_gmt ) ) : '',
        'updated_at'  => $post->post_modified_gmt !== '0000-00-00 00:00:00' ? gmdate( 'c', strtotime( $post->post_modified_gmt ) ) : '',
        'github'      => [
            'owner_repo' => get_post_meta( $post->ID, '_ep_github_owner_repo', true ) ?: '',
            'repo_id'    => get_post_meta( $post->ID, '_ep_github_repo_id', true ) ?: '',
            'html_url'   => get_post_meta( $post->ID, '_ep_github_html_url', true ) ?: '',
        ],
        'troy'        => [
            'server_url' => get_post_meta( $post->ID, '_ep_troy_server_url', true ) ?: '',
            'repo'       => get_post_meta( $post->ID, '_ep_troy_repo', true ) ?: '',
            'repo_id'    => get_post_meta( $post->ID, '_ep_troy_repo_id', true ) ?: '',
        ],
    ];
}

/**
 * Write record data to post meta. Only updates keys present in $data.
 */
function examplepress_cpt_write_meta( int $post_id, array $data ): void {
    $flat_map = [
        'description' => '_ep_description',
        'version'     => '_ep_version',
        'source'      => '_ep_source',
    ];

    foreach ( $flat_map as $key => $meta_key ) {
        if ( isset( $data[ $key ] ) ) {
            update_post_meta( $post_id, $meta_key, $data[ $key ] );
        }
    }

    if ( isset( $data['github'] ) && is_array( $data['github'] ) ) {
        $github_map = [
            'owner_repo' => '_ep_github_owner_repo',
            'repo_id'    => '_ep_github_repo_id',
            'html_url'   => '_ep_github_html_url',
        ];
        foreach ( $github_map as $key => $meta_key ) {
            if ( isset( $data['github'][ $key ] ) ) {
                update_post_meta( $post_id, $meta_key, $data['github'][ $key ] );
            }
        }
    }

    if ( isset( $data['troy'] ) && is_array( $data['troy'] ) ) {
        $troy_map = [
            'server_url' => '_ep_troy_server_url',
            'repo'       => '_ep_troy_repo',
            'repo_id'    => '_ep_troy_repo_id',
        ];
        foreach ( $troy_map as $key => $meta_key ) {
            if ( isset( $data['troy'][ $key ] ) ) {
                update_post_meta( $post_id, $meta_key, $data['troy'][ $key ] );
            }
        }
    }
}

// ── CRUD ──────────────────────────────────────────────────────────

function examplepress_registry_all(): array {
    $posts = get_posts( [
        'post_type'      => 'ep_app',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'no_found_rows'  => true,
    ] );

    $registry = [];

    foreach ( $posts as $post ) {
        $record = examplepress_cpt_to_record( $post );
        $registry[ $record['slug'] ] = $record;
    }

    return $registry;
}

function examplepress_registry_get( string $slug ): ?array {
    $post = examplepress_registry_get_post( $slug );

    if ( ! $post ) {
        return null;
    }

    return examplepress_cpt_to_record( $post );
}

function examplepress_registry_set( string $slug, array $data ): array {
    $post = examplepress_registry_get_post( $slug );

    if ( $post ) {
        $update_args = [ 'ID' => $post->ID ];

        if ( isset( $data['name'] ) && $data['name'] !== $post->post_title ) {
            $update_args['post_title'] = $data['name'];
        }

        if ( count( $update_args ) > 1 ) {
            wp_update_post( $update_args );
        }

        examplepress_cpt_write_meta( $post->ID, $data );

        return examplepress_cpt_to_record( get_post( $post->ID ) );
    }

    $post_id = wp_insert_post( [
        'post_type'   => 'ep_app',
        'post_title'  => $data['name'] ?? $slug,
        'post_name'   => $slug,
        'post_status' => 'draft',
    ] );

    if ( is_wp_error( $post_id ) ) {
        return array_merge( [ 'slug' => $slug ], $data );
    }

    update_post_meta( $post_id, '_ep_plugin_slug', $slug );
    examplepress_cpt_write_meta( $post_id, $data );

    return examplepress_cpt_to_record( get_post( $post_id ) );
}

function examplepress_registry_forget( string $slug ): bool {
    $post = examplepress_registry_get_post( $slug );

    if ( ! $post ) {
        return false;
    }

    wp_delete_post( $post->ID, true );

    return true;
}

// ── Merged Queries ───────────────────────────────────────────────

function examplepress_registry_list_merged(): array {
    $registry  = examplepress_registry_all();
    $local_apps = examplepress_get_apps();

    $local_by_slug = [];
    foreach ( $local_apps as $app ) {
        $local_by_slug[ $app['slug'] ] = $app;
    }

    $merged = [];

    foreach ( $registry as $slug => $record ) {
        $local = $local_by_slug[ $slug ] ?? null;
        $merged[] = examplepress_registry_merge_record( $record, $local );
        unset( $local_by_slug[ $slug ] );
    }

    foreach ( $local_by_slug as $slug => $local ) {
        $adopted = [
            'slug'        => $slug,
            'name'        => $local['name'],
            'description' => $local['description'],
            'version'     => $local['version'] ?? '',
            'source'      => 'discovered',
        ];

        if ( ! empty( $local['troy']['server_url'] ) ) {
            $adopted['troy'] = [
                'server_url' => $local['troy']['server_url'],
                'repo'       => $local['troy']['repo'] ?? '',
                'repo_id'    => $local['troy']['repo_id'] ?? '',
            ];
        }

        examplepress_registry_set( $slug, $adopted );
        $merged[] = examplepress_registry_merge_record( $adopted, $local );
    }

    return $merged;
}

function examplepress_registry_merge_record( array $record, ?array $local ): array {
    $has_local = $local !== null;

    $troy_server = $has_local
        ? ( $local['troy']['server_url'] ?? $record['troy']['server_url'] ?? '' )
        : ( $record['troy']['server_url'] ?? '' );
    $troy_repo = $has_local
        ? ( $local['troy']['repo'] ?? $record['troy']['repo'] ?? '' )
        : ( $record['troy']['repo'] ?? '' );
    $troy_repo_id = $has_local
        ? ( $local['troy']['repo_id'] ?? $record['troy']['repo_id'] ?? '' )
        : ( $record['troy']['repo_id'] ?? '' );

    $github_repo    = $record['github']['owner_repo'] ?? '';
    $github_repo_id = $record['github']['repo_id'] ?? '';
    $github_url     = $record['github']['html_url'] ?? '';

    $has_github = ! empty( $github_repo );
    $has_troy   = ! empty( $troy_server );

    $source = $record['source'] ?? 'scaffolded';

    return [
        'slug'        => $record['slug'],
        'name'        => $has_local ? $local['name'] : ( $record['name'] ?? $record['slug'] ),
        'description' => $has_local ? $local['description'] : ( $record['description'] ?? '' ),
        'version'     => $has_local ? $local['version'] : ( $record['version'] ?? '' ),
        'created_at'  => $record['created_at'] ?? '',
        'source'      => $source,
        'local' => [
            'installed'   => $has_local,
            'active'      => $has_local && $local['active'],
            'plugin_file' => $has_local ? $local['plugin_file'] : '',
        ],
        'github' => [
            'owner_repo' => $github_repo,
            'repo_id'    => $github_repo_id,
            'html_url'   => $github_url,
        ],
        'troy' => [
            'server_url' => $troy_server,
            'repo'       => $troy_repo,
            'repo_id'    => $troy_repo_id,
        ],
        'status' => $has_local && $has_github
            ? 'connected'
            : ( $has_local ? 'disconnected' : 'orphan' ),
        'active' => $has_local && $local['active'],
        'routing' => $has_local ? ( $local['routing'] ?? [] ) : [],
        'orphan' => ! $has_local && ( $has_github || $has_troy ),
        'exists' => [
            'local'  => $has_local,
            'github' => $has_github,
            'troy'   => $has_troy,
        ],
    ];
}

// ── Destructive Operations ────────────────────────────────────────

function examplepress_destroy_app( string $slug ): array {
    $record  = examplepress_registry_get( $slug );
    $deleted = [];
    $failed  = [];
    $warnings = [];

    $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

    // 1. Deactivate + delete local plugin.
    if ( is_dir( $plugin_dir ) ) {
        $plugin_file = $slug . '/' . $slug . '.php';

        if ( is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
        }

        $fs = examplepress_get_filesystem();
        if ( $fs && $fs->delete( $plugin_dir, true ) ) {
            $deleted[] = 'local';
        } else {
            $failed[] = 'local';
            $warnings[] = 'Could not delete plugin directory.';
        }
    }

    // 2. Delete GitHub repo.
    $owner_repo = $record['github']['owner_repo'] ?? '';

    if ( $owner_repo ) {
        $pat = examplepress_github_get_write_token();

        if ( $pat ) {
            $response = wp_remote_request( "https://api.github.com/repos/{$owner_repo}", [
                'method'  => 'DELETE',
                'headers' => [
                    'Authorization' => "Bearer {$pat}",
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
                ],
                'timeout' => 15,
            ] );

            $code = wp_remote_retrieve_response_code( $response );

            if ( $code === 204 || $code === 404 ) {
                $deleted[] = 'github';
            } else {
                $failed[] = 'github';
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $warnings[] = 'GitHub delete: ' . ( $body['message'] ?? "HTTP {$code}" );
            }
        } else {
            $failed[] = 'github';
            $warnings[] = 'No GitHub write token — cannot delete repo.';
        }
    }

    // 3. Unregister from Troy.
    $troy_server = $record['troy']['server_url'] ?? '';

    if ( $troy_server ) {
        $troy_url  = 'https://' . $troy_server;
        $troy_auth = get_option( 'ep_troy_credentials', '' );

        if ( $troy_auth ) {
            $response = wp_remote_request(
                "{$troy_url}/wp-json/troy-server/v1/plugins/manage/unregister",
                [
                    'method'  => 'POST',
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode( $troy_auth ),
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode( [ 'slug' => $slug ] ),
                    'timeout' => 15,
                ]
            );

            $code = wp_remote_retrieve_response_code( $response );

            if ( $code >= 200 && $code < 300 || $code === 404 ) {
                $deleted[] = 'troy';
            } else {
                $failed[] = 'troy';
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $warnings[] = 'Troy unregister: ' . ( $body['message'] ?? "HTTP {$code}" );
            }
        } else {
            $failed[] = 'troy';
            $warnings[] = 'No Troy credentials — cannot unregister.';
        }
    }

    // 4. Remove from registry.
    examplepress_registry_forget( $slug );

    return [
        'deleted'  => $deleted,
        'failed'   => $failed,
        'warnings' => $warnings,
    ];
}
