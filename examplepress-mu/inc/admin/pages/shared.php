<?php
/**
 * Shared admin page components.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the shared page header: dev banner, logo, version badge.
 *
 * @param bool   $is_dev     Whether EP_DEV_MODE is active.
 * @param string $extra_html Optional HTML for the header-right area (e.g. copy report button).
 */
function examplepress_render_page_header( bool $is_dev, string $extra_html = '' ): void {
	if ( $is_dev ) : ?>
		<div class="ep-dev-banner">Developer Mode is active — template guards are bypassed by the MU Kernel. Remove <code>EP_DEV_MODE</code> from wp-config.php before deploying.</div>
	<?php endif; ?>

	<header class="ep-header">
		<div class="ep-header-top">
			<div class="ep-logo"><span>&lt;</span>ExamplePress<span>/&gt;</span></div>
			<div class="ep-header-right">
				<?php echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ep-version">v<?php echo esc_html( EXAMPLEPRESS_MU_VERSION ); ?></div>
			</div>
		</div>
	</header>
	<?php
}

/**
 * Render the shared detail modal overlay.
 *
 * Used by features, hooks, blocks, dependencies — any page that shows
 * a detail popup via the JS modal system.
 */
function examplepress_render_detail_modal(): void {
	?>
	<div class="ep-modal-overlay" id="ep-feature-modal" style="display:none">
		<div class="ep-modal">
			<div class="ep-modal-header">
				<div>
					<span class="ep-modal-title" id="ep-modal-title"></span>
					<span class="ep-modal-id" id="ep-modal-id"></span>
				</div>
				<button class="ep-modal-close" id="ep-modal-close">&times;</button>
			</div>
			<div class="ep-modal-body" id="ep-modal-body"></div>
		</div>
	</div>
	<?php
}
