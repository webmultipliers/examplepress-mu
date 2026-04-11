<?php
/**
 * Template: Docs
 *
 * Unified documentation browser — merges the former "Docs" (hooks,
 * resolution order, support links) and "Skills" (agent curriculum)
 * pages into a single sidebar-driven reference with a TOC rail.
 *
 * Rendered by PageController::render() — the Vite `docs` entry point
 * binds to these elements.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<section class="ep-section">
			<div class="ep-section__header">
				<span class="ep-section__title">Documentation</span>
				<div class="ep-section__line"></div>
			</div>
			<p class="ep-section__desc">
				Kernel reference (hooks, resolution order, support) and the
				agent skill curriculum. Search across everything with
				<kbd>/</kbd>, or click any entry in the sidebar.
			</p>
		</section>

		<div class="ep-docs-layout" id="ep-docs">

			<!-- Sidebar: search + grouped nav -->
			<nav class="ep-docs-layout__nav" id="ep-docs-nav" role="navigation" aria-label="Documentation navigation">
				<div class="ep-search">
					<svg class="ep-search__icon" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
						<path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/>
					</svg>
					<input
						type="search"
						class="ep-search__input"
						id="ep-docs-search"
						placeholder="Search docs..."
						autocomplete="off"
						spellcheck="false"
					/>
					<kbd class="ep-search__kbd">/</kbd>
				</div>
				<div id="ep-docs-nav-groups" role="tablist" aria-label="Documentation entries">
					<!-- Grouped nav rendered by JS -->
				</div>
			</nav>

			<!-- Content panel -->
			<main class="ep-docs-layout__content" id="ep-docs-content" role="tabpanel">
				<div class="ep-empty" id="ep-docs-empty">
					<p>Loading documentation...</p>
				</div>
			</main>

			<!-- Table of contents rail -->
			<aside class="ep-docs-layout__toc" id="ep-docs-toc" aria-label="On this page">
				<div class="ep-docs-layout__toc-label">On this page</div>
				<ul class="ep-docs-layout__toc-list" id="ep-docs-toc-list">
					<!-- TOC rendered by JS from heading anchors -->
				</ul>
			</aside>

		</div>
