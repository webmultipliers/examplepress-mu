<?php
/**
 * Template: Library
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true" aria-controls="p-library" id="t-library" data-tab-id="library">Library</button>
		</nav>

		<div class="ep-panels">

		<div class="ep-panel" id="p-library" role="tabpanel" aria-hidden="false">
			<section class="ep-section">
				<div class="ep-empty">
					<div class="ep-empty__title">The Component Library is arriving soon.</div>
					<div class="ep-empty__desc">Browse, preview, and import companion plugins built for the ExamplePress ecosystem. Install with one click and extend your site with pre-built functionality.</div>
				</div>
				<span class="ep-badge ep-badge--info"><span class="ep-dot"></span>Coming Soon</span>
			</section>
		</div>

		</div><!-- /.ep-panels -->
