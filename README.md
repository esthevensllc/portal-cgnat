# Portal CGNAT

Portal interno Laravel para consultar sesiones IPv4 PAT en los nodos
ClickHouse configurados (ampliación prevista de cuatro a siete). Usa el diseño visual de los portales internos existentes y no
recarga la página al ejecutar una consulta.

## Arquitectura

```text
Navegador
    │ HTTPS/HTTP interno :8080
Nginx (Podman) ──> PHP-FPM / Laravel (Podman)
                           │        │
                           │        └── Redis: sesión, caché y colas
                           │
                           ├── LDAP corporativo: autentica identidad AD
                           ├── ClickHouse cgnat: consultas CGNAT
                           └── ClickHouse portal_cgnat: usuarios, roles,
                               permisos y auditoría
```

No se usa PostgreSQL. Redis no es la base de datos funcional: solo mantiene
estado efímero de Laravel. Toda autorización persistente del portal está en
ClickHouse `portal_cgnat`.

## Flujo de identidad y autorización

1. El usuario abre `/portalcgnat/login`.
2. Laravel valida las credenciales directamente contra LDAP/Active Directory.
3. Si `LDAP_ALLOWED_GROUP` está configurado, comprueba la pertenencia al grupo.
4. Laravel consulta ClickHouse: el usuario debe estar activo y tener un rol
   con el permiso `cgnat.query`.
5. La identidad se guarda en la sesión Redis. Cada solicitud protegida vuelve
   a comprobar su permiso; retirar un rol corta el acceso inmediatamente.

El portal admite únicamente dos roles: `cgnat_basico` para los usuarios finales
y `cgnat_administrador` para la administración completa, incluida la auditoría.
La autenticación LDAP por sí sola no otorga acceso: cada usuario debe ser
aprovisionado en ClickHouse.

## Ampliar de cuatro a siete colectores (runbook)

La ampliación es de configuración: `config/clickhouse.php` construye los nodos
desde `CLICKHOUSE_NODES`. No hay un máximo de cuatro en el selector, la
validación, el conteo, la combinación/paginación de resultados ni la exportación.
El fallback del código sigue siendo cuatro para conservar instalaciones que no
declaren esa variable; ambos archivos de ejemplo declaran siete y mantienen
`CLICKHOUSE_ENABLED=false` hasta completar los datos.

Los tres nuevos colectores quedan asignados así:

| Nodo | Servidor / etiqueta | URL |
|---|---|---|
| `ch05` | `TRJELOGF01` | `http://10.96.167.180:8123` |
| `ch06` | `TRJELOGF02` | `http://10.96.167.181:8123` |
| `ch07` | `LORELOGF01` | `http://10.96.167.196:8123` |

Se confirmó que comparten la conexión, esquema y tablas de los cuatro actuales:
HTTP por 8123, `DATABASE=cgnat`,
`TABLE_PATTERN=huawei_cgn_nat_v2_%s` (fecha `YYYY_MM_DD`) y `VERIFY_TLS=false`.
Los ejemplos usan `USERNAME=portal_cgnat`; en el servidor, copiar el usuario y
contraseña efectivos de uno de los cuatro colectores actuales a CH05/CH06/CH07,
conservando su formato y comillas. Las contraseñas de los ejemplos permanecen
vacías; no versionar secretos. Comprobar acceso `SELECT` con esa cuenta en los
tres nuevos antes de habilitar la lista de siete. Un nodo sin
URL o inaccesible provoca error; no se omite silenciosamente para presentar un
resultado incompleto. Si un colector no tiene las tablas diarias del rango,
también se rechaza la consulta. Confirmar retención y fechas disponibles en los
siete; no se deduplican registros repetidos entre colectores.

`cgnat_basico` consulta y exporta en todos los nodos configurados, incluso si
envía manualmente un subconjunto. `cgnat_administrador`, con `cgnat.nodes`, ve
los siete y puede elegir un subconjunto. Las plantillas administrativas que ya
guardaban cuatro nodos conservan esa selección: revisarlas si deben incluir los
nuevos. No hacen falta cambios de roles ni DDL por añadir colectores.
`PORTAL_STORE_*` es independiente: conservar su servidor, usuarios y auditoría;
no copiar `portal_cgnat` a los nuevos colectores para esta ampliación.

