# Portal CGNAT

Portal interno Laravel para consultar sesiones IPv4 PAT en los cuatro nodos
ClickHouse. Usa el diseño visual de los portales internos existentes y no
recarga la página al ejecutar una consulta.

## Arquitectura

```text
Navegador
    │ HTTPS/HTTP interno :8080
Nginx (Podman) ──> PHP-FPM / Laravel (Podman)
                           │        │
                           │        └── Redis: sesión, caché y colas
                           │
                           ├── CAS corporativo: autentica identidad AD
                           ├── ClickHouse cgnat: consultas CGNAT
                           └── ClickHouse portal_cgnat: usuarios, roles,
                               permisos y auditoría
```

No se usa PostgreSQL. Redis no es la base de datos funcional: solo mantiene
estado efímero de Laravel. Toda autorización persistente del portal está en
ClickHouse `portal_cgnat`.

## Flujo de identidad y autorización

1. El usuario abre `/consultas`.
2. Laravel lo redirige al CAS corporativo, igual que `portalseguimiento`.
3. CAS valida la sesión de Active Directory y vuelve a
   `CAS_REDIRECT_TO?ticketID=...`.
4. Laravel valida el ticket por servidor con `checkRemoteLogin`.
5. Laravel consulta ClickHouse: el usuario debe estar activo y tener un rol
   con el permiso `cgnat.query`.
6. La identidad se guarda en la sesión Redis. Cada solicitud protegida vuelve
   a comprobar su permiso; retirar un rol corta el acceso inmediatamente.

El rol inicial es `cgnat_consultor`. La autenticación AD por sí sola no otorga
acceso: cada usuario debe ser aprovisionado en ClickHouse.

## Versiones fijadas

| Componente | Versión |
|---|---:|
| Laravel | 13 |
| PHP | 8.5.9 |
| Composer | 2.10.2 |
| Node.js | 24.18.0 |
| Redis | 8.10.0 |
| Nginx | 1.30.4 |
| ClickHouse del entorno temporal | 26.5.3.52 |
| RHEL | 8.10 |

## Desarrollo local (Windows/Docker)

Copiar `.env.example` a `.env`, completar ClickHouse y CAS, y ejecutar:

```powershell
docker compose up -d --build
npm ci
npm run build
```

No ejecutar `php artisan migrate`: el portal no usa migraciones relacionales.
Para reconstruir la interfaz después de editar `resources/`, ejecutar siempre
`npm run build` y transferir `public/build/` al servidor.

Si npm requiere el proxy corporativo:

```powershell
npm config set proxy http://proxy-claro
npm config set https-proxy http://proxy-claro
npm config set registry https://registry.npmjs.org/
npm ci
npm run build
```

## Preparar un RHEL nuevo y offline

### Precondiciones obligatorias antes de transferir archivos

No inicies la instalación hasta confirmar estas condiciones en el servidor de
producción:

- RHEL 8.10 compatible, Podman y Quadlet instalados; `podman info` funciona.
- `/index2` existe, tiene espacio suficiente y XFS con `ftype=1`.
- El servidor del portal puede llegar por TCP `8123` a cada ClickHouse que se
  habilitará en `CLICKHOUSE_NODES`.
- El servidor del portal puede resolver y llegar al CAS corporativo por HTTPS.
- La red permite a los usuarios llegar al puerto TCP `8080` del portal (o al
  puerto que se publicará detrás del proxy productivo).
- La aplicación CAS ya tiene registrado el retorno productivo exacto:
  `CAS_REDIRECT_TO=http://NOMBRE_O_IP_PRODUCTIVA:8080/consultas`.
- Existe una cuenta ClickHouse de runtime con permisos para las cuatro fuentes
  `cgnat.*` y para el nodo que almacenará `portal_cgnat`.

Comandos de verificación, sin exponer contraseñas:

```bash
cat /etc/redhat-release
podman info --format 'GRAPHROOT={{.Store.GraphRoot}} DRIVER={{.Store.GraphDriverName}}'
xfs_info /index2 | grep 'ftype=1'
df -hT /index2

# Repetir para cada IP ClickHouse configurada.
curl --max-time 3 -fsS http://IP_CLICKHOUSE:8123/ping

# Ajustar host y puerto reales del CAS si usa un puerto distinto de 443.
getent hosts HOST_CAS
nc -vz -w 3 HOST_CAS 443
ss -lntp | grep ':8080' || true
```

El host temporal actual usa `/index2` (XFS con `ftype=1`) y Podman debe guardar
las imágenes en ese volumen:

