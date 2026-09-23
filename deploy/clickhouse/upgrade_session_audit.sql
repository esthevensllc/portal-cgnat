-- Ejecutar una vez en instalaciones que ya tienen audit_events.
ALTER TABLE portal_cgnat.audit_events ADD COLUMN IF NOT EXISTS user_description String DEFAULT '';
ALTER TABLE portal_cgnat.audit_events ADD COLUMN IF NOT EXISTS source_hostname String DEFAULT '';
ALTER TABLE portal_cgnat.audit_events ADD COLUMN IF NOT EXISTS destination_ip String DEFAULT '';
ALTER TABLE portal_cgnat.audit_events ADD COLUMN IF NOT EXISTS destination_hostname String DEFAULT '';
ALTER TABLE portal_cgnat.audit_events ADD COLUMN IF NOT EXISTS os_username String DEFAULT '';

CREATE TABLE IF NOT EXISTS portal_cgnat.user_sessions
(
    username String,
    session_hash String,
    connected UInt8,
    last_seen_at DateTime64(3, 'UTC'),
    expires_at DateTime64(3, 'UTC'),
    version UInt64
)
ENGINE = ReplacingMergeTree(version)
ORDER BY username;
