# Bitácora — Aviso automático de equipos sin lectura para la gerencia

**Fecha:** 23/10/2026
**Objetivo:** los equipos con lectura vencida (9, 10 y 11 días en la captura de referencia) se reclaman a los choferes y no suben. Escalonar el aviso hacia la dueña/gerencia, automáticamente, por WhatsApp y por correo.
**Origen:** captura del control de lecturas (`/mantenimiento/lecturas/control`) con cuatro camiones sin lectura y la pregunta "¿podemos enviar automáticamente un informe a la dueña? por whatsapp? por mail?".

---

## 1. Diagnóstico: qué existía y qué faltaba

Relevimiento del código antes de tocar nada (no se asumió nada):

| Pieza | Estado | Por qué no resolvía el caso |
|---|---|---|
| Reclamo al chofer | Ya existía: recordatorio automático los lunes/miércoles/viernes (`scheduleWeeklyReadingReminders`) + botón manual "Reclamar por WhatsApp" | Ya se usaba y no sube. El escalamiento moría en el chofer. |
| Informe gerencial por **correo** | Ya existía (`ScheduleManagementReports`), con la línea "Equipos sin lectura reciente" y detalle por equipo | El corte era de **30 días** (`alerts.lecturasVencidasDias`): los camiones de 9-11 días **no aparecían**. Además nace deshabilitado por empresa. |
| Aviso a gerencia por **WhatsApp** | **No existía** | Solo había digest diario a usuarios con permiso `notificaciones.ver` (lista notificaciones internas, no equipos) y el aviso de teléfonos de chofer faltantes al rol "Responsable de mantenimiento". |

**El hueco concreto:** nadie le avisa a la gerencia que hay equipos sin lectura antes de los 30 días.

## 2. Decisiones

| Decisión | Valor | Motivo |
|---|---|---|
| Canal | WhatsApp **+** correo | El correo ya existía (configuración, sin código) pero con umbral de 30 días; el WhatsApp no existía y es lo que la dueña mira. |
| Destinatario del WhatsApp | Teléfono de gerencia **por empresa** (campo nuevo) | En paralelo al `emails_informes` que ya existe, configurable en la misma pantalla. No exige que la dueña tenga usuario en el sistema. |
| Umbral | **7 días** | Es el criterio que ya usa el tablero (`STALE_READING_DIAS = 7` en `GetMaintenanceDashboard` y `CodeIgniterGlobalDashboardReadModel`) y coincide con el filtro `gt_7` de la pantalla de la captura. |
| Frecuencia | 1 aviso por empresa por día | Si el problema sigue, se ve todos los días; si se resuelve, el aviso desaparece solo. |
| Silencio | Si no hay equipos atrasados, no se manda nada | Un aviso vacío entrena a la gerencia a ignorar el canal. |
| Router | Enlace al control de lecturas con filtro de antigüedad | La dueña cae en la misma pantalla de la captura y puede reclamar desde ahí con un toque. |

## 3. Bug encontrado y corregido

**El conteo de "equipos sin lectura" del informe por correo contaba equipos que no controlan kilometraje.**

```sql
-- antes: todos los equipos activos de la empresa
FROM equipos e LEFT JOIN (...) l ON l.equipo_id = e.id
WHERE e.empresa_id = ? AND e.estado = 'ACTIVO' AND e.deleted_at IS NULL

-- ahora: solo los que controlan km
FROM equipos e
INNER JOIN tipos_equipo te ON te.id = e.tipo_equipo_id AND te.activo = 1 AND te.controla_km = 1
```

Con el corte de 30 días era ruido menor. Al bajar el umbral a 7 días, el informe se llenaba de falsos positivos: semirremolques y equipos sin km listados como "sin lectura" para siempre. El defecto era invisible mientras nadie miraba el número.

Se corrigió **en los dos canales** (correo y WhatsApp) y se cubrió con pruebas contra un motor SQL real.

## 4. Qué se implementó

