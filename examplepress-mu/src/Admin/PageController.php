<?php

declare(strict_types=1);

namespace ExamplePress\MU\Admin;

/**
 * Renders the wrapper HTML for every admin page. Each page renders the
 * same shell; the Vite JS entry point mounts the appropriate UI into
 * #examplepress-app.
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
        $isDev  = defined('EP_DEV_MODE') && EP_DEV_MODE;
        $pageId = self::resolveCurrentPageId();

        // Resolve the template path through a whitelist + realpath check.
        // Without this, a crafted ?page= value could walk out of the
        // Templates directory ("examplepress-../../../../wp-config") and
        // include() arbitrary PHP files. Admin precondition required but
        // still a capability-escalation footgun.
        $template = self::resolveTemplatePath($pageId);

        ?>
        <div class="ep-page">
            <div class="ep-page__shell">

                <?php self::renderHeader($isDev); ?>

                <div class="ep-page__body">
                    <?php
                    if ($template !== null) {
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
     * Resolve the safe absolute path to a page template, or null if
     * none exists OR the request is trying to escape the Templates
     * directory. Three guards:
     *
     *   1. Format check — page ID must match /^[a-z0-9-]+$/. This is
     *      the same shape admin slugs already use, so legitimate pages
     *      always pass.
     *   2. Directory containment — the realpath of the resolved file
     *      must start with the realpath of the Templates directory.
     *      Closes any edge case the format check misses (e.g. symlinks).
     *   3. File existence check.
     */
    private static function resolveTemplatePath(string $pageId): ?string
    {
        if ($pageId === '' || !preg_match('/^[a-z0-9-]+$/', $pageId)) {
            return null;
        }

        $templatesDir = __DIR__ . '/Templates';
        $candidate    = $templatesDir . '/' . $pageId . '.php';

        if (!file_exists($candidate)) {
            return null;
        }

        $realBase = realpath($templatesDir);
        $realFile = realpath($candidate);
        if ($realBase === false || $realFile === false) {
            return null;
        }

        // The candidate MUST be inside the templates directory. The
        // trailing separator prevents a prefix-only match (e.g. a
        // sibling directory that happens to start with the same path).
        if (!str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    /**
     * Render the shared page header: dev banner, logo, version badge.
     */
    public static function renderHeader(bool $isDev, string $extraHtml = ''): void
    {
        if ($isDev) : ?>
            <div class="ep-page__dev-banner">Developer Mode is active &mdash; template guards are bypassed by the MU Kernel. Remove <code>EP_DEV_MODE</code> from wp-config.php before deploying.</div>
        <?php endif; ?>

        <header class="ep-page__header">
            <div class="ep-page__header-top">
                <div class="ep-page__logo"><span>&lt;</span>ExamplePress<span>/&gt;</span></div>
                <div class="ep-page__header-actions">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $extraHtml;
                    ?>
                    <div class="ep-page__version">v<?php echo esc_html(EXAMPLEPRESS_MU_VERSION); ?></div>
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
        <div class="ep-modal__overlay" id="ep-feature-modal" style="display:none">
            <div class="ep-modal">
                <div class="ep-modal__header">
                    <div>
                        <span class="ep-modal__title" id="ep-modal-title"></span>
                        <span class="ep-modal__subtitle" id="ep-modal-id"></span>
                    </div>
                    <button class="ep-modal__close" id="ep-modal-close">&times;</button>
                </div>
                <div class="ep-modal__body" id="ep-modal-body"></div>
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
