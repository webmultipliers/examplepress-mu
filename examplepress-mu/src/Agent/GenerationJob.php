<?php

declare(strict_types=1);

namespace ExamplePress\MU\Agent;

use ExamplePress\MU\Governance\AppValidator;
use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\GitHub;
use ExamplePress\MU\Infrastructure\Helpers;

/**
 * Async LLM generation job runner backed by Action Scheduler.
 *
 * Two decoupled phases, each on its own Action Scheduler hook:
 *
 *   Phase 1 (draft) — HOOK_GENERATE
 *     Call the LLM, merge the partial tree against the parent, run
 *     the validator, stash the payload, mark the job status=drafted.
 *
 *   Phase 2 (commit) — HOOK_COMMIT
 *     Push the merged tree to GitHub, tag a release, update the
 *     AppRegistry record, mark the job status=success.
 *
 * The commit phase runs in a SEPARATE async worker so the user's
 * "Push to GitHub" click can return immediately — a slow GitHub
 * response no longer hangs the REST request until it times out.
 *
 * All job state lives on the ep_app CPT via AppRegistry. There is no
 * options table, no wp_options lock, and no split between "job state"
 * and "draft state" — the post IS the job record.
 */
final class GenerationJob {
	public const HOOK_GENERATE = 'ep_agent_generate';
	public const HOOK_COMMIT   = 'ep_agent_commit';