| Archivo | Rol |
|---|---|
| `app/Database/Migrations/2026-10-23-140000_AddManagementReadingReportSettings.php` | Columnas `telefono_gerencia`, `informe_lecturas_whatsapp_habilitado`, `informe_lecturas_hora`, `informe_lecturas_dias` en `empresas` (con `down()`). |
| `app/Application/Notifications/Port/ReadingComplianceReadModel.php` | Puerto del universo de equipos con lectura vencida. |
| `app/Application/Notifications/Port/ManagementReadingReportSettingsReadModel.php` | Puerto de la configuración por empresa. |
| `app/Infrastructure/Notifications/CodeIgniterReadingComplianceReadModel.php` | Adaptador SQL (misma regla de última lectura determinista que `ListReadingControl`). |
| `app/Infrastructure/Notifications/CodeIgniterManagementReadingReportSettingsReadModel.php` | Lee `empresas`; si la migración no corrió, no hay nada que informar. |
| `app/Application/Notifications/ReadingComplianceReportMessageBuilder.php` | Texto del aviso (ES/PT, singular/plural, "nunca registró lectura", encabezado de piloto). |
| `app/Application/Notifications/ScheduleManagementReadingReport.php` | Caso de uso: decide, arma el mensaje y encola. |
| `app/Application/Notifications/Port/WhatsAppNotificationDeliveryQueue.php` | Nuevo método `enqueueManagementReadingReport()`. |
| `app/Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php` | Encolado con clave de entrega idempotente. |
| `app/Application/Notifications/ScheduleManagementReports.php` | Fix del `controla_km` y umbral por empresa. |
| `app/Application/Notifications/RunNotificationCycle.php` | Nueva etapa "informe de lecturas a gerencia", antes del despacho. |
| `app/Config/Services.php` | Servicio `managementReadingReport` + cableado en el ciclo. |
| `app/Controllers/SuperAdmin.php`, `app/Config/Routes.php` | Validación del teléfono, persistencia y **Probar aviso de lecturas**. |
| `app/Presentation/AdministrationPayload.php`, `app/Infrastructure/Organization/CodeIgniterOrganizationAdministration.php` | Payload y lectura de la configuración para la pantalla. |
| `frontend/src/pages/admin/SuperAdminPage.vue` | Bloque de configuración, badge de estado, botón de prueba y resumen en la ficha. |

## 5. Pruebas

| Archivo | Qué cubre |
|---|---|
| `tests/unit/Application/Notifications/ManagementReadingReportTest.php` | 13 casos: se encola / no se encola sin atraso / no se encula deshabilitado / teléfono inválido / idempotencia diaria / vuelve al día siguiente / modo piloto / ventana horaria / umbral por empresa / aislamiento entre empresas. |
| `tests/unit/Application/Notifications/ReadingComplianceReportMessageBuilderTest.php` | 6 casos del texto: impacto, plural, "nunca registró", resumen de excedentes, português, encabezado de piloto. |
| `tests/unit/Infrastructure/Notifications/CodeIgniterReadingComplianceReadModelTest.php` | 6 casos contra SQLite: excluye semirremolques, orden "nunca registró" primero, km de la misma fila que la fecha, chofer vigente, otra empresa, tipo/sucursal inactivos. |
| `tests/unit/Infrastructure/Notifications/ManagementReadingReportCountRegressionTest.php` | 4 casos de regresión del conteo del correo contra SQLite (el bug de la sección 3). |
| `tests/unit/Infrastructure/Notifications/ManagementReadingReportContractTest.php` | 6 casos de contrato: migración reversible, cableado en el ciclo, clave de entrega, prueba manual desde Superadmin, validación del teléfono, mismo umbral en los dos canales. |

Dobles nuevos en `tests/_support/Notifications/`: `FakeWhatsAppDeliveryQueue`, `FakeGlobalNotificationSettingsStore`, `FakeManagementReadingReportSettings`, `FakeReadingComplianceReadModel`.

### Resultado de la suite

```
Tests: 1223, Assertions: 4386, Failures: 7, Skipped: 22
```

Los **7 fallos son preexistentes y ajenos a este cambio**. Se comprobó guardando
**solo los 13 archivos de este trabajo** (`git stash push -- <archivos>`) y
volviendo a correr esos 7 tests: fallan exactamente igual sin mis cambios.

