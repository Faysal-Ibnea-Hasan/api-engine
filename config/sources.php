<?php

return [
    // Hard limits for one snapshot. ZIP import (P04) enforces its own limits on top.
    'max_files' => (int) env('SOURCES_MAX_FILES', 100000),
    'max_total_bytes' => (int) env('SOURCES_MAX_TOTAL_BYTES', 1024 * 1024 * 1024), // 1 GB of kept source
    'max_file_bytes' => (int) env('SOURCES_MAX_FILE_BYTES', 5 * 1024 * 1024),
    'max_path_length' => 1024,

    // Comma-separated folder names that are skipped before ZIP entries are written.
    // Dependencies and VCS/tool folders are always skipped by FileClassifier.
    'ignored_directories' => array_values(array_filter(array_map(
        fn (string $directory): string => trim($directory),
        explode(',', (string) env('SOURCES_IGNORED_DIRECTORIES', 'public')),
    ))),

    // ZIP uploads (P04). The archive limits are bomb protection and count every entry,
    // including vendor/ and node_modules/, which are never extracted; the stored-file
    // limits above apply to what is actually extracted and kept.
    'zip' => [
        'max_upload_bytes' => (int) env('SOURCES_ZIP_MAX_UPLOAD_BYTES', 10 * 1024 * 1024 * 1024), // 10 GB archive
        // Uploads arrive in pieces of this size, so PHP/nginx only need to accept ~chunk-sized bodies.
        'chunk_bytes' => (int) env('SOURCES_ZIP_CHUNK_BYTES', 50 * 1024 * 1024),
        'upload_ttl_hours' => 24, // unfinished uploads are deleted after this
        'max_entries' => (int) env('SOURCES_ZIP_MAX_ENTRIES', 2000000),
        'max_ratio' => 100,        // uncompressed / compressed, for extracted entries over 1 MB
        'max_depth' => 32,         // folder levels
        'max_seconds' => 1200,     // well under the 1800s job timeout
    ],

    // Retention for things that never became a ready snapshot.
    'quarantine_ttl_hours' => (int) env('SOURCES_QUARANTINE_TTL_HOURS', 24),
    'failed_snapshot_ttl_days' => (int) env('SOURCES_FAILED_SNAPSHOT_TTL_DAYS', 7),

    // Controlled fixtures for local/testing only; never enabled in production.
    'fixtures_path' => base_path('tests/Fixtures/sources'),
];
