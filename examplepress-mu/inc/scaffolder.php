<?php
/**
 * ExamplePress Scaffolder — GitHub Template Repository
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

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default template repository for scaffold operations.
 *
 * Override via the `ep_app_template_repo` option in the admin
 * Connections tab, or filter with `examplepress_template_repo`.
 */
define( 'EP_DEFAULT_TEMPLATE_REPO', 'webmultipliers/examplepress-theme-app' );

/**
 * Placeholder tokens used in the template repository files.
 */
define( 'EP_SCAFFOLD_PLACEHOLDERS', [ '__NAME__', '__SLUG__', '__DESC__' ] );

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
function examplepress_get_template_repo(): string {
	$repo = get_option( 'ep_app_template_repo', EP_DEFAULT_TEMPLATE_REPO );

	if ( ! $repo ) {
		$repo = EP_DEFAULT_TEMPLATE_REPO;
	}

	return apply_filters( 'examplepress_template_repo', $repo );
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
 * @return array{full_name: string, id: int, html_url: string}|WP_Error
 */
function examplepress_scaffold_from_template( string $slug, string $description, string $owner ) {
	$pat = examplepress_github_get_write_token();

	if ( ! $pat ) {
		return new WP_Error(
			'no_github_token',
			'No GitHub write token available. Install the GitHub App or configure a write access token.'
		);
	}

	$response = wp_remote_post(
		'https://api.github.com/repos/' . examplepress_get_template_repo() . '/generate',
		[
			'headers' => [
				'Authorization' => "Bearer {$pat}",
				'Accept'        => 'application/vnd.github.v3+json',
				'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
			],
			'body'    => wp_json_encode( [
				'owner'       => $owner,
				'name'        => $slug,
				'description' => $description,
				'private'     => true,
			] ),
			'timeout' => 30,
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code === 422 ) {
		$msg = $body['message'] ?? 'Validation failed';
		if ( str_contains( $msg, 'already exists' ) || str_contains( $msg, 'name already exists' ) ) {
			return new WP_Error( 'repo_exists', "Repository \"{$owner}/{$slug}\" already exists." );
		}
		return new WP_Error( 'github_validation', $msg );
	}

	if ( $code !== 201 ) {
		$msg = $body['message'] ?? "GitHub API returned HTTP {$code}.";
		return new WP_Error( 'github_api_error', $msg );
	}

	return [
		'full_name' => $body['full_name'] ?? "{$owner}/{$slug}",
		'id'        => (int) ( $body['id'] ?? 0 ),
		'html_url'  => $body['html_url'] ?? '',
	];
}

/**
 * Replace placeholder tokens in files within a GitHub repository.
 *
 * Uses the Contents API to read, transform, and commit each file.
 * Only processes files that contain at least one placeholder token.
 *
 * @param string $full_name   GitHub "owner/repo" string.
 * @param string $slug        Plugin slug.
 * @param string $name        Plugin display name.
 * @param string $description Plugin description.
 * @return true|WP_Error
 */
function examplepress_scaffold_replace_remote_placeholders(
	string $full_name,
	string $slug,
	string $name,
	string $description
) {
	$pat = examplepress_github_get_write_token();

	if ( ! $pat ) {
		return new WP_Error( 'no_github_token', 'No GitHub write token.' );
	}

	$headers = [
		'Authorization' => "Bearer {$pat}",
		'Accept'        => 'application/vnd.github.v3+json',
		'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
	];

	$base_url = "https://api.github.com/repos/{$full_name}";

	// Resolve the default branch — template repos may use 'development', not 'main'.
	$repo_response = wp_remote_get( $base_url, [
		'headers' => $headers,
		'timeout' => 10,
	] );

	if ( is_wp_error( $repo_response ) ) {
		return $repo_response;
	}

	$repo_data      = json_decode( wp_remote_retrieve_body( $repo_response ), true );
	$default_branch = $repo_data['default_branch'] ?? 'development';

	// Get the full file tree so we know which files to check.
	// GitHub's "generate from template" is async — the repo exists immediately
	// but the tree may take a few seconds to populate. Retry up to 5 times.
	$tree    = [];
	$retries = 5;

	for ( $attempt = 0; $attempt < $retries; $attempt++ ) {
		if ( $attempt > 0 ) {
			sleep( 2 );
		}

		$tree_response = wp_remote_get( "{$base_url}/git/trees/{$default_branch}?recursive=1", [
			'headers' => $headers,
			'timeout' => 15,
		] );

		if ( is_wp_error( $tree_response ) ) {
			return $tree_response;
		}

		$tree_body = json_decode( wp_remote_retrieve_body( $tree_response ), true );
		$tree      = $tree_body['tree'] ?? [];

		if ( ! empty( $tree ) ) {
			break;
		}
	}

	if ( empty( $tree ) ) {
		return new WP_Error( 'empty_tree', 'Repository tree is still empty after waiting. GitHub may be slow — try the Connect button in a moment.' );
	}

	$replacements = [
		'__NAME__' => $name,
		'__SLUG__' => $slug,
		'__DESC__' => $description,
	];

	// Collect files to process (skip directories, images, etc.).
	$processable_extensions = [ 'php', 'json', 'md', 'yml', 'yaml', 'txt', 'xml', 'css', 'js', 'html' ];
	$files_to_check         = [];

	foreach ( $tree as $entry ) {
		if ( $entry['type'] !== 'blob' ) {
			continue;
		}

		$ext = strtolower( pathinfo( $entry['path'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $processable_extensions, true ) ) {
			$files_to_check[] = $entry['path'];
		}
	}

	// Also handle the __SLUG__.php rename.
	$slug_file_path = '__SLUG__.php';
	$has_slug_file  = in_array( $slug_file_path, $files_to_check, true );

	foreach ( $files_to_check as $file_path ) {
		$get_response = wp_remote_get( "{$base_url}/contents/{$file_path}", [
			'headers' => $headers,
			'timeout' => 10,
		] );

		if ( is_wp_error( $get_response ) || wp_remote_retrieve_response_code( $get_response ) !== 200 ) {
			continue;
		}

		$file_data = json_decode( wp_remote_retrieve_body( $get_response ), true );

		if ( empty( $file_data['content'] ) || empty( $file_data['sha'] ) ) {
			continue;
		}

		$content  = base64_decode( $file_data['content'] );
		$replaced = str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$content
		);

		if ( $replaced === $content && $file_path !== $slug_file_path ) {
			continue; // No placeholders found, skip.
		}

		// If this is __SLUG__.php, we need to delete and recreate with new name.
		if ( $file_path === $slug_file_path ) {
			// Create the renamed file.
			$create_response = wp_remote_request( "{$base_url}/contents/{$slug}.php", [
				'method'  => 'PUT',
				'headers' => $headers,
				'body'    => wp_json_encode( [
					'message' => "Rename {$slug_file_path} to {$slug}.php",
					'content' => base64_encode( $replaced ),
				] ),
				'timeout' => 15,
			] );

			if ( is_wp_error( $create_response ) ) {
				continue;
			}

			// Delete the old __SLUG__.php.
			wp_remote_request( "{$base_url}/contents/{$slug_file_path}", [
				'method'  => 'DELETE',
				'headers' => $headers,
				'body'    => wp_json_encode( [
					'message' => "Remove placeholder file {$slug_file_path}",
					'sha'     => $file_data['sha'],
				] ),
				'timeout' => 15,
			] );

			continue;
		}

		// Update file in place.
		wp_remote_request( "{$base_url}/contents/{$file_path}", [
			'method'  => 'PUT',
			'headers' => $headers,
			'body'    => wp_json_encode( [
				'message' => "Replace placeholders in {$file_path}",
				'content' => base64_encode( $replaced ),
				'sha'     => $file_data['sha'],
			] ),
			'timeout' => 15,
		] );
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
 * @param string $full_name GitHub "owner/repo" string.
 * @return true|WP_Error
 */
function examplepress_scaffold_create_initial_release( string $full_name ) {
	$pat = examplepress_github_get_write_token();

	if ( ! $pat ) {
		return new WP_Error( 'no_github_token', 'No GitHub write token.' );
	}

	$response = wp_remote_post(
		"https://api.github.com/repos/{$full_name}/releases",
		[
			'headers' => [
				'Authorization' => "Bearer {$pat}",
				'Accept'        => 'application/vnd.github+json',
				'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'tag_name'               => 'v0.0.0',
				'name'                   => 'v0.0.0',
				'body'                   => 'Initial scaffold release.',
				'draft'                  => false,
				'prerelease'             => false,
				'generate_release_notes' => false,
			] ),
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( $code !== 201 ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return new WP_Error(
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
 * canonical template repo exactly. The template repo is public,
 * so no auth token is needed.
 *
 * @param string $dest        Local destination directory.
 * @param string $slug        Plugin slug.
 * @param string $name        Plugin display name.
 * @param string $description Plugin description.
 * @return true|WP_Error
 */
function examplepress_scaffold_download_template(
	string $dest,
	string $slug,
	string $name,
	string $description
) {
	$headers = [
		'Accept'     => 'application/vnd.github.v3+json',
		'User-Agent' => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
	];

	// Add auth if available (higher rate limits, private template repos).
	$pat = examplepress_github_get_write_token();
	if ( $pat ) {
		$headers['Authorization'] = "Bearer {$pat}";
	}

	$base_url = 'https://api.github.com/repos/' . examplepress_get_template_repo();

	// Resolve default branch.
	$repo_response = wp_remote_get( $base_url, [
		'headers' => $headers,
		'timeout' => 10,
	] );

	if ( is_wp_error( $repo_response ) ) {
		return $repo_response;
	}

	$repo_data      = json_decode( wp_remote_retrieve_body( $repo_response ), true );
	$default_branch = $repo_data['default_branch'] ?? 'development';

	// Get the file tree.
	$tree_response = wp_remote_get( "{$base_url}/git/trees/{$default_branch}?recursive=1", [
		'headers' => $headers,
		'timeout' => 15,
	] );

	if ( is_wp_error( $tree_response ) ) {
		return $tree_response;
	}

	$tree_body = json_decode( wp_remote_retrieve_body( $tree_response ), true );
	$tree      = $tree_body['tree'] ?? [];

	if ( empty( $tree ) ) {
		return new WP_Error( 'empty_tree', 'Template repository tree is empty.' );
	}

	$replacements = [
		'__NAME__' => $name,
		'__SLUG__' => $slug,
		'__DESC__' => $description,
	];

	$processable_extensions = [ 'php', 'json', 'md', 'yml', 'yaml', 'txt', 'xml', 'css', 'js', 'html' ];

	$fs = examplepress_get_filesystem();

	if ( ! $fs ) {
		return new WP_Error( 'filesystem_error', 'Could not initialise the WordPress filesystem.' );
	}

	// Create the destination directory.
	if ( ! $fs->is_dir( $dest ) ) {
		$fs->mkdir( $dest );
	}

	foreach ( $tree as $entry ) {
		if ( $entry['type'] === 'tree' ) {
			// Create subdirectory.
			$dir_path = $dest . '/' . $entry['path'];
			if ( ! $fs->is_dir( $dir_path ) ) {
				wp_mkdir_p( $dir_path );
			}
			continue;
		}

		if ( $entry['type'] !== 'blob' ) {
			continue;
		}

		$file_path = $entry['path'];

		// Download file content.
		$file_response = wp_remote_get( "{$base_url}/contents/{$file_path}?ref={$default_branch}", [
			'headers' => $headers,
			'timeout' => 10,
		] );

		if ( is_wp_error( $file_response ) || wp_remote_retrieve_response_code( $file_response ) !== 200 ) {
			continue;
		}

		$file_data = json_decode( wp_remote_retrieve_body( $file_response ), true );

		if ( empty( $file_data['content'] ) ) {
			continue;
		}

		$content = base64_decode( $file_data['content'] );

		// Replace placeholders in text files.
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $processable_extensions, true ) ) {
			$content = str_replace(
				array_keys( $replacements ),
				array_values( $replacements ),
				$content
			);
		}

		// Handle __SLUG__.php → {slug}.php rename.
		$local_path = $file_path;
		if ( $file_path === '__SLUG__.php' ) {
			$local_path = $slug . '.php';
		}

		$fs->put_contents( $dest . '/' . $local_path, $content );
	}

	return true;
}

/**
 * Build a Codespaces launch URL for a repository.
 *
 * @param string $full_name GitHub "owner/repo" string.
 * @param int    $repo_id   GitHub repo ID.
 * @return string URL to open Codespaces for this repo.
 */
function examplepress_codespaces_url( string $full_name, int $repo_id = 0 ): string {
	if ( $repo_id ) {
		return 'https://github.com/codespaces/new?repo=' . $repo_id;
	}

	return 'https://github.com/codespaces/new?repo=' . urlencode( $full_name );
}
