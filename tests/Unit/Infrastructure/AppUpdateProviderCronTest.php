<?php
/**
 * Self-contained regression test for AppUpdateProvider's cron migration.
 *
 * The update-fetch flow used to run inside the admin request path via
 * pre_set_site_transient_update_plugins, which could block an admin
 * page for N × 10 seconds on a cold cache. It now runs in a twicedaily
 * cron job, with the admin path serving only the cached transient.
 *
 * This test exists to catch any regression that re-introduces the
 * synchronous fetch, plus to smoke-test the cron lifecycle and the
 * release-payload parser.
 *
 * Runnable standalone (no PHPUnit, no WordPress). From the repo root:
 *   php -d zend.assertions=1 tests/Unit/Infrastructure/AppUpdateProviderCronTest.php
 *
 * Tests live OUTSIDE the examplepress-mu/ package directory so they
 * never end up in the shipped artifact — the build scripts only tar
 * the examplepress-mu/ folder.
 *
 * Exit 0 on all pass, non-zero on any assertion failure.
 */

declare(strict_types=1);

// ── Stub namespaced dependencies ──────────────────────────────────
// AppUpdateProvider only touches these inside fetchAllUpdates(),
// which we never call during the cron-lifecycle tests. We still need
// empty class definitions so namespace-resolution doesn't error when
// AppUpdateProvider.php parses.
namespace ExamplePress\MU\Infrastructure {
    // Stubs read from a per-test-controlled global so tests can
    // inject fake apps / registry entries without re-defining classes.
    if (!class_exists(AppDiscovery::class, false)) {
        final class AppDiscovery
        {
            public static function scan(): array
            {
                global $ep_test;
                return $ep_test['apps'] ?? [];
            }
        }
    }
    if (!class_exists(AppRegistry::class, false)) {
        final class AppRegistry
        {
            public static function all(): array
            {
                global $ep_test;
                return $ep_test['registry'] ?? [];
            }
        }
    }
    if (!class_exists(GitHub::class, false)) {
        final class GitHub
        {
            public static function writeToken(): string { return ''; }
            public static function readToken(): string { return ''; }
        }
    }
    if (!class_exists(Helpers::class, false)) {
        final class Helpers
        {
            public static function isValidGitHubRepo(string $repo): bool
            {
                return (bool) preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo);
            }
        }
    }
}

// ── Everything else lives in the global namespace ────────────────
namespace {

    // ── PHP assertions: hard fail-fast check ──────────────────────
    // zend.assertions=-1 (the PHP production default) compiles
    // assert() calls out entirely at parse time, so relying on
    // ep_assert() to catch regressions would silently degrade to no-ops
    // on any host with the production ini. We refuse to run the test
    // under that configuration — operators must explicitly invoke
    // with -d zend.assertions=1 (the PHP dev/CI default).
    if ((int) ini_get('zend.assertions') < 1) {
        fwrite(STDERR, "ERROR: This test suite requires zend.assertions >= 1.\n");
        fwrite(STDERR, "Re-run with:\n");
        fwrite(STDERR, "    php -d zend.assertions=1 " . __FILE__ . "\n");
        exit(2);
    }
    assert_options(ASSERT_ACTIVE, 1);
    assert_options(ASSERT_EXCEPTION, 1);

    // Defence in depth: use a tiny explicit assertion helper that
    // throws regardless of the zend.assertions compile flag. This
    // way a future contributor can swap assert() for ep_assert()
    // and get guaranteed runtime checks even when the production
    // php.ini is in effect.
    function ep_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    // ── Test state ────────────────────────────────────────────────
    $ep_test = [
        'actions'       => [],
        'filters'       => [],
        'scheduled'     => [],
        'single_events' => [],
        'transients'    => [],
        'deleted_trans' => [],
        'options'       => [],
        'http_calls'    => [],
        'is_doing_cron' => false,
        'error_log'     => [],
        // Fake apps + registry for tests that want to exercise the
        // fetchAllUpdates() code path. AppDiscovery::scan() and
        // AppRegistry::all() stubs read from these, so tests can inject
        // whatever state they need without re-defining the stubs.
        'apps'          => [],
        'registry'      => [],
    ];