```bash
install -d -m 0755 /index2/portal-cgnat/{source,deploy,config,exports,logs,backups}
install -d -m 0755 /index2/portal-cgnat/{shared/storage,shared/bootstrap-cache}
install -d -m 0755 /index2/portal-cgnat/{data/redis,containers/storage}

cp -a /etc/containers/storage.conf /etc/containers/storage.conf.bak-$(date +%F)
sed -i 's|^graphroot = ".*"|graphroot = "/index2/portal-cgnat/containers/storage"|' /etc/containers/storage.conf
podman info --format 'GRAPHROOT={{.Store.GraphRoot}} DRIVER={{.Store.GraphDriverName}}'
```

`/index2/portal-cgnat/data/postgres` puede conservarse temporalmente si viene
de una instalación anterior, pero ya no es usado por esta versión.

## Generar artefactos desde el último commit

En la máquina con Docker e Internet corporativo, verifica primero que el árbol
Git esté limpio y genera un release actual. Los archivos preexistentes bajo
`artifacts/` pueden corresponder a código anterior: no los uses sin regenerar.

```powershell
git status
.\scripts\build-offline-images.ps1 -Version 0.1.6
.\scripts\build-release.ps1 -Version 0.1.6
```

Transferir por WinSCP los cuatro archivos generados:

```text
portal-cgnat-images-0.1.6.tar
portal-cgnat-images-0.1.6.tar.sha256
portal-cgnat-release-0.1.6.tar.gz
portal-cgnat-release-0.1.6.tar.gz.sha256
```

Los tags definidos actualmente por Quadlet son `runtime:0.1.0`, `nginx:1.30.4`
y `redis:8.10.0`; el bundle de imágenes conserva esos tags aunque el release
de código tenga otra versión. Si se cambian los tags, actualizar Quadlet y el
script de build en el mismo commit.

## Transferencia y release offline

El desarrollo se hace en Git. Como los servidores no tienen salida a Internet,
transferir el release y los artefactos con WinSCP desde el equipo remoto.

```bash
cd /index2/portal-cgnat/deploy
sha256sum -c portal-cgnat-release-VERSION.tar.gz.sha256
sha256sum -c portal-cgnat-images-VERSION.tar.sha256

# Extraer temporalmente solo para disponer de los scripts de instalación.
install -d -m 0755 /tmp/portal-cgnat-bootstrap
tar -xzf portal-cgnat-release-VERSION.tar.gz -C /tmp/portal-cgnat-bootstrap

bash /tmp/portal-cgnat-bootstrap/deploy/scripts/load-images.sh \
  /index2/portal-cgnat/deploy/portal-cgnat-images-VERSION.tar
bash /tmp/portal-cgnat-bootstrap/deploy/scripts/install-release.sh \
  /index2/portal-cgnat/deploy/portal-cgnat-release-VERSION.tar.gz VERSION
```

El segundo argumento de `install-release.sh` es obligatorio: es el nombre de
la versión (`0.1.6` en el ejemplo), no el directorio extraído.

Para cambios solo de código realizados por WinSCP no hay que reconstruir una
imagen. Transferir los archivos cambiados (incluido `public/build` cuando
corresponda), limpiar cachés y reiniciar los contenedores indicados abajo.
La versión actual también carga explícitamente las clases nuevas de CAS para
ser compatible con el `vendor` classmap de la imagen ya desplegada.

## Configurar `portal.env`

Crear `/index2/portal-cgnat/config/portal.env` desde
`deploy/portal.env.example`, permisos `0600`. Nunca subir este archivo a Git.

Configuración de producción (reemplazar todos los valores de ejemplo):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://NOMBRE_O_IP_PRODUCTIVA:8080
APP_KEY=BASE64_GENERATED_BY_ARTISAN

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=null
REDIS_HOST=portal-redis

CLICKHOUSE_ENABLED=true
CLICKHOUSE_NODES=ch01,ch02,ch03,ch04
CLICKHOUSE_CH01_LABEL=CH-01
CLICKHOUSE_CH01_URL=http://IP_CH01:8123
CLICKHOUSE_CH01_USERNAME=portal_cgnat
CLICKHOUSE_CH01_PASSWORD=SECRETO_RUNTIME
CLICKHOUSE_CH01_DATABASE=cgnat
CLICKHOUSE_CH01_TABLE_PATTERN=huawei_cgn_nat_v2_%s
CLICKHOUSE_CH01_VERIFY_TLS=false

