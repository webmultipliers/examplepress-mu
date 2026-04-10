<?php
/**
 * Template: Proposer
 * In-admin Monaco editor that reads from GitHub at a pinned ref and
 * produces PRs. Never touches the local filesystem.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

$slug       = sanitize_title( $_GET['app'] ?? '' );
$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
$valid      = $slug && is_dir( $plugin_dir );
$apps_url   = \ExamplePress\MU\Admin\MenuManager::pageUrl( 'apps' );

// Read manifest for repository field.
$manifest = [];
if ($valid) {
    $manifest_path = $plugin_dir . '/examplepress.json';
    if (is_readable($manifest_path)) {
        $manifest = json_decode((string) file_get_contents($manifest_path), true) ?: [];
    }
}
$has_repo = !empty($manifest['repository']) || !empty($manifest['troy']['repo']);
?>

<div class="ep-proposer-wrapper">

	<?php if ( ! $valid ) : ?>
		<div class="ep-proposer-error">
			<h2>App not found</h2>
			<p>The plugin directory <code><?php echo esc_html( $slug ?: '(empty)' ); ?></code> does not exist.</p>
			<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-btn ep-btn--primary">&larr; Back to Apps</a>
		</div>
	<?php elseif ( ! $has_repo ) : ?>
		<div class="ep-proposer-error">
			<h2>Repository not configured</h2>
			<p>The app <code><?php echo esc_html( $slug ); ?></code> is missing the <code>repository</code> field in its manifest. The proposer requires a connected GitHub repository.</p>
			<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-btn ep-btn--primary">&larr; Back to Apps</a>
		</div>
	<?php else : ?>
		<div class="ep-proposer-toolbar">
			<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-proposer-toolbar__back">&larr; Apps</a>
			<span id="ep-proposer-app-name" class="ep-proposer-toolbar__app-name"><?php echo esc_html( $manifest['name'] ?? $slug ); ?></span>
			<span id="ep-proposer-ref" class="ep-proposer-toolbar__ref"></span>
			<span class="ep-proposer-toolbar__spacer"></span>
			<span id="ep-proposer-draft-badge" class="ep-badge--info" hidden></span>
			<button id="ep-proposer-discard" class="ep-btn ep-btn--secondary" hidden>Discard Draft</button>
			<button id="ep-proposer-submit" class="ep-btn ep-btn--primary">Submit Proposal</button>
		</div>
		<div class="ep-proposer-body">
			<div id="ep-proposer-tree" class="ep-proposer-body__tree"></div>
			<div class="ep-proposer-body__editor">
				<div id="ep-proposer-tabs" class="ep-proposer-tabs"></div>
				<div id="ep-proposer-container" class="ep-proposer-body__container"></div>
			</div>
		</div>
	<?php endif; ?>

</div>

<!-- Submit Proposal Modal -->
<div class="ep-modal__overlay" id="ep-proposer-submit-modal" hidden>
	<div class="ep-modal">
		<div class="ep-modal__header">
			<div>
				<span class="ep-modal__title">Submit Proposal</span>
				<span class="ep-modal__subtitle">Creates a pull request on GitHub</span>
			</div>
			<button class="ep-modal__close" data-modal="ep-proposer-submit-modal">&times;</button>
		</div>
		<div class="ep-modal__body">
			<div class="ep-build-field">
				<label class="ep-build-label" for="ep-proposer-pr-title">Title</label>
				<input type="text" class="ep-build-input" id="ep-proposer-pr-title" placeholder="Brief description of the change" />
			</div>
			<div class="ep-build-field">
				<label class="ep-build-label" for="ep-proposer-pr-body">Description</label>
				<textarea class="ep-build-input" id="ep-proposer-pr-body" rows="3" placeholder="What does this change do and why?"></textarea>
			</div>
			<div id="ep-proposer-changeset-summary" class="ep-proposer-changeset"></div>
			<div id="ep-proposer-submit-error" class="ep-build-error" hidden></div>
		</div>
		<div class="ep-modal__footer">
			<button class="ep-btn ep-btn--secondary" data-modal="ep-proposer-submit-modal">Cancel</button>
			<button class="ep-btn ep-btn--primary" id="ep-proposer-submit-confirm">Create Pull Request</button>
		</div>
	</div>
</div>

<!-- Proposal Success Card -->
<div class="ep-modal__overlay" id="ep-proposer-success-modal" hidden>
	<div class="ep-modal">
		<div class="ep-modal__body ep-modal__body--centered">
			<h2 class="ep-modal__title">Proposal Created</h2>
			<p id="ep-proposer-success-msg" class="ep-modal__desc"></p>
			<a id="ep-proposer-success-link" href="#" target="_blank" rel="noopener" class="ep-btn ep-btn--primary">View Pull Request</a>
			<div class="ep-modal__actions">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-proposer-success-modal">Close</button>
			</div>
		</div>
	</div>
</div>