    function ep_test_reset(): void
    {
        global $ep_test;
        $ep_test = [
            'actions'       => [],
            'filters'       => [],
            'scheduled'     => [],
            'single_events' => [],
            'transients'    => [],
            'deleted_trans' => [],
            'options'       => [],
            'http_calls'    => [],
            'is_doing_cron' => false,
            'error_log'     => [],
            'apps'          => [],
            'registry'      => [],
        ];
        // Reset the private static memo on AppUpdateProvider between
        // tests via reflection — there is no public reset and we don't
        // want to add one just for tests.
        if (class_exists('ExamplePress\\MU\\Infrastructure\\AppUpdateProvider', false)) {
            $ref  = new \ReflectionClass('ExamplePress\\MU\\Infrastructure\\AppUpdateProvider');
            $prop = $ref->getProperty('memo');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    // ── Minimal WP_Error stub ─────────────────────────────────────
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public string $message = '';
            public function __construct(string $code = '', string $message = '')
            {
                $this->message = $message;
            }
            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    // ── Constants the tested code reaches for ────────────────────
    if (!defined('EXAMPLEPRESS_MU_VERSION')) {
        define('EXAMPLEPRESS_MU_VERSION', '0.0.0-test');
    }

    // ── WordPress function stubs ─────────────────────────────────

    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        global $ep_test;
        $ep_test['actions'][$hook][] = ['callback' => $callback, 'priority' => $priority];
        return true;
    }

    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        global $ep_test;
        $ep_test['filters'][$hook][] = ['callback' => $callback, 'priority' => $priority];
        return true;
    }

