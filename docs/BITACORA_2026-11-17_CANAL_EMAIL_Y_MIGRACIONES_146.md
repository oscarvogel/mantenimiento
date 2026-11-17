# Bitácora 2026-11-17 — Canal de correo deshabilitado e instalación limpia de migraciones (#146)

**Rama:** `fix/issue-146-canal-email-y-migraciones` (desde `origin/main` = `1ecbfc57`)
**Alcance:** staging `fasa_189` y repositorio. **Producción no se tocó.**
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
| Base de la app después de todo | **intacta**: 133 migraciones, 420 entregas, `localidades` presente |

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

**No se tocan.** Son el registro fiel de que el despachador intentó enviar y el canal
estaba apagado. Su estado `FALLIDA` es correcto como registro histórico y, además,
el despachador nunca vuelve a tomar entregas `FALLIDA` (sólo `PENDIENTE` y
`REINTENTO`).

Reenviar digests de octubre y noviembre no corresponde: el destinatario recibiría
alertas de un estado que ya cambió. La reclasificación de esas 241 entregas a
`PENDIENTE` queda como **tarea explícita del operador**, con su procedimiento y su
auditoría, y **no** se ejecuta en este trabajo.

Lo que sí cambia a futuro: con la corrección, cuando el canal se rehabilite, las
entregas que estaban en espera se envían solas. De las 3 en `REINTENTO` que quedan
en staging, el mecanismo las cubre.

## 4. Pruebas

### 4.1 Automatizadas

| Prueba | Resultado |
|---|---|
| PHPUnit — suite completa | **1849 tests / 7280 assertions, 48 skipped, 0 fallos** |
| PHPUnit — `NotificationChannelAvailabilityTest` | 9 tests / 31 assertions (canal, recuperación, SMTP real) |
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
  activa: **OK** con la corrección, **reprodución del defecto** sin ella.
- Reversibilidad con `migrate:rollback --all` **sólo sobre el esquema temporal**:
  **OK**.
- `migrate:rollback` **nunca** se ejecutó sobre la base de la app.

## 5. Pendientes

1. **Despliegue de la rama en staging** y smoke E2E (campana, centro, contador,
   marcar leída, idempotencia, estados, permisos, multiempresa, EMAIL con SMTP de
   prueba y Web Push). En la sección de despliegue de este documento.
2. **Web Push E2E**: staging tiene 2 suscripciones pero 41 entregas `PUSH` en
   `OMITIDA` ("No existen dispositivos activos"). Sin un dispositivo de prueba
   registrations real no hay entrega verificable.
3. **EMAIL E2E**: el canal está apagado en staging desde el 24/10 y el contenedor
   conserva credenciales SMTP de producción. **No** se habilita con esas
   credenciales: para la prueba hay que apuntar a un SMTP de prueba (Mailpit) o
   dejar el bloqueo documentado.
4. **246 entregas `FALLIDA` históricas** (241 de usuario, 70 de empresa en
   staging): sin tocar. Reclasificación a criterio del operador.
5. **Staging conserva las credenciales SMTP de producción** montadas en el env del
   contenedor, con el canal apagado en la base. Se resuelve en Coolify, no por
   código.
6. El PR queda **sin mergear** hasta que el usuario lo autorice; el #146 y el #7
   quedan **abiertos**.
