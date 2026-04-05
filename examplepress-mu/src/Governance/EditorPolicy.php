<?php

declare(strict_types=1);

namespace ExamplePress\MU\Governance;

/**
 * Block editor policies — pattern restrictions, block type limits, etc.
 *
 * Converted from legacy feature logic into static methods.
 * Features are now controlled via FeatureRegistry.
 */
final class EditorPolicy
{
    // EditorPolicy features are registered through FeatureRegistry.
    // See FeatureRegistry::registerCoreFeatures() for 'disable-remote-block-patterns',
    // 'disable-core-block-patterns', 'restrict-block-types', and 'openverse'
    // registrations.
    //
    // This class exists as a namespace placeholder for future editor-specific
    // governance policies that don't fit the feature flag model.
}
