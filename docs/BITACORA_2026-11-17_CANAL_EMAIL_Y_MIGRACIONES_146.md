# Bitácora 2026-11-17 — Canal de correo deshabilitado e instalación limpia de migraciones (#146)

**Rama:** `fix/issue-146-canal-email-y-migraciones` (desde `origin/main` = `1ecbfc57`)
**Alcance:** staging `fasa_189` y repositorio. **Producción no se modificó.** Durante el
intento de recuperación de la sección 6 se **leyó** un dump de producción (FTPS de sólo
lectura, 10/10/2026); no se escribió nada en producción.
**Incidente:** durante la prueba de SMTP se vaciaron tres tablas de staging y se perdieron
el log histórico de entregas y las claves VAPID y de WhatsApp de staging (sección 6).
**Documentos relacionados:** `docs/AUDITORIA_2026-11-17_ISSUES_473_Y_146.md`

---

## 1. Contexto

La auditoría del 17/11/2026 (`docs/AUDITORIA_2026-11-17_ISSUES_473_Y_146.md`) cerró el
issue #473 como `LISTO_PARA_CERRAR` y dejó el #146 como `REQUIERE_CORRECCION` por dos
defectos concretos, ambos demostrados contra el entorno real:

- **Defecto A — la instalación limpia de migraciones se corta.** La migración
  `2026-11-06-100000_AddPrincipalFlagToLocalities` marcaba las localidades
  principales de Brasil con `provincia_id IN (…)`. En una instalación desde cero
  todavía no hay provincias cargadas, la lista llega vacía y la consulta queda
  como `WHERE provincia_id IN ()`, que es **SQL inválido en MySQL/MariaDB**. La
  instalación se interrumpe en esa migración.
- **Defecto B — un canal apagado se cuenta como falla.** Con `smtp_enabled = 0` el
  despachador seguía encolando entregas por correo, el gateway lanzaba una
  excepción genérica y el ciclo diario `notificaciones:ciclo` terminaba en
  `FALLIDA` todos los días, con 241 entregas de usuario y 70 de empresa en
  estado `FALLIDA`.

## 2. Defecto A — instalación limpia

### 2.1 Reproducción en MariaDB real

Esquema temporal descartable `mantenimiento_mig146` en el MariaDB de staging
(`we7ppyik29cgwvfpf1plkw1y`), creado con `GRANT ALL` para el usuario de la app.
**La base de la app (`mantenimiento_staging`) no se tocó en ningún momento.**

Control previo a migrar (requisito del protocolo, por el incidente del 06/11): un
comando propio dentro del contenedor de prueba imprime el esquema que CI4 resuelve
y el que MySQL reporta como base en uso.

```
CONFIG_DATABASE=
ACTIVE_DATABASE=mantenimiento_mig146
CONTROL_BASE=OK (mantenimiento_mig146)
```

Con el **código original** (imagen de staging `dc4d3bd`):

```
[CodeIgniter\Database\Exceptions\DatabaseException]
You have an error in your SQL syntax; ... near ')
AND `codigo_fuente` IN ('1100205','1200401',...
  at APPPATH/Database/Migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php:116
  at APPPATH/Database/Migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php:32
       App\Database\Migrations\AddPrincipalFlagToLocalities()->markBrazil([])
```

Estado tras el fallo: **126 migraciones aplicadas de 131, 112 tablas**, es decir un
esquema incompleto. La instalación limpia estaba rota.

> **Advertencia encontrada de paso:** `php spark migrate` imprimió el error de SQL y
> **devolvió código de salida 0**. Un pipeline que se guíe por el exit code daría
> por buena una instalación cortada. El control real tiene que ser la cantidad de
> migraciones aplicadas, no el código de salida.

### 2.2 Corrección

