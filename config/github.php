<?php

/*
| P09: fixed, non-secret GitHub constants. The GitHub App's own credentials are platform
| settings stored encrypted in the database (Settings → GitHub App), never env or config.
| api_url is the host allowlist, so it is deliberately not configurable.
*/

return [
    'api_url' => 'https://api.github.com',
    // OAuth code exchange and the install page live on the web host, not the API host.
    'web_url' => 'https://github.com',
    // The only hosts a zipball redirect may lead to (besides the API host itself).
    'archive_hosts' => ['codeload.github.com'],
    'connect_ttl_minutes' => 10,
    'http_timeout_seconds' => 30,
    'archive_timeout_seconds' => 300,
];
