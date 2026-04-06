<?php
/**
 * Template: Editor
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

$slug       = sanitize_title( $_GET['app'] ?? '' );
$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
$valid      = $slug && is_dir( $plugin_dir );
$apps_url   = \ExamplePress\MU\Admin\MenuManager::pageUrl( 'apps' );
?>

<div class="ep-editor-wrapper">

	<?php if ( ! $valid ) : ?>
		<div class="ep-editor-error">
			<h2>App not found</h2>
			<p>The plugin directory <code><?php echo esc_html( $slug ?: '(empty)' ); ?></code> does not exist.</p>
			<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-demo-btn ep-demo-btn-primary">&larr; Back to Apps</a>
		</div>
	<?php else : ?>
		<div class="ep-editor-toolbar">
			<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-editor-toolbar-back">&larr; Apps</a>
			<span id="ep-editor-app-name" class="ep-editor-app-name"></span>
			<span id="ep-editor-file-path" class="ep-editor-file-path"></span>
			<span class="ep-editor-spacer"></span>
			<span id="ep-editor-status" class="ep-editor-status"></span>
			<button id="ep-editor-save" class="ep-editor-save" disabled>Save</button>
		</div>
		<div class="ep-editor-body">
			<div id="ep-editor-tree" class="ep-editor-tree"></div>
			<div id="ep-editor-container" class="ep-editor-container"></div>
		</div>
	<?php endif; ?>

</div>
