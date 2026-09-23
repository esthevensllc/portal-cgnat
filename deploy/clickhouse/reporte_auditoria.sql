-- Consulta de auditoría con los nombres solicitados para el reporte.
SELECT
    event_id AS IdEvento,
    username AS Usuario,
    user_description AS DescripcionUsuario,
    source_ip AS `Source IP`,
    source_hostname AS `Source Hostname`,
    destination_ip AS `Destination IP`,
    destination_hostname AS `Detination Hostname`,
    os_username AS UsuarioSO,
    occurred_at AS `Fecha y Hora`,
    event_type AS `Transacción`,
    details_json AS Variables,
    multiIf(outcome IN ('success', 'Correcto'), 'Correcto',
            outcome IN ('pending', 'Pendiente'), 'Pendiente', 'Fallido') AS EstadoEvento
FROM portal_cgnat.audit_events
ORDER BY occurred_at DESC, event_id DESC
LIMIT 500;