App, worker y scheduler leen el mismo `EnvironmentFile` de Quadlet. No requiere
reconstruir imágenes, reinstalar Quadlets ni reiniciar/borrar Redis. Los jobs
ya encolados conservan su lista original de nodos. Antes de aplicar o revertir,
esperar a que terminen las exportaciones y suspender nuevas solicitudes durante
la ventana de mantenimiento. El límite global de filas se reparte siguiendo
el orden de los nodos. Siete
colectores pueden aumentar duración, disco y carga. El job y el worker Quadlet
tienen timeout de 3600 s para toda la tarea, mientras
`CLICKHOUSE_EXPORT_TIMEOUT` se aplica por solicitud HTTP. Validar una exportación
representativa antes de asumir capacidad productiva. Configurar
`REDIS_QUEUE_RETRY_AFTER=3660` (superior a 3600 s) evita que Redis vuelva a ofrecer
un job todavía en ejecución; el fallback actual de la cola es 90 s.

Las exportaciones descargan hasta `CLICKHOUSE_EXPORT_CONCURRENCY` colectores en
paralelo (predeterminado 3, máximo 7); cada colector procesa sus tablas diarias
en secuencia. Cada nodo escribe primero en su propio temporal y el worker los
combina en el CSV final por orden de nodo, con una sola cabecera. El progreso se
actualiza al completar cada tabla. Durante cada grupo se requiere espacio libre
para el CSV final y los temporales de hasta tres nodos; los temporales se borran
al combinarse o si la tarea falla. Reducir la variable a 1 vuelve al
comportamiento serial. El paralelismo puede aumentar la carga de lectura en
ClickHouse; empezar con 3 y ajustar solo después de medir en la prueba autorizada.

La siguiente es la **única secuencia operativa de esta ampliación** para una
instalación Quadlet existente. Ejecutarla en el entorno autorizado como root,
con las credenciales actuales disponibles. No ejecutar los pasos de instalación
desde cero para este cambio. No copiar el ejemplo completo sobre `portal.env`:
conservar los cuatro nodos, `APP_KEY`, LDAP y `PORTAL_STORE_*` existentes.

