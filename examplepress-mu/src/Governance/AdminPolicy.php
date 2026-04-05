<?php

declare(strict_types=1);

namespace ExamplePress\MU\Governance;

/**
 * WordPress UI cleanup — dashboard widgets, admin bar, etc.
 *
 * Converted from legacy feature logic into static methods hooked to WP core actions.
 * Features are now controlled via FeatureRegistry.
 */
final class AdminPolicy
{
    // AdminPolicy features are registered through FeatureRegistry.
    // See FeatureRegistry::registerCoreFeatures() for 'remove-dashboard-widgets',
    // 'post-lock-window', and 'login-branding' registrations.
    //
    // This class exists as a namespace placeholder for future admin-specific
    // governance policies that don't fit the feature flag model.
}
