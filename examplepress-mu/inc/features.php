<?php
/**
 * ExamplePress Feature Definitions
 *
 * Loads domain-specific feature files. Each feature is filterable:
 * - Toggle:  add_filter( 'examplepress_feature_{id}', '__return_false' );
 * - Option:  add_filter( 'examplepress_feature_{id}_{key}', fn() => $val );
 */

require_once __DIR__ . '/features/theme-support.php';
require_once __DIR__ . '/features/editor-controls.php';
require_once __DIR__ . '/features/admin-customization.php';
require_once __DIR__ . '/features/design-tokens.php';
require_once __DIR__ . '/features/blockstudio.php';
