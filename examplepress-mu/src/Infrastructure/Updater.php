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
        if (false !== get_transient(self::TRANSIENT_KEY)) {
            return;
        }

        // Set the transient immediately to prevent race conditions on concurrent requests.
        set_transient(self::TRANSIENT_KEY, 'checking', self::CHECK_INTERVAL);

        $remoteVersion = self::fetchRemoteVersion();

        if (! $remoteVersion) {
            return;
        }

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

        $wp_filesystem = Helpers::filesystem(forceDirect: true);

        if (!$wp_filesystem) {
            error_log('ExamplePress MU Updater: Filesystem unavailable — direct access denied.');
            return false;
        }

        $tempFile = download_url($packageUrl);

        if (is_wp_error($tempFile)) {
            error_log('ExamplePress MU Updater: Download failed — ' . $tempFile->get_error_message());
            return false;
        }

        // Verify checksum if provided.
        if ($checksum && hash_file('sha256', $tempFile) !== $checksum) {
            unlink($tempFile);
            error_log('ExamplePress MU Updater: Checksum mismatch. Update aborted.');
            return false;
        }

        $muPluginsDir   = dirname(EXAMPLEPRESS_MU_DIR);
        $tempExtractDir = $muPluginsDir . '/_ep_mu_update_temp';
        $backupDir      = $muPluginsDir . '/_ep_mu_update_backup';
        $kernelDir      = $muPluginsDir . '/examplepress-mu';
        $loaderFile     = $muPluginsDir . '/examplepress-mu.php';

        // Clean up any leftovers from a previous failed attempt.
        $wp_filesystem->delete($tempExtractDir, true);
        $wp_filesystem->delete($backupDir, true);

        $wp_filesystem->mkdir($tempExtractDir);

        $unzipResult = unzip_file($tempFile, $tempExtractDir);
        unlink($tempFile);

        if (is_wp_error($unzipResult)) {
            $wp_filesystem->delete($tempExtractDir, true);
            error_log('ExamplePress MU Updater: Unzip failed — ' . $unzipResult->get_error_message());
            return false;
        }

        $hasNewKernel = is_dir($tempExtractDir . '/examplepress-mu');
        $hasNewLoader = file_exists($tempExtractDir . '/examplepress-mu.php');

        if (!$hasNewKernel && !$hasNewLoader) {
            $wp_filesystem->delete($tempExtractDir, true);
            error_log('ExamplePress MU Updater: Release archive missing expected payload.');
            return false;
        }

        // Stage the current kernel/loader to a backup so we can roll back.
        $wp_filesystem->mkdir($backupDir);
        $kernelBackedUp = false;
        $loaderBackedUp = false;

        if ($hasNewKernel && is_dir($kernelDir)) {
            if (!$wp_filesystem->move($kernelDir, $backupDir . '/examplepress-mu', true)) {
                $wp_filesystem->delete($tempExtractDir, true);
                $wp_filesystem->delete($backupDir, true);
                error_log('ExamplePress MU Updater: Could not stage kernel backup.');
                return false;
            }
            $kernelBackedUp = true;
        }

        if ($hasNewLoader && file_exists($loaderFile)) {
            if (!$wp_filesystem->move($loaderFile, $backupDir . '/examplepress-mu.php', true)) {
                // Restore kernel and abort.
                if ($kernelBackedUp) {
                    $wp_filesystem->move($backupDir . '/examplepress-mu', $kernelDir, true);
                }
                $wp_filesystem->delete($tempExtractDir, true);
                $wp_filesystem->delete($backupDir, true);
                error_log('ExamplePress MU Updater: Could not stage loader backup.');
                return false;
            }
            $loaderBackedUp = true;
        }

        // Swap in the new payload.
        $swapOk = true;

        if ($hasNewKernel) {
            $swapOk = $swapOk && $wp_filesystem->move(
                $tempExtractDir . '/examplepress-mu',
                $kernelDir,
                true
            );
        }

        if ($swapOk && $hasNewLoader) {
            $swapOk = $swapOk && $wp_filesystem->move(
                $tempExtractDir . '/examplepress-mu.php',
                $loaderFile,
                true
            );
        }

        if (!$swapOk) {
            // Roll back to the previous version.
            $wp_filesystem->delete($kernelDir, true);
            if ($kernelBackedUp) {
                $wp_filesystem->move($backupDir . '/examplepress-mu', $kernelDir, true);
            }
            if ($loaderBackedUp) {
                $wp_filesystem->move($backupDir . '/examplepress-mu.php', $loaderFile, true);
            }
            $wp_filesystem->delete($tempExtractDir, true);
            $wp_filesystem->delete($backupDir, true);
            error_log('ExamplePress MU Updater: Atomic swap failed; previous version restored.');
            return false;
        }

        $wp_filesystem->delete($tempExtractDir, true);
        $wp_filesystem->delete($backupDir, true);

        // Clear the transient so the next check picks up the new version.
        delete_transient(self::TRANSIENT_KEY);

        error_log('ExamplePress MU Updater: Successfully updated to latest version.');

        return true;
    }
}