| Test | Causa | Relación |
|---|---|---|
| `ManageTelemetryEquipmentLinksTest` | El código dice "cada unidad **del proveedor**" y el test espera "de Wialon" | Trabajo pendiente de la rama (integración Autotrac) |
| `HomeRedirectTest`, `LoginRedirectTest` | `/inicio` vs `/mantenimiento/inicio` | Artefacto del `.env` local (ver `ERRORES_CONOCIDOS.md`) |
| `QrTechnicalFailureReporterTest`, `PreventivePlansPayloadTest` | Prefijo `/mantenimiento` duplicado | Artefacto del `.env` local, documentado en `BITACORA_2026-10-02.md` |
| `ExpirationDuplicateValidationContractTest`, `ExpirationRenewalFlowContractTest` | Contrato de componentes Vue de vencimientos | Trabajo pendiente de la rama |

Frontend: `npm run build` OK (incluye el control de encoding) y `npm test` con
**371 tests en verde, 4 fallos preexistentes** en `AppHeader.test.js` y
`TheoreticalCostPage.test.js` (componentes que este trabajo no toca).

## 5.b Un problema que se encontró verificando contra el servidor

Al armar el enlace del aviso aparece un problema heredado: el repo tiene **dos
convenciones de URL conviviendo** (173 call sites con `base_url('mantenimiento/...')` contra
25 aserciones de path sin prefijo) y la decisión está pendiente en
`ERRORES_CONOCIDOS.md`.

Se resolvió leyendo la verificación **contra el servidor real**, no el código
(`docs/BITACORA_2026-10-08_DEPLOY_PR469_ALERTA_DISCREPANCIA.md`):

| ruta | resultado en Ferozo |
|---|---|
| `/mantenimiento/mantenimiento/lecturas/1/evidencia` | **302 → login** (la ruta existe) |
| `/mantenimiento/lecturas/1/evidencia` | 404 |

Como `app.baseURL` en Ferozo **incluye** `/mantenimiento/` (obligatorio por
`DEPLOY_FEROZO.md` §15), el enlace correcto es
`base_url('mantenimiento/lecturas/control')`, que es el criterio que sigue el
resto del código. El aviso usa ese mismo criterio, y por eso el enlace no
arranca en 404.

## 6. Staging: migración aplicada y lo que se encontró en el servidor

**Migración aplicada y verificada** en `fasa_189` (stack `bdqictyu4q5xkfuh6aoh7sr8`,
base `mantenimiento_staging`, `CI_ENVIRONMENT=development`, NO producción):

```
Running: (App) 2026-10-23-140000_App\Database\Migrations\AddManagementReadingReportSettings
Migrations complete.
```

En la tabla `migrations` quedó registrada (id 293, batch 46) y las cuatro columnas
existen en `empresas`:

```
telefono_gerencia                     varchar(20)     YES  NULL
informe_lecturas_whatsapp_habilitado  tinyint(1) uns  YES  0
informe_lecturas_hora                 char(5)         YES  08:00
informe_lecturas_dias                 tinyint(1) uns  YES  7
```

### El problema de fondo: en staging el cron de notificaciones está muerto

Los logs del contenedor muestran, todos los días a las 07:00:

```
CRITICAL - Allowed memory size of 134217728 bytes exhausted
[Method: CLI, Route: notifications:dispatch]
in APPPATH/Infrastructure/Telematic/WialonRemoteApiGateway.php on line 339
```

El cron revienta por memoria **antes** de la etapa de informes, así que:

- no se generan informes gerenciales nuevos (la última entrega es del **15/10**);
- los correos quedaron en estado `REINTENTO` con *"No se pudo enviar el correo.
  Revisá la configuración SMTP"*;
- el aviso nuevo de WhatsApp tampoco se despacharía, porque comparte esa etapa.

**El arreglo ya está en el código local** (5 commits en HEAD, ninguno desplegado
en staging, que corre el build del 21/10):

