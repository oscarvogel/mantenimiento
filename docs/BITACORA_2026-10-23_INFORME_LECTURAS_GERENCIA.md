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

1. **Cargar el teléfono de Mariela Pawluk** en Superadmin > Empresas > "Aviso de
   equipos sin lectura por WhatsApp" (formato internacional, solo dígitos). Sin
   teléfono cargado el aviso no se programa.
2. **Desplegar el código** (los fixes de memoria + este aviso). Es lo que
   revivide el cron de notificaciones en staging.
3. **Revisar el modo piloto** de WhatsApp: el aviso sale al teléfono piloto
   mientras el piloto global esté activo.
4. **Umbral por empresa**: 7 días es el valor por defecto; se edita por empresa
   si el caso real pide otro.
5. **Informe por correo**: ya está configurado para TSA (3 destinatarios, diario
   07:00). Con el código nuevo su umbral pasa de 30 a 7 días, así que el correo
   va a listar los 27 equipos (con detalle de los 10 más antiguos). Conviene
   avisarle a la dueña antes del primer correo largo.
6. **Producción (Ferozo)**: aplicar la migración también allá. Ahí no hay CLI;
   la vía es el panel de migraciones de Superadmin.


## 8. Notas de entorno

- El SSH a `fasa_189` dio timeout al principio de la sesión y **funcionó después**: la verificación contra la base real sí se pudo hacer (migración aplicada, SMTP probado, mensaje generado con datos de TSA).
- La DB local de la PC (`localhost/a0110632_mant`) responde pero **rechaza la credencial** del `.env` (`Access denied for user 'a0110632_mant'@'localhost'`). Por eso la validación se hizo contra `mantenimiento_staging` en `fasa_189`, no contra la base local. Queda pendiente destrabar esa credencial.
- El build del frontend regeneró los assets versionados de `assets/dashboard/` (hashes nuevos), que quedaron consistentes con el working tree actual.
- Los temporales que se usaron para la verificación (scripts de SMTP y de consulta, y la copia de la migración dentro del contenedor) se eliminaron del host, del contenedor y del repo.
