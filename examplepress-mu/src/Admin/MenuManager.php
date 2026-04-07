<?php

declare(strict_types=1);

namespace ExamplePress\MU\Admin;

/**
 * Registers the top-level ExamplePress admin menu and all submenus.
 *
 * Ported from inc/admin/admin-registry.php. Each page is defined
 * declaratively and wired to PageController::render as its callback.
 */
final class MenuManager
{
    /**
     * Registered admin pages keyed by page ID.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $pages = [];

    /**
     * Map of page IDs to their WordPress hook suffixes.
     *
     * @var array<string, string>
     */
    private static array $hookSuffixes = [];

    /**
     * Hook into WordPress admin_menu to build the menu.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'buildMenu']);
    }

    /**
     * Build the admin menu: register core pages, fire extension hook, then
     * add the top-level menu and all submenus.
     */
    public static function buildMenu(): void
    {
        self::registerCorePages();

        /**
         * Allow companion plugins to inject additional admin pages.
         *
         * @param array $pages Current registered pages keyed by ID.
         */
        do_action('examplepress_mu_register_admin_pages');

        $pages = self::getSortedPages();

        if (empty($pages)) {
            return;
        }

        // The first page (lowest position) shares the parent slug to avoid
        // WordPress's duplicate first-submenu behaviour.
        $firstId = array_key_first($pages);

        $topHook = add_menu_page(
            __('ExamplePress', 'examplepress-mu'),
            'ExamplePress',
            'manage_options',
            EP_ADMIN_MENU_SLUG,
            [PageController::class, 'render'],
            'dashicons-layout',
            55
        );

        // Capture the top-level hook suffix so isExamplePressPage() and
        // pageIdFromHook() work for the parent menu page as well.
        if (is_string($topHook)) {
            self::$hookSuffixes[$firstId] = $topHook;
        }

        foreach ($pages as $id => $page) {
            $menuSlug = self::pageSlug($id);
            $parent = !empty($page['hidden']) ? null : EP_ADMIN_MENU_SLUG;

            $hook = add_submenu_page(
                $parent,
                $page['label'],
                $page['menu_title'],
                $page['capability'],
                $menuSlug,
                [PageController::class, 'render'],
                $page['position']
            );

            if (is_string($hook)) {
                self::$hookSuffixes[$id] = $hook;
            }
        }
    }

    /**
     * Register a single admin page into the internal store.
     */
    public static function registerPage(string $id, array $args): void
    {
        if (isset(self::$pages[$id])) {
            return;
        }

        $page = wp_parse_args($args, [
            'label'       => '',
            'menu_title'  => '',
            'capability'  => 'manage_options',
            'position'    => 50,
            'icon'        => 'dashicons-admin-generic',
            'hidden'      => false,
        ]);

        /**
         * Filter an individual admin page definition before it is stored.
         * Return null to suppress the page entirely.
         *
         * @param array|null $page Page definition (label, menu_title, position, icon, hidden, capability).
         * @param string     $id   Page ID.
         */
        $page = apply_filters('examplepress_mu_core_page', $page, $id);

        if ($page === null) {
            return;
        }

        self::$pages[$id] = $page;
    }

    /**
     * Get all registered pages sorted by position.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSortedPages(): array
    {
        $pages = self::$pages;

        /** @var array<string, array<string, mixed>> $pages */
        $pages = apply_filters('examplepress_mu_admin_pages', $pages);

        uasort($pages, static fn(array $a, array $b): int =>
            ($a['position'] ?? 50) <=> ($b['position'] ?? 50)
        );