- `62efa792` evitar que el ciclo muera por límite de ejecución
- `9da74fc2` despachar antes de la telemetría
- `8b001757` acotar la telemetría para no agotar el cron
- `8c882897` acotar ventanas de un cursor atrasado
- `814b75c4` usar la última lectura por equipo en el control de lecturas

### El SMTP no es el problema (verificado a nivel de protocolo)

Desde el contenedor, contra `a0110632.ferozo.com:587`:

```
1. BANNER: 220-a011.dattaweb.com ESMTP Server
2. EHLO:   250-... 250-AUTH LOGIN | 250-STARTTLS
3. STARTTLS: 220 TLS go ahead
4. TLS: NEGOCIADO OK
8. Password: 235 Authentication succeeded
RESULTADO: AUTENTICACION OK
```

Las credenciales y el servidor funcionan **hoy**. El fallo del 15/10 fue
consecuencia del cron muerto por memoria.

### La dueño y el teléfono

En `usuarios` de TSA (empresa 4) hay tres personas:

| id | Nombre | Email | Teléfono |
|---|---|---|---|
| 7 | **Mariela Pawluk** | mariela@tsa.com.ar | **NULL** |
| 10 | carlos.tsa | gimenezcarlosgabriel@gmail.com | 5493755274301 |
| 19 | Kunrath Camila | ventas@webtsa.com.ar | 5493743400547 |

Mariela Pawluk es la que crea todos los datos (`created_by = 7`) y **ya recibe el
informe por correo**: `emails_informes` de TSA es
`oscarvogel@gmail.com, pawlukma@gmail.com, ventas@webtsa.com.ar`.
No tiene teléfono cargado: hay que cargarlo en `telefono_gerencia`.

### Prueba end-to-end contra la base real

Se ejecutó la consulta del adaptador y el constructor de mensaje contra
`mantenimiento_staging` (sin escribir nada). Resultado: **27 equipos** de TSA con
control de km, **26 atrasados** y **1 que nunca registró lectura** (`GZD587`).
A 7 días el mensaje real es:

```
*TSA* · Mantenimiento

Hola 👋

🚛 *27 equipos sin lectura hace más de 7 días*

Estos equipos no están reportando kilómetros, así que no se puede calcular ni
controlar su mantenimiento:

• *GZD587* — nunca registró lectura
• *AD738EA* — hace 36 días
   Última lectura: 861.523 km · 17/09/2026 08:22
• *OJG526* — hace 25 días
   Última lectura: 1.216.168 km · 28/09/2026 10:35
• *IWE2I14* — hace 18 días
   Última lectura: 1.235.507 km · 05/10/2026 08:40
   Chofer: RICARDO FERREYRA
...
_… y 17 equipo(s) más._

👉 *VER LOS EQUIPOS SIN LECTURA:*
https://…/mantenimiento/lecturas/control?filter=gt_7

Cuando el equipo suba su lectura, este aviso deja de aparecer.

_Mantenimiento desarrollado por Vogel Consultoría_
```

Dato honesto sobre el fix del conteo: **en TSA hoy no cambia el número**, porque
los 27 equipos activos tienen todos `controla_km = 1` (no hay semirremolques en
esa empresa). El fix protege el caso en que entren equipos sin control de km, que
es el motivo de los documentos de semirremolques y Who's Who del repo.

## 7. Pendientes (requiere acción del usuario / entorno)

1. **Producción (Ferozo)**: aplicar la migración `2026-10-23-140000` también allá.
   Ahí no hay CLI ni SSH; la vía es el panel de migraciones de Superadmin. Sin la
   migración, la columna `telefono_gerencia` no existe y el Superadmin no puede
   cargar el teléfono.
2. **Cargar el teléfono en producción**: `5493755632536` en Superadmin > Empresas >
   "Aviso de equipos sin lectura por WhatsApp", con el aviso habilitado y umbral
   7 días. En **staging ya quedó cargado y verificado** (sección 8).
3. **Revisar el modo piloto** de WhatsApp: el aviso sale al teléfono piloto
   mientras el piloto global esté activo. En staging el piloto global está
   **desactivado** (`whatsapp_pilot_enabled = 0`), por eso salió al teléfono real.