- **`app/Infrastructure/Geography/PrincipalLocalityMarker.php`** (nuevo): la regla de
  marcado queda en **un solo lugar**. `applyFromScratch()` rehace la marca desde cero
  y `markPrincipal()` marca sin resetear, con guard de lista vacía en el marcado de
  Brasil.
- **`app/Database/Migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php`**:
  su `up()` delega en el marker. La corrección está **documentada de forma explícita**
  en el docblock de la clase (no es silenciosa): CI4 no hashea las migraciones, así
  que el cambio no altera el resultado donde la migración ya se aplicó — allí la
  lista de provincias estaba poblada y la marca se calculó igual — y lo que cambia
  es que, con la lista vacía, no se ejecuta la consulta que abortaba la instalación.
- **`app/Database/Migrations/2026-11-17-100000_ReapplyPrincipalLocalityFlags.php`**
  (nuevo): migración correctiva **aditiva e idempotente**. Garantiza la columna y
  re-marca **sin resetear**, para no borrar lo que marcaron
  `PrincipalLocalityPolicy` o el importador. Su `down()` no es destructivo y está
  documentado como tal.

**Medición del delta real antes de decidir** (sólo lectura, en staging): la regla
agregaría **0 filas**, y 24 filas marcadas de otro origen (entradas de provincia
`ARGENTINA`) se conservan. La migración correctiva es, en el estado actual, un
no-op seguro.

### 2.3 Resultado con la corrección

| Corrida | Resultado |
|---|---|
| Instalación limpia, código original | **FALLA** en `2026-11-06-100000` · 126/131 migraciones · 112 tablas |
| Instalación limpia, código corregido | **"Migrations complete"** · 131/131 migraciones · 114 tablas |
| `localidades.principal` | `tinyint(1) NOT NULL DEFAULT 0` |
| Reversibilidad (`migrate:rollback --all` **sobre el esquema temporal**) | **OK**, "Done rolling back migrations", exit 0 · 0 migraciones restantes · 1 tabla (la propia `migrations`) |
| Base de la app al terminar las pruebas de migración (**antes** del incidente de la sección 6) | **intacta**: 133 migraciones, 420 entregas, `localidades` presente. Las entregas se perdieron después, en la sección 6. |

El esquema temporal se eliminó al terminar. Sólo quedó `mantenimiento_staging`.

> Sobre las 3 filas de migración que tiene staging y la instalación limpia no
> (`2026-08-06-110008`, `2026-08-14-110050`, `2026-11-04-130000`): **sus archivos no
> existen en el repositorio** (130 archivos de migración en la imagen). Son filas
> huérfanas de migraciones que se borraron del repo después de aplicadas. No son un
> efecto de este trabajo.

## 3. Defecto B — el canal de correo deshabilitado es una decisión, no una falla

### 3.1 Evidencia del problema (sólo lectura, staging)

| Canal | Estado | Cantidad |
|---|---|---|
| EMAIL | `ENVIADA` | 135 |
| EMAIL | **`FALLIDA`** | **241** |
| EMAIL | `REINTENTO` | 3 |
| PUSH | `OMITIDA` | 41 |

Las 241 entregas `FALLIDA` se descomponen por motivo:

| Motivo registrado | Cantidad | Naturaleza |
|---|---|---|
| "el canal de correo está deshabilitado" | **220** | **decisión del administrador**, no un SMTP roto |
| "revisá la configuración SMTP" | 14 | falla real de configuración |
| "falló la negociación TLS/SSL" | 7 | falla real de transporte |

Periodo: 10/10/2026 → 12/11/2026. El canal está apagado en staging desde el 24/10.

En empresas: 131 `ENVIADA`, 70 `FALLIDA`, 113 `OMITIDA`, 8 `REINTENTO`.

### 3.2 Corrección

- **`app/Domain/Notifications/NotificationChannel.php`** (nuevo): enum de canales
  `EMAIL` / `PUSH` / `WHATSAPP`.
- **`app/Domain/Notifications/NotificationChannelDisabledException.php`** (nuevo): el
  canal apagado se distingue de una falla de envío.
