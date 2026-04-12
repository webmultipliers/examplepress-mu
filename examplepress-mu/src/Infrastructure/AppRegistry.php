<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Persistent record of every app created through the scaffold flow.
 * Stored as a Custom Post Type (ep_app).
 */
final class AppRegistry {
	// ── Per-post advisory lock ─────────────────────────────────────
	//
	// Several methods in this class do read-modify-write cycles on a
	// single post's meta (history append, log append, stash+history).
	// Two concurrent Action Scheduler workers, or a cron tick racing
	// a user-initiated commit, can interleave between the read and the
	// write and silently lose one side's update.
	//
	// We close the window with an advisory lock keyed per-post so
	// writes to different posts don't serialise unnecessarily. The
	// lock is backed by add_option()'s atomic compare-and-swap (via
	// the UNIQUE index on wp_options.option_name) and recovers from
	// stale locks older than POST_META_LOCK_MAX_AGE seconds. Nested
	// calls within the same process are re-entrant via the static
	// $heldPostMetaLocks map.

	private const POST_META_LOCK_PREFIX  = 'ep_app_post_meta_lock_';
	private const POST_META_LOCK_MAX_AGE = 15;
	// Exponential backoff sized so total wall-clock wait exceeds
	// POST_META_LOCK_MAX_AGE before we fall through unlocked. First
	// few attempts are fast (10/20/40/80/160/320ms) then the backoff
	// caps at 500ms per attempt. Running totals: 6 attempts = 630ms,
	// 40 attempts ≈ 17.6s, which exceeds the stale-lock threshold so
	// a living holder always resolves before we give up.
	private const POST_META_LOCK_ATTEMPTS    = 40;
	private const POST_META_LOCK_BACKOFF_CAP = 500000; // microseconds

	/** @var array<int,bool> Per-post re-entrance flags. */
	private static array $heldPostMetaLocks = [];

	public static function init(): void {
		add_action( 'init', [ self::class, 'registerCpt' ] );
	}

	/**
	 * Run a callable while holding the per-post meta lock. Callers that
	 * need to atomically read-modify-write meta on a single post should
	 * wrap the whole sequence in this helper.
	 *
	 * @template T
	 * @param int          $postId
	 * @param callable():T $fn
	 * @return T
	 */
	private static function withPostMetaLock( int $postId, callable $fn ): mixed {
		if ( $postId <= 0 ) {
			return $fn();
		}
		if ( isset( self::$heldPostMetaLocks[ $postId ] ) ) {
			// Re-entrant call from the same process — proceed directly.
			return $fn();
		}

		$lockKey = self::POST_META_LOCK_PREFIX . $postId;

		for ( $attempt = 0; $attempt < self::POST_META_LOCK_ATTEMPTS; $attempt++ ) {
			$now = time();

			if ( add_option( $lockKey, (string) $now, '', 'no' ) ) {
				self::$heldPostMetaLocks[ $postId ] = true;
				try {
					return $fn();
				} finally {
					unset( self::$heldPostMetaLocks[ $postId ] );
					delete_option( $lockKey );
				}
			}

			// Existing lock — check for staleness (orphaned from a dead process).
			$existing = (int) get_option( $lockKey, 0 );
			if ( $existing > 0 && ( $now - $existing ) > self::POST_META_LOCK_MAX_AGE ) {
				delete_option( $lockKey );
				continue;
			}

			// Exponential backoff capped at POST_META_LOCK_BACKOFF_CAP
			// microseconds. 10ms → 20 → 40 → ... → 500ms ceiling.
			$delay = min( self::POST_META_LOCK_BACKOFF_CAP, 10000 << min( $attempt, 16 ) );
			usleep( $delay );
		}

		error_log( sprintf(
			'[ExamplePress AppRegistry] Could not acquire post meta lock for post %d after %d attempts — proceeding unlocked.',
			$postId,
			self::POST_META_LOCK_ATTEMPTS
		) );
		return $fn();
	}

