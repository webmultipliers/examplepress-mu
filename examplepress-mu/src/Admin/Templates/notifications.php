<?php
/**
 * Template: Notifications
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-active"   id="t-active"   data-tab-id="active">Active</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-archived" id="t-archived" data-tab-id="archived">Archived</button>
		</nav>

		<div class="ep-panels">

		<div class="ep-panel" id="p-active" role="tabpanel" aria-hidden="false">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Active Notifications</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Theme-generated notices &mdash; errors, warnings, and informational messages.</p>
				<div id="notices-active"></div>
			</section>
		</div>

		<div class="ep-panel" id="p-archived" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Archived Notifications</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Previously archived notifications. Restore them to make them active again.</p>
				<div id="notices-archived"></div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
