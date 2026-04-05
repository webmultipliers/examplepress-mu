<?php
/**
 * ExamplePress REST API — App Endpoints
 *
 * Handles app listing, scaffolding (GitHub template), Troy binding
 * (opt-in), deactivation, and connection/reconnection.
 */

// ── Build / Scaffold ─────────────────────────────────────────────
//
// One-click orchestration: scaffold locally → create GitHub repo →
// replace placeholders → create initial release → register in
// app registry. Troy registration is opt-in (only when the app's
// examplepress.json declares a troy.server_url). Degrades
// gracefully when credentials are not configured.

// ── App Endpoints ────────────────────────────────────────────────

add_action( 'rest_api_init', 'examplepress_register_app_routes' );

function examplepress_register_app_routes() {
	register_rest_route( 'examplepress/v1', '/apps', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_handle_apps_list',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/apps/scaffold', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_app_scaffold',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'name' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'Human-readable app name.',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					if ( ! is_string( $value ) || strlen( $value ) < 2 || strlen( $value ) > 100 ) {
						return new WP_Error( 'invalid_name', 'Name must be between 2 and 100 characters.' );
					}
					return true;
				},
			],
			'description' => [
				'type'              => 'string',
				'description'       => 'Short description for the app.',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					if ( ! is_string( $value ) || strlen( $value ) > 500 ) {
						return new WP_Error( 'invalid_description', 'Description must be 500 characters or fewer.' );
					}
					return true;
				},
			],
		],
	] );

	register_rest_route( 'examplepress/v1', '/apps/(?P<slug>[a-z0-9-]+)/troy-bind', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_app_troy_bind',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'App slug.',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^[a-z0-9-]+$/', $value );
				},
			],
			'troy_type' => [
				'type'              => 'string',
				'description'       => 'Troy server type.',
				'default'           => 'cloud',
				'enum'              => [ 'cloud', 'custom' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'custom_url' => [
				'type'              => 'string',
				'description'       => 'Custom Troy server URL (required when troy_type is custom).',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			],
		],
	] );

	register_rest_route( 'examplepress/v1', '/apps/(?P<slug>[a-z0-9-]+)/deactivate', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_app_deactivate',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'App slug.',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^[a-z0-9-]+$/', $value );
				},
			],
		],
	] );

	register_rest_route( 'examplepress/v1', '/apps/(?P<slug>[a-z0-9-]+)/connect', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_app_connect',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'App slug.',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^[a-z0-9-]+$/', $value );
				},
			],
		],
	] );

	register_rest_route( 'examplepress/v1', '/apps/(?P<slug>[a-z0-9-]+)/health', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_handle_app_health',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'App slug.',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^[a-z0-9-]+$/', $value );
				},
			],
		],
	] );

	register_rest_route( 'examplepress/v1', '/apps/(?P<slug>[a-z0-9-]+)/destroy', [
		'methods'             => 'DELETE',
		'callback'            => 'examplepress_handle_app_destroy',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'App slug.',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^[a-z0-9-]+$/', $value );
				},
			],
		],
	] );

}

/**
 * List all ExamplePress apps — merges persistent registry with live
 * filesystem state. Includes orphan apps (deleted locally but still
 * on GitHub/Troy).
 */
function examplepress_handle_apps_list() {
	return rest_ensure_response( examplepress_registry_list_merged() );
}

/**
 * Delete an app everywhere: local plugin, GitHub repo, Troy registration.
 */
function examplepress_handle_app_destroy( WP_REST_Request $request ) {
	$slug   = $request->get_param( 'slug' );
	$result = examplepress_destroy_app( $slug );

	$all_deleted = empty( $result['failed'] );

	return rest_ensure_response( [
		'success'  => true,
		'message'  => $all_deleted
			? "App \"{$slug}\" deleted everywhere."
			: "App \"{$slug}\" partially deleted.",
		'deleted'  => $result['deleted'],
		'failed'   => $result['failed'],
		'warnings' => $result['warnings'],
	] );
}

/**
 * Scaffold a new ExamplePress app.
 *
 * Creates a repo from the official GitHub template repository
 * (webmultipliers/examplepress-theme-app), replaces placeholders
 * remotely, creates an initial release, and returns the repo
 * URL + Codespaces link.
 *
 * Requires a GitHub write token.
 */
