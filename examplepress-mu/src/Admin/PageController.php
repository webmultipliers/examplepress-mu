<?php

declare(strict_types=1);

namespace ExamplePress\MU\Admin;

/**
 * Handles routing for the React/JS frontend and renders the wrapper HTML.
 *
 * Ported from inc/admin/pages/shared.php and the per-page render functions.
 * Each admin page renders the same shell; the Vite JS entry point mounts
 * the appropriate UI into #examplepress-app.
 */
final class PageController
{
    /**
     * Hook any needed admin actions.
     */
    public static function init(): void
    {
        // No additional hooks needed at this time.
        // Asset enqueue and data localization are handled by
        // AssetManager and DataProvider respectively.
    }

    /**
     * Render the admin page HTML wrapper.
     *
     * This outputs the shell that all ExamplePress admin pages share:
     *  - Dev mode banner (when EP_DEV_MODE is set)
     *  - Shared header with logo and version badge
     *  - The #examplepress-app div that the JS entry point mounts into
     *  - Detail modal overlay for feature/hook/block popups
     */
    public static function render(): void
    {
        $isDev = defined('EP_DEV_MODE') && EP_DEV_MODE;
        $pageId = self::resolveCurrentPageId();
        $template = __DIR__ . '/Templates/' . $pageId . '.php';

        ?>
        <div class="ep-settings-wrapper">
            <div class="ep-settings">

                <?php self::renderHeader($isDev); ?>

                <div class="ep-layout">
                    <?php
                    if (file_exists($template)) {
                        include $template;
                    } else {
                        echo '<div id="examplepress-app" data-page="' . esc_attr($pageId) . '"></div>';
                    }
                    ?>
                </div>

            </div>

            <?php self::renderDetailModal(); ?>

        </div>
        <?php
    }

    /**
     * Render the shared page header: dev banner, logo, version badge.
     */
    public static function renderHeader(bool $isDev, string $extraHtml = ''): void
    {
        if ($isDev) : ?>
            <div class="ep-dev-banner">Developer Mode is active &mdash; template guards are bypassed by the MU Kernel. Remove <code>EP_DEV_MODE</code> from wp-config.php before deploying.</div>
        <?php endif; ?>

        <header class="ep-header">
            <div class="ep-header-top">
                <div class="ep-logo"><span>&lt;</span>ExamplePress<span>/&gt;</span></div>
                <div class="ep-header-right">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $extraHtml;
                    ?>
                    <div class="ep-version">v<?php echo esc_html(EXAMPLEPRESS_MU_VERSION); ?></div>
                </div>
            </div>
        </header>
        <?php
    }

    /**
     * Render the shared detail modal overlay.
     *
     * Used by features, hooks, blocks, dependencies -- any page that shows
     * a detail popup via the JS modal system.
     */
    public static function renderDetailModal(): void
    {
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

    /**
     * Resolve the current page ID from the query string.
     */
    private static function resolveCurrentPageId(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $slug = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));

        if ($slug === EP_ADMIN_MENU_SLUG) {
            // First page in the sorted list.
            $pages = MenuManager::getSortedPages();
            return (string) array_key_first($pages);
        }

        $prefix = EP_ADMIN_MENU_SLUG . '-';
        if (str_starts_with($slug, $prefix)) {
            return substr($slug, strlen($prefix));
        }

        return $slug;
    }
}