```bash
set -Eeuo pipefail
root=/index2/portal-cgnat
umask 077
install -d -m 0700 "$root/backups"
backup="$root/backups/portal.env-before-seven-$(date +%Y%m%d-%H%M%S)"
cp -p "$root/config/portal.env" "$backup"
printf 'Respaldo para reversión: %s\n' "$backup"

# Revisar las tareas en el portal; continuar solo con la cola drenada.
podman exec portal-worker php artisan queue:monitor redis:default --max=0
# Editar solo CLICKHOUSE_NODES, añadir CH05/CH06/CH07 desde
# deploy/portal.env.example, copiar USERNAME/PASSWORD efectivos de un colector
# actual a los tres nuevos y fijar REDIS_QUEUE_RETRY_AFTER=3660.
# Lista final: ch01,ch02,ch03,ch04,ch05,ch06,ch07 (sin duplicados).
vi "$root/config/portal.env"
chmod 0600 "$root/config/portal.env"

aplicar_configuracion() {
  systemctl stop portal-worker portal-scheduler
  podman exec portal-app php artisan config:clear
  systemctl restart portal-app
  systemctl start portal-worker portal-scheduler
  systemctl restart portal-nginx
}
aplicar_configuracion

# Comprobar la configuración efectiva de los tres procesos sin imprimir secretos.
for container in portal-app portal-worker portal-scheduler; do
  printf '%s: ' "$container"
  podman exec -i "$container" php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$nodes = config('clickhouse.nodes', []);
$expected = ['ch01', 'ch02', 'ch03', 'ch04', 'ch05', 'ch06', 'ch07'];
if (!config('clickhouse.enabled') || array_keys($nodes) !== $expected
    || config('queue.default') !== 'redis'
    || config('queue.connections.redis.retry_after') <= 3600) {
    fwrite(STDERR, "Configuración no válida; revisar lista, habilitación y cola.\n");
    exit(1);
}
foreach ($nodes as $name => $node) {
    if (blank($node['url']) || blank($node['username']) || blank($node['password'])) {
        fwrite(STDERR, "$name: faltan datos de conexión.\n");
        exit(1);
    }
}
echo implode(',', array_keys($nodes))." OK\n";
PHP
done

# Usar un día confirmado con tablas en los siete colectores.
read -r -p 'Fecha de prueba (YYYY_MM_DD): ' fecha
podman exec -i -e CGNAT_CHECK_DATE="$fecha" portal-app php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$date = getenv('CGNAT_CHECK_DATE');
if (!preg_match('/^\d{4}_\d{2}_\d{2}$/', $date)) exit(1);
$failed = false;
foreach (config('clickhouse.nodes', []) as $name => $node) {
    try {
        $db = $node['database'];
        $table = sprintf($node['table_pattern'], $date);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $db)
            || !preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException();
        // Comprueba autenticación, permiso SELECT y columnas sin leer registros.
        $sql = "SELECT start_time, end_time, "
            ."router_ip, router_port, private_ip, private_port, public_ip, "
            ."public_port, destination_ip, destination_port, packet_size "
            ."FROM `$db`.`$table` LIMIT 0 FORMAT JSONEachRow";
        $response = Illuminate\Support\Facades\Http::withBasicAuth($node['username'], $node['password'])
            ->connectTimeout(3)->timeout(15)->withOptions(['verify' => $node['verify_tls']])
            ->withBody($sql, 'text/plain')->post(rtrim($node['url'], '/').'/');
        if (!$response->successful()) throw new RuntimeException();
        echo "$name: SELECT OK\n";
    } catch (Throwable $error) {
        $failed = true;
        echo "$name: FALLÓ; revisar red, TLS, cuenta y tabla del día.\n";
    }
}
exit($failed ? 1 : 0);
PHP
systemctl status portal-redis portal-app portal-worker portal-scheduler portal-nginx --no-pager
curl -fsS http://127.0.0.1:8080/health/live
journalctl -u portal-app -u portal-worker -u portal-scheduler -n 100 --no-pager

# Validación funcional: iniciar sesión con básico y administrador; consultar un
# rango conocido en los siete, elegir CH05-CH07 como admin, generar y descargar
# un ZIP y revisar auditoría/estado de la tarea. /health/live solo prueba HTTP.

# Solo si las comprobaciones fallan: en esta misma sesión, drenar de nuevo las
# tareas y revertir la configuración con la copia protegida (no borrar Redis).
# cp -p "$backup" "$root/config/portal.env"
# aplicar_configuracion
```

Si un error cerró la sesión por `set -e`, recuperar la ruta del respaldo
mostrada, definir de nuevo `root`, `backup` y la función `aplicar_configuracion`
del mismo bloque, y ejecutar las dos líneas de reversión. No volver a crear el
respaldo sobre la configuración fallida.

La prueba automatizada específica es `tests/Feature/SevenCollectorTest.php`;
ejecutar `php artisan test --filter=SevenCollectorTest` con PHP 8.5 y dependencias
de desarrollo. Usa HTTP simulado y comprueba los permisos de selección,
conteo/mezcla de siete nodos, rechazo de nodos desconocidos o fallidos y la
exportación paralela/serialización de los siete. No acredita conectividad ni rendimiento
de servidores reales.

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

Copiar `.env.example` a `.env`, completar ClickHouse y LDAP, y ejecutar:

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
- El servidor del portal puede resolver y llegar al LDAP corporativo por TCP
  `389` o por el puerto acordado con infraestructura.
- La red permite a los usuarios llegar al puerto TCP `8080` del portal (o al
  puerto que se publicará detrás del proxy productivo).
