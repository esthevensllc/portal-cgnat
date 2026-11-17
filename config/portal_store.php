<?php

return [
    // Persistencia del portal independiente de los colectores CLICKHOUSE_NODES.
    'url' => env('PORTAL_STORE_URL'),
    'username' => env('PORTAL_STORE_USERNAME'),
    'password' => env('PORTAL_STORE_PASSWORD'),
    'database' => env('PORTAL_STORE_DATABASE', 'portal_cgnat'),
    'verify_tls' => filter_var(env('PORTAL_STORE_VERIFY_TLS', false), FILTER_VALIDATE_BOOL),
    'connect_timeout' => (int) env('PORTAL_STORE_CONNECT_TIMEOUT', 3),
    'query_timeout' => (int) env('PORTAL_STORE_QUERY_TIMEOUT', 10),
];