4. **Umbral por empresa**: 7 días es el valor por defecto; se edita por empresa
   si el caso real pide otro.
5. **Avisarle a la dueña antes del primer correo largo**: con el umbral nuevo el
   correo lista los 27 equipos de TSA (con detalle de los 10 más antiguos).
6. **Memoria del cron**: ver la sección 9. La telemetría ya no corre antes del
   despacho, pero hay que confirmar que la etapa de telemetría no vuelve a
   agotar los 128 MB del PHP CLI.


## 8. Staging: deploy y cadena de causas (qué estaba realmente roto)

Todo lo de esta sección pasó el 23/10/2026 en `fasa_189`, stack
`bdqictyu4q5xkfuh6aoh7sr8` (`mantenimiento-staging`), base `mantenimiento_staging`.
**No es producción.**

### 8.1 El deploy: la API de Coolify exige un PAT que nadie tenía guardado

Las 4 filas de `personal_access_tokens` de Coolify guardan **el hash SHA-256** del
token (así los hashea Sanctum), así que el valor plano no se puede recuperar. La
API de Coolify 4.4.7 expone el deploy en:

```
POST /api/v1/applications/{uuid}/start     -> ApplicationsController@action_deploy
```

con middleware `api.ability:deploy` (Sanctum). Se creó un **PAT temporal** desde
dentro del contenedor de Coolify, usando el propio código de Sanctum para no
cifrar a mano:

```php
$plainTextToken = sprintf('%s%s%s', config('sanctum.token_prefix', ''), $entropy, hash('crc32b', $entropy));
$token = $user->tokens()->create([
    'name' => 'mavis-deploy-temporal-...', 'token' => hash('sha256', $plainTextToken),
    'abilities' => ['*'], 'expires_at' => date('Y-m-d H:i:s', time() + 7200), 'team_id' => '0',
]);
// el valor plano se deja en /tmp/pat.txt dentro del contenedor, nunca en el repo
```

Se usó con `curl` **desde adentro** del contenedor de Coolify contra
`http://127.0.0.1:8080` (el puerto 8000 no escucha: el nginx interno escucha en
8080). El PAT se borró al terminar. **No quedó ningún token vivo.**

### 8.2 El primer deploy falló por un artefacto del merge (corregido)

El build de la imagen abortó en `RUN npm run build`:

```
[vite:vue] [vue/compiler-sfc] Identifier 'exportUrl' has already been declared. (41:6)
/app/frontend/src/pages/admin/TelemetrySamplesAdminPage.vue
```

Causa: el merge de la rama de informes concatenó los **dos** bloques
`<script setup>` del archivo (el de la rama, con `vista=samples` fijo, y el de
`main`, con la vista dinámica). El resultado declaraba `exportUrl` dos veces en
el mismo ámbito.

Corrección (`204395ce`, en `main`): se separó en dos computeds con nombre propio,
`samplesExportUrl` para el enlace "Exportar todas las muestras (CSV)" y
`exportUrl` para el botón "Exportar CSV" de la tabla. Build local después:
`822 modules transformed`, `✓ built in 7.53s`, `ENCODING_QA=OK`.

> Lección: un merge de ramas con `.vue` editados en los dos lados hay que
> compilarlo, no sólo pasar la suite de PHP. La suite no ve el frontend.

### 8.3 La causa de fondo: el orden de las etapas del ciclo

La imagen que estaba desplegada (build del 21/10) corría las etapas en este orden:

```
RunNotificationCycle.php (desplegado)   RunNotificationCycle.php (main, nuevo)
 43  detectOverdue                         43  detectOverdue
 50  TELEMETRÍA  <-- moría acá             52  collect
 69  collect                               58  missingDriverPhones
 81  managementReports  <-- nunca llegaba  64  managementReports
 87  dispatch                              70  managementReadingReport  (nuevo)
                                            76  dispatch
                                            86  TELEMETRÍA (al final, a propósito)
```