# Repetir estos parámetros para CH02 y CH03.
CLICKHOUSE_CH04_LABEL=CH-04
CLICKHOUSE_CH04_URL=http://IP_CH04:8123
CLICKHOUSE_CH04_USERNAME=portal_cgnat
CLICKHOUSE_CH04_PASSWORD=SECRETO_RUNTIME
CLICKHOUSE_CH04_DATABASE=cgnat
CLICKHOUSE_CH04_TABLE_PATTERN=huawei_cgn_nat_v2_%s
CLICKHOUSE_CH04_VERIFY_TLS=false

PORTAL_STORE_NODE=ch04
PORTAL_STORE_DATABASE=portal_cgnat

CAS_URL_AUTH=https://CAS_INTERNO/RUTA
CAS_API_KEY=VALOR_CAS
CAS_APP_SECRET=SECRETO_CAS
CAS_REDIRECT_TO=http://NOMBRE_O_IP_PRODUCTIVA:8080/consultas
CAS_VERIFY_TLS=true
```

Para generar `APP_KEY`, una vez iniciado el contenedor:

```bash
podman exec portal-app php artisan key:generate --show
```

Como `portal.env` se necesita antes de iniciar Quadlet, para una instalación
desde cero es más simple generarlo directamente en el host y pegar el resultado
en `APP_KEY` antes del arranque:

```bash
printf 'base64:'; openssl rand -base64 32; echo
```

La cuenta `admin` es solo temporal porque no tiene privilegios para crear una
cuenta restringida. Antes de producción, solicitar una cuenta ClickHouse con
solo `SELECT` sobre `cgnat.*` y `SELECT, INSERT` sobre `portal_cgnat.*`.

Con una cuenta que posea `CREATE USER` y `GRANT OPTION`, el perfil de servicio
recomendado es:

```sql
CREATE ROLE portal_cgnat_runtime;
GRANT SELECT ON cgnat.* TO portal_cgnat_runtime;
GRANT SELECT, INSERT ON portal_cgnat.* TO portal_cgnat_runtime;
CREATE USER portal_cgnat IDENTIFIED WITH sha256_password BY 'CONTRASENA_UNICA';
GRANT portal_cgnat_runtime TO portal_cgnat;
ALTER USER portal_cgnat DEFAULT ROLE portal_cgnat_runtime;
```

Luego sustituir `CLICKHOUSE_CHxx_USERNAME/PASSWORD` por esa cuenta en todos los
nodos. No usar la cuenta `admin` en producción.

## Inicializar ClickHouse: usuarios, roles y auditoría

Con una cuenta administrativa ClickHouse (en `.135` puede ser la misma cuenta
administrativa temporal), ejecutar una sola vez:

```bash
ch_query --multiquery < /index2/portal-cgnat/current/deploy/clickhouse/portal_cgnat.sql
```

Ejecutar este DDL en el mismo ClickHouse indicado por `PORTAL_STORE_NODE`
(normalmente `ch04`). Verificar antes de arrancar el portal:

```bash
clickhouse-client --host IP_PORTAL_STORE --port 9000 --user ADMIN \
  --password --query 'EXISTS DATABASE portal_cgnat'
```

Dar acceso a un usuario de AD (reemplazar valores, minúsculas):

```sql
INSERT INTO portal_cgnat.users VALUES
('usuario.ad', 'Nombre visible', 'usuario@empresa.pe', 1, 'AD', now64(3), now64(3));

