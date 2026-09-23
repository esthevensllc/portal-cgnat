-- Estado actual de todos los usuarios del portal. La sesión vence tras SESSION_LIFETIME minutos sin actividad.
SELECT
    users.username AS Usuario,
    argMax(users.display_name, users.version) AS DescripcionUsuario,
    if(argMax(users.is_active, users.version) = 0, 'Desconectado',
       if(ifNull(any(sessions.connected), 0) = 1 AND any(sessions.expires_at) > now64(3, 'UTC'),
          'Conectado', 'Desconectado')) AS EstadoActual
FROM portal_cgnat.users AS users
LEFT JOIN
(
    SELECT username,
           argMax(connected, version) AS connected,
           argMax(expires_at, version) AS expires_at
    FROM portal_cgnat.user_sessions
    GROUP BY username
) AS sessions ON lower(users.username) = sessions.username
GROUP BY users.username
ORDER BY Usuario;
