<?php

declare(strict_types=1);

namespace ExamplePress\MU\Governance;

/**
 * Fleet-wide policy enforcement.
 *
 * Strips dangerous capabilities, enforces permalink structure,
 * locks wp_options, and disables 404 redirect guessing.
 */
final class PlatformPolicy
{
    public static function init(): void
    {
        // 1. Enforce Permalink Structure globally (opt-out via filter).
        if (apply_filters('examplepress_mu_enforce_permalinks', true)) {
            add_filter('pre_option_permalink_structure', [self::class, 'enforcePermalinks']);
        }

        // 2. Capability stripping — only register if a filter populates a list.
        // Priority 20 to play nice with other plugins manipulating user caps.
        $strippedCaps = (array) apply_filters('examplepress_mu_stripped_capabilities', []);
        if (!empty($strippedCaps)) {
            add_filter('user_has_cap', [self::class, 'stripCapabilities'], 20, 4);
        }

        // Hard disable file editing (opt-out via filter; respects existing define).
        if (!defined('DISALLOW_FILE_EDIT') && apply_filters('examplepress_mu_disallow_file_edit', true)) {
            define('DISALLOW_FILE_EDIT', true);
        }

        // 3. Managed Options.
        /** @var array<string, mixed> $managedOptions */
        $managedOptions = apply_filters('examplepress_mu_managed_options', []);
        foreach ($managedOptions as $option => $value) {
            add_filter("pre_option_{$option}", static fn() => $value);
        }

        // 4. Disable 404 Redirect Guessing.
        if (apply_filters('examplepress_mu_disable_redirect_guess_404', true)) {
            add_filter('do_redirect_guess_404_permalink', '__return_false');
        }
    }

    /**
     * @return string Enforced permalink structure.
     */
    public static function enforcePermalinks(): string
    {
        return (string) apply_filters('examplepress_mu_permalink_structure', '/%postname%/');
    }

    /**
     * @param array<string, bool> $allcaps All capabilities for the user.
     * @param array<int, string>  $caps    Required primitive capabilities.
     * @param array<int, mixed>   $args    Arguments passed to has_cap.
     * @param \WP_User            $user    The user object.
     * @return array<string, bool>
     */
    public static function stripCapabilities(array $allcaps, array $caps, array $args, \WP_User $user): array
    {
        $strippedCaps = (array) apply_filters('examplepress_mu_stripped_capabilities', []);
        foreach ($strippedCaps as $cap) {
            if (isset($allcaps[$cap])) {
                $allcaps[$cap] = false;
            }
        }
        return $allcaps;
    }
}
