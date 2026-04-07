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
    private const TRANSIENT_KEY = 'ep_app_update_data';
    private const CHECK_INTERVAL = 6 * 3600; // 6 hours

    /** In-process memo for getUpdateData(). */
    private static ?array $memo = null;

    public static function init(): void
    {
        // Inject update data into the WordPress update transient.
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'injectUpdates']);

        // Provide plugin info for the "View Details" modal.
        add_filter('plugins_api', [self::class, 'pluginInfo'], 10, 3);

        // Rename GitHub archive directories during install to match the slug.
        add_filter('upgrader_source_selection', [self::class, 'fixSourceDir'], 10, 4);

        // Flush both the transient and the in-process memo after WordPress
        // finishes an upgrade/install via the injected update record —
        // otherwise the "update available" badge sticks around for 6h.
        add_action('upgrader_process_complete', [self::class, 'flush']);
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
     * Get cached update data for all companion apps.
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

        self::$memo = self::fetchAllUpdates();
        set_site_transient(self::TRANSIENT_KEY, self::$memo, self::CHECK_INTERVAL);

        return self::$memo;
    }

    /**
     * Query GitHub for the latest release of every companion app
     * that has a GitHub repo configured.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fetchAllUpdates(): array
    {
        $apps = AppDiscovery::scan();
        $registry = AppRegistry::all();
        $updates = [];

        $token = GitHub::writeToken() ?: GitHub::readToken();
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'ExamplePress/' . EXAMPLEPRESS_MU_VERSION,
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        foreach ($apps as $app) {
            $slug = $app['slug'];
            $pluginFile = $app['plugin_file'];
            $currentVersion = $app['version'];

            // Resolve GitHub owner_repo from registry or troy config.
            $ownerRepo = '';
            if (isset($registry[$slug])) {
                $ownerRepo = $registry[$slug]['github']['owner_repo'] ?? '';
            }
            if (!$ownerRepo) {
                $ownerRepo = $app['troy']['repo'] ?? '';
            }
            if (!$ownerRepo) {
                // Try inferring from org setting.
                $org = get_option('ep_github_org', '');
                if ($org) {
                    $ownerRepo = $org . '/' . $slug;
                }
            }

            if (!$ownerRepo) {
                continue;
            }

            $release = self::fetchLatestRelease($ownerRepo, $slug, $headers);

            if (!$release) {
                continue;
            }

            $newVersion = ltrim($release['tag_name'] ?? '', 'v');
            $hasUpdate = $newVersion && version_compare($newVersion, $currentVersion, '>');

            $updates[$pluginFile] = [
                'slug'             => $slug,
                'name'             => $app['name'],
                'description'      => $app['description'],
                'owner_repo'       => $ownerRepo,
                'current_version'  => $currentVersion,
                'new_version'      => $newVersion,
                'update_available' => $hasUpdate,
                'package'          => $release['package_url'] ?? '',
                'html_url'         => "https://github.com/{$ownerRepo}",
                'release_url'      => $release['html_url'] ?? '',
                'changelog'        => $release['body'] ?? '',
            ];
        }

        return $updates;
    }

    /**
     * Fetch the latest non-draft, non-prerelease release for a repo.
     *
     * Prefers a built ZIP asset named "{slug}.zip". Falls back to
     * the GitHub zipball_url (source archive).
     *
     * @return array{tag_name: string, html_url: string, body: string, package_url: string}|null
     */
    private static function fetchLatestRelease(string $ownerRepo, string $slug, array $headers): ?array
    {
        $response = wp_remote_get(
            "https://api.github.com/repos/{$ownerRepo}/releases/latest",
            ['timeout' => 10, 'headers' => $headers]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        // Skip draft and prerelease builds. /releases/latest already excludes
        // prereleases on GitHub's side, but guard anyway in case this method
        // is ever repointed at /releases (which lists them).
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
            return null;
        }

        // Prefer the built ZIP asset named "{slug}.zip".
        $packageUrl = '';
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === $slug . '.zip') {
                $packageUrl = $asset['browser_download_url'];
                break;
            }
        }

        // Fallback to GitHub's source archive.
        if (!$packageUrl) {
            $packageUrl = $release['zipball_url'] ?? '';
        }

        return [
            'tag_name'    => $release['tag_name'] ?? '',
            'html_url'    => $release['html_url'] ?? '',
            'body'        => $release['body'] ?? '',
            'package_url' => $packageUrl,
        ];
    }

    /**
     * Clear the cached update data.
     *
     * Call this after scaffolding, connecting, or destroying an app
     * so the next update check reflects the new state.
     */
    public static function flush(): void
    {
        self::$memo = null;
        delete_site_transient(self::TRANSIENT_KEY);
    }
}
