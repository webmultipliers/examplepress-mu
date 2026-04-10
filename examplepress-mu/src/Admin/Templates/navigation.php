<?php
/**
 * Template: Navigation
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-locations" id="t-locations" data-tab-id="locations">Locations</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-menus"     id="t-menus"     data-tab-id="menus">Menus</button>
		</nav>

		<div class="ep-panels">

		<!-- Locations -->
		<div class="ep-panel" id="p-locations" role="tabpanel" aria-hidden="false">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Registered Locations</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">ExamplePress uses the native WordPress menu system. Register locations in your companion plugin and assign menus via Appearance &rarr; Menus or the Navigation block.</p>
				<div id="tbl-nav-locations"></div>
			</section>
		</div>

		<!-- Menus -->
		<div class="ep-panel" id="p-menus" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Menus</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">All menus registered in this WordPress installation. Manage items via <a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>">Appearance &rarr; Menus</a> or the Navigation block in the editor.</p>
				<div id="tbl-nav-menus"></div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