- Existe una cuenta ClickHouse de runtime con permisos para todas las fuentes
  `cgnat.*` y para el nodo que almacenará `portal_cgnat`.

Comandos de verificación, sin exponer contraseñas:

```bash
cat /etc/redhat-release
podman info --format 'GRAPHROOT={{.Store.GraphRoot}} DRIVER={{.Store.GraphDriverName}}'
xfs_info /index2 | grep 'ftype=1'
df -hT /index2

# Repetir para cada IP ClickHouse configurada.
curl --max-time 3 -fsS http://IP_CLICKHOUSE:8123/ping

# Ajustar host y puerto reales de LDAP.
getent hosts HOST_LDAP
nc -vz -w 3 HOST_LDAP 389
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
La versión actual también carga explícitamente las clases nuevas de LDAP para
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
REDIS_QUEUE_RETRY_AFTER=3660

CLICKHOUSE_ENABLED=true
CLICKHOUSE_NODES=ch01,ch02,ch03,ch04,ch05,ch06,ch07
CLICKHOUSE_CH01_LABEL=CH-01
CLICKHOUSE_CH01_URL=http://IP_CH01:8123
CLICKHOUSE_CH01_USERNAME=portal_cgnat
CLICKHOUSE_CH01_PASSWORD=SECRETO_RUNTIME
CLICKHOUSE_CH01_DATABASE=cgnat
CLICKHOUSE_CH01_TABLE_PATTERN=huawei_cgn_nat_v2_%s
CLICKHOUSE_CH01_VERIFY_TLS=false

# Repetir estos parámetros para CH02, CH03 y CH05 a CH07 con sus datos reales.
# Los bloques completos de siete nodos están en deploy/portal.env.example.
CLICKHOUSE_CH04_LABEL=CH-04
CLICKHOUSE_CH04_URL=http://IP_CH04:8123
CLICKHOUSE_CH04_USERNAME=portal_cgnat
CLICKHOUSE_CH04_PASSWORD=SECRETO_RUNTIME
CLICKHOUSE_CH04_DATABASE=cgnat
CLICKHOUSE_CH04_TABLE_PATTERN=huawei_cgn_nat_v2_%s
CLICKHOUSE_CH04_VERIFY_TLS=false

CLICKHOUSE_EXPORT_TIMEOUT=3600
CLICKHOUSE_EXPORT_THRESHOLD=100000

PORTAL_STORE_URL=http://IP_CLICKHOUSE_PERSISTENCIA:8123
PORTAL_STORE_USERNAME=portal_cgnat
PORTAL_STORE_PASSWORD=SECRETO_RUNTIME
PORTAL_STORE_DATABASE=portal_cgnat
PORTAL_STORE_VERIFY_TLS=false
PORTAL_STORE_CONNECT_TIMEOUT=3
PORTAL_STORE_QUERY_TIMEOUT=10

PORTAL_AUTH_ENABLED=true
LDAP_HOST=IP_O_DNS_LDAP
LDAP_PORT=389
LDAP_BASE_DN="DC=empresa,DC=local"
LDAP_DOMAIN=TIM
# LDAP_ALLOWED_GROUP="CN=Grupo autorizado,OU=Grupos,DC=empresa,DC=local"
LDAP_TIMEOUT=10
LDAP_LOGIN_MAX_ATTEMPTS=5
LDAP_LOGIN_DECAY_SECONDS=300
```

`APP_DEBUG=false` es obligatorio fuera del entorno de desarrollo. Los errores
no recuperables HTTP 500 y el modo mantenimiento HTTP 503 muestran una página
neutra de mantenimiento, sin traza, rutas internas ni detalles de Laravel.
Después de modificar `portal.env`, limpiar la configuración almacenada en caché.

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

Ejecutar este DDL en el ClickHouse indicado por `PORTAL_STORE_URL`. Verificar
antes de arrancar el portal:

```bash
clickhouse-client --host IP_PORTAL_STORE --port 9000 --user ADMIN \
  --password --query 'EXISTS DATABASE portal_cgnat'
```

