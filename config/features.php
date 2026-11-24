<?php

/*
|--------------------------------------------------------------------------
| Feature Flags
|--------------------------------------------------------------------------
|
| Every unreleased feature gets a flag here, default off. Read flags with
| App\Support\Features::enabled('name'), or guard routes with the
| 'feature:name' middleware (404 while off). An unknown flag counts as off.
|
| Example: 'zip_import' => (bool) env('FEATURE_ZIP_IMPORT', false),
|
*/

return [
    'workspace_management' => (bool) env('FEATURE_WORKSPACE_MANAGEMENT', false),
    // Needs workspace_management too.
    'project_wizard' => (bool) env('FEATURE_PROJECT_WIZARD', false),
    // Needs project_wizard too.
    'source_snapshots' => (bool) env('FEATURE_SOURCE_SNAPSHOTS', false),
    // Needs source_snapshots too.
    'zip_import' => (bool) env('FEATURE_ZIP_IMPORT', false),
    // Needs source_snapshots too.
    'framework_detection' => (bool) env('FEATURE_FRAMEWORK_DETECTION', false),
    // Needs framework_detection too.
    'route_discovery' => (bool) env('FEATURE_ROUTE_DISCOVERY', false),

    // P07: on-demand request/response contract analysis of one endpoint. Needs route_discovery.
    'endpoint_analysis' => (bool) env('FEATURE_ENDPOINT_ANALYSIS', false),

    // P08: deterministic static documentation drafts from analysis results. Needs endpoint_analysis.
    'static_docs' => (bool) env('FEATURE_STATIC_DOCS', false),

    // P09: import a GitHub repository commit through the GitHub App. Needs source_snapshots.
    'git_import' => (bool) env('FEATURE_GIT_IMPORT', false),
];
