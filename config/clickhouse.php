<?php

$nodeNames = array_values(array_filter(array_map(
    static fn (string $node): string => trim($node),
    explode(',', (string) env('CLICKHOUSE_NODES', 'ch01,ch02,ch03,ch04')),
)));

$nodes = [];

foreach ($nodeNames as $nodeName) {
    $prefix = 'CLICKHOUSE_'.strtoupper(str_replace('-', '_', $nodeName));
    $defaultLabel = strtoupper((string) preg_replace('/^ch(\d+)$/i', 'CH-$1', $nodeName));

    $nodes[$nodeName] = [
        'label' => env($prefix.'_LABEL', $defaultLabel),
        'url' => env($prefix.'_URL'),
        'username' => env($prefix.'_USERNAME', 'portal_cgnat'),
        'password' => env($prefix.'_PASSWORD'),
        'database' => env($prefix.'_DATABASE', 'cgnat'),
        'table_pattern' => env($prefix.'_TABLE_PATTERN', 'huawei_cgn_nat_v2_%s'),
        'verify_tls' => filter_var(env($prefix.'_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
    ];
}

return [
    'enabled' => filter_var(env('CLICKHOUSE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'timezone' => env('CLICKHOUSE_TIMEZONE', 'America/Lima'),
    'connect_timeout' => (int) env('CLICKHOUSE_CONNECT_TIMEOUT', 3),
    'query_timeout' => (int) env('CLICKHOUSE_QUERY_TIMEOUT', 600),
    'export_timeout' => (int) env('CLICKHOUSE_EXPORT_TIMEOUT', 180000),
    'max_interactive_hours' => (int) env('CLICKHOUSE_MAX_INTERACTIVE_HOURS', 168),
    'page_size' => (int) env('CLICKHOUSE_PAGE_SIZE', 100),
    'export_threshold' => (int) env('CLICKHOUSE_EXPORT_THRESHOLD', 100000),
    'nodes' => $nodes,
];