	public static function registerCpt(): void {
		register_post_type( 'ep_app', [
			'labels'              => [
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

	/**
	 * Find the CPT post for an app by its plugin slug.
	 */
	public static function getPost( string $slug ): ?\WP_Post {
		$posts = get_posts( [
			'post_type'      => 'ep_app',
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'meta_key'       => '_ep_plugin_slug',
			'meta_value'     => $slug,
			'no_found_rows'  => true,
		] );

		return $posts[0] ?? NULL;
	}

	/**
	 * Convert a CPT post + meta into the record array.
	 *
	 * @return array<string, mixed>
	 */
	public static function toRecord( \WP_Post $post ): array {
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
	 * Write record data to post meta.
	 *
	 * Every meta key this method writes is contractually scalar (all
	 * strings in toRecord()'s read shape). update_post_meta will
	 * happily serialize an array if a caller passes one, which is
	 * technically valid WordPress behavior but surprising for the
	 * reader: toRecord() would hand back a string, but callers that
	 * bypass writeMeta and read raw meta would see the serialized
	 * form. Coerce every value through writeScalarMeta() which
	 * strips non-scalars with a WP_DEBUG log so the misbehaving
	 * caller notices.
	 */
	public static function writeMeta( int $postId, array $data ): void {
		$flatMap = [
			'description' => '_ep_description',
			'version'     => '_ep_version',
			'source'      => '_ep_source',
		];

		foreach ( $flatMap as $key => $metaKey ) {
			if ( array_key_exists( $key, $data ) ) {
				self::writeScalarMeta( $postId, $metaKey, $data[ $key ] );
			}
		}

		if ( isset( $data['github'] ) && is_array( $data['github'] ) ) {
			$githubMap = [
				'owner_repo' => '_ep_github_owner_repo',
				'repo_id'    => '_ep_github_repo_id',
				'html_url'   => '_ep_github_html_url',
			];
			foreach ( $githubMap as $key => $metaKey ) {
				if ( array_key_exists( $key, $data['github'] ) ) {
					self::writeScalarMeta( $postId, $metaKey, $data['github'][ $key ] );
				}
			}
		}

		if ( isset( $data['troy'] ) && is_array( $data['troy'] ) ) {
			$troyMap = [
				'server_url' => '_ep_troy_server_url',
				'repo'       => '_ep_troy_repo',
				'repo_id'    => '_ep_troy_repo_id',
			];
			foreach ( $troyMap as $key => $metaKey ) {
				if ( array_key_exists( $key, $data['troy'] ) ) {
					self::writeScalarMeta( $postId, $metaKey, $data['troy'][ $key ] );
				}
			}
		}
	}

	/**
	 * Coerce + write a scalar-only meta value. Arrays, objects, and
	 * resources are rejected with an error_log under WP_DEBUG (they
	 * signal a bug in the caller) and null/empty strings delete the
	 * meta entry so the read path doesn't see stale values.
	 */
	private static function writeScalarMeta( int $postId, string $metaKey, mixed $value ): void {
		if ( $value === NULL ) {
			delete_post_meta( $postId, $metaKey );
			return;
		}
		if ( ! is_scalar( $value ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[ExamplePress AppRegistry] writeMeta rejected non-scalar value for %s on post %d (got %s).',
					$metaKey,
					$postId,
					gettype( $value )
				) );
			}
			return;
		}
		update_post_meta( $postId, $metaKey, (string) $value );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Filter the maximum number of app records loaded by AppRegistry::all().
		 * Defaults to 500 to avoid unbounded queries.
		 */
		$limit = (int) apply_filters( 'examplepress_mu_apps_query_limit', 500 );

		$posts = get_posts( [
			'post_type'      => 'ep_app',
			'posts_per_page' => $limit,
			'post_status'    => 'any',
			'no_found_rows'  => true,
		] );

		// Normalize the return. get_posts returns WP_Post[] on success
		// but is typed as array|false|string depending on suppress hooks
		// (e.g. the 'posts_pre_query' filter can short-circuit and
		// return anything). Coerce defensively so the foreach always
		// iterates over WP_Post instances.
		if ( ! is_array( $posts ) ) {
			return [];
		}

		$registry = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$record                      = self::toRecord( $post );
			$registry[ $record['slug'] ] = $record;
		}

		return $registry;
	}

	public static function get( string $slug ): ?array {
		$post = self::getPost( $slug );
		return $post ? self::toRecord( $post ) : NULL;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function set( string $slug, array $data ): array {
		$post = self::getPost( $slug );

		if ( $post ) {
			$updateArgs = [ 'ID' => $post->ID ];
			if ( isset( $data['name'] ) && $data['name'] !== $post->post_title ) {
				$updateArgs['post_title'] = $data['name'];
			}
			if ( count( $updateArgs ) > 1 ) {
				wp_update_post( $updateArgs );
			}
			self::writeMeta( $post->ID, $data );

			$refreshed = get_post( $post->ID );
			if ( $refreshed instanceof \WP_Post ) {
				return self::toRecord( $refreshed );
			}
			// Post was deleted out from under us between the lookup and
			// the re-fetch. Fall back to a best-effort synthesised
			// record so callers don't get a TypeError from toRecord.
			return array_merge( [ 'slug' => $slug ], $data );
		}

		$postId = wp_insert_post( [
			'post_type'   => 'ep_app',
			'post_title'  => $data['name'] ?? $slug,
			'post_name'   => $slug,
			// Apps created via the legacy scaffold/connect path are
			// immediately considered "live" — they have a plugin
			// directory on disk and (usually) a GitHub repo. The agent
			// path uses openJob() / promoteToPublished() instead.
			'post_status' => 'publish',
		] );

		// wp_insert_post returns int|WP_Error: 0 on silent failure,
		// a WP_Error instance when given wp_error=true (we don't), or
		// the new post ID on success. The previous code only handled
		// WP_Error and would then call update_post_meta(0, …) and
		// toRecord(get_post(0)) — the latter violates the WP_Post
		// type-hint on toRecord since get_post(0) returns null.
		if ( is_wp_error( $postId ) || ! is_int( $postId ) || $postId <= 0 ) {
			return array_merge( [ 'slug' => $slug ], $data );
		}

		update_post_meta( $postId, '_ep_plugin_slug', $slug );
		self::writeMeta( $postId, $data );

		$fresh = get_post( $postId );
		if ( $fresh instanceof \WP_Post ) {
			return self::toRecord( $fresh );
		}
		return array_merge( [ 'slug' => $slug ], $data );
	}

	// ── Agent job state (single source of truth) ────────────────────
	//
	// The ep_app post is the canonical store for every agent job. A
	// job is a history entry on the post: its UUID lives in the
	// _ep_draft_history meta alongside prompt, mode, status, step,
	// errors, result, provider, model, and timestamps. There is no
	// separate wp_options table — the post IS the job record, so the
	// UI and the server cannot drift.
	//
	// History entry schema (one element of _ep_draft_history):
	//   id            string  UUID, matches _ep_draft_current_job_id
	//                         while the job is active.
	//   mode          string  generate | iterate | repair
	//   prompt        string  user prompt
	//   target_slug   string  app slug
	//   status        string  pending | running | drafted | success | failed
	//   step          string  queued | drafting | writing_code |
	//                         awaiting_review | pushing | done | failed
	//   errors        list<string> terminal error messages
	//   result        ?array  populated on status=success
	//   provider      string  anthropic | openai
	//   model         string  model id at time of enqueue
	//   user_id       int     originating WP user id
	//   auto_commit   bool    commit without review pause
	//   error_context ?array  repair-mode reported error
	//   created_at    int     unix timestamp
	//   updated_at    int     unix timestamp
	//
	// History is FIFO-capped at HISTORY_CAP entries per post so a
	// long-lived app that has been iterated hundreds of times doesn't
	// bloat wp_postmeta. Separate per-post cap is better than a global
	// one because one app's iteration history never evicts another
	// app's jobs.

	/**
	 * Meta keys used by the draft-stash layer. Centralised so the JS
	 * data provider can read the same keys without magic strings.
	 */
	public const META_DRAFT_PAYLOAD    = '_ep_draft_payload';
	public const META_DRAFT_STATUS     = '_ep_draft_status';
	public const META_DRAFT_STEP       = '_ep_draft_step';
	public const META_DRAFT_ERRORS     = '_ep_draft_errors';
	public const META_DRAFT_ORIGIN_JOB = '_ep_draft_origin_job_id';
	public const META_DRAFT_HISTORY    = '_ep_draft_history';
	public const META_DRAFT_UPDATED_AT = '_ep_draft_updated_at';
	public const META_DRAFT_PROMPT     = '_ep_draft_prompt';
	public const META_DRAFT_LOG        = '_ep_draft_log';

	public const HISTORY_CAP = 25;

	// Job status values.
	public const STATUS_PENDING = 'pending';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DRAFTED = 'drafted';
	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILED  = 'failed';

	// Job step values (UI progress pill).
	public const STEP_QUEUED   = 'queued';
	public const STEP_DRAFTING = 'drafting';
	public const STEP_WRITING  = 'writing_code';
	public const STEP_REVIEW   = 'awaiting_review';
	public const STEP_PUSHING  = 'pushing';
	public const STEP_DONE     = 'done';
	public const STEP_FAILED   = 'failed';

	/**
	 * Open a new agent job on the CPT. Creates the post if necessary
	 * (generate mode, brand-new slug) or reuses it (retry on a failed
	 * generate, iterate/repair on an existing app). Returns the post
	 * id, the new job UUID, and a flag indicating whether an existing
	 * post was reused.
	 *
	 * Critically, this method handles the "retry after failure" path
	 * without ever calling wp_insert_post on an existing slug — so WP
	 * never auto-suffixes the post_name, and subsequent UUID lookups
	 * keep resolving to the same post.
	 *
	 * @param array{
	 *   mode:string,
	 *   prompt:string,
	 *   target_slug:string,
	 *   app_name?:string,
	 *   app_description?:string,
	 *   user_id?:int,
	 *   auto_commit?:bool,
	 *   error_context?:array<string,mixed>,
	 *   provider?:string,
	 *   model?:string,
	 * } $args
	 * @return array{post_id:int, job_id:string, reused:bool}|null
	 */
	public static function openJob( array $args ): ?array {
		$mode       = (string) ( $args['mode'] ?? 'generate' );
		$prompt     = (string) ( $args['prompt'] ?? '' );
		$slug       = (string) ( $args['target_slug'] ?? '' );
		$appName    = (string) ( $args['app_name'] ?? '' );
		$appDesc    = (string) ( $args['app_description'] ?? '' );
		$userId     = (int) ( $args['user_id'] ?? 0 );
		$autoCommit = (bool) ( $args['auto_commit'] ?? false );
		$errorCtx   = is_array( $args['error_context'] ?? NULL ) ? $args['error_context'] : NULL;
		$provider   = (string) ( $args['provider'] ?? '' );
		$model      = (string) ( $args['model'] ?? '' );

		if ( $slug === '' ) {
			return NULL;
		}

		$existing = self::getPost( $slug );
		$reused   = false;
		$postId   = 0;

		if ( $existing ) {
			$reused = true;
			$postId = (int) $existing->ID;

			// A published app cannot be generated "again" — iterate or
			// repair against it instead.
			if ( $mode === 'generate' && $existing->post_status === 'publish' ) {
				return NULL;
			}
		} else {
			if ( $mode !== 'generate' ) {
				// iterate/repair against a non-existent slug is a no-op
				return NULL;
			}

			$name     = $appName !== '' ? $appName : ( $prompt !== '' ? mb_substr( $prompt, 0, 80 ) : $slug );
			$inserted = wp_insert_post( [
				'post_type'   => 'ep_app',
				'post_title'  => $name,
				'post_name'   => $slug,
				'post_status' => 'draft',
			] );
			if ( is_wp_error( $inserted ) || ! $inserted ) {
				return NULL;
			}
			$postId = (int) $inserted;

			update_post_meta( $postId, '_ep_plugin_slug', $slug );
			update_post_meta( $postId, '_ep_source', 'agent' );
			if ( $appDesc !== '' ) {
				update_post_meta( $postId, '_ep_description', $appDesc );
			}
		}

		$jobId = wp_generate_uuid4();
		$now   = time();

		self::withPostMetaLock( $postId, static function () use ($postId, $jobId, $mode, $prompt, $slug, $appName, $appDesc, $userId, $autoCommit, $errorCtx, $provider, $model, $now): void {
			// Clear transient per-job state so we start clean. History
			// is preserved — it's the chat thread / audit trail.
			delete_post_meta( $postId, self::META_DRAFT_ERRORS );
			delete_post_meta( $postId, self::META_DRAFT_LOG );

			update_post_meta( $postId, self::META_DRAFT_STATUS, self::STATUS_PENDING );
			update_post_meta( $postId, self::META_DRAFT_STEP, self::STEP_QUEUED );
			update_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB, $jobId );
			update_post_meta( $postId, self::META_DRAFT_PROMPT, $prompt );
			update_post_meta( $postId, self::META_DRAFT_UPDATED_AT, $now );

			$history   = self::readHistory( $postId );
			$history[] = [
				'id'              => $jobId,
				'mode'            => $mode,
				'prompt'          => $prompt,
				'target_slug'     => $slug,
				'app_name'        => $appName,
				'app_description' => $appDesc,
				'status'          => self::STATUS_PENDING,
				'step'            => self::STEP_QUEUED,
				'errors'          => [],
				'result'          => NULL,
				'provider'        => $provider,
				'model'           => $model,
				'user_id'         => $userId,
				'auto_commit'     => $autoCommit,
				'error_context'   => $errorCtx,
				'created_at'      => $now,
				'updated_at'      => $now,
			];
			self::writeHistory( $postId, $history );
		} );

		return [
			'post_id' => $postId,
			'job_id'  => $jobId,
			'reused'  => $reused,
		];
	}

	/**
	 * Look up a draft post by its current origin_job_id meta. Returns
	 * null if the job id doesn't match any post's *current* job — if
	 * you need to find an older entry, use getJobSnapshot() which
	 * scans history.
	 */
	public static function getPostByJobId( string $jobId ): ?\WP_Post {
		if ( $jobId === '' ) {
			return NULL;
		}
		$posts = get_posts( [
			'post_type'      => 'ep_app',
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'meta_key'       => self::META_DRAFT_ORIGIN_JOB,
			'meta_value'     => $jobId,
			'no_found_rows'  => true,
		] );
		if ( empty( $posts ) ) {
			error_log( "[AppRegistry::getPostByJobId] No post found for jobId={$jobId} via get_posts." );
			// Optionally, add a direct DB query fallback here if needed
			return NULL;
		}
		return $posts[0];
	}

	/**
	 * Return a synthesised job-shape array for a given UUID.
	 *
	 * Fast path only: the job id must match a post's current
	 * META_DRAFT_ORIGIN_JOB. Superseded jobs (retry replaced the
	 * active id) resolve to null — the UI should switch to polling
	 * the new job id returned by retryJob, not keep polling the old
	 * one. This is intentional: the alternative is scanning every
	 * agent post's history JSON on every poll tick, which hammers
	 * the database on a hot path.
	 *
	 * For rendering historical jobs in the chat thread or jobs
	 * modal, callers should use jobSnapshotsForSlug() or
	 * recentJobSnapshots() which read history directly.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function getJobSnapshot( string $jobId ): ?array {
		if ( $jobId === '' ) {
			return NULL;
		}

		$post = self::getPostByJobId( $jobId );
		if ( ! $post ) {
			return NULL;
		}
		$entry = self::findHistoryEntry( (int) $post->ID, $jobId );
		return $entry ? self::synthesiseJob( $post, $entry ) : NULL;
	}

	/**
	 * Apply a patch to the history entry identified by $jobId and
	 * mirror status/step/updated_at into post meta for polling.
	 *
	 * @param array<string,mixed> $patch
	 */
	public static function updateJob( string $jobId, array $patch ): bool {
		$post = self::getPostByJobId( $jobId );
		if ( ! $post ) {
			return false;
		}
		$postId = (int) $post->ID;

		return self::withPostMetaLock( $postId, static function () use ($postId, $jobId, $patch): bool {
			$history = self::readHistory( $postId );
			$found   = false;
			foreach ( $history as &$entry ) {
				if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $jobId ) {
					continue;
				}
				$entry               = array_merge( $entry, $patch );
				$entry['updated_at'] = time();
				$found               = true;
				break;
			}
			unset( $entry );
			if ( ! $found ) {
				return false;
			}
			self::writeHistory( $postId, $history );

			if ( isset( $patch['status'] ) ) {
				update_post_meta( $postId, self::META_DRAFT_STATUS, (string) $patch['status'] );
			}
			if ( isset( $patch['step'] ) ) {
				update_post_meta( $postId, self::META_DRAFT_STEP, (string) $patch['step'] );
			}
			if ( isset( $patch['errors'] ) && is_array( $patch['errors'] ) ) {
				update_post_meta( $postId, self::META_DRAFT_ERRORS, wp_json_encode( array_values( $patch['errors'] ) ) );
			}
			update_post_meta( $postId, self::META_DRAFT_UPDATED_AT, time() );
			return true;
		} );
	}

	/**
	 * Mark a job as terminally failed. Appends the error to the
	 * entry's errors array and mirrors status=failed to post meta.
	 */
	public static function failJob( string $jobId, string $error ): bool {
		$post = self::getPostByJobId( $jobId );
		if ( ! $post ) {
			error_log( "[AppRegistry::failJob] Could not find post for jobId={$jobId}. Forcing status and error update in post meta if possible." );
			// Could optionally scan all posts or add a direct DB query here if needed
			return false;
		}
		$postId = (int) $post->ID;

		return self::withPostMetaLock( $postId, static function () use ($postId, $jobId, $error): bool {
			$history     = self::readHistory( $postId );
			$finalErrors = [];
			$found       = false;
			foreach ( $history as &$entry ) {
				if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $jobId ) {
					continue;
				}
				$existing            = is_array( $entry['errors'] ?? NULL ) ? $entry['errors'] : [];
				$entry['status']     = self::STATUS_FAILED;
				$entry['step']       = self::STEP_FAILED;
				$entry['errors']     = array_merge( $existing, [ $error ] );
				$entry['updated_at'] = time();
				$finalErrors         = $entry['errors'];
				$found               = true;
				break;
			}
			unset( $entry );
			if ( ! $found ) {
				// If job is missing from history, log and still update post meta for UI
				error_log( "[AppRegistry::failJob] JobId={$jobId} not found in history for postId={$postId}. Forcing status and error update in post meta." );
				$finalErrors = [ $error ];
			} else {
				self::writeHistory( $postId, $history );
			}

			update_post_meta( $postId, self::META_DRAFT_STATUS, self::STATUS_FAILED );
			update_post_meta( $postId, self::META_DRAFT_STEP, self::STEP_FAILED );
			update_post_meta( $postId, self::META_DRAFT_ERRORS, wp_json_encode( $finalErrors ) );
			update_post_meta( $postId, self::META_DRAFT_UPDATED_AT, time() );
			return true;
		} );
	}

	/**
	 * Record a job as terminally succeeded. Stores the result payload
	 * on the history entry.
	 *
	 * @param array<string,mixed> $result
	 */
	public static function succeedJob( string $jobId, array $result ): bool {
		return self::updateJob( $jobId, [
			'status' => self::STATUS_SUCCESS,
			'step'   => self::STEP_DONE,
			'result' => $result,
		] );
	}

	/**
	 * Every job across all apps, newest-first. Used by the recent
	 * jobs view.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recentJobSnapshots( int $limit = 20 ): array {
		$posts = get_posts( [
			'post_type'      => 'ep_app',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$snapshots = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			foreach ( self::readHistory( (int) $post->ID ) as $entry ) {
				$snapshots[] = self::synthesiseJob( $post, $entry );
			}
		}
		usort( $snapshots, static fn( $a, $b ) => ( $b['created_at'] ?? 0 ) <=> ( $a['created_at'] ?? 0 ) );
		return array_slice( $snapshots, 0, $limit );
	}

	/**
	 * All history entries for a specific app slug, newest-first. Used
	 * by the iterate modal's chat thread.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function jobSnapshotsForSlug( string $slug, int $limit = 20 ): array {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return [];
		}
		$snapshots = [];
		foreach ( self::readHistory( (int) $post->ID ) as $entry ) {
			$snapshots[] = self::synthesiseJob( $post, $entry );
		}
		usort( $snapshots, static fn( $a, $b ) => ( $b['created_at'] ?? 0 ) <=> ( $a['created_at'] ?? 0 ) );
		return array_slice( $snapshots, 0, $limit );
	}

	/**
	 * Synthesise a job-shape array from a post + history entry.
	 * Attaches the current draft payload when this entry is the
	 * active one.
	 *
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private static function synthesiseJob( \WP_Post $post, array $entry ): array {
		$slug         = (string) get_post_meta( $post->ID, '_ep_plugin_slug', true );
		$currentJobId = (string) get_post_meta( $post->ID, self::META_DRAFT_ORIGIN_JOB, true );

		$draft = NULL;
		if ( ( $entry['id'] ?? '' ) === $currentJobId && $currentJobId !== '' ) {
			$draft = self::getDraftPayload( $slug );
		}

		return [
			'id'              => (string) ( $entry['id'] ?? '' ),
			'mode'            => (string) ( $entry['mode'] ?? 'generate' ),
			'prompt'          => (string) ( $entry['prompt'] ?? '' ),
			'target_slug'     => $slug,
			'app_name'        => (string) ( $entry['app_name'] ?? $post->post_title ),
			'app_description' => (string) ( $entry['app_description'] ?? '' ),
			'status'          => (string) ( $entry['status'] ?? self::STATUS_PENDING ),
			'step'            => (string) ( $entry['step'] ?? self::STEP_QUEUED ),
			'errors'          => is_array( $entry['errors'] ?? NULL ) ? array_values( $entry['errors'] ) : [],
			'draft'           => $draft,
			'result'          => is_array( $entry['result'] ?? NULL ) ? $entry['result'] : NULL,
			'provider'        => (string) ( $entry['provider'] ?? '' ),
			'model'           => (string) ( $entry['model'] ?? '' ),
			'user_id'         => (int) ( $entry['user_id'] ?? 0 ),
			'auto_commit'     => (bool) ( $entry['auto_commit'] ?? false ),
			'error_context'   => is_array( $entry['error_context'] ?? NULL ) ? $entry['error_context'] : NULL,
			'created_at'      => (int) ( $entry['created_at'] ?? 0 ),
			'updated_at'      => (int) ( $entry['updated_at'] ?? 0 ),
		];
	}

	/**
	 * Read the history array off a post.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function readHistory( int $postId ): array {
		$raw = (string) get_post_meta( $postId, self::META_DRAFT_HISTORY, true );
		if ( $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		return array_values( array_filter( $decoded, 'is_array' ) );
	}

	/**
	 * Write history back, FIFO-capped at HISTORY_CAP. The current
	 * running/drafted job (pointed to by META_DRAFT_ORIGIN_JOB) is
	 * always preserved even if it would otherwise be the oldest
	 * entry to evict — otherwise an app iterated rapidly past the
	 * cap could lose its own in-flight job and leave the origin
	 * meta pointing at nothing.
	 *
	 * @param array<int,array<string,mixed>> $history
	 */
	private static function writeHistory( int $postId, array $history ): void {
		if ( count( $history ) > self::HISTORY_CAP ) {
			$currentJobId = (string) get_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB, true );

			// Fast path: no current job, or current job is already
			// in the last HISTORY_CAP entries. Simple tail slice.
			$tail           = array_slice( $history, -self::HISTORY_CAP );
			$tailHasCurrent = $currentJobId === '' || self::historyContains( $tail, $currentJobId );

			if ( $tailHasCurrent ) {
				$history = $tail;
			} else {
				// Slow path: the current job would be evicted by a
				// naive tail slice. Keep it by pinning it to the
				// front of the retained window.
				$currentEntry = NULL;
				foreach ( $history as $entry ) {
					if ( is_array( $entry ) && ( $entry['id'] ?? '' ) === $currentJobId ) {
						$currentEntry = $entry;
						break;
					}
				}
				$tailCap = self::HISTORY_CAP - 1;
				$history = $tailCap > 0 ? array_slice( $history, -$tailCap ) : [];
				if ( $currentEntry !== NULL ) {
					array_unshift( $history, $currentEntry );
				}
			}
		}
		update_post_meta( $postId, self::META_DRAFT_HISTORY, wp_json_encode( array_values( $history ) ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $history
	 */
	private static function historyContains( array $history, string $jobId ): bool {
		foreach ( $history as $entry ) {
			if ( is_array( $entry ) && ( $entry['id'] ?? '' ) === $jobId ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function findHistoryEntry( int $postId, string $jobId ): ?array {
		foreach ( self::readHistory( $postId ) as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $jobId ) {
				return $entry;
			}
		}
		return NULL;
	}

	/** @var string|null Specific reason the last stashDraftPayload() call returned false. */
	private static ?string $lastStashError = NULL;

	/**
	 * Return the reason the most recent stashDraftPayload() call
	 * returned false, or null if the last call succeeded. Callers
	 * should grab this immediately after a false return so the
	 * activity log can surface the specific cause instead of a
	 * generic "persistence failed" message.
	 */
	public static function lastStashError(): ?string {
		return self::$lastStashError;
	}

	/**
	 * Stash a generated payload on the app post and mark the current
	 * job's history entry as status=drafted, step=awaiting_review.
	 * Works on both draft (never-pushed) AND publish (already-pushed)
	 * posts — the payload meta is independent of post status.
	 *
	 * Returns false if the payload write fails. When it does, the
	 * caller can read lastStashError() for the specific reason
	 * (JSON encode fail, size cap, MySQL silent drop, missing post).
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function stashDraftPayload( string $slug, array $payload ): bool {
		self::$lastStashError = NULL;

		$post = self::getPost( $slug );
		if ( ! $post ) {
			self::$lastStashError = "No post exists for slug \"{$slug}\".";
			return false;
		}
		$postId = (int) $post->ID;

		return self::withPostMetaLock( $postId, static function () use ($postId, $payload): bool {
			$writeResult = self::writePayloadMeta( $postId, $payload );
			if ( $writeResult !== true ) {
				self::$lastStashError = $writeResult;
				return false;
			}

			update_post_meta( $postId, self::META_DRAFT_STATUS, self::STATUS_DRAFTED );
			update_post_meta( $postId, self::META_DRAFT_STEP, self::STEP_REVIEW );
			update_post_meta( $postId, self::META_DRAFT_UPDATED_AT, time() );

			// Mirror into the current job's history entry.
			$currentJobId = (string) get_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB, true );
			if ( $currentJobId !== '' ) {
				$history = self::readHistory( $postId );
				foreach ( $history as &$entry ) {
					if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $currentJobId ) {
						continue;
					}
					$entry['status']     = self::STATUS_DRAFTED;
					$entry['step']       = self::STEP_REVIEW;
					$entry['updated_at'] = time();
					break;
				}
				unset( $entry );
				self::writeHistory( $postId, $history );
			}

			return true;
		} );
	}

	/**
	 * Encode and persist the draft payload to post meta. Verifies
	 * the write actually persisted. Returns true on success, or a
	 * human-readable error string on failure.
	 *
	 * Failure modes:
	 *   - wp_json_encode fails (non-UTF-8 bytes, cycles, etc.)
	 *   - payload exceeds 10 MB sanity cap
	 *   - MySQL silently drops the write (max_allowed_packet)
	 *
	 * @return true|string
	 */
	private static function writePayloadMeta( int $postId, array $payload ): true|string {
		$json = wp_json_encode( $payload );
		if ( $json === false ) {
			$reason = 'wp_json_encode failed: ' . json_last_error_msg();
			error_log( "ExamplePress agent: {$reason} (post {$postId})" );
			return $reason;
		}

		$slug = '';
		$post = get_post( $postId );
		if ( $post ) {
			$slug = get_post_meta( $postId, '_ep_plugin_slug', true ) ?: $post->post_name;
		}
		if ( $slug !== '' ) {
			$appsDir = dirname( __DIR__, 1 ) . '/apps/';
			if ( ! is_dir( $appsDir ) ) {
				mkdir( $appsDir, 0777, true );
			}
			$jobId        = '';
			$currentJobId = (string) get_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB, true );
			if ( $currentJobId !== '' ) {
				$jobId = $currentJobId;
			} else {
				$jobId = uniqid( 'job_', true );
			}
			$fileName = $appsDir . $slug . '-' . $jobId . '.json';
			$relPath  = 'apps/' . $slug . '-' . $jobId . '.json';
			$written  = file_put_contents( $fileName, $json );
			if ( $written === false ) {
				$reason = "Failed to write draft payload to file: {$fileName}";
				error_log( "ExamplePress agent: {$reason} (post {$postId})" );
				return $reason;
			}
			update_post_meta( $postId, self::META_DRAFT_PAYLOAD, 'file:' . $relPath );
			if ( ! is_readable( $fileName ) ) {
				$reason = "Draft payload file not readable: {$fileName}";
				error_log( "ExamplePress agent: {$reason} (post {$postId})" );
				return $reason;
			}
			return true;
		}

		$reason = 'No slug found for post when writing draft payload to file.';
		error_log( "ExamplePress agent: {$reason} (post {$postId})" );
		return $reason;
	}

	/**
	 * Read the stashed draft payload off a post.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function getDraftPayload( string $slug ): ?array {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return NULL;
		}
		$raw = (string) get_post_meta( $post->ID, self::META_DRAFT_PAYLOAD, true );
		if ( $raw === '' ) {
			return NULL;
		}
		if ( strpos( $raw, 'file:' ) === 0 ) {
			$relPath  = substr( $raw, 5 );
			$filePath = dirname( __DIR__, 1 ) . '/' . $relPath;
			if ( ! is_readable( $filePath ) ) {
				error_log( "ExamplePress agent: Draft payload file missing or unreadable: {$filePath}" );
				return NULL;
			}
			$json = file_get_contents( $filePath );
			if ( $json === false ) {
				error_log( "ExamplePress agent: Failed to read draft payload file: {$filePath}" );
				return NULL;
			}
			$decoded = json_decode( $json, true );
			return is_array( $decoded ) ? $decoded : NULL;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : NULL;
	}

	/**
	 * Has this app got a pending stashed revision waiting for review?
	 */
	public static function hasDraftPayload( string $slug ): bool {
		return self::getDraftPayload( $slug ) !== NULL;
	}

	/**
	 * Record a successful iterate/repair push. Bumps the version meta,
	 * marks the current job's history entry as status=success, and
	 * clears the pending stash.
	 *
	 * @param array<string,mixed> $result
	 */
	public static function recordPush( string $slug, string $newVersion, array $result = [] ): bool {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return false;
		}
		$postId = (int) $post->ID;

		return self::withPostMetaLock( $postId, static function () use ($postId, $newVersion, $result): bool {
			update_post_meta( $postId, '_ep_version', $newVersion );

			$currentJobId = (string) get_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB, true );
			if ( $currentJobId !== '' ) {
				$history = self::readHistory( $postId );
				foreach ( $history as &$entry ) {
					if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $currentJobId ) {
						continue;
					}
					$entry['status']     = self::STATUS_SUCCESS;
					$entry['step']       = self::STEP_DONE;
					$entry['result']     = array_merge( [ 'version' => $newVersion ], $result );
					$entry['updated_at'] = time();
					break;
				}
				unset( $entry );
				self::writeHistory( $postId, $history );
			}

			self::clearDraftPayload( $postId );
			return true;
		} );
	}

	/**
	 * Promote a never-pushed draft to publish after a successful push.
	 * Records the github coordinates, flips the post status, marks
	 * the current job's history entry as success, and clears the
	 * pending payload.
	 *
	 * @param array<string,mixed> $githubData { owner_repo, repo_id, html_url }
	 * @param array<string,mixed> $result     Extra result fields merged into history entry.
	 */
	public static function promoteToPublished( string $slug, array $githubData, array $result = [] ): bool {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return false;
		}
		$postId       = (int) $post->ID;
		$wasPublished = $post->post_status === 'publish';

		return self::withPostMetaLock( $postId, static function () use ($slug, $postId, $wasPublished, $githubData, $result): bool {
			if ( ! $wasPublished ) {
				wp_update_post( [ 'ID' => $postId, 'post_status' => 'publish' ] );
			}

			// Pull the version from the stashed payload before clearing it.
			$payload = self::getDraftPayload( $slug ) ?? [];
			$version = (string) ( $payload['manifest']['version'] ?? '' );
			if ( $version !== '' ) {
				update_post_meta( $postId, '_ep_version', $version );
			}

			if ( ! empty( $githubData['owner_repo'] ) ) {
				update_post_meta( $postId, '_ep_github_owner_repo', (string) $githubData['owner_repo'] );
			}
			if ( isset( $githubData['repo_id'] ) ) {
				update_post_meta( $postId, '_ep_github_repo_id', (string) $githubData['repo_id'] );
			}
			if ( ! empty( $githubData['html_url'] ) ) {
				update_post_meta( $postId, '_ep_github_html_url', (string) $githubData['html_url'] );
			}

			$currentJobId = (string) get_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB, true );
			if ( $currentJobId !== '' ) {
				$history = self::readHistory( $postId );
				foreach ( $history as &$entry ) {
					if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $currentJobId ) {
						continue;
					}
					$entry['status']     = self::STATUS_SUCCESS;
					$entry['step']       = self::STEP_DONE;
					$entry['result']     = array_merge( [ 'version' => $version ], $githubData, $result );
					$entry['updated_at'] = time();
					break;
				}
				unset( $entry );
				self::writeHistory( $postId, $history );
			}

			self::clearDraftPayload( $postId );
			return true;
		} );
	}

	/**
	 * Drop the stashed payload + transient per-job meta (status, step,
	 * errors, log, prompt, updated_at, origin_job_id). History is
	 * preserved. Called after a successful push or when the user
	 * explicitly discards the pending work.
	 */
	private static function clearDraftPayload( int $postId ): void {
		$raw = (string) get_post_meta( $postId, self::META_DRAFT_PAYLOAD, true );
		if ( strpos( $raw, 'file:' ) === 0 ) {
			$relPath  = substr( $raw, 5 );
			$filePath = dirname( __DIR__, 1 ) . '/' . $relPath;
			if ( is_file( $filePath ) ) {
				@unlink( $filePath );
			}
		}
		delete_post_meta( $postId, self::META_DRAFT_PAYLOAD );
		delete_post_meta( $postId, self::META_DRAFT_STATUS );
		delete_post_meta( $postId, self::META_DRAFT_STEP );
		delete_post_meta( $postId, self::META_DRAFT_ERRORS );
		delete_post_meta( $postId, self::META_DRAFT_LOG );
		delete_post_meta( $postId, self::META_DRAFT_PROMPT );
		delete_post_meta( $postId, self::META_DRAFT_UPDATED_AT );
		delete_post_meta( $postId, self::META_DRAFT_ORIGIN_JOB );
	}

	/**
	 * Append a timestamped entry to the draft's activity log.
	 * The log is a lightweight timeline that the UI polls to show
	 * live progress during in-flight jobs.
	 */
	public static function appendLog( string $slug, string $message ): void {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return;
		}
		self::withPostMetaLock( (int) $post->ID, static function () use ($post, $message): void {
			$raw = (string) get_post_meta( $post->ID, self::META_DRAFT_LOG, true );
			$log = $raw !== '' ? ( json_decode( $raw, true ) ?: [] ) : [];
			if ( ! is_array( $log ) ) {
				$log = [];
			}
			$log[] = [
				'ts'  => time(),
				'msg' => $message,
			];
			// Cap at 50 entries so the meta doesn't bloat.
			if ( count( $log ) > 50 ) {
				$log = array_slice( $log, -50 );
			}
			update_post_meta( $post->ID, self::META_DRAFT_LOG, wp_json_encode( $log ) );
		} );
	}

	/**
	 * Read the draft activity log.
	 *
	 * @return array<int,array{ts:int,msg:string}>
	 */
	public static function getDraftLog( string $slug ): array {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return [];
		}
		$raw = (string) get_post_meta( $post->ID, self::META_DRAFT_LOG, true );
		return $raw !== '' ? ( json_decode( $raw, true ) ?: [] ) : [];
	}

	/**
	 * Discard a draft entirely. If the post has never been pushed,
	 * delete it. If it's already published, just drop the pending
	 * payload. History is preserved when the post survives.
	 */
	public static function discardDraft( string $slug ): bool {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return false;
		}
		if ( $post->post_status === 'draft' ) {
			wp_delete_post( $post->ID, true );
			return true;
		}
		self::clearDraftPayload( (int) $post->ID );
		return true;
	}

	/**
	 * Read the audit trail of every job against this app (in order).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function getDraftHistory( string $slug ): array {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return [];
		}
		return self::readHistory( (int) $post->ID );
	}

	/**
	 * List every app with in-flight or pending draft work. Used by
	 * the Drafts surface in the admin page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function listDraftsPending(): array {
		$posts = get_posts( [
			'post_type'      => 'ep_app',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		] );

		$posts = array_filter( $posts, static function ( \WP_Post $post ): bool {
			if ( $post->post_status !== 'publish' ) {
				return true;
			}
			$hasPayload = metadata_exists( 'post', $post->ID, self::META_DRAFT_PAYLOAD );
			$hasStatus  = metadata_exists( 'post', $post->ID, self::META_DRAFT_STATUS );
			return $hasPayload || $hasStatus;
		} );

		$inFlightStates = [
			self::STATUS_PENDING,
			self::STATUS_RUNNING,
		];

		$out = [];
		foreach ( $posts as $post ) {
			$slug = (string) get_post_meta( $post->ID, '_ep_plugin_slug', true );
			if ( $slug === '' ) {
				continue;
			}
			$payload     = self::getDraftPayload( $slug ) ?? [];
			$files       = is_array( $payload['files'] ?? NULL ) ? $payload['files'] : [];
			$draftStatus = (string) get_post_meta( $post->ID, self::META_DRAFT_STATUS, true );
			$draftStep   = (string) get_post_meta( $post->ID, self::META_DRAFT_STEP, true );
			$errorsRaw   = (string) get_post_meta( $post->ID, self::META_DRAFT_ERRORS, true );
			$errors      = $errorsRaw !== '' ? ( json_decode( $errorsRaw, true ) ?: [] ) : [];
			$prompt      = (string) get_post_meta( $post->ID, self::META_DRAFT_PROMPT, true );
			$updatedAt   = (int) get_post_meta( $post->ID, self::META_DRAFT_UPDATED_AT, true );

			$rawErrors = is_array( $errors ) ? array_values( $errors ) : [];
			$out[]     = [
				'slug'            => $slug,
				'name'            => $post->post_title,
				'post_status'     => $post->post_status,
				'draft_status'    => $draftStatus,
				'draft_step'      => $draftStep,
				'prompt'          => $prompt,
				'has_payload'     => ! empty( $files ),
				'in_flight'       => in_array( $draftStatus, $inFlightStates, true ),
				'stalled'         => in_array( $draftStatus, $inFlightStates, true )
					&& $updatedAt > 0
					&& $updatedAt < ( time() - 300 ),
				'updated_at'      => $updatedAt,
				'version'         => (string) ( $payload['manifest']['version'] ?? '' ),
				'files_count'     => count( $files ),
				'change_summary'  => is_array( $payload['change_summary'] ?? NULL ) ? $payload['change_summary'] : NULL,
				'origin_job_id'   => (string) get_post_meta( $post->ID, self::META_DRAFT_ORIGIN_JOB, true ),
				'errors'          => $rawErrors,
				'friendly_errors' => ! empty( $rawErrors ) ? \ExamplePress\MU\Governance\AppValidator::humanizeErrors( $rawErrors ) : [],
				'log'             => self::getDraftLog( $slug ),
			];
		}

		usort( $out, static fn( $a, $b ) => ( $b['updated_at'] ?? 0 ) <=> ( $a['updated_at'] ?? 0 ) );
		return $out;
	}

	public static function forget( string $slug ): bool {
		$post = self::getPost( $slug );
		if ( ! $post ) {
			return false;
		}
		// wp_delete_post returns the post object on success, false on
		// failure, and null when nothing was deleted. Previously we
		// returned true unconditionally which hid real failures from
		// callers (e.g. destroy() would report success even when the
		// CPT deletion silently failed). Return an honest bool so
		// downstream recovery paths can react.
		$result = wp_delete_post( $post->ID, true );
		return $result !== false && $result !== NULL;
	}

	/**
	 * Merge persistent registry with live filesystem state.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function listMerged(): array {
		$registry  = self::all();
		$localApps = AppDiscovery::scan();

		$localBySlug = [];
		foreach ( $localApps as $app ) {
			if ( ! is_array( $app ) ) {
				continue;
			}
			$appSlug = (string) ( $app['slug'] ?? '' );
			if ( $appSlug === '' ) {
				continue;
			}
			$localBySlug[ $appSlug ] = $app;
		}

		$merged = [];

		foreach ( $registry as $slug => $record ) {
			$local    = $localBySlug[ $slug ] ?? NULL;
			$merged[] = self::mergeRecord( $record, $local );
			unset( $localBySlug[ $slug ] );
		}

		foreach ( $localBySlug as $slug => $local ) {
			$adopted = [
				'slug'        => $slug,
				'name'        => (string) ( $local['name'] ?? $slug ),
				'description' => (string) ( $local['description'] ?? '' ),
				'version'     => (string) ( $local['version'] ?? '' ),
				'source'      => 'discovered',
			];

			if ( ! empty( $local['troy']['server_url'] ) ) {
				$adopted['troy'] = [
					'server_url' => (string) $local['troy']['server_url'],
					'repo'       => (string) ( $local['troy']['repo'] ?? '' ),
					'repo_id'    => (string) ( $local['troy']['repo_id'] ?? '' ),
				];
			}

			self::set( $slug, $adopted );
			$merged[] = self::mergeRecord( $adopted, $local );
		}

		/**
		 * Filter the merged registry/filesystem app list.
		 *
		 * @param array $merged Merged app records.
		 */
		return (array) apply_filters( 'examplepress_mu_apps_merged', $merged );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function mergeRecord( array $record, ?array $local ): array {
		$hasLocal = $local !== NULL;

		$troyServer = $hasLocal
			? ( $local['troy']['server_url'] ?? $record['troy']['server_url'] ?? '' )
			: ( $record['troy']['server_url'] ?? '' );
		$troyRepo   = $hasLocal
			? ( $local['troy']['repo'] ?? $record['troy']['repo'] ?? '' )
			: ( $record['troy']['repo'] ?? '' );
		$troyRepoId = $hasLocal
			? ( $local['troy']['repo_id'] ?? $record['troy']['repo_id'] ?? '' )
			: ( $record['troy']['repo_id'] ?? '' );

		$githubRepo   = $record['github']['owner_repo'] ?? '';
		$githubRepoId = $record['github']['repo_id'] ?? '';
		$githubUrl    = $record['github']['html_url'] ?? '';

		$hasGithub = ! empty( $githubRepo );
		$hasTroy   = ! empty( $troyServer );

		return [
			'slug'        => $record['slug'],
			'name'        => $hasLocal ? $local['name'] : ( $record['name'] ?? $record['slug'] ),
			'description' => $hasLocal ? $local['description'] : ( $record['description'] ?? '' ),
			'version'     => $hasLocal ? $local['version'] : ( $record['version'] ?? '' ),
			'created_at'  => $record['created_at'] ?? '',
			'source'      => $record['source'] ?? 'scaffolded',
			'local'       => [
				'installed'   => $hasLocal,
				'active'      => $hasLocal && $local['active'],
				'plugin_file' => $hasLocal ? $local['plugin_file'] : '',
			],
			'github'      => [
				'owner_repo' => $githubRepo,
				'repo_id'    => $githubRepoId,
				'html_url'   => $githubUrl,
			],
			'troy'        => [
				'server_url' => $troyServer,
				'repo'       => $troyRepo,
				'repo_id'    => $troyRepoId,
			],
			'status'      => $hasLocal && $hasGithub
				? 'connected'
				: ( $hasLocal ? 'disconnected' : 'orphan' ),
			'active'      => $hasLocal && $local['active'],
			'routing'     => $hasLocal ? ( $local['routing'] ?? [] ) : [],
			'orphan'      => ! $hasLocal && ( $hasGithub || $hasTroy ),
			'exists'      => [
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
	public static function destroy( string $slug ): array {
		$deleted  = [];
		$failed   = [];
		$warnings = [];

		// Defense in depth: the REST layer validates slug against
		// /^[a-z0-9-]+$/ before we get here, but AppRegistry::destroy
		// is a public API that could be called from WP-CLI, a test, or
		// a future admin handler that forgets to validate. A traversal
		// in $slug would mean the filesystem deletion below wipes out
		// a neighboring plugin directory. Refuse the call on anything
		// that fails Helpers::isSafeRelativePath.
		if ( ! Helpers::isSafeRelativePath( $slug ) || str_contains( $slug, '/' ) ) {
			return [
				'deleted'  => [],
				'failed'   => [ 'local', 'github', 'troy' ],
				'warnings' => [ 'Refused: slug "' . $slug . '" is not a safe directory name.' ],
			];
		}

		$record = self::get( $slug );

		$pluginDir = WP_PLUGIN_DIR . '/' . $slug;

		// 1. Deactivate + delete local plugin.
		if ( is_dir( $pluginDir ) ) {
			$pluginFile = $slug . '/' . $slug . '.php';

			if ( is_plugin_active( $pluginFile ) ) {
				deactivate_plugins( $pluginFile );
			}

			$fs = Helpers::filesystem();
			if ( $fs && $fs->delete( $pluginDir, true ) ) {
				$deleted[] = 'local';
			} else {
				$failed[]   = 'local';
				$warnings[] = 'Could not delete plugin directory.';
			}
		}

		// 2. Delete GitHub repo.
		$ownerRepo = $record['github']['owner_repo'] ?? '';

		if ( $ownerRepo ) {
			$pat = GitHub::writeToken();

			if ( $pat ) {
				$response = wp_remote_request( "https://api.github.com/repos/{$ownerRepo}", [
					'method'  => 'DELETE',
					'headers' => [
						'Authorization' => "Bearer {$pat}",
						'Accept'        => 'application/vnd.github.v3+json',
						'User-Agent'    => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
					],
					'timeout' => 15,
				] );

				if ( is_wp_error( $response ) ) {
					$failed[]   = 'github';
					$warnings[] = 'GitHub delete: ' . $response->get_error_message();
				} else {
					$code = wp_remote_retrieve_response_code( $response );

					if ( $code === 204 || $code === 404 ) {
						$deleted[] = 'github';
					} else {
						$failed[]   = 'github';
						$body       = json_decode( (string) wp_remote_retrieve_body( $response ), true );
						$message    = ( is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) )
							? $body['message']
							: "HTTP {$code}";
						$warnings[] = 'GitHub delete: ' . $message;
					}
				}
			} else {
				$failed[]   = 'github';
				$warnings[] = 'No GitHub write token — cannot delete repo.';
			}
		}

		// 3. Unregister from Troy.
		$troyServer = $record['troy']['server_url'] ?? '';

		if ( $troyServer ) {
			$troyUrl  = 'https://' . $troyServer;
			$troyAuth = get_option( 'ep_troy_credentials', '' );

			if ( $troyAuth ) {
				$response = wp_remote_request(
					"{$troyUrl}/wp-json/troy-server/v1/plugins/manage/unregister",
					[
						'method'  => 'POST',
						'headers' => [
							'Authorization' => 'Basic ' . base64_encode( $troyAuth ),
							'Content-Type'  => 'application/json',
						],
						'body'    => wp_json_encode( [ 'slug' => $slug ] ),
						'timeout' => 15,
					]
				);

				if ( is_wp_error( $response ) ) {
					$failed[]   = 'troy';
					$warnings[] = 'Troy unregister: ' . $response->get_error_message();
				} else {
					$code = wp_remote_retrieve_response_code( $response );

					if ( ( $code >= 200 && $code < 300 ) || $code === 404 ) {
						$deleted[] = 'troy';
					} else {
						$failed[]   = 'troy';
						$body       = json_decode( (string) wp_remote_retrieve_body( $response ), true );
						$message    = ( is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) )
							? $body['message']
							: "HTTP {$code}";
						$warnings[] = 'Troy unregister: ' . $message;
					}
				}
			} else {
				$failed[]   = 'troy';
				$warnings[] = 'No Troy credentials — cannot unregister.';
			}
		}

		// 4. Remove from registry.
		self::forget( $slug );

		// 5. Invalidate the AppDiscovery cache so the next admin read
		//    doesn't serve a ghost entry for the just-destroyed plugin.
		//    The mtime fingerprint would eventually catch this, but an
		//    explicit flush guarantees the next REST hit is fresh.
		AppDiscovery::flushCache();

		return [
			'deleted'  => $deleted,
			'failed'   => $failed,
			'warnings' => $warnings,
		];
	}
}