- **`app/Application/Notifications/Port/NotificationChannelAvailability.php`** (nuevo):
  puerto de disponibilidad efectiva.
- **`app/Infrastructure/Notifications/CodeIgniterGlobalNotificationChannelAvailability.php`**
  (nuevo): adapter que lee la configuración global.
- **Cola** (`CodeIgniterNotificationDeliveryQueue`): con el canal apagado **no se
  encola ninguna entrega por correo**; la entrega de empresa queda `OMITIDA` con el
  motivo explícito; y la no-encolada de usuario queda **registrada en el log**.
- **Gateway** (`CodeIgniterEmailNotificationGateway`): lanza
  `NotificationChannelDisabledException` en vez de una excepción genérica.
- **Despachador** (`RunNotificationDispatch`): captura esa excepción y **no cuenta
  fallas ni reintentos**; difiere la entrega y suma un contador explícito
  `email_channel_disabled`.
- **Puerto** `NotificationDeliveryQueue`: nuevo `deferred()`, que **conserva el estado
  y los intentos** y sólo escribe el motivo.

**Separación deliberada:** la disponibilidad del canal responde **sólo a la decisión
del administrador** (SMTP encendido o apagado). Un remitente inválido **no** apaga el
canal: es una configuración equivocada y tiene que verse como **falla real** en el
despacho, con su reintento y su traza. Si la disponibilidad exigiera un remitente
válido, un correo mal configurado dejaría de encolarse sin dejar rastro y el
operador no vería ningún error.

### 3.3 Semántica de recuperación

- **Entregas de usuario** encoladas antes de que el canal se apagara: quedan en
  espera (`PENDIENTE`, con el motivo escrito) y **se envían una sola vez** cuando el
  canal vuelve. Sin pérdida, sin duplicados.
- **Entregas de empresa** creadas con el canal ya apagado: quedan `OMITIDA` con el
  motivo. Un informe gerencial es una foto del estado de ese momento: no se encola
  para mandar más tarde, porque un informe viejo muestra métricas que ya no aplican.
- **Correo con el canal apagado** que llega al despacho (por ejemplo, encolado antes
  del apagado): `deferred()`, no falla.

### 3.4 Qué se hace con las 241 entregas `FALLIDA` históricas

> **Actualización:** estas filas **ya no existen**. Se perdieron con el vaciado de
> `notificacion_entregas` y `notificacion_empresa_entregas` (sección 6), junto con las
> 3 en `REINTENTO`. La reclasificación ya no es posible. Lo que sigue queda como el
> criterio que se había adoptado, y aplica si el caso se repite en producción.

**No se tocan.** Son el registro fiel de que el despachador intentó enviar y el canal
estaba apagado. Su estado `FALLIDA` es correcto como registro histórico y, además,
el despachador nunca vuelve a tomar entregas `FALLIDA` (sólo `PENDIENTE` y
`REINTENTO`).

Reenviar digests de octubre y noviembre no corresponde: el destinatario recibiría
alertas de un estado que ya cambió. La reclasificación de esas 241 entregas a
`PENDIENTE` queda como **tarea explícita del operador**, con su procedimiento y su
auditoría, y **no** se ejecuta en este trabajo.

Lo que sí cambia a futuro: con la corrección, cuando el canal se rehabilite, las
entregas que estaban en espera se envían solas.

## 4. Pruebas

### 4.1 Automatizadas

| Prueba | Resultado |
|---|---|
| PHPUnit — suite completa | **1850 tests / 7282 assertions, 48 skipped, 0 fallos** |
| PHPUnit — `NotificationChannelAvailabilityTest` | 9 tests / 29 assertions (canal, recuperación, SMTP real) |
| PHPUnit — migraciones (`AddPrincipalFlagToLocalities`, `ReapplyPrincipalLocalityFlags`) | 9 tests / 31 assertions OK |
| PHPUnit — `AddPrincipalFlagToLocalitiesMariaDbTest` | se omite fuera de MariaDB (patrón del repo) |
| Lint PHP de todos los archivos tocados | OK |
| Vitest — frontend de notificaciones | 9 tests OK |
| `npm run verify:build-sync` | OK (bundles versionados = source) |

