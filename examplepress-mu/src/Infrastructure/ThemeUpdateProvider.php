<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * GitHub release-based update provider for the ExamplePress theme.
 *
 * Ported from the former `examplepress-theme-update` companion plugin so
 * the kernel can own the theme update lifecycle directly — no extra
 * plugin to install, deactivate, or lose.
 *
 * Hooks into `pre_set_site_transient_update_themes` to inject an update
 * record for the theme when a newer release exists on GitHub, and
 * `themes_api` so the "View version details" modal works. Also provides
 * install/reinstall entry points used by ThemeUpdateController.
 *
 * Settings live in options:
 *   ep_theme_update_channel       stable|development
 *   ep_theme_update_pinned        version string or empty
 *   ep_theme_update_last_checked  unix timestamp of last successful check
 *
 * Channel resolution priority:
 *   1. examplepress_mu_theme_update_channel filter
 *   2. EP_THEME_UPDATE_CHANNEL constant
 *   3. ep_theme_update_channel option
 *   4. Auto-detect from installed theme version (dev/alpha/beta/rc → development)
 *   5. 'stable'
 */
final class ThemeUpdateProvider
{
    public const THEME_SLUG = 'examplepress-theme';

    // ── Options ─────────────────────────────────────────────────────
    private const CHANNEL_OPTION      = 'ep_theme_update_channel';
    private const PIN_OPTION          = 'ep_theme_update_pinned';
    private const LAST_CHECKED_OPTION = 'ep_theme_update_last_checked';

    // ── Transients ──────────────────────────────────────────────────
    private const MANIFEST_KEY = 'ep_theme_update_manifest';
    private const RELEASES_KEY = 'ep_theme_update_releases';
    private const MANIFEST_TTL = 6 * HOUR_IN_SECONDS;
    private const RELEASES_TTL = 30 * MINUTE_IN_SECONDS;
    private const ERROR_TTL    = 5 * MINUTE_IN_SECONDS;

    private const VALID_CHANNELS = ['stable', 'development'];

    /**
     * When true, injectUpdate() passes through without modifying the
     * transient. Used during reinstall to keep the fake-version entry alive.
     */
    private static bool $bypassInject = false;

