<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * ExamplePress MU — Self-Updater
 *
 * Checks the updates.json attached to the latest GitHub release on a WP-Cron
 * schedule (twicedaily). If a newer version is found, silently downloads and
 * overwrites the kernel and loader files.
 *
 * Uses WP-Cron instead of admin_init so the kernel updates in the background
 * even if an administrator never logs in.
 */
final class Updater
{
    public const CHECK_INTERVAL   = 12 * 3600; // HOUR_IN_SECONDS may not be defined yet.
    public const TRANSIENT_KEY    = 'ep_mu_update_check';
    public const UPDATES_FILENAME = 'updates.json';
    public const CRON_HOOK        = 'examplepress_mu_update_check';

    /** @var string */
    private static string $repoOwner = 'webmultipliers';

    /** @var string */
    private static string $repoName = 'examplepress-mu';

    /**
     * Register the WP-Cron event (if not already scheduled) and hook the
     * cron action callback.
     */
    public static function init(): void
    {
        // Hook the callback that WP-Cron will fire.
        add_action(self::CRON_HOOK, [self::class, 'maybeCheckForUpdate']);

        // Schedule the recurring event if it isn't already.
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    /**
     * WP-Cron callback — run the update check if the transient has expired.
     *
     * The transient acts as a secondary guard against overlapping checks
     * (e.g. if cron fires twice in quick succession).
     */
    public static function maybeCheckForUpdate(): void
    {
        // Guard the public do_action entry point. WP-Cron is HTTP-triggerable,
        // so we only permit this callback to do work when it's actually cron
        // or CLI driving it — not an arbitrary HTTP request happening to
        // fire examplepress_mu_update_check.
        $isCron = function_exists('wp_doing_cron') && wp_doing_cron();
        $isCli  = defined('WP_CLI') && WP_CLI;
        if (!$isCron && !$isCli) {
            return;
        }

        /**
         * Update window hook. Return false to skip this cron tick — useful
         * for honoring publish-in-progress locks, quiet hours, or release
         * freezes without disabling the cron event altogether.
         */
        if (!apply_filters('examplepress_mu_should_update_now', true)) {
            return;
        }

        // Multisite-safe: site transients are network-scoped. A per-blog
        // transient would let two blogs race the same swap on the shared
        // mu-plugins/ filesystem.
        if (false !== get_site_transient(self::TRANSIENT_KEY)) {
            return;
        }

        // Short in-flight lock (60s) — only about race protection. We extend
        // this to the full CHECK_INTERVAL *after* a successful fetch, so a
        // GitHub outage can't lock out retries for 12 hours.
        set_site_transient(self::TRANSIENT_KEY, 'checking', 60);

        $remoteVersion = self::fetchRemoteVersion();

        if (! $remoteVersion) {
            // Leave the 60s lock in place; it will expire naturally and let
            // the next cron tick retry.
            return;
        }

        // Fetch succeeded — extend the throttle to the real check interval.
        set_site_transient(self::TRANSIENT_KEY, 'checked', self::CHECK_INTERVAL);

        if (version_compare($remoteVersion['version'], EXAMPLEPRESS_MU_VERSION, '>')) {
            self::performUpdate($remoteVersion['package'], $remoteVersion['checksum']);
        }
    }

    /**
     * Fetch the updates.json from the latest GitHub release.
     *
     * @return array{version: string, package: string, checksum: string}|null
     */
    public static function fetchRemoteVersion(): ?array
    {
        $apiUrl  = 'https://api.github.com/repos/' . self::$repoOwner . '/' . self::$repoName . '/releases/latest';
        $response = wp_remote_get($apiUrl, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ExamplePress-Updater',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($release) || empty($release['assets']) || !is_array($release['assets'])) {
            return null;
        }

        // Find the updates.json asset.
        $updatesUrl = null;
        foreach ($release['assets'] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === self::UPDATES_FILENAME) {
                $updatesUrl = $asset['browser_download_url'] ?? null;
                break;
            }
        }

        if (! $updatesUrl) {
            return null;
        }

        $updatesResponse = wp_remote_get($updatesUrl, [
            'headers' => ['User-Agent' => 'ExamplePress-Updater'],
            'timeout' => 10,
        ]);

        if (is_wp_error($updatesResponse) || wp_remote_retrieve_response_code($updatesResponse) !== 200) {
            return null;
        }

        $updates = json_decode(wp_remote_retrieve_body($updatesResponse), true);

        if (empty($updates['version']) || empty($updates['packages'][0]['package'])) {
            return null;
        }

        return [
            'version'  => $updates['version'],
            'package'  => $updates['packages'][0]['package'],
            'checksum' => $updates['packages'][0]['checksum'] ?? '',
        ];
    }

    /**
     * Download the ZIP and overwrite the kernel + loader in place.
     *
     * @param string $packageUrl Direct download URL for the release ZIP.
     * @param string $checksum   Expected SHA-256 hash of the ZIP.
     */
    public static function performUpdate(string $packageUrl, string $checksum = ''): bool
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        // The loader (examplepress-mu.php) is guaranteed to be in scope —
        // it's what required bootstrap.php and booted us — so the
        // ExamplePress_MU_Bootstrapper class is already available and
        // owns the single install code path (cold-start + self-upgrade).

        $tempFile = download_url($packageUrl);

        if (is_wp_error($tempFile)) {
            error_log('ExamplePress MU Updater: Download failed — ' . $tempFile->get_error_message());
            return false;
        }

        // Verify checksum if provided.
        if ($checksum && hash_file('sha256', $tempFile) !== $checksum) {
            @unlink($tempFile);
            error_log('ExamplePress MU Updater: Checksum mismatch. Update aborted.');
            return false;
        }

        $ok = \ExamplePress_MU_Bootstrapper::installFromZip(
            $tempFile,
            dirname(EXAMPLEPRESS_MU_DIR),
            'Updater'
        );

        if ($ok) {
            // Clear the transient so the next check picks up the new version.
            // Note: the new code only takes effect on the NEXT request — the
            // currently-running kernel cannot re-require its own replacement.
            delete_site_transient(self::TRANSIENT_KEY);
            error_log('ExamplePress MU Updater: Successfully updated to latest version.');
        }

        return $ok;
    }
}