function examplepress_handle_app_scaffold( WP_REST_Request $request ) {
	$name = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
	$desc = sanitize_text_field( $request->get_param( 'description' ) ?? '' );

	if ( ! $name ) {
		return new WP_Error(
			'missing_name',
			'App name is required.',
			[ 'status' => 400 ]
		);
	}

	$slug = examplepress_slugify_app_name( $name );

	if ( ! $slug ) {
		return new WP_Error(
			'invalid_name',
			'Could not generate a valid slug from the provided name.',
			[ 'status' => 400 ]
		);
	}

	if ( ! examplepress_github_get_write_token() ) {
		return examplepress_scaffold_local_mode( $slug, $name, $desc );
	}

	return examplepress_scaffold_template_mode( $slug, $name, $desc );
}

/**
 * Template mode: scaffold locally, create GitHub repo, replace placeholders, create initial release.
 *
 * The local plugin is created first so the app is immediately visible
 * in the Build tab. The GitHub repo is layered on top — failures in
 * those steps are non-fatal warnings.
 */
function examplepress_scaffold_template_mode( string $slug, string $name, string $desc ) {
	$description = $desc ?: ( function_exists( 'examplepress_get_default_app_description' ) ? examplepress_get_default_app_description() : 'A companion plugin.' );
	$org         = get_option( 'ep_github_org', 'webmultipliers' );
	$warnings    = [];
	$steps       = [];
	$full_name   = $org . '/' . $slug;
	$html_url    = '';
	$repo_id     = 0;

	// ── Step 1: Create local plugin files ───────────────────────

	$local_result = examplepress_scaffold_local_mode( $slug, $name, $desc );

	if ( is_wp_error( $local_result ) ) {
		$warnings[]        = 'Local scaffold: ' . $local_result->get_error_message();
		$steps['scaffold'] = false;
	} else {
		$local_data = $local_result->get_data();
		if ( ! empty( $local_data['success'] ) ) {
			$steps['scaffold'] = true;
		} else {
			$warnings[]        = 'Local scaffold: ' . ( $local_data['message'] ?? 'Failed.' );
			$steps['scaffold'] = false;
		}
	}

	// ── Step 2: Create GitHub repo from template ────────────────

	$repo_result = examplepress_scaffold_from_template( $slug, $description, $org );

	if ( is_wp_error( $repo_result ) ) {
		if ( $repo_result->get_error_code() === 'repo_exists' ) {
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

	// ── Step 3: Replace placeholders in the new repo ────────────

	if ( ! empty( $steps['template_create'] ) ) {
		$replace_result = examplepress_scaffold_replace_remote_placeholders(
			$full_name, $slug, $name, $description
		);

		if ( is_wp_error( $replace_result ) ) {
			$warnings[]                   = 'Placeholder replacement: ' . $replace_result->get_error_message();
			$steps['placeholder_replace'] = false;
		} else {
			$steps['placeholder_replace'] = true;
		}
	}

	// ── Step 4: Create initial release (v0.0.0) ────────────────

	if ( ! empty( $steps['template_create'] ) ) {
		$release_result = examplepress_scaffold_create_initial_release( $full_name );

		if ( is_wp_error( $release_result ) ) {
			$warnings[]             = 'Initial release: ' . $release_result->get_error_message();
			$steps['initial_release'] = false;
		} else {
			$steps['initial_release'] = true;
		}
	}

	// ── Step 5: Register in persistent app registry ───────────

	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

	$registry_data = [
		'name'        => $name,
		'description' => $description,
		'version'     => '0.1.0',
		'source'      => 'scaffolded',
	];

	if ( $full_name && $full_name !== $org . '/' . $slug ) {
		// Only store if we got real data back from GitHub.
	}
	if ( $html_url || $repo_id ) {
		$registry_data['github'] = [
			'owner_repo' => $full_name,
			'repo_id'    => (string) $repo_id,
			'html_url'   => $html_url,
		];
	}

	examplepress_registry_set( $slug, $registry_data );

	// Re-read the app so the response reflects the latest state.
	$app = examplepress_parse_app( $slug, $plugin_dir . '/examplepress.json', $plugin_dir );

	return rest_ensure_response( [
		'success'        => true,
		'mode'           => 'template',
		'message'        => "App \"{$name}\" created.",
		'app'            => $app,
		'repo_url'       => $html_url,
		'codespaces_url' => $repo_id ? examplepress_codespaces_url( $full_name, $repo_id ) : '',
		'owner_repo'     => $full_name,
		'warnings'       => $warnings,
		'steps'          => $steps,
	] );
}

/**
 * Prepare Troy binding for an app.
 *
 * Returns the Troy scaffold URL for client-side redirect.
 * The actual repo provisioning happens on Troy's side.
 */
function examplepress_handle_app_troy_bind( WP_REST_Request $request ) {
	$slug      = $request->get_param( 'slug' );
	$troy_type = sanitize_text_field( $request->get_param( 'troy_type' ) ?? 'cloud' );
	$custom_url = esc_url_raw( $request->get_param( 'custom_url' ) ?? '' );

	$plugin_path = WP_PLUGIN_DIR . '/' . $slug;
	$json_path   = $plugin_path . '/examplepress.json';

	if ( ! file_exists( $json_path ) ) {
		return new WP_Error(
			'app_not_found',
			'App not found or missing examplepress.json.',
			[ 'status' => 404 ]
		);
	}

	$cloud_url   = examplepress_get_troy_cloud_url();
	$troy_server = 'cloud' === $troy_type
		? str_replace( [ 'https://', 'http://' ], '', rtrim( $cloud_url, '/' ) )
		: rtrim( str_replace( [ 'https://', 'http://' ], '', $custom_url ), '/' );

	if ( empty( $troy_server ) ) {
		return new WP_Error(
			'missing_troy_url',
			'Troy server URL is required for custom server binding.',
			[ 'status' => 400 ]
		);
	}

	$redirect_url = 'https://' . $troy_server . '/scaffold?' . http_build_query( [
		'slug'  => $slug,
		'theme' => 'examplepress-theme',
	] );

	return rest_ensure_response( [
		'success'      => true,
		'troy_server'  => $troy_server,
		'redirect_url' => $redirect_url,
	] );
}

/**
 * Deactivate an ExamplePress app.
 */
function examplepress_handle_app_deactivate( WP_REST_Request $request ) {
	$slug = $request->get_param( 'slug' );
	$apps = examplepress_get_apps();
	$app  = null;

	foreach ( $apps as $a ) {
		if ( $a['slug'] === $slug ) {
			$app = $a;
			break;
		}
	}

	if ( ! $app ) {
		return new WP_Error(
			'app_not_found',
			'App not found.',
			[ 'status' => 404 ]
		);
	}

	if ( ! $app['active'] ) {
		return rest_ensure_response( [
			'success' => true,
			'message' => 'App is already inactive.',
		] );
	}

	deactivate_plugins( $app['plugin_file'] );

	return rest_ensure_response( [
		'success' => true,
		'message' => "App \"{$app['name']}\" deactivated.",
	] );
}

/**
 * Connect a disconnected app: create GitHub repo, push code.
 *
 * Runs the same GitHub flow as the scaffold endpoint but on an
 * existing plugin that's already installed locally. Troy registration
 * only fires if the app already has troy.server_url configured.
 */
function examplepress_handle_app_connect( WP_REST_Request $request ) {
	$slug = $request->get_param( 'slug' );
	$apps = examplepress_get_apps();
	$app  = null;

	foreach ( $apps as $a ) {
		if ( $a['slug'] === $slug ) {
			$app = $a;
			break;
		}
	}

	if ( ! $app ) {
		return new WP_Error( 'app_not_found', 'App not found.', [ 'status' => 404 ] );
	}

	// No early return for "connected" apps — this endpoint also heals
	// incomplete connections (e.g. GitHub repo missing).

	$plugin_path = WP_PLUGIN_DIR . '/' . $slug;
	$name        = $app['name'];
	$description = $app['description'] ?: ( function_exists( 'examplepress_get_default_app_description' ) ? examplepress_get_default_app_description() : 'A companion plugin.' );
	$org         = get_option( 'ep_github_org', 'webmultipliers' );
	$owner_repo  = $org . '/' . $slug;
	$warnings    = [];
	$github_data = [];

	// ── Step 1: Create GitHub repo ──────────────────────────────

	$write_token = examplepress_github_get_write_token();

	if ( $write_token ) {
		$repo_result = examplepress_github_create_repo( $slug, $description );

		if ( is_wp_error( $repo_result ) ) {
			if ( $repo_result->get_error_code() === 'repo_exists' ) {
				// Repo already exists — proceed.
				$github_data = [ 'owner_repo' => $owner_repo ];
				$warnings[]  = 'GitHub repo already exists — skipped creation.';
			} else {
				$warnings[] = 'GitHub repo: ' . $repo_result->get_error_message();
			}
		} else {
			$github_data = $repo_result;
			$owner_repo  = $repo_result['owner_repo'];

			// ── Step 2: Push code to repo ───────────────────────

			$push_result = examplepress_github_push_scaffold( $repo_result['owner_repo'], $plugin_path );

			if ( is_wp_error( $push_result ) ) {
				$warnings[] = 'GitHub push: ' . $push_result->get_error_message();
			}
		}
	} else {
		$warnings[] = 'No GitHub write token. Install the GitHub App or configure a write access token.';
	}

	// ── Step 3: Register on Troy (only if app has Troy configured) ──
	// Troy registration is opt-in — only fires when the app's local
	// examplepress.json declares a troy.server_url.

	$app_troy_server = $app['troy']['server_url'] ?? '';

	if ( $app_troy_server && get_option( 'ep_troy_credentials', '' ) ) {
		$troy_url = 'https://' . rtrim( $app_troy_server, '/' );

		$troy_result = examplepress_troy_register_and_connect(
			$slug, $name, $description, $owner_repo
		);

		if ( is_wp_error( $troy_result ) ) {
			if ( $troy_result->get_error_code() !== 'troy_slug_exists' ) {
				$warnings[] = 'Troy: ' . $troy_result->get_error_message();
			}
		}
	}

	// Update the persistent registry with connection data.
	$registry_update = [];
	if ( ! empty( $github_data['owner_repo'] ) ) {
		$registry_update['github'] = [
			'owner_repo' => $github_data['owner_repo'],
			'repo_id'    => (string) ( $github_data['repo_id'] ?? '' ),
			'html_url'   => $github_data['html_url'] ?? '',
		];
	}
	if ( ! empty( $registry_update ) ) {
		examplepress_registry_set( $slug, $registry_update );
	}

	// Re-read the app data.
	$updated_app = examplepress_parse_app( $slug, $plugin_path . '/examplepress.json', $plugin_path );

	return rest_ensure_response( [
		'success'  => true,
		'message'  => empty( $warnings ) ? "App \"{$name}\" connected to GitHub." : "App \"{$name}\" partially connected.",
		'app'      => $updated_app,
		'warnings' => $warnings,
		'github'   => $github_data,
	] );
}

// ── App Health Check ────────────────────────────────────────────

/**
 * Check the health of an app's remote connections (Troy + GitHub).
 *
 * Reads the app's local Troy config, pings the Troy Server health
 * endpoint, and independently verifies the GitHub repo is reachable
 * with the stored credentials.
 *
 * Troy endpoint: GET /wp-json/troy-server/v1/plugins/manage/health?slug={slug}
 */
function examplepress_handle_app_health( WP_REST_Request $request ) {
	$slug = $request->get_param( 'slug' );
	$apps = examplepress_get_apps();
	$app  = null;

	foreach ( $apps as $a ) {
		if ( $a['slug'] === $slug ) {
			$app = $a;
			break;
		}
	}

	if ( ! $app ) {
		return new WP_Error( 'app_not_found', 'App not found.', [ 'status' => 404 ] );
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

	// ── Troy health (only when app has Troy configured) ─────────

	$troy_server = $app['troy']['server_url'] ?? '';

	if ( $troy_server ) {
		$troy_auth = get_option( 'ep_troy_credentials', '' );

		if ( $troy_auth ) {
			$troy_url = 'https://' . rtrim( $troy_server, '/' );

			$troy_response = wp_remote_get(
				$troy_url . '/wp-json/troy-server/v1/plugins/manage/health?' . http_build_query( [ 'slug' => $slug ] ),
				[
					'headers' => [
						'Authorization' => 'Basic ' . base64_encode( $troy_auth ),
						'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
						'Accept'        => 'application/json',
					],
					'timeout' => 15,
				]
			);

			if ( is_wp_error( $troy_response ) ) {
				$health['troy'] = [
					'reachable' => false,
					'error'     => $troy_response->get_error_message(),
				];
			} else {
				$code = wp_remote_retrieve_response_code( $troy_response );
				$body = json_decode( wp_remote_retrieve_body( $troy_response ), true );

				if ( $code === 200 && is_array( $body ) ) {
					$health['troy'] = array_merge( [ 'reachable' => true ], $body );
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
	// When no Troy server is configured, health['troy'] stays null.

	// ── GitHub health ───────────────────────────────────────────

	$owner_repo = $app['troy']['repo'] ?? '';

	if ( ! $owner_repo ) {
		// Try to infer from org + slug.
		$org        = get_option( 'ep_github_org', '' );
		$owner_repo = $org ? $org . '/' . $slug : '';
	}

	if ( $owner_repo ) {
		$token = examplepress_github_get_write_token();

		if ( ! $token ) {
			$token = get_option( 'ep_troy_github_pat', '' );
		}

		if ( $token ) {
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

			if ( is_wp_error( $gh_response ) ) {
				$health['github'] = [
					'reachable'  => false,
					'owner_repo' => $owner_repo,
					'error'      => $gh_response->get_error_message(),
				];
			} else {
				$code = wp_remote_retrieve_response_code( $gh_response );
				$body = json_decode( wp_remote_retrieve_body( $gh_response ), true );

				if ( $code === 200 ) {
					// Fetch latest release tag.
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
					if ( ! is_wp_error( $release_resp ) && wp_remote_retrieve_response_code( $release_resp ) === 200 ) {
						$rel_body   = json_decode( wp_remote_retrieve_body( $release_resp ), true );
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

	return rest_ensure_response( $health );
}

// ── Local Scaffold Mode ─────────────────────────────────────────

/**
 * Scaffold a companion plugin locally by downloading from the template repo.
 *
 * Pulls files from webmultipliers/examplepress-theme-app (public, no token
 * required), replaces placeholders, and writes to wp-content/plugins/.
 * The plugin is created but NOT activated — the developer activates it
 * after configuring routes and template blocks.
 */
function examplepress_scaffold_local_mode( string $slug, string $name, string $desc ) {
	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

	$fs = examplepress_get_filesystem();
	if ( ! $fs ) {
		return new WP_Error( 'filesystem_error', 'Could not initialise the WordPress filesystem.', [ 'status' => 500 ] );
	}

	if ( $fs->is_dir( $plugin_dir ) ) {
		return new WP_Error( 'plugin_exists', "A plugin directory \"{$slug}\" already exists.", [ 'status' => 409 ] );
	}

	$description = $desc ?: ( function_exists( 'examplepress_get_default_app_description' ) ? examplepress_get_default_app_description() : 'A companion plugin.' );

	$result = examplepress_scaffold_download_template(
		$plugin_dir, $slug, $name, $description
	);

	if ( is_wp_error( $result ) ) {
		// Clean up partial directory on failure.
		if ( $fs->is_dir( $plugin_dir ) ) {
			$fs->delete( $plugin_dir, true );
		}
		return $result;
	}

	// Register in persistent app registry.
	examplepress_registry_set( $slug, [
		'name'        => $name,
		'description' => $description,
		'version'     => '0.1.0',
		'source'      => 'scaffolded',
	] );

	$app = examplepress_parse_app( $slug, $plugin_dir . '/examplepress.json', $plugin_dir );

	return rest_ensure_response( [
		'success' => true,
		'mode'    => 'local',
		'message' => "App \"{$name}\" scaffolded. Activate it after adding your routes and template blocks.",
		'app'     => $app,
	] );
}