El `json_decode` de Wialon (`WialonRemoteApiGateway.php:339`) agotaba los 128 MB
del PHP CLI, y como la telemetría corría **antes**, el proceso moría antes de
generar informes y despachar. Por eso no había informes desde el 15/10.

En `main` la telemetría ya corre al final (commit `9da74fc2`), con los fixes
`62efa792` (evitar que el ciclo muera por límite de ejecución), `8b001757`
(acotar la telemetría para no agotar el cron) y `8c882897` (acotar ventanas de un
cursor atrasado). **Los cinco commits de memoria están en `main`** (verificado
con `git merge-base --is-ancestor`).

### 8.4 El segundo bloqueo: los secretos cifrados con la clave de otra máquina

Con el código nuevo el ciclo llegó a los informes, pero:

- los correos seguían sin salir, y
- la etapa nueva reportaba `companies: 0`.

En el diálogo SMTP **no aparecía el paso `auth:`**, y el relay respondía
`550-5.1.1 The email account that you tried to reach does not exist`. La causa
real: `CodeIgniterGlobalNotificationSettingsStore::decrypt()` devuelve `''` en
silencio cuando el descifrado falla, y con `smtp_pass` vacío el cliente no se
autentica → el relay rechaza el destinatario.

La causa del descifrado: la `encryption.key` del contenedor **no es la misma con
la que se cifraron los secretos** de `configuracion_canales_globales` (los cifró
la instalación de producción). Se comprobó con el algoritmo de CI4 replicado a
mano (HMAC-SHA512 sobre `iv + ciphertext` con `hash_hkdf`):

```
SMTP:     bytes=90  hmac_ok=NO      (64 hmac + 16 iv + 10 de clave)
WhatsApp: bytes=129 hmac_ok=NO      (64 hmac + 16 iv + 49 de clave)
```

Efecto colateral: la API key de WhatsApp también quedaba en `''`, y
`VogelWhatsAppApiGateway::available()` exige que no esté vacía → por eso la
etapa nueva no veía ninguna empresa.

**Reparación aplicada en staging** (equivalente a lo que hace el Superadmin al
guardar la configuración: re-cifrar con la clave de esta instalación):

- SMTP: se tomó la contraseña del `.env` del contenedor (`email.SMTPPass`, que
  además autentica bien contra el relay) y se re-cifró con la clave vigente.
- WhatsApp: se obtuvo la credencial del cliente **`bot_fg`** de la API de
  WhatsApp, que es el único cliente amarrado a la instancia `oscar_claro_2`
  (`api_client_instances`), y se re-cifró con la clave vigente.

La API key se descifró con el algoritmo del propio servicio
(`v1.iv.tag.cipher`, AES-256-GCM con `sha256(ADMIN_SESSION_SECRET)`) y **se
verificó con un GET** a `/api/v1/instances/oscar_claro_2/messages/{id}`: la
respuesta fue `404 Mensaje inexistente` (o sea, la clave es válida y el mensaje
no existe) en lugar de `401 API Key inválida`.

> El comando `telematria:integracion` ya documenta esta misma trampa: *"un token
> cifrado con la clave de otra máquina no descifra acá, y el síntoma es una
> 'integración caída' que nadie entiende"*. El mismo razonamiento aplica a los
> secretos de notificaciones.

### 8.5 Resultado end-to-end verificado

Ciclo ejecutado dentro del contenedor desplegado
(`php spark notifications:dispatch`), después de la reparación:

```json
{"management_reports":{"companies":3,"queued":0,"duplicates":2,"skipped":4,"failed":0},
 "management_reading_report":{"companies":3,"queued":1,"duplicates":0,"skipped":2,"failed":0},
 "dispatched":{"email_sent":7,"company_email_sent":6,"whatsapp_sent":3,"failed":0,"retry":0}}
```

- **WhatsApp**: `notificacion_whatsapp_entregas` id **5959**, tipo
  `informe.lecturas.gerencia`, teléfono `5493755632536`, instancia
  `oscar_claro_2`, `gateway_message_id` `78cb3193-…`. En la API de WhatsApp el
  mensaje figura **`status: accepted`**, `providerMessageId`
  `3EB01FD5E902E25213607C`, `to: 5493755632536`, `lastError` vacío.
