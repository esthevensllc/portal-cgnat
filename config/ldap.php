<?php

return [
    // Mantiene el mismo interruptor que ya usa portal.env para habilitar/deshabilitar el login.
    'enabled' => filter_var(env('PORTAL_AUTH_ENABLED', true), FILTER_VALIDATE_BOOL),
    'temporary_username' => (string) env('PORTAL_AUTH_TEMPORARY_USERNAME', 'acceso_temporal'),
    'temporary_permissions' => [
        'cgnat.query',
        'cgnat.exports',
        'cgnat.templates',
        'cgnat.nodes',
    ],
    
    // Active Directory / LDAP corporativo.
    'host' => (string) env('LDAP_HOST', ''),
    'port' => (int) env('LDAP_PORT', 389),
    'base_dn' => (string) env('LDAP_BASE_DN', ''),
    'domain' => trim((string) env('LDAP_DOMAIN', 'TIM')),
    'allowed_group' => filled(env('LDAP_ALLOWED_GROUP')) ? (string) env('LDAP_ALLOWED_GROUP') : null,
    'timeout' => (int) env('LDAP_TIMEOUT', 10),

    // Equivale al control de intentos que usa el proyecto de bajas.
    'max_attempts' => (int) env('LDAP_LOGIN_MAX_ATTEMPTS', 5),
    'decay_seconds' => (int) env('LDAP_LOGIN_DECAY_SECONDS', 300),
];
