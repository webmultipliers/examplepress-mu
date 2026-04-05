<?php
/**
 * ExamplePress MU — FSE Guards
 *
 * Prevents access to the WordPress Full Site Editor (FSE) by intercepting
 * three vectors: direct page access, REST API endpoints, and template resolution.
 *
 * Because these hooks fire from an MU plugin (at `muplugins_loaded`), no standard
 * plugin can unhook them or bypass the guards.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_FSE_Guard {

    /**
     * Register all three guards.
     */
    public static function init() {
        if ( defined( 'EP_DEV_MODE' ) && EP_DEV_MODE ) {
            return; // Bypass guards during development
        }

        // Guard 1: Block direct navigation to the Site Editor admin page.
        add_action( 'admin_init', [ __CLASS__, 'guard_template_redirect' ], 0 );

        // Guard 2: Block REST API requests to template/template-part endpoints.
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'guard_template_rest' ], 0, 3 );

        // Guard 3: Prevent FSE template resolution from overriding the theme's template hierarchy.
        add_filter( 'pre_get_block_templates', [ __CLASS__, 'guard_template_resolution' ], 0, 3 );
    }

    /**
     * Guard 1 — Template Redirect
     *
     * If a user navigates to the Site Editor (site-editor.php), redirect them
     * back to the dashboard with an admin notice explaining why.
     */
    public static function guard_template_redirect() {
        global $pagenow;

        if ( 'site-editor.php' !== $pagenow ) {
            return;
        }

        add_settings_error(
            'general',
            'ep_fse_blocked',
            __( 'The Site Editor is disabled by ExamplePress. Templates are managed by the platform.', 'examplepress-mu' ),
            'info'
        );

        wp_safe_redirect( admin_url() );
        exit;
    }

    /**
     * Guard 2 — Template REST
     *
     * Intercepts REST API calls targeting wp/v2/templates and wp/v2/template-parts.
     * Returns a WP_Error so external tools (and the editor itself) cannot read or
     * write block templates via the API.
     *
     * @param mixed           $result  Response to replace the requested API response.
     * @param WP_REST_Server  $server  Server instance.
     * @param WP_REST_Request $request The request.
     * @return mixed|WP_Error
     */
    public static function guard_template_rest( $result, $server, $request ) {
        $route = $request->get_route();

        // Match /wp/v2/templates and /wp/v2/template-parts (including child routes).
        if ( preg_match( '#^/wp/v2/template(s|-parts)#', $route ) ) {
            return new WP_Error(
                'ep_fse_rest_blocked',
                __( 'Template endpoints are disabled by ExamplePress.', 'examplepress-mu' ),
                [ 'status' => 403 ]
            );
        }

        return $result;
    }

    /**
     * Guard 3 — Template Resolution
     *
     * Short-circuits the block template query so WordPress never resolves
     * FSE/block templates from the database or theme. This forces the classic
     * template hierarchy to remain in full control.
     *
     * Returning an empty array tells WordPress "there are no block templates,"
     * which causes it to fall through to the PHP template hierarchy.
     *
     * @param array|null $result      Existing pre-filtered result.
     * @param array      $query       Template query arguments.
     * @param string     $template_type 'wp_template' or 'wp_template_part'.
     * @return array  Empty array — no block templates.
     */
    public static function guard_template_resolution( $result, $query, $template_type ) {
        return [];
    }
}
