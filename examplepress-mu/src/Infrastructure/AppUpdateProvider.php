<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * GitHub release-based update provider for ExamplePress companion apps.
 *
 * Hooks into WordPress's native plugin update system so companion apps
 * with a GitHub repo show update badges and can be updated with one
 * click from the Plugins screen — just like wordpress.org plugins.
 *
 * For each companion app that has a GitHub owner_repo in the registry,
 * this class checks the repo's latest release, compares versions, and
 * injects update data into the `update_plugins` site transient.
 */
final class AppUpdateProvider
{
    private const TRANSIENT_KEY  = 'ep_app_update_data';
    private const CHECK_INTERVAL = 6 * 3600; // 6 hours

    /** In-flight lock transient used by the cron callback to prevent
     *  overlapping fetches during the same cron tick window. Separate
     *  key from the data transient so one can't clobber the other. */
    private const LOCK_KEY = 'ep_app_update_check_lock';

    /** WP-Cron hook for the background update check. */
    public const CRON_HOOK = 'examplepress_mu_app_update_check';

    /** In-process memo for getUpdateData(). */
    private static ?array $memo = null;

    public static function init(): void
    {
        // Inject update data into the WordPress update transient.
        // This now READS ONLY — never triggers a synchronous GitHub fetch.
        // The background cron below is the only thing that populates the
        // transient. Consequences:
        //   1. First admin hit after a fresh install shows no update
        //      badges until the next cron tick (~60s in typical wp-cron
        //      configurations). This matches how WordPress.org plugin
        //      updates work — WP doesn't refetch on every page render.
        //   2. A slow or rate-limited GitHub response can no longer
        //      block an admin page render. Previously, a cold cache on
        //      a site with N companion apps could block for up to N*10s
        //      inside pre_set_site_transient_update_plugins.
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'injectUpdates']);

        // Provide plugin info for the "View Details" modal.
        add_filter('plugins_api', [self::class, 'pluginInfo'], 10, 3);

        // Rename GitHub archive directories during install to match the slug.
        add_filter('upgrader_source_selection', [self::class, 'fixSourceDir'], 10, 4);

        // Flush both the transient and the in-process memo after WordPress
        // finishes an upgrade/install via the injected update record —
        // otherwise the "update available" badge sticks around for 6h.
        add_action('upgrader_process_complete', [self::class, 'flush']);

        // Background refresh: twicedaily cron job hits GitHub for the
        // latest releases of every companion app and caches the result
        // in the 6-hour transient. The callback is guarded against
        // HTTP-triggered wp-cron hijacks so only genuine cron/CLI
        // runs do the fetch.
        add_action(self::CRON_HOOK, [self::class, 'maybeCheckForUpdates']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // time() so the first execution fires on the next cron tick
            // rather than waiting ~12 hours for the twicedaily interval.
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    /**
     * WP-Cron callback — refresh the update cache in the background.
     *
     * Guarded against HTTP-triggered wp-cron hijacks (the do_action
     * entry point is publicly reachable, so anyone can fire this hook
     * without context). We only run when wp_doing_cron() or WP-CLI is
     * driving the call, AND when the operator-controlled
     * examplepress_mu_should_update_now filter says it's ok.
     */
    public static function maybeCheckForUpdates(): void
    {
        $isCron = function_exists('wp_doing_cron') && wp_doing_cron();
        $isCli  = defined('WP_CLI') && \WP_CLI;
        if (!$isCron && !$isCli) {
            return;
        }

        /**
         * Shared update-window hook with the kernel Updater. Return false
         * to skip this cron tick — useful for publish-in-progress locks,
         * quiet hours, or release freezes.
         */
        if (!apply_filters('examplepress_mu_should_update_now', true)) {
            return;
        }

        // In-flight lock: short (60s) transient that prevents two
        // overlapping cron ticks from hitting GitHub simultaneously
        // for the same site. The LONG-lived cache is the separate
        // TRANSIENT_KEY — we never use one for both roles because the
        // data payload and the lock need independent TTLs.
        if (false !== get_site_transient(self::LOCK_KEY)) {
            return;
        }
        set_site_transient(self::LOCK_KEY, 'checking', 60);

        try {
            self::refreshCache();
        } finally {
            // Always clear the lock, even on exception, so a transient
            // error doesn't wedge the cron loop for 60 seconds per tick.
            delete_site_transient(self::LOCK_KEY);
        }
    }

    /**
     * Refresh the update cache synchronously. Exposed as a public
     * method so REST/CLI callers can force a refresh without waiting
     * for the next cron tick (e.g. a "Check for updates" button in
     * the admin UI). Bypasses the cron/CLI guard but not the
     * examplepress_mu_should_update_now filter.
     */
    public static function checkNow(): void
    {
        if (!apply_filters('examplepress_mu_should_update_now', true)) {
            return;
        }
        self::refreshCache();
    }

    /**
     * Run fetchAllUpdates() and persist the result. Separated from
     * maybeCheckForUpdates() so checkNow() can share it without
     * duplicating the cache-write path.
     */
    private static function refreshCache(): void
    {
        $fresh = self::fetchAllUpdates();
        set_site_transient(self::TRANSIENT_KEY, $fresh, self::CHECK_INTERVAL);
        self::$memo = $fresh;
    }

    /**
     * Check companion app repos for new releases and inject update
     * records into the WordPress update_plugins transient.
     */
    public static function injectUpdates(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }

        if (!isset($transient->response)) {
            $transient->response = [];
        }

        $updates = self::getUpdateData();

        foreach ($updates as $pluginFile => $update) {
            if (!empty($update['update_available'])) {
                $transient->response[$pluginFile] = (object) [
                    'slug'        => $update['slug'],
                    'plugin'      => $pluginFile,
                    'new_version' => $update['new_version'],
                    'package'     => $update['package'],
                    'url'         => $update['html_url'],
                    'tested'      => '',
                    'icons'       => [],
                ];
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View version X details" link.
     */
    public static function pluginInfo(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $updates = self::getUpdateData();
        $slug = $args->slug ?? '';

        // Find the matching app.
        foreach ($updates as $pluginFile => $update) {
            if ($update['slug'] !== $slug) {
                continue;
            }

            return (object) [
                'name'          => $update['name'],
                'slug'          => $update['slug'],
                'version'       => $update['new_version'],
                'author'        => '<a href="https://github.com/' . esc_attr($update['owner_repo']) . '">GitHub</a>',
                'homepage'      => $update['html_url'],
                'download_link' => $update['package'],
                'requires'      => '',
                'tested'        => '',
                'sections'      => [
                    'description'  => $update['description'] ?: 'An ExamplePress companion app.',
                    'changelog'    => $update['changelog'] ?: 'See the <a href="' . esc_url($update['release_url']) . '">GitHub release</a> for details.',
                ],
            ];
        }

        return $result;
    }

    /**
     * Fix the extracted directory name when installing from GitHub.
     *
     * GitHub archive ZIPs extract to "owner-repo-hash/" instead of "slug/".
     * This renames the directory to match the plugin slug.
     */
    public static function fixSourceDir(string $source, string $remoteSource, \WP_Upgrader $upgrader, array $extra): string
    {
        $pluginFile = $extra['plugin'] ?? '';
        if (!$pluginFile) {
            return $source;
        }

        $updates = self::getUpdateData();
        if (!isset($updates[$pluginFile])) {
            return $source;
        }

        $slug = $updates[$pluginFile]['slug'];
        $expected = trailingslashit($remoteSource) . $slug . '/';

        if ($source === $expected) {
            return $source;
        }

        $basename = basename(untrailingslashit($source));
        if ($basename !== $slug) {
            global $wp_filesystem;
            if ($wp_filesystem instanceof \WP_Filesystem_Base && $wp_filesystem->move($source, $expected, true)) {
                return $expected;
            }
        }

        return $source;
    }

    /**
     * Get cached update data for all companion apps. READ-ONLY — never
     * triggers a synchronous GitHub fetch. The background cron
     * (maybeCheckForUpdates) and the explicit manual-refresh entry
     * point (checkNow) are the only things that populate the cache.
     *
     * Returns an empty array when the cache hasn't been warmed yet,
     * which makes `injectUpdates()` a no-op on the first admin hit
     * after a fresh install. The next cron tick will populate it.
     *
     * @return array<string, array<string, mixed>> Keyed by plugin_file.
     */
    private static function getUpdateData(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cached = get_site_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            self::$memo = $cached;
            return self::$memo;
        }

        // Cold cache — return empty and let the cron populate in the
        // background. DO NOT fetch synchronously here; the whole point
        // of the refactor is to keep admin page renders off the
        // GitHub-latency critical path.
        self::$memo = [];
        return self::$memo;
    }

    /**
     * Query GitHub for the latest release of every companion app
     * that has a GitHub repo configured.
     *
     * Three-phase:
     *   1. Resolve each app's owner/repo + build a request map keyed
     *      by plugin_file. Apps without a valid repo are dropped.
     *   2. Batch-execute the HTTP calls via batchGitHubRequests(),
     *      which prefers WpOrg\Requests\Requests::request_multiple for
     *      genuine parallelism, falling back to sequential wp_remote_get
     *      when the bundled Requests library isn't available.
     *   3. Walk the context map, match each entry against the response,
     *      parse the release payload, and build the update record.
     *
     * The parallelization matters because this runs in the cron
     * background and was previously O(n_apps × 10s) in wall time on a
     * site where every repo is cold-cached. Now it's one batch call
     * with a 5s per-request timeout — a 10-app site finishes in ~5s
     * instead of 100s if all repos stall.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fetchAllUpdates(): array
    {
        $apps     = AppDiscovery::scan();
        $registry = AppRegistry::all();

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        ];
        $token = GitHub::writeToken() ?: GitHub::readToken();
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // Phase 1: build the request and context maps.
        $requests = [];
        $contexts = [];

        foreach ($apps as $app) {
            // Per-entry shape guard — AppDiscovery::scan builds these
            // from the plugin manifest, so they're normally well-formed,
            // but a hostile examplepress_mu_discovered_apps filter could
            // inject non-array entries.
            if (!is_array($app)) {
                continue;
            }

            $slug           = (string) ($app['slug'] ?? '');
            $pluginFile     = (string) ($app['plugin_file'] ?? '');
            $currentVersion = (string) ($app['version'] ?? '');

            if ($slug === '' || $pluginFile === '') {
                continue;
            }

            // Resolve GitHub owner_repo from registry or troy config.
            $ownerRepo = '';
            if (isset($registry[$slug]) && is_array($registry[$slug])) {
                $ownerRepo = (string) ($registry[$slug]['github']['owner_repo'] ?? '');
            }
            if ($ownerRepo === '') {
                $ownerRepo = (string) ($app['troy']['repo'] ?? '');
            }
            if ($ownerRepo === '') {
                // Try inferring from org setting.
                $org = (string) get_option('ep_github_org', '');
                if ($org !== '') {
                    $ownerRepo = $org . '/' . $slug;
                }
            }

            if ($ownerRepo === '') {
                continue;
            }

            // Defence in depth: never trust a filter-supplied or
            // registry-supplied repo string as a URL path segment
            // without validating its shape. A malformed value here
            // becomes the PATH in the GitHub API URL below.
            if (!Helpers::isValidGitHubRepo($ownerRepo)) {
                continue;
            }

            $requests[$pluginFile] = [
                'url'     => "https://api.github.com/repos/{$ownerRepo}/releases/latest",
                'headers' => $headers,
            ];
            $contexts[$pluginFile] = [
                'slug'            => $slug,
                'name'            => (string) ($app['name'] ?? $slug),
                'description'     => (string) ($app['description'] ?? ''),
                'owner_repo'      => $ownerRepo,
                'current_version' => $currentVersion,
            ];
        }

        if (empty($requests)) {
            return [];
        }

        // Phase 2: batch-execute the requests.
        $bodies = self::batchGitHubRequests($requests);

        // Phase 3: merge responses with contexts into the final payload.
        $updates = [];
        foreach ($contexts as $pluginFile => $ctx) {
            $body = $bodies[$pluginFile] ?? null;
            if (!is_string($body)) {
                continue;
            }
            $release = self::parseReleaseBody($body, $ctx['slug']);
            if ($release === null) {
                continue;
            }

            $newVersion = ltrim($release['tag_name'], 'v');
            $hasUpdate  = $newVersion !== '' && version_compare($newVersion, $ctx['current_version'], '>');

            $updates[$pluginFile] = [
                'slug'             => $ctx['slug'],
                'name'             => $ctx['name'],
                'description'      => $ctx['description'],
                'owner_repo'       => $ctx['owner_repo'],
                'current_version'  => $ctx['current_version'],
                'new_version'      => $newVersion,
                'update_available' => $hasUpdate,
                'package'          => $release['package_url'],
                'html_url'         => "https://github.com/{$ctx['owner_repo']}",
                'release_url'      => $release['html_url'],
                'changelog'        => $release['body'],
            ];
        }

        return $updates;
    }

    /**
     * Execute a batch of GitHub requests in parallel when possible.
     *
     * WordPress 6.2+ ships the Requests library under the
     * WpOrg\Requests namespace with a request_multiple() method that
     * runs requests concurrently via curl_multi_* under the hood.
     * Using it cuts the cron's wall time from O(n) to O(1) for sites
     * with several companion apps.
     *
     * If the namespaced class isn't available (older WordPress, or a
     * plugin that has replaced the Requests library), we fall back to
     * sequential wp_remote_get calls with a short per-request timeout.
     *
     * @param array<string, array{url: string, headers: array<string,string>}> $requests
     * @return array<string, string|null> Map of request key to response body
     *                                    or null on failure.
     */
    private static function batchGitHubRequests(array $requests): array
    {
        $perRequestTimeout = 5;

        $parallelClass = '\\WpOrg\\Requests\\Requests';
        if (class_exists($parallelClass) && method_exists($parallelClass, 'request_multiple')) {
            try {
                return self::batchViaRequestsLibrary($requests, $perRequestTimeout);
            } catch (\Throwable $e) {
                // Fall through to sequential fallback. Log so operators
                // know why parallelism silently degraded.
                error_log(
                    '[ExamplePress AppUpdateProvider] Parallel update fetch failed, '
                    . 'falling back to sequential: ' . $e->getMessage()
                );
            }
        }

        return self::batchSequential($requests, $perRequestTimeout);
    }

    /**
     * Execute requests in parallel via the bundled Requests library.
     *
     * @param array<string, array{url: string, headers: array<string,string>}> $requests
     * @return array<string, string|null>
     */
    private static function batchViaRequestsLibrary(array $requests, int $timeout): array
    {
        $req = [];
        foreach ($requests as $key => $r) {
            $req[$key] = [
                'url'     => $r['url'],
                'headers' => $r['headers'],
                'type'    => 'GET',
                'cookies' => [],
                'data'    => [],
            ];
        }

        $options = [
            'timeout'            => $timeout,
            'connect_timeout'    => $timeout,
            'useragent'          => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
            'follow_redirects'   => true,
            'redirects'          => 3,
        ];

        /** @var array<string, mixed> $responses */
        $responses = \WpOrg\Requests\Requests::request_multiple($req, $options);

        $out = [];
        foreach ($responses as $key => $response) {
            // Request-level exceptions become \WpOrg\Requests\Exception
            // instances in the result array rather than being thrown.
            if ($response instanceof \WpOrg\Requests\Exception) {
                $out[$key] = null;
                continue;
            }
            if (!($response instanceof \WpOrg\Requests\Response)) {
                $out[$key] = null;
                continue;
            }
            if ((int) $response->status_code !== 200) {
                $out[$key] = null;
                continue;
            }
            $out[$key] = (string) $response->body;
        }
        return $out;
    }

    /**
     * Sequential fallback for environments without the parallel
     * Requests library. Uses a short per-request timeout to cap the
     * worst-case runtime at O(n × timeout).
     *
     * @param array<string, array{url: string, headers: array<string,string>}> $requests
     * @return array<string, string|null>
     */
    private static function batchSequential(array $requests, int $timeout): array
    {
        $out = [];
        foreach ($requests as $key => $r) {
            $response = wp_remote_get($r['url'], [
                'headers' => $r['headers'],
                'timeout' => $timeout,
            ]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                $out[$key] = null;
                continue;
            }
            $out[$key] = (string) wp_remote_retrieve_body($response);
        }
        return $out;
    }

    /**
     * Parse a GitHub /releases/latest response body into the shape
     * fetchAllUpdates() consumes. Returns null when the body is
     * non-JSON, malformed, or represents a draft/prerelease build.
     *
     * Extracted so both the batched and sequential paths share one
     * parser — and so the parser is trivially unit-testable without
     * needing a real HTTP roundtrip.
     *
     * @return array{tag_name: string, html_url: string, body: string, package_url: string}|null
     */
    public static function parseReleaseBody(string $body, string $slug): ?array
    {
        $release = json_decode($body, true);

        // Skip draft and prerelease builds. /releases/latest already
        // excludes prereleases on GitHub's side, but guard anyway in
        // case this method is ever fed a response from /releases
        // (which lists them).
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
            return null;
        }

        // Prefer the built ZIP asset named "{slug}.zip".
        $packageUrl = '';
        $assets     = $release['assets'] ?? [];
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                if (($asset['name'] ?? '') !== $slug . '.zip') {
                    continue;
                }
                $url = $asset['browser_download_url'] ?? '';
                if (is_string($url) && $url !== '') {
                    $packageUrl = $url;
                    break;
                }
            }
        }

        // Fallback to GitHub's source archive.
        if ($packageUrl === '') {
            $packageUrl = (string) ($release['zipball_url'] ?? '');
        }

        return [
            'tag_name'    => (string) ($release['tag_name'] ?? ''),
            'html_url'    => (string) ($release['html_url'] ?? ''),
            'body'        => (string) ($release['body'] ?? ''),
            'package_url' => $packageUrl,
        ];
    }

    /**
     * Clear the cached update data.
     *
     * Called after scaffolding, connecting, or destroying an app so
     * the next update check reflects the new state. Since admin
     * requests no longer trigger a synchronous refresh, we ALSO
     * enqueue a single-event cron tick to repopulate the cache ASAP —
     * otherwise a post-flush page load would see no update badges
     * for up to ~12 hours until the next scheduled twicedaily run.
     *
     * wp_schedule_single_event is idempotent per (timestamp, hook, args)
     * so calling it multiple times in quick succession coalesces into
     * one scheduled run.
     */
    public static function flush(): void
    {
        self::$memo = null;
        delete_site_transient(self::TRANSIENT_KEY);
        // Also drop the in-flight lock so the next cron tick — which
        // may be the one we're about to schedule — can immediately
        // run a fresh check instead of hitting a stale 60s lock from
        // a previous aborted run.
        delete_site_transient(self::LOCK_KEY);

        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
            // Schedule the async repopulation for the next cron tick
            // (wp-cron checks on any request after the timestamp has
            // passed, so "now" means "within seconds of the next
            // admin page load"). Only schedule if nothing is already
            // pending to avoid piling up events on a flush-storm.
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time(), self::CRON_HOOK);
            }
        }
    }
}