La prueba del canal cubre: no encolar EMAIL con el canal apagado, PUSH intacto,
`PENDIENTE` cuando el canal está disponible, `OMITIDA` de empresa con motivo,
`deferred()` conserva estado e intentos, el rechazo del gateway no es falla, el
envío único al volver el canal, un error SMTP temporal sigue contando falla y
reintento, y el remitente inválido con canal habilitado se encola (para que el
error se vea).

### 4.2 Contra MariaDB real (staging `fasa_189`)

- Instalación limpia en esquema temporal aislado, con control previo de la base
  activa: **OK** con la corrección, **reproducción del defecto** sin ella.
- Reversibilidad con `migrate:rollback --all` **sólo sobre el esquema temporal**:
  **OK**.
- `migrate:rollback` **nunca** se ejecutó sobre la base de la app.

## 5. Despliegue en staging y smoke end-to-end

**Rama desplegada:** `fix/issue-146-canal-email-y-migraciones` (SHA `a693a551`).
Proyecto de Coolify `mantenimiento` (id 3) = **1 sola aplicación**, entorno
`staging`. **Producción tiene 0 recursos en ese proyecto**, como exige
`DEPLOY_COOLIFY.md`. Procedimiento: PAT temporal de Sanctum creado desde dentro
del contenedor `coolify` (el token nunca salió del host), `PATCH` de
`git_branch`/`git_commit_sha`, `POST .../start` con `force` e `instant_deploy`, y
**revocación del PAT al terminar**. Rollback documentado en
`/tmp/fix146-rollback.sh`: reapuntar a `main` en `dc4d3bd28fbf0a484aa8dca21270dec54c112a90`
y redisparar el deploy.

### 5.1 Códigos de control

| Control | Resultado |
|---|---|
| `GET /login` | 200 |
| `GET /.env` · `/app` · `/vendor` · `/writable` | **403 en los cuatro** |
| `GET /cron/notificaciones/probe` (ruta legacy, token falso) | 403 |
| `GET /notificaciones/resumen` sin sesión | 302 |
| `POST /login/authenticate` (usuario demo) | 303 |
| `GET /notificaciones/resumen` | 200 · `unread: 18` |
| `GET /notificaciones` (centro) | 200 |
| `POST /notificaciones/leer/{id}` | 303 · contador **18 → 17** |
| `POST /notificaciones/leer-todas` | 303 · contador → **0** |
| `POST /logout` | 303 · la campana vuelve a pedir sesión (302) |

**Smoke HTTP: 17 OK / 0 FALLA.**

### 5.2 Ciclo de notificaciones con el canal apagado

| Control | Antes (código viejo) | Después (esta rama) |
|---|---|---|
| Etapa `notificaciones:ciclo:despacho` | **FALLIDA** (todos los días) | **COMPLETADA** |
| Ciclo `notificaciones:ciclo` | `FALLIDA` | **`FINALIZADA`** (`stages: 7, completed: 6, failed: 0`) |
| `failed` / `retry` del despacho | **11 / 11** (17/11 07:00) | **0 / 0** |
| Contador nuevo `email_channel_disabled` | — | **11** (8 de empresa + 3 de usuario) |

Las entregas en espera conservan estado e intentos y llevan el motivo escrito:

```
id  canal  estado    intentos  proximo_intento   motivo
780 EMAIL  REINTENTO  2        2026-11-17 07:02  El canal EMAIL está deshabilitado: lo apagó el administrador del sistema.
```

