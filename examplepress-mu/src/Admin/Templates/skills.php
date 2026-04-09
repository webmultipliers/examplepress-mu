<?php
/**
 * Template: Skills
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to. The Skills page reads its
 * skill list and per-file markdown bodies from window.ExamplePressData
 * and renders each one as HTML inside a tabbed panel.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<section class="ep-section">
			<div class="ep-section-header">
				<span class="ep-section-title">Generative UI Agent — Skill Curriculum</span>
				<div class="ep-section-line"></div>
			</div>
			<p class="ep-section-desc">
				These markdown files are loaded into the system prompt of every
				agent generation, with all <code>{{merge_tag}}</code> placeholders
				resolved against the live install. They are the single source of
				truth for what the LLM knows about this site's conventions.
			</p>
		</section>

		<section class="ep-section">
			<div class="ep-section-header">
				<span class="ep-section-title">Skill Files</span>
				<div class="ep-section-line"></div>
			</div>
			<p class="ep-section-desc">
				Select a skill on the left to view its rendered markdown. Edit the
				underlying files on disk to change what the agent sees.
			</p>
			<div class="ep-skills-layout" id="ep-skills-layout">
				<aside class="ep-skills-sidebar" id="ep-skills-sidebar" role="tablist" aria-label="Skill files">
					<!-- Tab list rendered by JS from ExamplePressData.skills -->
				</aside>
				<article class="ep-skills-content" id="ep-skills-content" role="tabpanel">
					<!-- Markdown rendered by marked.js into here -->
					<div class="ep-skills-empty" id="ep-skills-empty">
						<p>Loading skill curriculum…</p>
					</div>
				</article>
			</div>
		</section>