    function apply_filters(string $hook, $value, ...$rest)
    {
        return $value;
    }

    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        global $ep_test;
        $ep_test['scheduled'][] = [
            'timestamp'  => $timestamp,
            'recurrence' => $recurrence,
            'hook'       => $hook,
            'args'       => $args,
        ];
        return true;
    }

    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        global $ep_test;
        $ep_test['single_events'][] = [
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
        ];
        return true;
    }

    function wp_next_scheduled(string $hook, array $args = [])
    {
        global $ep_test;
        foreach ($ep_test['scheduled'] as $event) {
            if ($event['hook'] === $hook) {
                return $event['timestamp'];
            }
        }
        foreach ($ep_test['single_events'] as $event) {
            if ($event['hook'] === $hook) {
                return $event['timestamp'];
            }
        }
        return false;
    }

    function wp_doing_cron(): bool
    {
        global $ep_test;
        return $ep_test['is_doing_cron'];
    }

    function get_site_transient(string $key)
    {
        global $ep_test;
        return $ep_test['transients'][$key] ?? false;
    }

    function set_site_transient(string $key, $value, int $expiration = 0): bool
    {
        global $ep_test;
        $ep_test['transients'][$key] = $value;
        return true;
    }

    function delete_site_transient(string $key): bool
    {
        global $ep_test;
        unset($ep_test['transients'][$key]);
        $ep_test['deleted_trans'][] = $key;
        return true;
    }

    function get_option(string $key, $default = false)
    {
        global $ep_test;
        return $ep_test['options'][$key] ?? $default;
    }

    function wp_remote_get(string $url, array $args = [])
    {
        global $ep_test;
        $ep_test['http_calls'][] = ['method' => 'GET', 'url' => $url, 'args' => $args];
        return [
            'response' => ['code' => 0, 'message' => 'Stubbed'],
            'body'     => '',
        ];
    }

    function wp_remote_retrieve_response_code($response): int
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }

    function wp_remote_retrieve_body($response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }

    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }

    // ── Load the class under test ─────────────────────────────────
    //
    // Tests live OUTSIDE the examplepress-mu/ package directory (they
    // must not ship to operators), so the relative path walks up three
    // levels to the repo root, then back down into examplepress-mu/src.
    //
    //   tests/Unit/Infrastructure/AppUpdateProviderCronTest.php
    //   → ../../../                             (repo root)
    //   → examplepress-mu/src/Infrastructure/AppUpdateProvider.php

    require_once __DIR__ . '/../../../examplepress-mu/src/Infrastructure/AppUpdateProvider.php';

    // ── Test runner ───────────────────────────────────────────────

    $tests = 0;
    $fails = [];

    $run_test = function (string $name, callable $fn) use (&$tests, &$fails): void {
        $tests++;
        ep_test_reset();
        try {
            $fn();
            echo "  PASS  {$name}\n";
        } catch (\Throwable $e) {
            $fails[] = ['name' => $name, 'error' => $e->getMessage()];
            echo "  FAIL  {$name} — {$e->getMessage()}\n";
        }
    };

    echo "AppUpdateProvider cron lifecycle\n";

    // ── Test: init() schedules the cron ──────────────────────────
    $run_test('init() schedules the twicedaily cron event', static function (): void {
        global $ep_test;

        \ExamplePress\MU\Infrastructure\AppUpdateProvider::init();

        $cronHook = \ExamplePress\MU\Infrastructure\AppUpdateProvider::CRON_HOOK;

        ep_assert(
            isset($ep_test['actions'][$cronHook]),
            'CRON_HOOK should have an add_action() registration'
        );

        $found = false;
        foreach ($ep_test['scheduled'] as $event) {
            if ($event['hook'] === $cronHook && $event['recurrence'] === 'twicedaily') {
                $found = true;
                break;
            }
        }
        ep_assert($found, 'CRON_HOOK should be wp_schedule_event-ed with twicedaily recurrence');

        ep_assert(
            isset($ep_test['filters']['pre_set_site_transient_update_plugins']),
            'injectUpdates filter must remain hooked into pre_set_site_transient_update_plugins'
        );
    });

    // ── Test: maybeCheckForUpdates refuses without cron context ──
    $run_test('maybeCheckForUpdates() is a no-op when not in cron context', static function (): void {
        global $ep_test;

        $ep_test['is_doing_cron'] = false;

        \ExamplePress\MU\Infrastructure\AppUpdateProvider::maybeCheckForUpdates();

        ep_assert(
            count($ep_test['http_calls']) === 0,
            'maybeCheckForUpdates() must make zero HTTP calls when wp_doing_cron() is false'
        );
        ep_assert(
            !isset($ep_test['transients']['ep_app_update_data']),
            'maybeCheckForUpdates() must not write the update data transient outside cron context'
        );
    });

    // ── Test: injectUpdates reads from transient only ─────────────
    $run_test('injectUpdates() never triggers an HTTP fetch (warm cache)', static function (): void {
        global $ep_test;

        $ep_test['transients']['ep_app_update_data'] = [
            'some-app/some-app.php' => [
                'slug'             => 'some-app',
                'new_version'      => '1.2.3',
                'update_available' => true,
                'package'          => 'https://example.invalid/some-app.zip',
                'html_url'         => 'https://github.com/acme/some-app',
                'name'             => 'Some App',
            ],
        ];

        $transient = new \stdClass();
        $transient->response = [];

        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::injectUpdates($transient);

        ep_assert(
            count($ep_test['http_calls']) === 0,
            'injectUpdates() must never call wp_remote_get — that path moved to the cron job'
        );
        ep_assert(
            isset($result->response['some-app/some-app.php']),
            'injectUpdates() should copy cached entries into the update_plugins transient'
        );
    });

    // ── Test: cold-cache injectUpdates is still read-only ────────
    //
    // This test specifically guards against the original regression:
    // someone reintroducing a synchronous fallback fetch in
    // getUpdateData that runs when the transient is empty. The
    // warm-cache test above wouldn't catch it because it pre-seeds
    // the transient, so getUpdateData's cold-cache branch is never
    // reached.
    //
    // We seed a fake app into AppDiscovery::scan()'s return so that
    // a regressed synchronous-fetch path would actually have work to
    // do and reach wp_remote_get. Without a fake app, fetchAllUpdates
    // would short-circuit on an empty input and the regression would
    // still slip past the test.
    $run_test('injectUpdates() on cold cache serves no updates and makes no HTTP calls', static function (): void {
        global $ep_test;

        // Precondition: cold cache.
        ep_assert(
            !isset($ep_test['transients']['ep_app_update_data']),
            'precondition: cache transient must be empty'
        );

        // Seed a fake discovered app + matching registry entry. If a
        // regression reintroduces synchronous fetch on cold-cache
        // getUpdateData, this app's /releases/latest URL will be
        // called and our stub wp_remote_get records it.
        $ep_test['apps'] = [
            [
                'slug'        => 'some-app',
                'plugin_file' => 'some-app/some-app.php',
                'version'     => '1.0.0',
                'name'        => 'Some App',
                'description' => 'A fake app for regression testing.',
                'troy'        => ['repo' => ''],
            ],
        ];
        $ep_test['registry'] = [
            'some-app' => [
                'github' => [
                    'owner_repo' => 'acme/some-app',
                ],
            ],
        ];

        $transient = new \stdClass();
        $transient->response = [];

        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::injectUpdates($transient);

        // The key assertion. If someone re-adds a synchronous fallback
        // fetch to getUpdateData, wp_remote_get WILL fire for
        // acme/some-app during this path and this assertion will blow up
        // with a clear regression message.
        ep_assert(
            count($ep_test['http_calls']) === 0,
            'REGRESSION: injectUpdates() on a cold cache made '
            . count($ep_test['http_calls'])
            . ' HTTP call(s). '
            . 'getUpdateData() must NEVER fetch synchronously — the cron job is '
            . 'the only thing that populates ep_app_update_data. If you need to '
            . 'force a refresh, call AppUpdateProvider::checkNow() explicitly.'
        );

        // And nothing should have been injected into the transient.
        ep_assert(
            empty($result->response),
            'Cold cache must produce an empty injection set'
        );
    });

    // ── Test: flush() schedules an async repopulation ─────────────
    $run_test('flush() schedules a single-event cron tick to repopulate', static function (): void {
        global $ep_test;

        $ep_test['transients']['ep_app_update_data']       = ['stale' => 'data'];
        $ep_test['transients']['ep_app_update_check_lock'] = 'checking';

        \ExamplePress\MU\Infrastructure\AppUpdateProvider::flush();

        ep_assert(
            !isset($ep_test['transients']['ep_app_update_data']),
            'flush() must delete the update data transient'
        );
        ep_assert(
            !isset($ep_test['transients']['ep_app_update_check_lock']),
            'flush() must also drop the in-flight lock so the next cron can run immediately'
        );

        $hasSingle = false;
        foreach ($ep_test['single_events'] as $event) {
            if ($event['hook'] === \ExamplePress\MU\Infrastructure\AppUpdateProvider::CRON_HOOK) {
                $hasSingle = true;
                break;
            }
        }
        ep_assert(
            $hasSingle,
            'flush() must schedule a single-event cron tick for CRON_HOOK so the cache repopulates ASAP'
        );
    });

    // ── Test: parseReleaseBody rejects drafts and prereleases ────
    $run_test('parseReleaseBody() rejects draft releases', static function (): void {
        $body = json_encode([
            'tag_name'    => 'v1.2.3',
            'draft'       => true,
            'prerelease'  => false,
            'assets'      => [],
            'zipball_url' => 'https://api.github.com/repos/acme/foo/zipball/v1.2.3',
        ]);
        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::parseReleaseBody((string) $body, 'foo');
        ep_assert($result === null, 'Draft releases must not produce an update record');
    });

    $run_test('parseReleaseBody() rejects prerelease builds', static function (): void {
        $body = json_encode([
            'tag_name'    => 'v1.2.3-rc1',
            'draft'       => false,
            'prerelease'  => true,
            'assets'      => [],
            'zipball_url' => 'https://api.github.com/repos/acme/foo/zipball/v1.2.3-rc1',
        ]);
        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::parseReleaseBody((string) $body, 'foo');
        ep_assert($result === null, 'Prerelease builds must not produce an update record');
    });

    $run_test('parseReleaseBody() prefers the built ZIP asset', static function (): void {
        $body = json_encode([
            'tag_name'    => 'v2.0.0',
            'html_url'    => 'https://github.com/acme/foo/releases/tag/v2.0.0',
            'body'        => 'Release notes',
            'draft'       => false,
            'prerelease'  => false,
            'assets'      => [
                [
                    'name'                 => 'foo.zip',
                    'browser_download_url' => 'https://github.com/acme/foo/releases/download/v2.0.0/foo.zip',
                ],
                [
                    'name'                 => 'other-thing.zip',
                    'browser_download_url' => 'https://github.com/acme/foo/releases/download/v2.0.0/other-thing.zip',
                ],
            ],
            'zipball_url' => 'https://api.github.com/repos/acme/foo/zipball/v2.0.0',
        ]);
        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::parseReleaseBody((string) $body, 'foo');

        ep_assert(is_array($result), 'Valid release should parse to an array');
        ep_assert($result['tag_name'] === 'v2.0.0', 'tag_name must survive through the parser');
        ep_assert(
            $result['package_url'] === 'https://github.com/acme/foo/releases/download/v2.0.0/foo.zip',
            'Named ZIP asset must be preferred over sibling assets and zipball_url'
        );
    });

    $run_test('parseReleaseBody() falls back to zipball_url when no matching asset', static function (): void {
        $body = json_encode([
            'tag_name'    => 'v2.0.0',
            'html_url'    => 'https://github.com/acme/foo/releases/tag/v2.0.0',
            'body'        => '',
            'draft'       => false,
            'prerelease'  => false,
            'assets'      => [],
            'zipball_url' => 'https://api.github.com/repos/acme/foo/zipball/v2.0.0',
        ]);
        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::parseReleaseBody((string) $body, 'foo');

        ep_assert(is_array($result), 'Valid release should parse to an array');
        ep_assert(
            $result['package_url'] === 'https://api.github.com/repos/acme/foo/zipball/v2.0.0',
            'package_url must fall back to zipball_url when no matching ZIP asset is shipped'
        );
    });

    $run_test('parseReleaseBody() returns null on non-JSON body', static function (): void {
        $result = \ExamplePress\MU\Infrastructure\AppUpdateProvider::parseReleaseBody('<html>not json</html>', 'foo');
        ep_assert($result === null, 'Non-JSON body must return null (GitHub error page / rate-limit)');
    });

    // ── Report ────────────────────────────────────────────────────
    echo "\n";
    if (empty($fails)) {
        echo "PASS — {$tests} tests\n";
        exit(0);
    }

    echo "FAIL — " . count($fails) . " of {$tests} tests failed\n\n";
    foreach ($fails as $failure) {
        echo "  {$failure['name']}\n    {$failure['error']}\n\n";
    }
    exit(1);
}