Dar acceso a un usuario de AD (reemplazar valores, minúsculas):

```sql
INSERT INTO portal_cgnat.users VALUES
('usuario.ad', 'Nombre visible', 'usuario@empresa.pe', 1, 'AD', now64(3), now64(3));

INSERT INTO portal_cgnat.user_roles VALUES
('usuario.ad', 'cgnat_basico', 1, now64(3), 'admin.portal');
```

Para retirar acceso sin borrar historial, insertar una nueva versión:

```sql
INSERT INTO portal_cgnat.user_roles VALUES
('usuario.ad', 'cgnat_basico', 0, now64(3), 'admin.portal');
```

Para asignar todos los privilegios, incluida la auditoría, usar el rol
`cgnat_administrador`:

```sql
INSERT INTO portal_cgnat.user_roles VALUES
('usuario.ad', 'cgnat_administrador', 1, now64(3), 'admin.portal');
```

No crear ni asignar `cgnat_auditor`, `cgnat_consultor` u otros roles. La
autorización del portal solo acepta roles activos con código
`cgnat_administrador` o `cgnat_basico`.

Las tablas de roles usan `ReplacingMergeTree(version)`. No ejecutar `UPDATE`;
cada cambio es un nuevo `INSERT` versionado. El portal determina el último
estado con `argMax`, por lo que no depende de que ocurra una compactación.

## Auditoría del portal

Antes de publicar esta versión en una instalación existente, aplicar la ampliación
del esquema (el `CREATE TABLE IF NOT EXISTS` original no altera tablas ya creadas):

```bash
ch_query --multiquery < deploy/clickhouse/upgrade_session_audit.sql
```

El inicio de sesión único usa el bloqueo distribuido del `CACHE_STORE=redis` y
`portal_cgnat.user_sessions` en ClickHouse. El segundo navegador muestra un
diálogo de Bootstrap para cancelar o cerrar la sesión anterior. El navegador
anterior pierde acceso en su siguiente solicitud. La inactividad se calcula con
`SESSION_LIFETIME`; un cierre inesperado aparece desconectado al vencer ese plazo.
Todas las instancias del portal deben usar el mismo Redis y ClickHouse.

Para obtener el estado actual de todos los usuarios, ejecutar:

```bash
ch_query < /index2/portal-cgnat/current/deploy/clickhouse/reporte_usuarios_estado.sql
```

Las doce columnas de auditoría también están disponibles mediante
`deploy/clickhouse/reporte_auditoria.sql`. `Source Hostname` se informa cuando el
servidor recibe `REMOTE_HOST`; de lo contrario se muestra vacío porque el
navegador no comunica de forma fiable el nombre de su estación.

La auditoría registra los accesos LDAP, denegaciones por rol, consultas CGNAT,
exportaciones y plantillas. Nunca almacena contraseñas, cookies, tokens ni la
lista completa de grupos LDAP. `query_audit` contiene los filtros técnicos de
las consultas y `audit_events` conserva los demás eventos durante 365 días.

El DDL concede `cgnat.audit.view` únicamente a `cgnat_administrador`; por tanto,
ese rol incluye los privilegios que correspondían al auditor. Para habilitarlo
en una instalación existente, ejecutar una sola vez el DDL completo y comprobar
las dos tablas:

```bash
ch_query --multiquery < /index2/portal-cgnat/current/deploy/clickhouse/portal_cgnat.sql

ch_query "EXISTS TABLE portal_cgnat.query_audit"
ch_query "EXISTS TABLE portal_cgnat.audit_events"
ch_query "SELECT role_code, permission, argMax(is_active, version) AS active FROM portal_cgnat.role_permissions WHERE permission = 'cgnat.audit.view' GROUP BY role_code, permission"
```

Después de iniciar sesión con un administrador, el menú `Auditoría` permite
filtrar los últimos 500 eventos por fecha, usuario, tipo, resultado, IP o ID de
solicitud. Las modificaciones ejecutadas directamente con DBeaver no atraviesan
Laravel; para investigarlas se debe consultar también `system.query_log` con
una cuenta administrativa de ClickHouse.