        return $pages;
    }

    /**
     * Derive the WordPress menu slug for a given page ID.
     *
     * The first page (lowest position) uses the parent slug directly.
     */
    public static function pageSlug(string $id): string
    {
        $pages = self::getSortedPages();
        $first = array_key_first($pages);

        if ($id === $first) {
            return EP_ADMIN_MENU_SLUG;
        }

        return EP_ADMIN_MENU_SLUG . '-' . $id;
    }

    /**
     * Get the full admin URL for a page.
     */
    public static function pageUrl(string $id, array $args = []): string
    {
        $slug = self::pageSlug($id);
        $url  = admin_url('admin.php?page=' . $slug);

        if ($args) {
            $url = add_query_arg($args, $url);
        }

        return $url;
    }

    /**
     * Return the hook suffix map (page ID => WP hook suffix).
     *
     * @return array<string, string>
     */
    public static function getHookSuffixes(): array
    {
        return self::$hookSuffixes;
    }

    /**
     * Determine the page ID from a WordPress hook suffix.
     */
    public static function pageIdFromHook(string $hookSuffix): ?string
    {
        return array_search($hookSuffix, self::$hookSuffixes, true) ?: null;
    }

    /**
     * Check whether a hook suffix belongs to an ExamplePress admin page.
     */
    public static function isExamplePressPage(string $hookSuffix): bool
    {
        return in_array($hookSuffix, self::$hookSuffixes, true);
    }

    /**
     * Register the core admin pages shipped with the platform.
     */
    private static function registerCorePages(): void
    {
        self::registerPage('apps', [
            'label'      => __('ExamplePress — Apps', 'examplepress-mu'),
            'menu_title' => __('Apps', 'examplepress-mu'),
            'position'   => 0,
            'icon'       => 'dashicons-screenoptions',
        ]);

        self::registerPage('theme', [
            'label'      => __('ExamplePress — Theme', 'examplepress-mu'),
            'menu_title' => __('Theme', 'examplepress-mu'),
            'position'   => 10,
            'icon'       => 'dashicons-admin-appearance',
        ]);

        self::registerPage('navigation', [
            'label'      => __('ExamplePress — Navigation', 'examplepress-mu'),
            'menu_title' => __('Navigation', 'examplepress-mu'),
            'position'   => 20,
            'icon'       => 'dashicons-menu',
        ]);

        self::registerPage('dependencies', [
            'label'      => __('ExamplePress — Dependencies', 'examplepress-mu'),
            'menu_title' => __('Dependencies', 'examplepress-mu'),
            'position'   => 30,
            'icon'       => 'dashicons-admin-plugins',
        ]);

        self::registerPage('library', [
            'label'      => __('ExamplePress — Library', 'examplepress-mu'),
            'menu_title' => __('Library', 'examplepress-mu'),
            'position'   => 40,
            'icon'       => 'dashicons-book-alt',
        ]);

        self::registerPage('settings', [
            'label'      => __('ExamplePress — Settings', 'examplepress-mu'),
            'menu_title' => __('Settings', 'examplepress-mu'),
            'position'   => 50,
            'icon'       => 'dashicons-admin-settings',
        ]);

        self::registerPage('notifications', [
            'label'      => __('ExamplePress — Notifications', 'examplepress-mu'),
            'menu_title' => __('Notifications', 'examplepress-mu'),
            'position'   => 55,
            'icon'       => 'dashicons-bell',
        ]);

        self::registerPage('system', [
            'label'      => __('ExamplePress — System', 'examplepress-mu'),
            'menu_title' => __('System', 'examplepress-mu'),
            'position'   => 60,
            'icon'       => 'dashicons-dashboard',
        ]);

        self::registerPage('docs', [
            'label'      => __('ExamplePress — Docs', 'examplepress-mu'),
            'menu_title' => __('Docs', 'examplepress-mu'),
            'position'   => 70,
            'icon'       => 'dashicons-media-document',
        ]);

        self::registerPage('editor', [
            'label'      => __('ExamplePress — Editor', 'examplepress-mu'),
            'menu_title' => __('Editor', 'examplepress-mu'),
            'position'   => 999,
            'icon'       => 'dashicons-editor-code',
            'hidden'     => true,
        ]);
    }
}