- **Correo**: las 4 entregas de `informe.gerencial.diario` del 23/10 pasaron de
  `REINTENTO` a **`ENVIADA`** con `enviada_en` entre 17:32:42 y 17:33:11,
  incluidos `pawlukma@gmail.com` y `ventas@webtsa.com.ar`.
- El mensaje que salió a la dueña es el esperado: *27 equipos sin lectura hace
  más de 7 días*, con `GZD587` (nunca registró lectura), `AD738EA` (36 días),
  `OJG526` (25 días) y los siguientes, con km, fecha y chofer.
- La segunda corrida dio `duplicates: 1` en la etapa de lecturas: la clave de
  entrega idempotente `informe_lecturas_gerencia:empresa:4:fecha:20261023`
  impidió un segundo aviso el mismo día. **Una vez por empresa por día.**

### 8.6 Datos cargados en TSA (empresa 4)

```sql
telefono_gerencia                    = '5493755632536'
informe_lecturas_whatsapp_habilitado = 1
informe_lecturas_hora                = '07:00'
informe_lecturas_dias                = 7
```

El teléfono va **solo dígitos**: `WhatsAppPhone::normalize()` acepta
`^[0-9]+$` y para Argentina exige `^549[0-9]{10}$` (13 dígitos). Con el número
que pasó initially (`+54 9 375 63-2536`, 12 dígitos) el aviso **nunca se habría
programado**; con `+54 9 3755 63-2536` son 13 y encaja.

Dato de calendario que importa: la tarea programada de Coolify corre a las
**10:00 UTC = 07:00 hora Argentina**, no a las 07:00 UTC. Por eso
`informe_lecturas_hora = '07:00'` (y no el default `08:00`) para que el aviso
salga en el mismo ciclo que el informe diario por correo.


## 9. Pendiente técnico: la telemetría y el límite de memoria del CLI

El ciclo ya no muere **antes** de los informes, pero la etapa de telemetría sigue
siendo la más pesada: el `json_decode` de la respuesta de Wialon necesita más de
128 MB (`memory_limit` del PHP CLI). Los commits `8b001757` y `8c882897` acotan la
ventana de consulta, pero **falta confirmar con una corrida real** que la etapa
ya no agota la memoria: el 23/10 la telemetría no llegó a correr porque su
disparador es `minuto % 15 === 0` y las corridas de prueba caen en otros
minutos.

Dos salidas posibles, en orden de preferencia:

1. Subir el `memory_limit` del CLI en el `Dockerfile` (por ejemplo a 512M) para
   que el margen no dependa del tamaño de la respuesta del proveedor.
2. Si se quiere evitar subir el límite: procesar la respuesta de Wialon por
   streaming en vez de `json_decode` completo.

Mientras tanto, el aviso a gerencia **no depende** de esa etapa: los informes y
el despacho corren antes, y la clave de entrega idempotente impide duplicados.


## 10. Notas de entorno

- El SSH a `fasa_189` dio timeout al principio de la sesión y **funcionó después**: la verificación contra la base real sí se pudo hacer (migración aplicada, SMTP probado, mensaje generado con datos de TSA).
- La DB local de la PC (`localhost/a0110632_mant`) responde pero **rechaza la credencial** del `.env` (`Access denied for user 'a0110632_mant'@'localhost'`). Por eso la validación se hizo contra `mantenimiento_staging` en `fasa_189`, no contra la base local. Queda pendiente destrabar esa credencial.
- El build del frontend regeneró los assets versionados de `assets/dashboard/` (hashes nuevos), que quedaron consistentes con el working tree actual.
- Las variables de entorno de Coolify llegan al contenedor en **forma con guion bajo** (`database_default_hostname`, no `database.default.hostname`): CI4 resuelve las dos, pero un script propio tiene que probar la variante con `_`.
- Los temporales que se usaron para la verificación (scripts de SMTP, de descifrado y de consulta; la copia de la migración dentro del contenedor; el PAT temporal de Coolify) se eliminaron del host, de los contenedores y del repo.