## Arranque con Quadlet

Orden final de instalación desde cero:

1. Preparar `/index2` y el `graphroot` de Podman.
2. Transferir y validar los artefactos; cargar imágenes e instalar el release.
3. Crear `config/portal.env` con `APP_KEY`, los nodos confirmados, la cuenta
   restringida ClickHouse y la conexión LDAP.
4. Crear `portal_cgnat` en `PORTAL_STORE_URL` y aprovisionar al menos
   un usuario AD con `cgnat_basico`.
5. Instalar Quadlets, abrir TCP `8080` según la política de red y arrancar.
6. Validar salud, red ClickHouse y autenticación LDAP con ese usuario AD.

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

### Una exportación no avanza o no genera el CSV

El progreso se actualiza al terminar cada nodo ClickHouse, no por cada fila.
Mientras se descarga el primer nodo puede permanecer en `0 %`. Revisar en este
orden, sin volver a enviar la misma exportación:

```bash
systemctl status portal-worker portal-redis --no-pager
journalctl -u portal-worker -n 200 --no-pager
podman logs --tail 200 portal-worker

podman exec portal-worker php artisan queue:monitor redis:default --max=1000000

find /index2/portal-cgnat/exports -maxdepth 3 -type f -printf '%TY-%Tm-%Td %TH:%TM:%TS %10s %p\n' | sort | tail -30
df -h /index2
df -i /index2
```

Un archivo terminado en `.part` que aumenta de tamaño indica que ClickHouse
todavía está transmitiendo ese nodo. Ejecutar dos veces, con algunos segundos
de diferencia, para comparar:

```bash
find /index2/portal-cgnat/exports -type f -name '*.part' -printf '%10s %p\n'
```

Consultar el estado y el error persistido de las últimas tareas. Reemplazar
`USUARIO_LDAP` y ejecutar contra el ClickHouse configurado en
`PORTAL_STORE_URL`:

```bash
ch_query "SELECT id, argMax(state, version) AS state, argMax(total_rows, version) AS total_rows, argMax(processed_rows, version) AS processed_rows, argMax(progress, version) AS progress, argMax(filename, version) AS filename, argMax(error, version) AS error, max(updated_at) AS updated_at FROM portal_cgnat.export_tasks WHERE lower(username) = lower('USUARIO_LDAP') GROUP BY id ORDER BY updated_at DESC LIMIT 10 FORMAT Vertical"
```

Interpretación rápida:

- `queued`: el worker no tomó la tarea; revisar Redis y `portal-worker`.
- `running` en `0 %` con un `.part` creciente: el primer nodo sigue exportando.
- `running` sin crecimiento: revisar `system.processes` y conectividad al nodo.
- `failed`: la causa está en `error` y en el journal de `portal-worker`.
- `completed` sin descarga: comprobar que `filename` exista físicamente y que
  los volúmenes de `portal-app` y `portal-worker` apunten al mismo directorio.

Para comprobar una consulta de exportación activa en cada servidor ClickHouse:

```sql
SELECT query_id, elapsed, read_rows, read_bytes, memory_usage, query
FROM system.processes
WHERE query ILIKE '%FORMAT CSV%'
FORMAT Vertical;
```

Comprobar ClickHouse desde el host:

```bash
curl --max-time 3 -fsS http://10.96.167.135:8123/ping
```

Problemas frecuentes:

| Síntoma | Corrección |
|---|---|
| `getaddrinfo for portal-redis failed` | Confirmar `DNS=10.89.0.1`, alias `--network-alias=portal-redis`, recargar Quadlet y reiniciar. |
| LDAP acepta la cuenta pero el portal deniega el acceso | Falta la fila activa en `portal_cgnat.users`, el rol o el permiso `cgnat.query`. |
| LDAP no conecta | Revisar `LDAP_HOST`, `LDAP_PORT`, DNS, firewall y que la extensión LDAP esté instalada en el contenedor. |
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