	/**
	 * Enqueue a new generation job and return its id, or a WP_Error
	 * explaining why the enqueue failed (missing Action Scheduler,
	 * slug collision, etc.).
	 *
	 * @param array{
	 *   prompt:string,
	 *   mode:string,
	 *   target_slug:string,
	 *   app_name?:string,
	 *   app_description?:string,
	 *   user_id?:int,
	 *   auto_commit?:bool,
	 *   error_context?:array<string,mixed>,
	 * } $args
	 */
	public static function enqueue( array $args ): string|\WP_Error {
		// Hard refusal if Action Scheduler isn't loaded. We used to
		// fall through to a synchronous inline handle(), but that
		// blocks the REST request for up to 2 minutes and 504s behind
		// any normal reverse proxy — worse than failing fast.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error(
				'no_scheduler',
				'Action Scheduler is not available. The agent requires an async worker to run LLM calls.',
				[ 'status' => 503 ]
			);
		}

		$opened = AppRegistry::openJob( [
			'mode'            => (string) ( $args['mode'] ?? 'generate' ),
			'prompt'          => (string) ( $args['prompt'] ?? '' ),
			'target_slug'     => (string) ( $args['target_slug'] ?? '' ),
			'app_name'        => (string) ( $args['app_name'] ?? '' ),
			'app_description' => (string) ( $args['app_description'] ?? '' ),
			'user_id'         => (int) ( $args['user_id'] ?? get_current_user_id() ),
			'auto_commit'     => (bool) ( $args['auto_commit'] ?? false ),
			'error_context'   => is_array( $args['error_context'] ?? NULL ) ? $args['error_context'] : NULL,
			'provider'        => (string) get_option( 'ep_agent_provider', 'anthropic' ),
			'model'           => (string) get_option( 'ep_agent_model', '' ),
		] );

		if ( $opened === NULL ) {
			return new \WP_Error(
				'enqueue_failed',
				'Could not open a new job on this slug. The target app may not exist or a published app already owns that slug.',
				[ 'status' => 409 ]
			);
		}

		$jobId = $opened['job_id'];
		self::log( $opened['post_id'], 'Job queued — ' . $args['mode'] . ' on ' . ( $args['target_slug'] ?? '(unknown)' ) );

		as_enqueue_async_action( self::HOOK_GENERATE, [ $jobId ], 'examplepress-agent' );

		return $jobId;
	}

	// ── Phase 1: draft ─────────────────────────────────────────────

	/**
	 * Action Scheduler callback for HOOK_GENERATE. Registered in Kernel::boot().
	 */
	public static function handleGenerate( string $jobId ): void {
		$job = AppRegistry::getJobSnapshot( $jobId );
		if ( ! $job ) {
			return;
		}

		$shutdownHandler = function () use ($jobId) {
			$error = error_get_last();
			if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
				// Only mark as failed if not already failed
				$job = AppRegistry::getJobSnapshot( $jobId );
				if ( $job && $job['status'] !== AppRegistry::STATUS_FAILED ) {
					AppRegistry::failJob( $jobId, 'Job terminated by fatal error or timeout: ' . $error['message'] );
					error_log( "ExamplePress agent job {$jobId} failed due to fatal error or timeout: " . $error['message'] );
				}
			}
		};
		register_shutdown_function( $shutdownHandler );

		try {
			AppRegistry::updateJob( $jobId, [
				'status' => AppRegistry::STATUS_RUNNING,
				'step'   => AppRegistry::STEP_DRAFTING,
			] );
			self::logForJob( $jobId, 'Sending prompt to LLM (' . $job['provider'] . '/' . $job['model'] . ')…' );

			switch ( $job['mode'] ) {
				case 'iterate':
					self::draftIterate( $job );
					break;
				case 'repair':
					self::draftRepair( $job );
					break;
				default:
					self::draftGenerate( $job );
					break;
			}

			// If the user asked for auto-commit and we reached the
			// drafted state successfully, chain into Phase 2.
			$refreshed = AppRegistry::getJobSnapshot( $jobId );
			if ( $refreshed
				&& $refreshed['status'] === AppRegistry::STATUS_DRAFTED
				&& ! empty( $refreshed['auto_commit'] )
			) {
				as_enqueue_async_action( self::HOOK_COMMIT, [ $jobId ], 'examplepress-agent' );
			}
		} catch (\Throwable $e) {
			self::fail( $jobId, $e->getMessage() );
		}
	}

	/**
	 * Action Scheduler callback for HOOK_COMMIT.
	 */
	public static function handleCommit( string $jobId ): void {
		$job = AppRegistry::getJobSnapshot( $jobId );
		if ( ! $job ) {
			return;
		}
		if ( $job['status'] !== AppRegistry::STATUS_DRAFTED ) {
			// Defensive: don't commit something that isn't actually drafted.
			return;
		}

		try {
			AppRegistry::updateJob( $jobId, [
				'status' => AppRegistry::STATUS_RUNNING,
				'step'   => AppRegistry::STEP_PUSHING,
			] );

			if ( $job['mode'] === 'generate' ) {
				self::commitGenerate( $jobId, $job );
			} else {
				self::commitIterate( $jobId, $job );
			}
		} catch (\Throwable $e) {
			self::fail( $jobId, $e->getMessage() );
		}
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private static function draftGenerate( array $job ): void {
		$jobId = (string) $job['id'];
		$slug  = (string) $job['target_slug'];

		$appContext = '';
		if ( $job['app_name'] !== '' ) {
			$appContext .= "\n\nApp Name: {$job['app_name']}";
		}
		if ( $slug !== '' ) {
			$appContext .= "\nApp Slug: {$slug}";
		}
		if ( $job['app_description'] !== '' ) {
			$appContext .= "\nApp Description: {$job['app_description']}";
		}
		$fullPrompt = $job['prompt'] . $appContext;

		$generated = LLMClient::generateApp( $fullPrompt );

		AppRegistry::updateJob( $jobId, [ 'step' => AppRegistry::STEP_WRITING ] );
		self::logForJob( $jobId, 'LLM returned ' . count( $generated->filesChanged ) . ' files — validating…' );

		// Pin the user-controlled identity fields so the LLM can't drift.
		$manifest         = $generated->manifest;
		$manifest['slug'] = $slug;
		if ( $job['app_name'] !== '' ) {
			$manifest['name'] = $job['app_name'];
		}
		if ( $job['app_description'] !== '' ) {
			$manifest['description'] = $job['app_description'];
		}

		// Generate mode: files_changed IS the full tree. No merge
		// needed, and files_deleted has no meaning (empty repo).
		$merged = $generated->filesChanged;

		$result = self::validateAndRepair( $manifest, $merged, $job );
		if ( ! $result['ok'] ) {
			self::stashAndFail( $jobId, $slug, $result['manifest'], $result['files'], $generated->commitMessage, $generated->version, $result['errors'] );
			return;
		}

		self::stashDraft( $jobId, $slug, [
			'manifest'       => $result['manifest'],
			'files'          => $result['files'],
			'files_deleted'  => [],
			'commit_message' => $generated->commitMessage,
			'version'        => $generated->version,
		] );
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private static function draftIterate( array $job ): void {
		$jobId = (string) $job['id'];
		$slug  = (string) $job['target_slug'];

		if ( $slug === '' ) {
			self::fail( $jobId, 'Iteration job missing target_slug.' );
			return;
		}

		self::logForJob( $jobId, 'Loading source files for iteration…' );
		$context = self::loadIterationContext( $slug, $jobId );
		if ( $context === NULL ) {
			return; // fail() already called
		}

		$generated = LLMClient::iterateApp( (string) $job['prompt'], $context['files'], $context['manifest'] );

		AppRegistry::updateJob( $jobId, [ 'step' => AppRegistry::STEP_WRITING ] );
		self::logForJob( $jobId, 'LLM returned ' . count( $generated->filesChanged ) . ' changed / ' . count( $generated->filesDeleted ) . ' deleted — merging…' );

		// Merge the partial tree onto the parent.
		$merged = $generated->mergeOnto( $context['files'] );

		// Pin the slug — iteration must never rename the app.
		$manifest         = $generated->manifest;
		$manifest['slug'] = (string) ( $context['manifest']['slug'] ?? $slug );

		$newVersion          = $generated->version !== '' ? $generated->version : self::bumpPatch( (string) ( $context['manifest']['version'] ?? '1.0.0' ) );
		$manifest['version'] = $newVersion;

		$result = self::validateAndRepair( $manifest, $merged, $job );
		if ( ! $result['ok'] ) {
			self::stashAndFail( $jobId, $slug, $result['manifest'], $result['files'], $generated->commitMessage, $newVersion, $result['errors'], [
				'parent_sha' => $context['parent_sha'],
				'owner_repo' => $context['owner_repo'],
			] );
			return;
		}

		self::stashDraft( $jobId, $slug, [
			'manifest'       => $result['manifest'],
			'files'          => $result['files'],
			'files_deleted'  => $generated->filesDeleted,
			'commit_message' => $generated->commitMessage,
			'version'        => $newVersion,
			'parent_sha'     => $context['parent_sha'],
			'owner_repo'     => $context['owner_repo'],
			'change_summary' => self::computeChangeSummary( $context['files'], $result['files'] ),
		] );
	}

	/**
	 * Persist a validation-failed draft onto the post (so the user can
	 * see which files the LLM produced and run repair) and mark the job failed.
	 *
	 * @param array<string,mixed> $manifest
	 * @param array<int,array{path:string,contents:string}> $files
	 * @param array<int,string> $errors
	 * @param array<string,mixed> $extra
	 */
	private static function stashAndFail(
		string $jobId,
		string $slug,
		array $manifest,
		array $files,
		string $commitMessage,
		string $version,
		array $errors,
		array $extra = []
	): void {
		$taggedFiles = [];
		foreach ( $files as $f ) {
			if ( ! is_array( $f ) )
				continue;
			$taggedFiles[] = [
				'path'     => (string) ( $f['path'] ?? '' ),
				'contents' => (string) ( $f['contents'] ?? '' ),
				'bytes'    => strlen( (string) ( $f['contents'] ?? '' ) ),
			];
		}
		// Always normalize files_deleted
		$filesDeleted = [];
		if ( isset( $extra['files_deleted'] ) && is_array( $extra['files_deleted'] ) ) {
			foreach ( $extra['files_deleted'] as $p ) {
				if ( is_string( $p ) && $p !== '' ) {
					$filesDeleted[] = $p;
				}
			}
		}
		$payload = array_merge( [
			'manifest'       => $manifest,
			'files'          => $taggedFiles,
			'files_deleted'  => $filesDeleted,
			'commit_message' => $commitMessage,
			'version'        => $version,
		], $extra );
		$ok      = AppRegistry::stashDraftPayload( $slug, $payload );
		if ( ! $ok ) {
			$reason = AppRegistry::lastStashError() ?? 'unknown persistence failure';
			self::fail( $jobId, 'Failed to persist failed draft: ' . $reason . ' (original validation errors: ' . implode( ' ', $errors ) . ')' );
			return;
		}
		self::fail( $jobId, 'Validation failed: ' . implode( ' ', $errors ) );
	}

	/**
	 * Commit the generated app to GitHub, create release, and update registry.
	 * @param string $jobId
	 * @param array<string,mixed> $job
	 */
	private static function commitGenerate( string $jobId, array $job ): void {
		$draft    = $job['draft'] ?? [];
		$manifest = is_array( $draft['manifest'] ?? NULL ) ? $draft['manifest'] : [];
		$slug     = (string) ( $manifest['slug'] ?? '' );
		$name     = (string) ( $manifest['name'] ?? '' );
		if ( $slug === '' || $name === '' ) {
			self::fail( $jobId, 'Internal error: draft manifest is missing slug or name.' );
			return;
		}
		$description   = (string) ( $manifest['description'] ?? '' );
		$commitMessage = (string) ( $draft['commit_message'] ?? 'Generated by ExamplePress Agent' );
		$version       = (string) ( $draft['version'] ?? '1.0.0' );
		$files         = self::draftFilesForPush( is_array( $draft['files'] ?? NULL ) ? $draft['files'] : [] );

		self::logForJob( $jobId, 'Creating GitHub repo ' . $slug . '…', $slug );
		$repo = GitHub::createRepo( $slug, $description );
		if ( is_wp_error( $repo ) ) {
			self::fail( $jobId, 'GitHub repo: ' . $repo->get_error_message() );
			return;
		}
		$ownerRepo = (string) ( $repo['owner_repo'] ?? '' );
		$repoId    = (string) ( $repo['repo_id'] ?? '' );
		$htmlUrl   = (string) ( $repo['html_url'] ?? '' );
		self::logForJob( $jobId, 'Repo ready: ' . $ownerRepo, $slug );

		self::logForJob( $jobId, 'Pushing ' . count( $files ) . ' files to ' . $ownerRepo . '…', $slug );
		$push = self::pushWithRetry( $ownerRepo, $files, $commitMessage, $jobId, slug: $slug );
		if ( is_wp_error( $push ) ) {
			self::fail( $jobId, 'GitHub push: ' . $push->get_error_message() );
			return;
		}

		self::logForJob( $jobId, 'Creating release v' . $version . '…', $slug );
		$release = GitHub::createRelease(
			ownerRepo: $ownerRepo,
			tag: 'v' . $version,
			name: 'v' . $version,
			body: $commitMessage,
		);
		if ( is_wp_error( $release ) ) {
			self::fail( $jobId, 'GitHub release: ' . $release->get_error_message() );
			return;
		}

		AppRegistry::promoteToPublished( $slug, [
			'owner_repo' => $ownerRepo,
			'repo_id'    => $repoId,
			'html_url'   => $htmlUrl,
		], [
			'commit_sha' => (string) ( $push['commit_sha'] ?? '' ),
		] );
		AppUpdateProvider::flush();
		self::logForJob( $jobId, '✓ Published ' . $slug . ' v' . $version . ' → ' . $htmlUrl, $slug );
	}

	/**
	 * Commit an iterated app to GitHub, create release, and update registry.
	 * @param string $jobId
	 * @param array<string,mixed> $job
	 */
	private static function commitIterate( string $jobId, array $job ): void {
		$slug  = (string) ( $job['target_slug'] ?? '' );
		$draft = $job['draft'];
		if ( $slug === '' || ! is_array( $draft ) ) {
			self::fail( $jobId, 'Internal error: iterate job missing slug or draft.' );
			return;
		}

		$ownerRepo     = (string) ( $draft['owner_repo'] ?? '' );
		$version       = (string) ( $draft['version'] ?? '' );
		$commitMessage = (string) ( $draft['commit_message'] ?? '' );
		$parentSha     = (string) ( $draft['parent_sha'] ?? '' );
		$files         = self::draftFilesForPush( is_array( $draft['files'] ?? NULL ) ? $draft['files'] : [] );
		$filesDeleted  = [];
		if ( isset( $draft['files_deleted'] ) && is_array( $draft['files_deleted'] ) ) {
			foreach ( $draft['files_deleted'] as $p ) {
				if ( is_string( $p ) && $p !== '' ) {
					$filesDeleted[] = $p;
				}
			}
		}

		if ( $version === '' ) {
			self::fail( $jobId, 'Internal error: draft payload missing version.' );
			return;
		}
		if ( $commitMessage === '' ) {
			$commitMessage = 'iterate v' . $version;
		}

		// Iteration against a never-pushed draft — create the repo first, then commit + release.
		if ( $ownerRepo === '' ) {
			$manifest    = is_array( $draft['manifest'] ?? NULL ) ? $draft['manifest'] : [];
			$description = (string) ( $manifest['description'] ?? '' );

			self::logForJob( $jobId, 'Creating GitHub repo ' . $slug . '…', $slug );
			$repo = GitHub::createRepo( $slug, $description );
			if ( is_wp_error( $repo ) ) {
				self::fail( $jobId, 'GitHub repo: ' . $repo->get_error_message() );
				return;
			}
			$ownerRepo = (string) ( $repo['owner_repo'] ?? '' );
			$repoId    = (string) ( $repo['repo_id'] ?? '' );
			$htmlUrl   = (string) ( $repo['html_url'] ?? '' );
			self::logForJob( $jobId, 'Repo ready: ' . $ownerRepo, $slug );

			self::logForJob( $jobId, 'Pushing ' . count( $files ) . ' files to ' . $ownerRepo . '…', $slug );
			$push = self::pushWithRetry( $ownerRepo, $files, $commitMessage, $jobId, slug: $slug );
			if ( is_wp_error( $push ) ) {
				self::fail( $jobId, 'GitHub push: ' . $push->get_error_message() );
				return;
			}

			self::logForJob( $jobId, 'Creating release v' . $version . '…', $slug );
			$release = GitHub::createRelease(
				ownerRepo: $ownerRepo,
				tag: 'v' . $version,
				name: 'v' . $version,
				body: $commitMessage,
			);
			if ( is_wp_error( $release ) ) {
				self::fail( $jobId, 'GitHub release: ' . $release->get_error_message() );
				return;
			}

			AppRegistry::promoteToPublished( $slug, [
				'owner_repo' => $ownerRepo,
				'repo_id'    => $repoId,
				'html_url'   => $htmlUrl,
			], [
				'commit_sha' => (string) ( $push['commit_sha'] ?? '' ),
			] );
			AppUpdateProvider::flush();
			self::logForJob( $jobId, '✓ Published ' . $slug . ' v' . $version . ' → ' . $htmlUrl, $slug );
			return;
		}

		// Standard path: chain commit onto parent SHA, tag release, record push on the existing post.
		self::logForJob( $jobId, 'Pushing ' . count( $files ) . ' files to ' . $ownerRepo . '…', $slug );
		$push = self::pushWithRetry( $ownerRepo, $files, $commitMessage, $jobId, $parentSha, $filesDeleted, $slug );
		if ( is_wp_error( $push ) ) {
			self::fail( $jobId, 'GitHub push: ' . $push->get_error_message() );
			return;
		}

		self::logForJob( $jobId, 'Creating release v' . $version . '…', $slug );
		$release = GitHub::createRelease(
			ownerRepo: $ownerRepo,
			tag: 'v' . $version,
			name: 'v' . $version,
			body: $commitMessage,
		);
		if ( is_wp_error( $release ) ) {
			self::fail( $jobId, 'GitHub release: ' . $release->get_error_message() );
			return;
		}

		AppRegistry::recordPush( $slug, $version, [
			'owner_repo' => $ownerRepo,
			'commit_sha' => (string) ( $push['commit_sha'] ?? '' ),
		] );
		AppUpdateProvider::flush();
		self::logForJob( $jobId, '✓ Pushed ' . $slug . ' v' . $version, $slug );
		$context = self::loadIterationContext( $slug, $jobId );
		if ( $context === NULL ) {
			return;
		}

		$generated = LLMClient::repairApp(
			(string) $job['prompt'],
			$context['files'],
			$context['manifest'],
			$job['error_context']
		);

		AppRegistry::updateJob( $jobId, [ 'step' => AppRegistry::STEP_WRITING ] );
		self::logForJob( $jobId, 'LLM returned ' . count( $generated->filesChanged ) . ' changed / ' . count( $generated->filesDeleted ) . ' deleted — merging…' );

		$merged = $generated->mergeOnto( $context['files'] );

		$manifest            = $generated->manifest;
		$manifest['slug']    = (string) ( $context['manifest']['slug'] ?? $slug );
		$newVersion          = $generated->version !== '' ? $generated->version : self::bumpPatch( (string) ( $context['manifest']['version'] ?? '1.0.0' ) );
		$manifest['version'] = $newVersion;

		$result = self::validateAndRepair( $manifest, $merged, $job );
		if ( ! $result['ok'] ) {
			self::stashAndFail( $jobId, $slug, $result['manifest'], $result['files'], $generated->commitMessage, $newVersion, $result['errors'], [
				'parent_sha' => $context['parent_sha'],
				'owner_repo' => $context['owner_repo'],
			] );
			return;
		}

		self::stashDraft( $jobId, $slug, [
			'manifest'       => $result['manifest'],
			'files'          => $result['files'],
			'files_deleted'  => $generated->filesDeleted,
			'commit_message' => $generated->commitMessage,
			'version'        => $newVersion,
			'parent_sha'     => $context['parent_sha'],
			'owner_repo'     => $context['owner_repo'],
			'change_summary' => self::computeChangeSummary( $context['files'], $result['files'] ),
		] );
	}

	/**
	 * Resolve the iteration source for a slug. Prefers a pending
	 * stashed draft payload over the live GitHub tree so the user
	 * can iterate on an unpushed draft.
	 *
	 * @return array{
	 *   files:array<int,array{path:string,contents:string}>,
	 *   manifest:array<string,mixed>,
	 *   parent_sha:string,
	 *   owner_repo:string,
	 *   source:string
	 * }|null
	 */
	private static function loadIterationContext( string $slug, string $jobId ): ?array {
		$stashed = AppRegistry::getDraftPayload( $slug );
		if ( is_array( $stashed ) && ! empty( $stashed['files'] ) ) {
			$files = [];
			foreach ( $stashed['files'] as $f ) {
				if ( ! is_array( $f ) )
					continue;
				$files[] = [
					'path'     => (string) ( $f['path'] ?? '' ),
					'contents' => (string) ( $f['contents'] ?? '' ),
				];
			}
			return [
				'files'      => $files,
				'manifest'   => is_array( $stashed['manifest'] ?? NULL ) ? $stashed['manifest'] : [],
				'parent_sha' => (string) ( $stashed['parent_sha'] ?? '' ),
				'owner_repo' => (string) ( $stashed['owner_repo'] ?? '' ),
				'source'     => 'stash',
			];
		}

		$record = AppRegistry::get( $slug );
		if ( ! $record || empty( $record['github']['owner_repo'] ) ) {
			self::fail( $jobId, "App {$slug} has no stashed draft and no GitHub repo to iterate against." );
			return NULL;
		}
		$ownerRepo = (string) $record['github']['owner_repo'];

		$manifestPath = WP_PLUGIN_DIR . '/' . $slug . '/examplepress.json';
		$manifest     = is_readable( $manifestPath )
			? (array) json_decode( (string) file_get_contents( $manifestPath ), true )
			: [];

		$tree = GitHub::fetchRepoTree( $ownerRepo );
		if ( is_wp_error( $tree ) ) {
			self::fail( $jobId, 'Fetch repo tree: ' . $tree->get_error_message() );
			return NULL;
		}

		return [
			'files'      => $tree['files'],
			'manifest'   => $manifest,
			'parent_sha' => (string) $tree['sha'],
			'owner_repo' => $ownerRepo,
			'source'     => 'github',
		];
	}

	/**
	 * Validate a fully merged tree. If validation fails, attempt ONE
	 * automatic repair pass before giving up.
	 *
	 * Critical detail on the repair call: the auto-repair LLM is fed
	 * the MERGED (validation-failed) tree, not the original parent.
	 * The validation errors reference paths and line numbers in the
	 * merged state, so sending the parent tree instead would make
	 * the repair LLM blind to what actually needs fixing. The repair
	 * output is then merged onto the SAME merged tree, so any files
	 * the first attempt correctly produced are preserved.
	 *
	 * @param array<int,array{path:string,contents:string}>  $merged
	 * @param array<string,mixed>                             $job
	 * @return array{ok:bool,manifest:array<string,mixed>,files:array<int,array{path:string,contents:string}>,errors:array<int,string>}
	 */
	private static function validateAndRepair(
		array $manifest,
		array $merged,
		array $job
	): array {
		$jobId = (string) $job['id'];

		$validation = AppValidator::validateGenerated( $manifest, $merged );
		if ( $validation['ok'] ) {
			return [ 'ok' => true, 'manifest' => $manifest, 'files' => $merged, 'errors' => [] ];
		}

		$errorCount = count( $validation['errors'] );
		self::logForJob( $jobId, "⚠ Validation found {$errorCount} error(s) — attempting auto-repair…" );

		$errorMessage = implode( "\n", $validation['errors'] );

		try {
			// Feed the repair LLM the actual broken state (the
			// merged tree), not the original parent. The errors
			// reference this state, not the parent.
			$repaired = LLMClient::repairApp(
				'',
				$merged,
				$manifest,
				[
					'error_message' => "The following validation errors must be fixed:\n\n" . $errorMessage,
					'reported_at'   => time(),
				]
			);

			// Merge the repair output onto the merged tree so any
			// files the first attempt correctly produced survive.
			$repairedMerged           = $repaired->mergeOnto( $merged );
			$repairedManifest         = array_merge( $manifest, $repaired->manifest );
			$repairedManifest['slug'] = $manifest['slug']; // pin slug

			$validation2 = AppValidator::validateGenerated( $repairedManifest, $repairedMerged );
			if ( $validation2['ok'] ) {
				self::logForJob( $jobId, "✓ Auto-repair fixed all {$errorCount} error(s)" );
				return [ 'ok' => true, 'manifest' => $repairedManifest, 'files' => $repairedMerged, 'errors' => [] ];
			}
			$remaining = count( $validation2['errors'] );
			self::logForJob( $jobId, "⚠ Auto-repair resolved " . ( $errorCount - $remaining ) . " of {$errorCount} error(s), {$remaining} remain" );
			return [
				'ok'       => false,
				'manifest' => $repairedManifest,
				'files'    => $repairedMerged,
				'errors'   => $validation2['errors'],
			];
		} catch (\Throwable $e) {
			self::logForJob( $jobId, '⚠ Auto-repair failed: ' . $e->getMessage() );
			return [
				'ok'       => false,
				'manifest' => $manifest,
				'files'    => $merged,
				'errors'   => $validation['errors'],
			];
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function stashDraft( string $jobId, string $slug, array $payload ): void {
		// Tag each file with its size so the preview can render without
		// loading the full contents for every row.
		$files = [];
		if ( isset( $payload['files'] ) && is_array( $payload['files'] ) ) {
			foreach ( $payload['files'] as $f ) {
				if ( ! is_array( $f ) )
					continue;
				$files[] = [
					'path'     => (string) ( $f['path'] ?? '' ),
					'contents' => (string) ( $f['contents'] ?? '' ),
					'bytes'    => strlen( (string) ( $f['contents'] ?? '' ) ),
				];
			}
		}
		$payload['files'] = $files;

		// Normalize files_deleted to a string list so commitIterate can pass it straight to GitHub::pushFiles.
		$deleted = [];
		if ( isset( $payload['files_deleted'] ) && is_array( $payload['files_deleted'] ) ) {
			foreach ( $payload['files_deleted'] as $p ) {
				if ( is_string( $p ) && $p !== '' ) {
					$deleted[] = $p;
				}
			}
		}
		$payload['files_deleted'] = $deleted;

		// Defensive sanity check: generate/iterate should never stash an empty file tree.
		if ( empty( $files ) ) {
			self::fail( $jobId, "Draft payload contains 0 files — the LLM or merge layer produced an empty tree. Try again or rephrase the prompt." );
			return;
		}

		// Breadcrumb BEFORE the persistence attempt so the activity log always shows "we tried to stash N files, X KB".
		$totalBytes = 0;
		foreach ( $files as $f ) {
			$totalBytes += (int) ( $f['bytes'] ?? 0 );
		}
		$humanSize = number_format( $totalBytes / 1024, 1 ) . ' KB';
		self::logForJob( $jobId, 'Persisting draft (' . count( $files ) . ' files, ' . $humanSize . ')…' );

		if ( ! AppRegistry::stashDraftPayload( $slug, $payload ) ) {
			$reason = AppRegistry::lastStashError() ?? 'unknown persistence failure';
			self::fail( $jobId, 'Failed to persist draft: ' . $reason );
			return;
		}

		$deletedSuffix = ! empty( $deleted ) ? ( ' (' . count( $deleted ) . ' deletion' . ( count( $deleted ) === 1 ? '' : 's' ) . ')' ) : '';
		self::logForJob( $jobId, '\u2713 Draft ready for review — ' . count( $files ) . ' files' . $deletedSuffix );
	}

	/**
	 * Push-phase entry point for the user's "Push to GitHub" click.
	 * Does NOT run the push synchronously — it enqueues HOOK_COMMIT
	 * and returns immediately so the REST call can't 504 on a slow
	 * GitHub response.
	 */
	public static function enqueueCommit( string $jobId ): bool|\WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error(
				'no_scheduler',
				'Action Scheduler is not available.',
				[ 'status' => 503 ]
			);
		}

		$job = AppRegistry::getJobSnapshot( $jobId );
		if ( ! $job || $job['status'] !== AppRegistry::STATUS_DRAFTED ) {
			return new \WP_Error(
				'not_drafted',
				'Job is not in a drafted state and cannot be committed.',
				[ 'status' => 409 ]
			);
		}

		// Don't transition to RUNNING here — handleCommit() does that
		// itself and also checks for DRAFTED to prevent double-commits.
		as_enqueue_async_action( self::HOOK_COMMIT, [ $jobId ], 'examplepress-agent' );
		return true;
	}

	/**
	 * Commit directly from a stashed draft (no job context required).
	 * Used when the user resumes a draft after the originating job
	 * was evicted from history by the FIFO cap. Creates a
	 * commit-only synthetic job that the commit handler can run.
	 */
	public static function enqueueCommitFromStash( string $slug ): string|\WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error( 'no_scheduler', 'Action Scheduler is not available.', [ 'status' => 503 ] );
		}

		$payload = AppRegistry::getDraftPayload( $slug );
		if ( ! $payload ) {
			return new \WP_Error( 'no_draft', "No pending draft for {$slug}." );
		}

		// Open a synthetic commit-only job on the post. Mode is picked
		// based on whether the app has a github owner_repo yet.
		$hasRepo = ! empty( $payload['owner_repo'] );
		$record  = AppRegistry::get( $slug );
		$mode    = ( $hasRepo || ( $record && ! empty( $record['github']['owner_repo'] ) ) ) ? 'iterate' : 'generate';

		$opened = AppRegistry::openJob( [
			'mode'            => $mode,
			'prompt'          => '(resumed stashed draft)',
			'target_slug'     => $slug,
			'app_name'        => (string) ( $payload['manifest']['name'] ?? '' ),
			'app_description' => (string) ( $payload['manifest']['description'] ?? '' ),
			'user_id'         => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'auto_commit'     => true,
		] );
		if ( $opened === NULL ) {
			return new \WP_Error( 'enqueue_failed', 'Could not open a commit job for the stashed draft.', [ 'status' => 500 ] );
		}

		$jobId = $opened['job_id'];

		// The stash IS the draft — skip Phase 1 and jump straight to commit.
		AppRegistry::updateJob( $jobId, [
			'status' => AppRegistry::STATUS_DRAFTED,
			'step'   => AppRegistry::STEP_REVIEW,
		] );

		$committed = self::enqueueCommit( $jobId );
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}

		return $jobId;
	}

	/**
	 * Install a stashed draft directly into wp-content/plugins/{slug}/.
	 * Skips GitHub entirely — the files are written to disk and the app
	 * is registered in the AppRegistry so it shows up on the Apps page.
	 */
	public static function installLocal( string $slug ): true|\WP_Error {
		$payload = AppRegistry::getDraftPayload( $slug );
		if ( ! $payload || empty( $payload['files'] ) ) {
			return new \WP_Error( 'no_draft', "No pending draft for {$slug}.", [ 'status' => 404 ] );
		}

		$fs = Helpers::filesystem();
		if ( ! $fs ) {
			return new \WP_Error( 'filesystem_error', 'Could not initialise the WordPress filesystem.', [ 'status' => 500 ] );
		}

		$pluginDir = WP_PLUGIN_DIR . '/' . $slug;
		if ( $fs->is_dir( $pluginDir ) ) {
			// Overwrite — the user is explicitly installing their draft.
			$fs->delete( $pluginDir, true );
		}

		foreach ( $payload['files'] as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$path     = (string) ( $f['path'] ?? '' );
			$contents = (string) ( $f['contents'] ?? '' );
			if ( $path === '' || str_contains( $path, '..' ) ) {
				continue;
			}

			$fullPath = $pluginDir . '/' . $path;
			$dir      = dirname( $fullPath );
			if ( ! $fs->is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$fs->put_contents( $fullPath, $contents, FS_CHMOD_FILE );
		}

		AppRegistry::promoteToPublished( $slug, [], [
			'installed_locally' => true,
		] );

		AppDiscovery::flushCache();
		AppUpdateProvider::flush();

		return true;
	}

	/**
	 * Discard a drafted job without committing.
	 */
	public static function discard( string $jobId ): bool {
		$job = AppRegistry::getJobSnapshot( $jobId );
		if ( ! $job ) {
			return false;
		}
		$slug = (string) $job['target_slug'];
		if ( $slug !== '' ) {
			AppRegistry::discardDraft( $slug );
		}
		return true;
	}

	// ── Helpers ────────────────────────────────────────────────────

	/**
	 * Convert (path, contents, bytes) rows into (path, contents) rows
	 * for GitHub::pushFiles.
	 *
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array{path:string,contents:string}>
	 */
	private static function draftFilesForPush( array $files ): array {
		return array_values( array_map( static fn( array $f ): array => [
			'path'     => (string) ( $f['path'] ?? '' ),
			'contents' => (string) ( $f['contents'] ?? '' ),
		], $files ) );
	}

	/**
	 * Compute a modified/added/removed/unchanged summary between a
	 * parent and a merged tree so the preview UI can render badges.
	 *
	 * @param array<int,array{path:string,contents:string}> $parent
	 * @param array<int,array<string,mixed>>                $merged
	 * @return array{modified:int,added:int,removed:int,unchanged:int}
	 */
	/**
	 * Push files to GitHub with a single retry on 404 (new-repo propagation delay).
	 */
	private static function pushWithRetry(
		string $ownerRepo,
		array $files,
		string $message,
		string $jobId,
		?string $parentSha = NULL,
		array $filesDeleted = [],
		string $slug = ''
	): array|\WP_Error {
		$push = GitHub::pushFiles(
			ownerRepo: $ownerRepo,
			files: $files,
			message: $message,
			parentSha: $parentSha,
			filesDeleted: $filesDeleted,
		);

		// A 404 right after repo creation is usually a propagation delay.
		// Wait a few seconds and retry once.
		if ( is_wp_error( $push ) && str_contains( $push->get_error_message(), 'Not Found' ) ) {
			self::logForJob( $jobId, 'Push got 404 — waiting 5s for repo propagation…', $slug );
			sleep( 5 );
			$push = GitHub::pushFiles(
				ownerRepo: $ownerRepo,
				files: $files,
				message: $message,
				parentSha: $parentSha,
				filesDeleted: $filesDeleted,
			);
		}

		return $push;
	}

	private static function computeChangeSummary( array $parent, array $merged ): array {
		$parentByPath = [];
		foreach ( $parent as $f ) {
			if ( ! is_array( $f ) )
				continue;
			$parentByPath[ (string) ( $f['path'] ?? '' ) ] = (string) ( $f['contents'] ?? '' );
		}
		$modified = $added = $unchanged = 0;
		$seen = [];
		foreach ( $merged as $f ) {
			if ( ! is_array( $f ) )
				continue;
			$path          = (string) ( $f['path'] ?? '' );
			$contents      = (string) ( $f['contents'] ?? '' );
			$seen[ $path ] = true;
			if ( ! isset( $parentByPath[ $path ] ) ) {
				$added++;
			} elseif ( $parentByPath[ $path ] !== $contents ) {
				$modified++;
			} else {
				$unchanged++;
			}
		}
		$removed = 0;
		foreach ( $parentByPath as $path => $_ ) {
			if ( ! isset( $seen[ $path ] ) ) {
				$removed++;
			}
		}
		return [
			'modified'  => $modified,
			'added'     => $added,
			'removed'   => $removed,
			'unchanged' => $unchanged,
		];
	}

	private static function bumpPatch( string $version ): string {
		$parts = array_map( 'intval', explode( '.', ltrim( $version, 'v' ) ) );
		$parts = array_pad( $parts, 3, 0 );
		$parts[2]++;
		return implode( '.', $parts );
	}

	private static function fail( string $jobId, string $error ): void {
		AppRegistry::failJob( $jobId, $error );
		$job = AppRegistry::getJobSnapshot( $jobId );
		if ( $job && $job['target_slug'] !== '' ) {
			AppRegistry::appendLog( $job['target_slug'], '✗ ' . $error );
		}
		error_log( "ExamplePress agent job {$jobId} failed: {$error}" );
	}

	private static function log( int $postId, string $message ): void {
		$slug = (string) get_post_meta( $postId, '_ep_plugin_slug', true );
		if ( $slug !== '' ) {
			AppRegistry::appendLog( $slug, $message );
		}
	}

	private static function logForJob( string $jobId, string $message, string $slug = '' ): void {
		if ( $slug === '' ) {
			$job = AppRegistry::getJobSnapshot( $jobId );
			$slug = ( $job && $job['target_slug'] !== '' ) ? $job['target_slug'] : '';
		}
		if ( $slug !== '' ) {
			AppRegistry::appendLog( $slug, $message );
		}
	}
}