La 780 ya estaba en `REINTENTO` cuando se apagó el canal: `deferred()` conserva ese
estado y los intentos. Las entregas encoladas sin intentos previos quedan en
`PENDIENTE` (sección 3.3).

### 5.3 Idempotencia y concurrencia

- **Idempotencia:** la segunda corrida con la misma clave de ejecución devuelve
  `already_completed: 1`, sin etapas y sin tocar las entregas. Sin trabajo repetido.
- **Concurrencia:** dos despachos con claves distintas en paralelo → **uno
  completa** y **el otro es rechazado por el bloqueo** (excepción). El proceso no
  se duplica.

### 5.4 EMAIL end-to-end con SMTP de prueba (Mailpit)

Con un servidor SMTP **de prueba** (Mailpit en la red `coolify`, nunca
credenciales de producción):

- `email_sent: 3`, `email_channel_disabled: 0`, `failed: 0`.
- Mailpit recibió **3 mensajes** (`Resumen de mantenimiento - 17/11/2026`): las
  3 entregas que estaban en espera se enviaron **una sola vez** al volver el
  canal. El mecanismo de recuperación queda verificado de punta a punta.
- La entrega de correo que había quedado registrada como falla por SMTP real (error de
  configuración o TLS) sigue contando como falla y reintento: el cambio no
  tapa los errores de verdad.

### 5.5 Web Push end-to-end: **NO VERIFICADO**

Staging tiene 2 suscripciones, pero 41 entregas `PUSH` quedaron en `OMITIDA`
("No existen dispositivos activos") y **no hay un dispositivo de prueba** contra
el que verificar una entrega real. Una entrega `OMITIDA` no cuenta como Web Push
exitoso, así que este control queda **pendiente de un dispositivo real**.

### 5.6 Multiempresa y alcance

- El usuario demo pertenece a la empresa 7; las 403 notificaciones siguen
  perteneciendo a su propia empresa: **0 notificaciones** con `empresa_id` distinto
  al de su usuario.
- El centro y la campana exponen únicamente las notificaciones del usuario de la
  sesión.

### 5.7 Gate de logs y estado del programado

| Chequeo | Resultado |
|---|---|
| Tarea programada "Notificaciones diarias" | `php spark notifications:dispatch`, `0 10 * * *`, **habilitada**, `consecutive_failures = 0` |
| Ejecuciones de la tarea | **success** los días 13, 14, 15, 16 y 17/11 (10:00 UTC) |
| Base efectiva del contenedor | `database_default_database=mantenimiento_staging` |
| `.env` de la app | sin definición de base: la conexión viene del env del contenedor |
| `GET /login` · `/.env` | 200 · 403 |

**Sobre las entradas CRITICAL/ERROR del log de hoy** (4 CRITICAL, 7 ERROR = 11 entradas).
**Estado del gate: PARCIAL.** La tabla atribuye **8 de las 11**; las otras 3 quedan
**sin atribuir** y hay que revisarlas antes de dar el gate por cerrado.

| Hora (local) | Entrada | Origen |
|---|---|---|
| 07:55 (×2) | `Access denied ... to database 'mantenimiento_audit_mig_tmp'` | Esquema temporal de un incidente anterior, ya borrado. **No se reproduce**: no hay tarea, proceso ni variable que lo apunte, y la tarea diaria corre bien contra `mantenimiento_staging`. |
| 07:57 y 08:00 | error de sintaxis SQL cerca de `)` | **Reproducción del Defecto A** en el contenedor de prueba, con el código original. |
| 09:10 y 09:29 (×2 cada uno) | `Duplicate entry 'notificaciones:ciclo'` + `NotificationDispatchInProgress` | **Prueba de concurrencia**: el segundo despacho paralelo es rechazado por el bloqueo. Es el comportamiento esperado. |

Observaciones:

