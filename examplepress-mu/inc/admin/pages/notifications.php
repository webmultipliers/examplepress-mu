<?php
/**
 * ExamplePress Admin — Notifications Page
 *
 * Active and archived theme notifications.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifications page data payload.
 */
function examplepress_notifications_data(): array {
	return [
		'themeVersion'  => EXAMPLEPRESS_MU_VERSION,
		'devMode'       => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'          => 'notifications',
		'notifications' => function_exists( 'examplepress_gather_notifications' ) ? examplepress_gather_notifications() : [],
		'archived'      => function_exists( 'examplepress_get_archived_notifications' ) ? examplepress_get_archived_notifications() : [],
		'restUrl'       => esc_url_raw( rest_url( 'examplepress/v1/notifications/archive' ) ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * Notifications page render.
 */
function examplepress_render_notifications_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php examplepress_render_page_header( $is_dev ); ?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-active"   id="t-active"   data-tab-id="active">Active</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-archived" id="t-archived" data-tab-id="archived">Archived</button>
			</nav>

			<div class="ep-panels">

			<div class="ep-panel" id="p-active" role="tabpanel" aria-hidden="false">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Active Notifications</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Theme-generated notices &mdash; errors, warnings, and informational messages.</p>
					<div id="notices-active" class="ep-notif-list"></div>
				</section>
			</div>

			<div class="ep-panel" id="p-archived" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Archived Notifications</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Previously archived notifications. Restore them to make them active again.</p>
					<div id="notices-archived" class="ep-notif-list"></div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>
	</div>
	<?php
}