    public static function init(): void
    {
        // Core update pipeline.
        add_filter('pre_set_site_transient_update_themes', [self::class, 'injectUpdate']);
        add_filter('themes_api', [self::class, 'themeInfo'], 10, 3);
        add_filter('upgrader_source_selection', [self::class, 'fixSourceDir'], 10, 4);

        // Cache invalidation.
        add_action('switch_theme', [self::class, 'flushCache']);
        add_action('upgrader_process_complete', [self::class, 'flushAfterUpgrade'], 10, 2);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Repo
    // ─────────────────────────────────────────────────────────────────

    /**
     * Resolve the GitHub repo slug (owner/name) for the theme. Filterable
     * so fleets can point at a private fork or enterprise mirror.
     */
    public static function repo(): string
    {
        /**
         * Filter the GitHub repo used for ExamplePress theme updates.
         */
        return (string) apply_filters('examplepress_mu_theme_repo', 'webmultipliers/examplepress-theme');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Channel + pin
    // ─────────────────────────────────────────────────────────────────

    public static function resolveChannel(): string
    {
        // 1. Filter.
        /** @var string $filtered */
        $filtered = (string) apply_filters('examplepress_mu_theme_update_channel', '');
        if (in_array($filtered, self::VALID_CHANNELS, true)) {
            return $filtered;
        }

        // 2. Constant.
        if (defined('EP_THEME_UPDATE_CHANNEL') && in_array(EP_THEME_UPDATE_CHANNEL, self::VALID_CHANNELS, true)) {
            return EP_THEME_UPDATE_CHANNEL;
        }

        // 3. Option.
        $opt = get_option(self::CHANNEL_OPTION);
        if (is_string($opt) && in_array($opt, self::VALID_CHANNELS, true)) {
            return $opt;
        }

        // 4. Auto-detect from installed theme version.
        $theme = wp_get_theme(self::THEME_SLUG);
        if ($theme->exists()) {
            $version = (string) $theme->get('Version');
            if ($version !== '' && preg_match('/(dev|alpha|beta|rc)/i', $version)) {
                return 'development';
            }
        }

        // 5. Default.
        return 'stable';
    }

    /**
     * Which source is controlling the channel?
     *
     * @return string One of: filter, constant, option, auto, default.
     */
    public static function channelSource(): string
    {
        $filtered = (string) apply_filters('examplepress_mu_theme_update_channel', '');
        if (in_array($filtered, self::VALID_CHANNELS, true)) {
            return 'filter';
        }
        if (defined('EP_THEME_UPDATE_CHANNEL') && in_array(EP_THEME_UPDATE_CHANNEL, self::VALID_CHANNELS, true)) {
            return 'constant';
        }
        $opt = get_option(self::CHANNEL_OPTION);
        if (is_string($opt) && in_array($opt, self::VALID_CHANNELS, true)) {
            return 'option';
        }
        $theme = wp_get_theme(self::THEME_SLUG);
        if ($theme->exists()) {
            $version = (string) $theme->get('Version');
            if ($version !== '' && preg_match('/(dev|alpha|beta|rc)/i', $version)) {
                return 'auto';
            }
        }
        return 'default';
    }

    /**
     * Save the channel preference. Returns false if a higher-priority
     * source (filter/constant) is controlling it and the option would
     * have no effect.
     */
    public static function setChannel(string $channel): bool
    {
        if (!in_array($channel, self::VALID_CHANNELS, true)) {
            return false;
        }
        $source = self::channelSource();
        if (in_array($source, ['filter', 'constant'], true)) {
            return false;
        }
        update_option(self::CHANNEL_OPTION, $channel, false);
        return true;
    }

    public static function getPinnedVersion(): ?string
    {
        $v = get_option(self::PIN_OPTION, '');
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function setPinnedVersion(?string $version): void
    {
        if ($version === null || $version === '') {
            delete_option(self::PIN_OPTION);
            return;
        }
        update_option(self::PIN_OPTION, $version, false);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Status (for REST + admin UI)
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function getStatus(): array
    {
        $channel      = self::resolveChannel();
        $localVersion = self::getLocalVersion();
        $manifest     = self::getManifest($channel);
        $packageUrl   = $manifest ? self::resolvePackageUrl($manifest) : null;
        $pinned       = self::getPinnedVersion();

        $latestVersion   = $manifest['version'] ?? null;
        $updateAvailable = false;

        if ($localVersion && $latestVersion) {
            if ($pinned) {
                $updateAvailable = version_compare($pinned, $localVersion, '>');
            } else {
                $updateAvailable = version_compare($latestVersion, $localVersion, '>');
            }
        }

        return [
            'current_version'  => $localVersion,
            'latest_version'   => $latestVersion,
            'update_available' => $updateAvailable,
            'package_url'      => $packageUrl,
            'channel'          => $channel,
            'channel_source'   => self::channelSource(),
            'pinned_version'   => $pinned,
            'last_checked'     => self::getLastChecked(),
            'theme_active'     => self::isThemeActive(),
            'repo'             => self::repo(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Injection
    // ─────────────────────────────────────────────────────────────────

    public static function injectUpdate(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }
        if (self::$bypassInject) {
            return $transient;
        }

        $localVersion = self::getLocalVersion();
        if (!$localVersion) {
            return $transient;
        }

        $channel = self::resolveChannel();
        $pinned  = self::getPinnedVersion();

        if ($pinned) {
            return self::injectPinned($transient, $localVersion, $pinned);
        }

        $manifest = self::getManifest($channel);
        if (!$manifest) {
            return $transient;
        }

        $remoteVersion = (string) ($manifest['version'] ?? '');
        $packageUrl    = self::resolvePackageUrl($manifest);

        if ($remoteVersion === '' || !$packageUrl) {
            return $transient;
        }

        if (version_compare($remoteVersion, $localVersion, '>')) {
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            $transient->response[self::THEME_SLUG] = self::buildUpdateRecord($manifest, $remoteVersion, $packageUrl);
        } else {
            unset($transient->response[self::THEME_SLUG]);
            if (!isset($transient->checked) || !is_array($transient->checked)) {
                $transient->checked = [];
            }
            $transient->checked[self::THEME_SLUG] = $localVersion;
        }

        return $transient;
    }

    private static function injectPinned(object $transient, string $localVersion, string $pinned): object
    {
        if (!isset($transient->checked) || !is_array($transient->checked)) {
            $transient->checked = [];
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        // On the pinned version already — nothing to offer.
        if (version_compare($pinned, $localVersion, '==')) {
            unset($transient->response[self::THEME_SLUG]);
            $transient->checked[self::THEME_SLUG] = $localVersion;
            return $transient;
        }

        // Pinned is newer — fetch that specific manifest.
        if (version_compare($pinned, $localVersion, '>')) {
            $manifest = self::getManifestForVersion($pinned);
            if ($manifest) {
                $packageUrl = self::resolvePackageUrl($manifest);
                if ($packageUrl) {
                    $transient->response[self::THEME_SLUG] = self::buildUpdateRecord($manifest, $pinned, $packageUrl);
                    return $transient;
                }
            }
        }

        // Pinned is older (refuse to downgrade automatically) or manifest missing.
        unset($transient->response[self::THEME_SLUG]);
        $transient->checked[self::THEME_SLUG] = $localVersion;
        return $transient;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function buildUpdateRecord(array $manifest, string $version, string $packageUrl): array
    {
        return [
            'theme'        => self::THEME_SLUG,
            'new_version'  => $version,
            'url'          => $manifest['details_url'] ?? ('https://github.com/' . self::repo()),
            'package'      => $packageUrl,
            'requires'     => $manifest['requires'] ?? '',
            'requires_php' => $manifest['requires_php'] ?? '8.0',
        ];
    }

    public static function themeInfo(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'theme_information') {
            return $result;
        }
        if (($args->slug ?? '') !== self::THEME_SLUG) {
            return $result;
        }

        $manifest = self::getManifest(self::resolveChannel());
        if (!$manifest) {
            return $result;
        }

        $packageUrl = self::resolvePackageUrl($manifest);
        $repoUrl    = 'https://github.com/' . self::repo();

        return (object) [
            'name'          => $manifest['name'] ?? 'ExamplePress',
            'slug'          => self::THEME_SLUG,
            'version'       => $manifest['version'] ?? '',
            'author'        => $manifest['author'] ?? 'Web Multipliers',
            'homepage'      => $manifest['homepage'] ?? $repoUrl,
            'download_link' => $packageUrl,
            'requires'      => $manifest['requires'] ?? '',
            'requires_php'  => $manifest['requires_php'] ?? '8.0',
            'tested'        => $manifest['tested'] ?? '',
            'last_updated'  => $manifest['last_updated'] ?? '',
            'sections'      => [
                'description' => $manifest['description'] ?? 'A code-first WordPress theme built on Blockstudio.',
                'changelog'   => $manifest['changelog'] ?? ('<p>See the <a href="' . esc_url($repoUrl . '/releases') . '">GitHub Releases</a> page.</p>'),
            ],
        ];
    }

    public static function fixSourceDir(string $source, string $remoteSource, \WP_Upgrader $upgrader, array $extras): string
    {
        if (($extras['theme'] ?? '') !== self::THEME_SLUG) {
            return $source;
        }
        $expected = trailingslashit($remoteSource) . self::THEME_SLUG . '/';
        if ($source === $expected) {
            return $source;
        }
        global $wp_filesystem;
        if ($wp_filesystem instanceof \WP_Filesystem_Base && $wp_filesystem->move($source, $expected, true)) {
            return $expected;
        }
        return $source;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Install / reinstall
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array{success: bool, message: string, version?: string}
     */
    public static function installVersion(?string $version = null): array
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        $manifest = $version
            ? self::getManifestForVersion($version)
            : self::getManifest(self::resolveChannel());

        if (!$manifest) {
            return ['success' => false, 'message' => 'Could not fetch update manifest.'];
        }

        $packageUrl = self::resolvePackageUrl($manifest);
        if (!$packageUrl) {
            return ['success' => false, 'message' => 'No download URL found in manifest.'];
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader($skin);

        // Inject into the transient so Theme_Upgrader::upgrade() sees it.
        $transient = get_site_transient('update_themes');
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[self::THEME_SLUG] = self::buildUpdateRecord(
            $manifest,
            (string) ($manifest['version'] ?? ''),
            $packageUrl
        );
        set_site_transient('update_themes', $transient);

        $result = $upgrader->upgrade(self::THEME_SLUG);
        self::flushCache();

        if (is_wp_error($result)) {
            return ['success' => false, 'message' => $result->get_error_message()];
        }
        if ($result !== true) {
            $messages = $skin->get_upgrade_messages();
            $last     = end($messages);
            return ['success' => false, 'message' => $last ?: 'Update failed.'];
        }

        return [
            'success' => true,
            'message' => sprintf('Updated to version %s.', $manifest['version'] ?? ''),
            'version' => (string) ($manifest['version'] ?? ''),
        ];
    }

    /**
     * Reinstall the current (or specified) version. Uses a "fake newer
     * version" trick to force Theme_Upgrader to proceed when the version
     * string doesn't change — the package URL still downloads the real
     * target release, so the theme directory ends up at the correct state.
     *
     * @return array{success: bool, message: string, version?: string}
     */
    public static function reinstall(?string $version = null): array
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        $localVersion = self::getLocalVersion();
        if (!$localVersion) {
            return ['success' => false, 'message' => 'ExamplePress theme is not installed.'];
        }

        $target   = $version ?? $localVersion;
        $manifest = self::getManifestForVersion($target);

        if (!$manifest) {
            $channel = self::resolveChannel();
            $fallback = self::getManifest($channel);
            if ($fallback && (string) ($fallback['version'] ?? '') === $target) {
                $manifest = $fallback;
            }
        }

        if (!$manifest) {
            return ['success' => false, 'message' => sprintf('Could not fetch manifest for version %s.', $target)];
        }

        $packageUrl = self::resolvePackageUrl($manifest);
        if (!$packageUrl) {
            return ['success' => false, 'message' => 'No download URL found in manifest.'];
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader($skin);

        self::$bypassInject = true;

        $transient = get_site_transient('update_themes');
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        // Fake-newer version forces Theme_Upgrader::upgrade to act.
        $transient->response[self::THEME_SLUG] = self::buildUpdateRecord(
            $manifest,
            $target . '.999',
            $packageUrl
        );
        set_site_transient('update_themes', $transient);

        $result = $upgrader->upgrade(self::THEME_SLUG);

        self::$bypassInject = false;
        self::flushCache();

        if (is_wp_error($result)) {
            return ['success' => false, 'message' => $result->get_error_message()];
        }
        if ($result !== true) {
            $messages = $skin->get_upgrade_messages();
            $last     = end($messages);
            return ['success' => false, 'message' => $last ?: 'Reinstall failed.'];
        }

        return [
            'success' => true,
            'message' => sprintf('Reinstalled version %s.', $target),
            'version' => $target,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Manifest + releases (cached)
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public static function getManifest(string $channel): ?array
    {
        $cached = get_transient(self::MANIFEST_KEY);
        if (is_array($cached)) {
            return !empty($cached) ? $cached : null;
        }

        $url  = self::buildManifestUrl($channel);
        $body = self::remoteGet($url);

        if ($body === null) {
            $fallback = self::resolveManifestFromReleases($channel);
            if ($fallback) {
                set_transient(self::MANIFEST_KEY, $fallback, self::MANIFEST_TTL);
                self::touchLastChecked();
                return $fallback;
            }
            set_transient(self::MANIFEST_KEY, [], self::ERROR_TTL);
            return null;
        }

        $data = json_decode($body, true);
        if (!self::validateManifest($data)) {
            $fallback = self::resolveManifestFromReleases($channel);
            if ($fallback) {
                set_transient(self::MANIFEST_KEY, $fallback, self::MANIFEST_TTL);
                self::touchLastChecked();
                return $fallback;
            }
            set_transient(self::MANIFEST_KEY, [], self::ERROR_TTL);
            return null;
        }

        set_transient(self::MANIFEST_KEY, $data, self::MANIFEST_TTL);
        self::touchLastChecked();
        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getManifestForVersion(string $version): ?array
    {
        $releases = self::getReleases();
        if ($releases === null) {
            return null;
        }

        foreach ($releases as $release) {
            $tag = (string) ($release['tag_name'] ?? '');
            if (self::extractVersionFromTag($tag) !== $version) {
                continue;
            }

            // Try the named asset first.
            foreach ($release['assets'] ?? [] as $asset) {
                if (($asset['name'] ?? '') === 'updates.json') {
                    $body = self::remoteGet((string) ($asset['browser_download_url'] ?? ''));
                    if ($body !== null) {
                        $data = json_decode($body, true);
                        if (self::validateManifest($data)) {
                            return $data;
                        }
                    }
                    break;
                }
            }

            // Predictable fallback URL.
            $url = sprintf('https://github.com/%s/releases/download/%s/updates.json', self::repo(), $tag);
            $body = self::remoteGet($url);
            if ($body !== null) {
                $data = json_decode($body, true);
                if (self::validateManifest($data)) {
                    return $data;
                }
            }
            return null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public static function getReleases(): ?array
    {
        $cached = get_transient(self::RELEASES_KEY);
        if (is_array($cached)) {
            return !empty($cached) ? $cached : null;
        }

        $url = sprintf('https://api.github.com/repos/%s/releases', self::repo());
        $body = self::remoteGet($url, ['headers' => ['Accept' => 'application/vnd.github+json']]);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        set_transient(self::RELEASES_KEY, $data, self::RELEASES_TTL);
        return $data;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function resolvePackageUrl(array $manifest, string $variant = 'full'): ?string
    {
        if (!empty($manifest['download_url'])) {
            return (string) $manifest['download_url'];
        }

        $packages = $manifest['packages'] ?? [];
        if (empty($packages) || !is_array($packages)) {
            return null;
        }

        /** @var string $preferred */
        $preferred = (string) apply_filters('examplepress_mu_theme_variant', $variant);

        foreach ($packages as $pkg) {
            if (is_array($pkg) && ($pkg['variant'] ?? '') === $preferred && !empty($pkg['package'])) {
                return (string) $pkg['package'];
            }
        }
        foreach ($packages as $pkg) {
            if (is_array($pkg) && !empty($pkg['package'])) {
                return (string) $pkg['package'];
            }
        }

        return null;
    }

    public static function extractVersionFromTag(string $tag): string
    {
        $tag = ltrim($tag, 'v');
        if (preg_match('/^(\d+\.\d+\.\d+)/', $tag, $m)) {
            return $m[1];
        }
        return $tag;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Cache control
    // ─────────────────────────────────────────────────────────────────

    public static function flushCache(): void
    {
        delete_transient(self::MANIFEST_KEY);
        delete_transient(self::RELEASES_KEY);
        delete_site_transient('update_themes');
    }

    public static function flushAfterUpgrade(\WP_Upgrader $upgrader, array $options): void
    {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'theme') {
            return;
        }
        $themes = $options['themes'] ?? [];
        if (in_array(self::THEME_SLUG, $themes, true)) {
            self::flushCache();
        }
    }

    public static function getLastChecked(): ?int
    {
        $ts = get_option(self::LAST_CHECKED_OPTION);
        return $ts ? (int) $ts : null;
    }

    private static function touchLastChecked(): void
    {
        update_option(self::LAST_CHECKED_OPTION, time(), false);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────

    public static function getLocalVersion(): ?string
    {
        $theme = wp_get_theme(self::THEME_SLUG);
        if (!$theme->exists()) {
            return null;
        }
        $version = (string) $theme->get('Version');
        return $version !== '' ? $version : null;
    }

    public static function isThemeActive(): bool
    {
        return self::THEME_SLUG === get_option('stylesheet')
            || self::THEME_SLUG === get_option('template');
    }

    private static function buildManifestUrl(string $channel): string
    {
        /**
         * Filter the updates.json manifest URL. Empty string = default resolution.
         */
        $override = (string) apply_filters('examplepress_mu_theme_manifest_url', '', $channel);
        if ($override !== '') {
            return $override;
        }

        if ($channel === 'stable') {
            return sprintf('https://github.com/%s/releases/latest/download/updates.json', self::repo());
        }

        return self::resolvePrereleaseManifestUrl();
    }

    private static function resolvePrereleaseManifestUrl(): string
    {
        $fallback = sprintf('https://github.com/%s/releases/latest/download/updates.json', self::repo());
        $releases = self::getReleases();
        if ($releases === null) {
            return $fallback;
        }
        foreach ($releases as $release) {
            $tag = (string) ($release['tag_name'] ?? '');
            if ($tag === '' || strpos($tag, 'development') === false) {
                continue;
            }
            foreach ($release['assets'] ?? [] as $asset) {
                if (($asset['name'] ?? '') === 'updates.json') {
                    return (string) ($asset['browser_download_url'] ?? $fallback);
                }
            }
            return sprintf('https://github.com/%s/releases/download/%s/updates.json', self::repo(), $tag);
        }
        return $fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveManifestFromReleases(string $channel): ?array
    {
        $releases = self::getReleases();
        if ($releases === null || empty($releases)) {
            return null;
        }

        $target = null;
        if ($channel === 'stable') {
            foreach ($releases as $r) {
                if (empty($r['prerelease']) && !empty($r['tag_name'])) {
                    $target = $r;
                    break;
                }
            }
        } else {
            foreach ($releases as $r) {
                if (!empty($r['tag_name']) && strpos((string) $r['tag_name'], 'development') !== false) {
                    $target = $r;
                    break;
                }
            }
        }
        if (!$target) {
            foreach ($releases as $r) {
                if (!empty($r['tag_name'])) {
                    $target = $r;
                    break;
                }
            }
        }
        if (!$target) {
            return null;
        }

        return self::fetchManifestFromRelease($target);
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null
     */
    private static function fetchManifestFromRelease(array $release): ?array
    {
        $tag = (string) ($release['tag_name'] ?? '');

        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === 'updates.json') {
                $body = self::remoteGet((string) ($asset['browser_download_url'] ?? ''));
                if ($body !== null) {
                    $data = json_decode($body, true);
                    if (self::validateManifest($data)) {
                        return $data;
                    }
                }
                break;
            }
        }

        $url  = sprintf('https://github.com/%s/releases/download/%s/updates.json', self::repo(), $tag);
        $body = self::remoteGet($url);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        return self::validateManifest($data) ? $data : null;
    }

    private static function validateManifest(mixed $data): bool
    {
        if (!is_array($data) || empty($data['version'])) {
            return false;
        }
        $hasDownload = !empty($data['download_url']);
        $hasPackages = !empty($data['packages']) && is_array($data['packages']);
        return $hasDownload || $hasPackages;
    }

    private static function remoteGet(string $url, array $args = []): ?string
    {
        if ($url === '') {
            return null;
        }

        $defaults = [
            'timeout'    => 15,
            'user-agent' => 'ExamplePress-MU/' . (defined('EXAMPLEPRESS_MU_VERSION') ? EXAMPLEPRESS_MU_VERSION : '0.0.0') . ' (+' . home_url() . ')',
            'headers'    => ['Accept' => 'application/json'],
        ];

        if (isset($args['headers']) && is_array($args['headers'])) {
            $args['headers'] = array_merge($defaults['headers'], $args['headers']);
        }

        $merged   = array_merge($defaults, $args);
        $response = wp_remote_get($url, $merged);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return (string) wp_remote_retrieve_body($response);
    }
}