- **07:55 sin explicar.** El acceso a `mantenimiento_audit_mig_tmp` ocurrió **antes** de
  que empezara la reproducción de este trabajo (07:57), así que lo originó otro proceso o
  sesión. Que no se repita no identifica la causa. Pista: a las 07:55:50 otra sesión en
  paralelo generó `dist/ferozo-backup-lecturas-20261117-075550.sql` (ver
  `docs/AUDITORIA_2026-11-17_ISSUES_473_Y_146.md`).
- **Las entradas de las 09:29 pueden ser del build desplegado.** El deploy final es
  `bdqictyu4q5xkfuh6aoh7sr8-20261117T121937`; si esa marca es UTC, corresponde a las
  09:19 local, antes de las entradas de las 09:29. A confirmar.
- **Nivel de log del bloqueo.** El rechazo por bloqueo es comportamiento esperado pero
  se registra como CRITICAL/ERROR. En producción, cualquier solapamiento de despachos
  dispararía alarmas falsas. Conviene bajarlo a WARNING (pendiente 9).

## 6. Incidente durante la prueba: se vaciaron tres tablas de staging

**Qué pasó.** Al terminar la prueba de SMTP con Mailpit, el script de
restauración borró las filas y **no las repuso**: el respaldo se generaba con
`mysqldump`, que **no existe en el contenedor de MariaDB** (sólo el cliente
`mariadb`). Las tres tablas afectadas quedaron vacías:

| Tabla | Filas antes | Filas después | Estado |
|---|---|---|---|
| `configuracion_canales_globales` | 1 | 0 | **Restaurada** |
| `notificacion_entregas` | 420 | 0 | **Perdida (log histórico)** |
| `notificacion_empresa_entregas` | 322 | 0 | **Perdida (log histórico)** |

`notificaciones` (403 filas) **no se tocó**. **Producción no se modificó**; sí se leyó
el dump de producción del 10/10/2026 para intentar la recuperación (ver abajo).

**Qué se hizo para recuperar.** Se buscó una fuente de restauración antes de
inventar datos:

- Backups programados de Coolify: son de **otro proyecto** (`femag_web`), no
  sirven.
- Binlog de MariaDB: **`log_bin = OFF`**, no hay recuperación por binlog.
- Volúmenes y archivos del host: no hay copia de los datos de staging.
- Dump de producción (FTPS, 10/10/2026): contiene la fila de configuración de
  **producción**, pero sus valores cifrados **no se descifran con la clave de
  staging** (verificado: `SMTP_PASS_DESCIFRABLE=no`,
  `VAPID_PRIV_DESCIFRABLE=no`, `WHATSAPP_KEY_DESCIFRABLE=no`).

**Configuración: restaurada.** Se reconstruyó la fila con el **propio store de la
aplicación** (para que los secretos queden cifrados con la clave del entorno),
tomando el SMTP del env del contenedor —el mismo origen del seed original— y
dejando el canal **apagado**, como estaba desde el 24/10:

```
smtp_enabled=0  smtp_host=(host del buzón de producción)  smtp_port=587  smtp_crypto=tls
smtp_user=(buzón de producción)  smtp_pass=(cifrado)  smtp_from_name=Mantenimiento
webpush_enabled=0  whatsapp_enabled=0  whatsapp_pilot_enabled=1  whatsapp_pilot_phone=(teléfono piloto)
```

El teléfono piloto y `whatsapp_pilot_enabled=1` están documentados en la
bitácora del 24/10/2026. El canal de correo queda **apagado**: el entorno de
staging tiene las credenciales del buzón de producción y prender el canal
haría salir correo real.

**Lo que no se pudo recuperar.** El par de claves **VAPID** y la **clave de la
API de WhatsApp** de staging existían sólo cifrados en esa fila, con una clave
que no se puede recuperar. Consecuencias, ambas acotadas:

- **Web Push** queda apagado en staging (ya lo estaba en su env y en
  `DEPLOY_COOLIFY.md`). Las 2 suscripciones existentes quedaron inservibles,
  pero **no había ninguna entrega real de Web Push que verificar** de todos modos
  (41 entregas `OMITIDA` por falta de dispositivos activos).