INSERT INTO portal_cgnat.user_roles VALUES
('usuario.ad', 'cgnat_consultor', 1, now64(3), 'admin.portal');
```

Para retirar acceso sin borrar historial, insertar una nueva versión:

```sql
INSERT INTO portal_cgnat.user_roles VALUES
('usuario.ad', 'cgnat_consultor', 0, now64(3), 'admin.portal');
```

Para crear más permisos/roles:

```sql
INSERT INTO portal_cgnat.roles VALUES
('cgnat_auditor', 'Auditoría CGNAT', 1, now64(3), 'admin.portal');
INSERT INTO portal_cgnat.role_permissions VALUES
('cgnat_auditor', 'cgnat.query', 1, now64(3), 'admin.portal');
INSERT INTO portal_cgnat.user_roles VALUES
('usuario.ad', 'cgnat_auditor', 1, now64(3), 'admin.portal');
```

Las tablas de roles usan `ReplacingMergeTree(version)`. No ejecutar `UPDATE`;
cada cambio es un nuevo `INSERT` versionado. El portal determina el último
estado con `argMax`, por lo que no depende de que ocurra una compactación.

## Arranque con Quadlet

Orden final de instalación desde cero:

1. Preparar `/index2` y el `graphroot` de Podman.
2. Transferir y validar los artefactos; cargar imágenes e instalar el release.
3. Crear `config/portal.env` con `APP_KEY`, los cuatro nodos, la cuenta
   restringida ClickHouse y el retorno CAS de producción.
4. Crear `portal_cgnat` en el nodo `PORTAL_STORE_NODE` y aprovisionar al menos
   un usuario AD con `cgnat_consultor`.
5. Instalar Quadlets, abrir TCP `8080` según la política de red y arrancar.
6. Validar salud, red ClickHouse y autenticación CAS con ese usuario AD.

Si `firewalld` administra el acceso local y la política lo permite, publicar
el puerto de forma permanente antes de la prueba de usuario:

```bash
firewall-cmd --permanent --add-port=8080/tcp
firewall-cmd --reload
firewall-cmd --list-ports
```

No abrir `8123` ni `9000` en el servidor del portal: son conexiones salientes
del portal hacia ClickHouse. El acceso entrante requerido al portal es solo
`8080/tcp` mientras no exista un proxy inverso corporativo.

```bash
bash /index2/portal-cgnat/current/deploy/scripts/install-quadlets.sh
systemctl status portal-redis portal-app portal-worker portal-scheduler portal-nginx --no-pager
curl -fsS http://127.0.0.1:8080/health/live
```

En hosts RHEL con Podman 4/CNI es obligatorio el alias ya incluido mediante
`PodmanArgs=--network-alias=portal-redis` y `DNS=10.89.0.1`. No usar
`NetworkAlias=`: esa versión de Quadlet no lo soporta.

### Retirar PostgreSQL de una instalación anterior

Después de transferir esta versión y antes de hacer el arranque:

```bash
systemctl stop portal-postgres.service || true
rm -f /etc/containers/systemd/portal-postgres.container
systemctl daemon-reload
```

No borrar `data/postgres` ni el contenedor `portal-postgres` hasta validar el
portal y confirmar que ya no se necesita un rollback. Esta guía no destruye
datos históricos.

## Actualización directa por WinSCP

Después de copiar el código al release activo:

```bash
podman exec portal-app php artisan optimize:clear
systemctl restart portal-app portal-worker portal-scheduler portal-nginx
curl -fsS http://127.0.0.1:8080/health/live
```

Si cambian archivos Quadlet, ejecutar también:

```bash
bash /index2/portal-cgnat/current/deploy/scripts/install-quadlets.sh
```

Si cambian DDL de `deploy/clickhouse/portal_cgnat.sql`, revisar el cambio y
ejecutarlo con `ch_query --multiquery`; no hay migraciones Laravel.

## Diagnóstico

```bash
podman ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
journalctl -u portal-app -u portal-nginx -n 150 --no-pager
podman logs --tail 150 portal-app
podman exec portal-app php artisan about
curl -fsS http://127.0.0.1:8080/health/live
```

Comprobar ClickHouse desde el host:

```bash
curl --max-time 3 -fsS http://10.96.167.135:8123/ping
```

Problemas frecuentes:

| Síntoma | Corrección |
|---|---|
| `getaddrinfo for portal-redis failed` | Confirmar `DNS=10.89.0.1`, alias `--network-alias=portal-redis`, recargar Quadlet y reiniciar. |
| CAS vuelve pero muestra 403 | El ticket AD es válido, pero falta la fila activa en `portal_cgnat.users` o el rol/permisos. |
| CAS no conecta | Revisar URL/ruta, DNS y certificado; mantener `CAS_VERIFY_TLS=true` y corregir la cadena CA, no desactivar verificación permanentemente. |
| CAS muestra `dh key too small` | El CAS usa DH débil para OpenSSL 3. Temporalmente configurar `OPENSSL_CONF=/var/www/html/deploy/openssl/cas-legacy.cnf`; solicitar al equipo CAS ECDHE o DH de al menos 2048 bits y retirar esa variable. |
| Consulta sin resultados | Verificar rango horario, nodo seleccionado, tabla diaria y los filtros. |
| Error `String, IPv4` | Transferir la versión que normaliza IP con `toString()` antes de hacer `UNION ALL`. |

## Rutas persistentes

```text
/index2/portal-cgnat/
├── current -> releases/VERSION
├── releases/                       código de releases
├── config/portal.env               secretos, no Git
├── data/redis/                     estado Redis
├── shared/storage/                 logs y archivos Laravel
├── shared/bootstrap-cache/         cachés Laravel
├── containers/storage/             imágenes y capas Podman
├── exports/                        exportaciones futuras
├── backups/                        copias operativas
└── deploy/                         releases e imágenes transferidas
```
