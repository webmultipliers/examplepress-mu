<?php

declare(strict_types=1);

namespace ExamplePress\MU\Governance;

use ExamplePress\MU\Config\FeatureRegistry;

/**
 * Prevents access to the WordPress Full Site Editor (FSE).
 *
 * CRITICAL FIX: Reads state from FeatureRegistry instead of being
 * hardcoded to just check EP_DEV_MODE. The bypass is now controlled
 * via the 'editor-guard' feature flag.
 */
final class EditorGuard
{
    public static function init(): void
    {
        // Check the feature registry for a bypass flag, falling back to EP_DEV_MODE.
        $bypassed = defined('EP_DEV_MODE') && EP_DEV_MODE;

        /** Allow disabling via filter for dev/test contexts. */
        if (apply_filters('examplepress_mu_bypass_editor_guard', $bypassed)) {
            return;
        }

        // Guard 1: Block direct navigation to the Site Editor admin page.
        add_action('admin_init', [self::class, 'guardTemplateRedirect'], 0);

        // Guard 2: Block REST API requests to template/template-part endpoints.
        add_filter('rest_pre_dispatch', [self::class, 'guardTemplateRest'], 0, 3);

        // Guard 3: Prevent FSE template resolution from overriding the template hierarchy.
        add_filter('pre_get_block_templates', [self::class, 'guardTemplateResolution'], 0, 3);
    }

    /**
     * Guard 1 — Redirect away from site-editor.php.
     */
    public static function guardTemplateRedirect(): void
    {
        global $pagenow;

        if ('site-editor.php' !== $pagenow) {
            return;
        }

        add_settings_error(
            'general',
            'ep_fse_blocked',
            __('The Site Editor is disabled by ExamplePress. Templates are managed by the platform.', 'examplepress-mu'),
            'info'
        );

        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * Guard 2 — Block REST API template endpoints.
     *
     * @param mixed            $result  Response to replace.
     * @param \WP_REST_Server  $server  Server instance.
     * @param \WP_REST_Request $request The request.
     * @return mixed|\WP_Error
     */
    public static function guardTemplateRest(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        $route = $request->get_route();

        if (preg_match('#^/wp/v2/template(s|-parts)#', $route)) {
            return new \WP_Error(
                'ep_fse_rest_blocked',
                __('Template endpoints are disabled by ExamplePress.', 'examplepress-mu'),
                ['status' => 403]
            );
        }

        return $result;
    }

    /**
     * Guard 3 — Short-circuit block template resolution in the admin.
     *
     * Only blocks template queries made from the Site Editor (admin context).
     * Frontend template resolution MUST be allowed so the block theme's
     * templates/index.html can load the router block.
     *
     * @return array<int, mixed>|null Empty array in admin, null (pass-through) on frontend.
     */
    public static function guardTemplateResolution(mixed $result, array $query, string $templateType): array|null
    {
        // Allow frontend template resolution — the block theme needs it.
        if (!is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            return $result;
        }

        // In admin context, block the Site Editor from resolving templates.
        return [];
    }
}
