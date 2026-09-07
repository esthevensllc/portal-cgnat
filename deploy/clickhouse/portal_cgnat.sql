-- Ejecutar con una cuenta administrativa de ClickHouse antes de habilitar el acceso LDAP.
CREATE DATABASE IF NOT EXISTS portal_cgnat;

CREATE TABLE IF NOT EXISTS portal_cgnat.users
(
    username String,
    display_name String,
    email String,
    is_active UInt8,
    source LowCardinality(String),
    version DateTime64(3),
    updated_at DateTime64(3)
)
ENGINE = ReplacingMergeTree(version)
ORDER BY username;

CREATE TABLE IF NOT EXISTS portal_cgnat.roles
(
    code LowCardinality(String),
    name String,
    is_active UInt8,
    version DateTime64(3),
    updated_by String
)
ENGINE = ReplacingMergeTree(version)
ORDER BY code;

CREATE TABLE IF NOT EXISTS portal_cgnat.user_roles
(
    username String,
    role_code LowCardinality(String),
    is_active UInt8,
    version DateTime64(3),
    updated_by String
)
ENGINE = ReplacingMergeTree(version)
ORDER BY (username, role_code);

CREATE TABLE IF NOT EXISTS portal_cgnat.role_permissions
(
    role_code LowCardinality(String),
    permission LowCardinality(String),
    is_active UInt8,
    version DateTime64(3),
    updated_by String
)
ENGINE = ReplacingMergeTree(version)
ORDER BY (role_code, permission);

CREATE TABLE IF NOT EXISTS portal_cgnat.query_audit
(
    occurred_at DateTime64(3),
    request_id UUID,
    username String,
    selected_nodes Array(LowCardinality(String)),
    from_time DateTime,
    to_time DateTime,
    router_ip Nullable(String),
    private_ip Nullable(String),
    private_port Nullable(UInt16),
    public_ip Nullable(String),
    public_port_from Nullable(UInt16),
    public_port_to Nullable(UInt16),
    destination_ip Nullable(String),
    destination_port Nullable(UInt16),
    rows UInt32,
    elapsed_ms UInt32,
    status LowCardinality(String),
    error String
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (occurred_at, username, request_id)
TTL occurred_at + INTERVAL 365 DAY DELETE;

CREATE TABLE IF NOT EXISTS portal_cgnat.audit_events
(
    event_id UUID,
    occurred_at DateTime64(3, 'UTC'),
    request_id UUID,
    event_type LowCardinality(String),
    outcome LowCardinality(String),
    username String,
    auth_provider LowCardinality(String),
    source_ip String,
    http_method LowCardinality(String),
    route_name LowCardinality(String),
    resource_type LowCardinality(String),
    resource_id String,
    elapsed_ms UInt32,
    total_rows UInt64,
    details_json String
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (occurred_at, username, event_type, event_id)
TTL occurred_at + INTERVAL 365 DAY DELETE;

CREATE TABLE IF NOT EXISTS portal_cgnat.query_templates
(
    id UUID,
    username String,
    name String,
    filters_json String,
    is_active UInt8,
    created_at DateTime64(3),
    version DateTime64(3)
)
ENGINE = ReplacingMergeTree(version)
ORDER BY (username, id);

CREATE TABLE IF NOT EXISTS portal_cgnat.export_tasks
(
    id UUID,
    username String,
    state LowCardinality(String),
    total_rows UInt64,
    processed_rows UInt64,
    progress UInt8,
    filename String,
    filters_json String,
    error String,
    created_at DateTime64(3),
    updated_at DateTime64(3),
    version DateTime64(3)
)
ENGINE = ReplacingMergeTree(version)
ORDER BY (username, id)
TTL updated_at + INTERVAL 30 DAY DELETE;

INSERT INTO portal_cgnat.roles VALUES
('cgnat_basico', 'Consulta CGNAT básica', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'Administrador Portal CGNAT', 1, now64(3), 'bootstrap'),
('cgnat_consultor', 'Consulta CGNAT (legado)', 1, now64(3), 'bootstrap');

INSERT INTO portal_cgnat.role_permissions VALUES
('cgnat_basico', 'cgnat.query', 1, now64(3), 'bootstrap'),
('cgnat_basico', 'cgnat.exports', 1, now64(3), 'bootstrap'),
('cgnat_basico', 'cgnat.templates', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'cgnat.query', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'cgnat.exports', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'cgnat.templates', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'cgnat.nodes', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'cgnat.admin', 1, now64(3), 'bootstrap'),
('cgnat_administrador', 'cgnat.audit.view', 1, now64(3), 'bootstrap'),
('cgnat_consultor', 'cgnat.query', 1, now64(3), 'bootstrap'),
('cgnat_consultor', 'cgnat.exports', 1, now64(3), 'bootstrap'),
('cgnat_consultor', 'cgnat.templates', 1, now64(3), 'bootstrap'),
('cgnat_consultor', 'cgnat.nodes', 1, now64(3), 'bootstrap'),
('cgnat_consultor', 'cgnat.admin', 1, now64(3), 'bootstrap');