- **WhatsApp** queda apagado, con el modo piloto y su teléfono, que es lo que
  estaba antes desde el 23/10.

**Lo que se perdió y no se inventó.** El **log histórico de entregas** (420
filas de usuario y 322 de empresa): las 241 `FALLIDA`, las 135 `ENVIADA`, las 41
`PUSH` `OMITIDA` y las 3 `REINTENTO`. **No se reconstruyeron con datos
inventados**: el estado de cada fila (si se envió, si falló, con qué error y en
qué fecha) no es derivable de forma fiable, y reconstruirlas a mano sería
falsear la historia. Los números y el desglose por motivo quedan registrados en
la auditoría y en esta bitácora; las tablas **se repueblan solas** con las
entregas nuevas. La pérdida es de trazabilidad histórica en un entorno de
prueba, **no de datos operativos ni de producción**.

**Cómo se evita que se repita.** Todo script que toque datos de staging tiene que:

1. Respaldar **con una herramienta que exista en el contenedor** (`mariadb` con
   `--batch`, o `SELECT ... INTO OUTFILE`), no con `mysqldump`.
2. Verificar que el respaldo **no está vacío** antes de borrar nada.
3. Restaurar y **contar filas antes y después**, en el mismo script.

## 7. Pendientes

1. **Web Push E2E: NO VERIFICADO.** No hay dispositivo de prueba real, y ahora además
   faltan las claves VAPID (pendiente 2). Una entrega `OMITIDA` no cuenta como éxito.
2. **Claves VAPID y clave de la API de WhatsApp de staging: perdidas** (sección 6). Web
   Push y WhatsApp quedan apagados en staging. Para rehabilitar Web Push hay que generar
   un par VAPID nuevo y volver a suscribir los dispositivos (las 2 suscripciones actuales
   quedaron inservibles). Para WhatsApp hay que volver a cargar la clave de la API.
3. **Log histórico de entregas de staging: perdido** (sección 6): 420 filas de usuario y
   322 de empresa, incluidas las 241 + 70 `FALLIDA`. No se reconstruyó con datos
   inventados; los números quedan en la auditoría y en esta bitácora. **Ya no hay
   entregas `FALLIDA` históricas que reclasificar.**
4. **Gate de logs: PARCIAL** (sección 5.7). Faltan atribuir 3 de las 11 entradas,
   identificar el origen del acceso de las 07:55 y confirmar la hora del deploy final.
5. **Staging conserva las credenciales SMTP de producción** en el env del contenedor,
   con el canal apagado en la base. Hay que sacarlas o reemplazarlas en Coolify y evaluar
   rotar la contraseña del buzón.
6. **`php spark migrate` devuelve exit 0 aunque una migración falle** (sección 2.1). Antes
   de desplegar en producción, el procedimiento tiene que verificar la cantidad de
   migraciones aplicadas, no el código de salida.
7. **Producción no se verificó con estos cambios**: no se desplegó nada en Ferozo. Antes
   hay que decidir si la migración correctiva `2026-11-17-100000` se aplica en producción
   y con qué ventana. La prueba `AddPrincipalFlagToLocalitiesMariaDbTest` se omite en CI:
   la corrección del Defecto A sólo está verificada a mano contra MariaDB de staging.
8. **Script de rollback** en `/tmp/fix146-rollback.sh` (efímero): reapuntar la app de
   staging a `main` en `dc4d3bd28fbf0a484aa8dca21270dec54c112a90` y redisparar el deploy.
9. **Nivel de log del rechazo por bloqueo** (`NotificationDispatchInProgress`): hoy
   CRITICAL/ERROR; conviene WARNING para no generar alarmas falsas.
10. **El PR (#534) queda sin mergear** hasta que el usuario lo autorice; el #146 y el #7
    quedan **abiertos**.
