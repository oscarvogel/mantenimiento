# Auditoría de cierre — Issues #473 y #146

Fecha: 17/11/2026
Repositorio: `oscarvogel/mantenimiento`
SHA de `origin/main` auditado: `41cf4dc47980a99d1375e7948f84e0ffd1cd77a8` (2026-11-16 18:41:33 -0300)
Alcance: verificación de evidencia para decidir el cierre administrativo. No se desarrolló funcionalidad nueva.

---

## 0. Correcciones al encargo y advertencias de entorno

1. **Staging canónico.** El encargo menciona `fasa_195`. Según `AGENTS.md` y
   `docs/notificaciones-qa.md`, el staging canónico de este proyecto es **`fasa_189`**;
   `fasa_195` no pertenece al circuito. **Se auditó `fasa_189` y no se operó `fasa_195`.**
2. **URL/puerto real del staging.** No existe un servicio en el puerto `8090` de `fasa_189`
   para este proyecto: el stack es la aplicación Coolify `mantenimiento-staging`
   (`bdqictyu4q5xkfuh6aoh7sr8`), servida en
   `https://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io`.
   El `8090` de `fasa_189` corresponde a otro stack (`crons-dashboard`).
3. **Acceso de solo lectura a producción.** Ferozo no tiene SSH ni CLI. El acceso disponible
   fue **FTPS de solo lectura** (`scripts/ferozo-ftps.py` con `.ferozo-credentials`), usado
   únicamente con `inventory` y `backup` (descarga). **No se subió, movió ni borró nada en
   producción.** Para el estado de datos en producción se usó un dump local existente
   (`dist/ferozo-backups/mantenimiento-produccion-20261010.sql`, 10/10/2026) y, para el estado
   de esquema, un dump de producción del 17/11/2026 (`dist/ferozo-backup-lecturas-20261117-075550.sql`,
   generado por otra sesión en paralelo).
4. **Dato personal.** No se reproducen teléfonos, nombres ni correos de personas en este informe.
   Los agregados se presentan sin datos identificatorios.

---

## 1. Issue #473 — Alertas obsoletas de celulares WhatsApp

**PR:** #474 `fix(notificaciones): regularizar alertas obsoletas de celulares de choferes`
**Merge:** `74fadec9ec748f2ea50f1526e39d34e8a9d48036` — 09/10/2026 18:50:23 UTC
**Ancestro de `origin/main`:** confirmado (`git merge-base --is-ancestor` → 0)

### 1.1 Tabla de verificación

| Criterio | Evidencia | Resultado | Observación |
|---|---|---|---|
| Móvil chileno válido se acepta | `app/Domain/Notifications/WhatsAppPhone.php:28-30` (`^56[0-9]{9}$`); `normalize('56921687769')` = `'56921687769'` verificado en ejecución real | PASS | El número del incidente se conserva tal cual |
| Validaciones Argentina y Brasil sin regresión | `WhatsAppPhone.php:22-27`; ejecución real: `5493764123456`→válido, `549351444999`→NULL, `5511987654321`→válido | PASS | Sin regresión |
| Notificaciones obsoletas pasan a `REGULARIZADA` | `app/Domain/Notifications/NotificationState.php:30`; `app/Infrastructure/Notifications/CodeIgniterNotificationRepository.php:93-118` (`regularizePending`) | PASS | Estado propio, distinto de `LEIDA` |
| `LEIDA` sigue siendo acción humana | `NotificationState.php:12-13,29`; en producción el aviso W39 quedó `LEIDA` con `leida_en` de lectura humana | PASS | Semántica preservada |
| Una alerta regularizada no reaparece sin cambio real | `CodeIgniterNotificationRepository.php:70-77` (`sameContent && !wasRegularized` → `UNCHANGED`); test `testReadAlertIsNotReopenedWhenContentDidNotChange` | PASS | Un aviso leído no se reabre |
| Corrección parcial deja solo las irregularidades actuales | `NotifyAdminsMissingDriverPhones.php:250-294` (`composeSummary`); test `testPartiallyCorrectedAlertKeepsOnlyRemainingDrivers` | PASS | El resumen se regenera con las irregularidades vigentes |
| No se generan alertas duplicadas | Clave lógica estable por empresa (sin semana): `NotifyAdminsMissingDriverPhones.php:305-308`; unique `(usuario_id, clave_evento)`; staging: 0 duplicados lógicos | PASS | Un aviso vivo por empresa |
| Los avisos anteriores conservan su trazabilidad | `regularizePending` actualiza estado/`leida_en`/`updated_at` sin borrar (`:110-115`); en producción el aviso regularizado conserva el texto original con el teléfono obsoleto | PASS | Nada se borra |
| Aislamiento entre empresas | `regularizePending` filtra por `empresa_id` (`:100`); `refresh` filtra empresa+usuario+clave (`:59-64`); staging: 0 avisos con `empresa_id` distinto del destinatario | PASS | Sin cruce de empresas |
| Pruebas automatizadas | `DriverPhoneAlertRegularizationTest`: **21 tests, 85 assertions, OK**; carpeta de notificaciones: **235 tests, 1279 assertions, OK**; suite total: **1820 tests, 7207 assertions, OK (45 skipped)**; frontend: **9 tests, OK** | PASS | Ejecutado en WSL/PHP 8.5.4 el 17/11/2026 |
| Código desplegado en producción | FTPS de solo lectura: `NotificationState.php`, `NotifyAdminsMissingDriverPhones.php` y `CodeIgniterNotificationRepository.php` **idénticos byte a byte a `main`**; `eventKey()` en producción = `…:empresa:{id}` (sin semana) | PASS | Verificado el 17/11/2026 |
| Frontend de producción con el estado nuevo | Bundle publicado `main-XNNWqsIi.js` (200) contiene `Regularizada` | PASS | Centro de notificaciones en producción |
| Transición real a `REGULARIZADA` en producción | Dump de producción 10/10/2026: aviso `id 150922`, clave `chofer.telefono_faltante:empresa:4:semana:2026-W40`, `estado=REGULARIZADA`, `leida_en/updated_at=2026-10-09 16:30:02` (posterior al deploy de las 16:01) | PASS | Evidencia directa de la transición |
| Ese aviso abandonó `PENDIENTE` y no reaparece | Mismo dump: **0 avisos `PENDIENTE`** del tipo; el W39 quedó `LEIDA` (leído por humano) | PASS | Sin avisos obsoletos colgados |
| Ejecuciones posteriores al despliegue | Producción: regularización el 09/10/2026 16:30. Staging: etapa `notificaciones:ciclo:telefonos_choferes` **COMPLETADA 11/11 corrida, del 07/11 al 17/11** | PASS | Corrida diaria real |
| Sin múltiples alertas activos por empresa | Staging: 0 `PENDIENTE` del tipo. Producción (10/10): 0 `PENDIENTE` del tipo | PASS | — |
| Los teléfonos válidos no generan advertencias | Staging: 0 avisos `PENDIENTE` del tipo con una irregularidad real abierta | PASS | Ver observación de cobertura abajo |
| Estado actual de la base de producción | **NO VERIFICADO** — sin acceso de solo lectura a la BD de Ferozo (sin SSH/CLI y sin script remoto) | NO VERIFICADO | La evidencia de producción disponible es de 10/10/2026; el código y el bundle se verificaron hoy |

### 1.2 Observaciones (no bloquean el cierre de #473)

1. **Cobertura de detección de números locales sin código de país.**
   `WhatsAppPhone::normalize()` solo aplica reglas específicas a los prefijos `54`, `55` y `56`;
   cualquier otro número de 10 a 15 dígitos se devuelve "tal cual" y se considera **válido**
   (verificado: `normalize('3754123456')` = `'3754123456'`). En consecuencia, la categoría
   `local` ("número local sin código internacional") solo se alcanza para números que empiezan
   con 54/55/56. En staging hay exactamente 1 teléfono de 10 dígitos activo de una flota de
   choferes con prefijo `375` (AR local sin `54`): por eso la etapa corre y devuelve
   `drivers=0`. Es una limitación **preexistente** del dominio, no una regresión de #474, y
   produce avisos ausentes, no avisos falsos.

2. **Semántica de `leida_en` en avisos regularizados.** `REGULARIZADA` usa `leida_en` como
   marca de salida de la bandeja (igual que `WeeklyReadingNotificationRevalidator`). El
   estado `REGULARIZADA` es el que distingue la regularización automática de la lectura humana.

### 1.3 Veredicto #473: `LISTO_PARA_CERRAR`

Los nueve criterios de código del issue están implementados y cubiertos por pruebas, los dos
PR están fusionados en `main`, **el código está desplegado en producción (verificado hoy por
FTPS, idéntico a `main`)** y **la transición real `PENDIENTE → REGULARIZADA` está documentada en
producción** (10/10/2026, 09/10 16:30) y en staging (10/10/2026, 07:00). No quedan avisos
obsoletos pendientes en ninguno de los dos entornos verificables.

Única salvedad: no se pudo leer la base de producción **hoy** (Ferozo no expone CLI/SSH). Si se
quiere cerrar con evidencia de la foto actual, la comprobación puntual es una consulta
`SELECT estado, COUNT(*) FROM notificaciones WHERE tipo_evento='chofer.telefono_faltante' GROUP BY estado;`
desde phpMyAdmin del panel Ferozo (solo lectura).

### 1.4 Comentario de cierre propuesto para #473

```markdown
Cerrado: la corrección está implementada, desplegada y verificada.

- PR #474 (`74fadec9`) fusionado a `main` el 09/10/2026; auditado sobre `main` = `41cf4dc`.
- El aviso obsoleto dejó de depender de una copia congelada del teléfono: la clave lógica
  ahora es estable por empresa (`chofer.telefono_faltante:empresa:{id}`, sin semana) y el
  estado `REGULARIZADA` distingue la regularización automática de la lectura humana (`LEIDA`).
- Verificado en producción por FTPS de solo lectura (17/11/2026): `NotificationState.php`,
  `NotifyAdminsMissingDriverPhones.php` y `CodeIgniterNotificationRepository.php` son idénticos
  byte a byte a `main`; el bundle publicado contiene `Regularizada`.
- Evidencia funcional de la transición real: el aviso semanal heredado
  `chofer.telefono_faltante:empresa:4:semana:2026-W40` pasó de `PENDIENTE` a `REGULARIZADA` el
  09/10/2026 16:30, conservando su texto original como auditoría; no quedan avisos `PENDIENTE`
  de ese tipo. El aviso semanal anterior (W39) permanece `LEIDA`, porque fue leído por una
  persona.
- Pruebas: `DriverPhoneAlertRegularizationTest` 21 tests / 85 assertions OK; suite de
  notificaciones 235 tests / 1279 assertions OK; suite completa 1820 tests / 7207 assertions OK
  (45 skipped); frontend de notificaciones 9 tests OK. Cubren: móvil chileno válido, local sin
  código internacional inválido, sin regresión en Argentina/Brasil, corrección total y parcial,
  no reaparición, no duplicados, trazabilidad, aislamiento por empresa y por destinatario, y
  ausencia total de envíos de WhatsApp.
- Pendiente informativo (no bloqueante): la regla de dominio sólo reconoce los prefijos 54/55/56,
  por lo que un teléfono local argentino sin `54` se considera válido y no genera aviso. Es una
  limitación previa, ajena a este issue.
```

---

## 2. Issue #146 — Motor de notificaciones: idempotencia, trazabilidad, migraciones y QA E2E

**PR:** #152 `test(notificaciones): cerrar gate de idempotencia y QA (#146)`
**Merge:** `6bd781c74c269b213a4ebb7cc9458a9ae4ae9aad` — 26/08/2026 10:32:33 UTC
**Ancestro de `origin/main`:** confirmado (`git merge-base --is-ancestor` → 0)
**Commentario del issue:** el propio autor dejó constancia de que el único gate pendiente es el
smoke E2E real en staging con campana + email + push y la validación de migraciones sobre el
entorno desplegado.

### 2.1 Tabla de verificación

| Criterio | Evidencia | Resultado | Observación |
|---|---|---|---|
| Índices únicos de notificaciones | `2026-08-12-120300`: `addUniqueKey(['usuario_id','clave_evento'])`; confirmado en MariaDB real (staging): `usuario_id_clave_evento` NON_UNIQUE=0 | PASS | También presente en el dump de producción |
| Índices únicos de entregas | `2026-08-12-120302`: unique `clave_entrega`; confirmado en el motor real | PASS | 0 duplicados en staging |
| Índice único de ejecuciones programadas | `2026-08-12-120302`: unique `(proceso, clave_ejecucion)`; confirmado en el motor real | PASS | 0 claves de ejecución duplicadas |
| Identificación lógica de eventos | `clave_evento` por tipo+entidad+destinatario; `NotifiableEvent` con `entityType/entityId/eventKey/url` | PASS | — |
| Estado e historial de entregas | `notificacion_entregas`: `estado`, `intentos`, `proximo_intento`, `enviada_en`, `ultimo_error` | PASS | Staging: 251 entregas con `intentos>0` (máx 4) |
| Persistencia de errores | `CodeIgniterNotificationRepository`/`CodeIgniterNotificationDeliveryQueue::failed()`; staging: 241 `FALLIDA` **con** `ultimo_error` (0 sin error) | PASS | Errores auditados |
| Reintentos controlados | `RunNotificationDispatch.php:85,138,250,275,283`: reintentable mientras `intentos < 3` (máx 4 intentos) con `proximo_intento` y backoff | PASS | Comportamiento observado en staging |
| Bloqueo de ejecuciones concurrentes | `CodeIgniterNotificationProcessControl::acquire()` con token aleatorio y expiración; `bloqueos_proceso` con PK `proceso`; `RunNotificationCycle` y `RunNotificationDispatch` toman locks distintos | PASS (código + pruebas) | No se observó contención en vivo; `bloqueos_proceso` queda vacío tras cada corrida (locks liberados) |
| Separación por empresa y sucursal | FK compuesta `(empresa_id, usuario_id)` y `(empresa_id, sucursal_id)` en `notificaciones`; `scope()` filtra empresa+usuario+sucursal; staging: 0 avisos con empresa distinta del destinatario | PASS | — |
| Permisos de acceso | Rutas con `permission:notificaciones.ver` (`Routes.php:354`); chequeos en `GetNotificationCenter`, `MarkNotificationRead`, `ManageNotificationPreferences`, `ManageWebPushSubscriptions`; SuperAdmin excluido del centro de tenant | PASS | `notificaciones.ver` asignado por rol en el seeder |
| Cron web: 404/401/403/405/429 | `app/Controllers/NotificationCron.php:32,44,53,96-97,104,113,119` + `NotificationWebCronContractTest` | PASS | Cubierto por pruebas |
| Idempotencia del collector | Staging (hoy): etapa `recoleccion` = `{"events":93,"created":0,"duplicates":231}`; días previos con `created=3` | PASS | Sin duplicados lógicos: 0 en staging y 0 por índice único |
| Idempotencia del dispatch | 0 duplicados por `clave_entrega`; `ejecuciones_programadas` con clave única | PASS | — |
| Trazabilidad de entregas | Estados `ENVIADA` / `FALLIDA` / `REINTENTO` / `OMITIDA` con fecha de envío y error | PASS | — |
| Pruebas automatizadas | Notificaciones: **235 tests / 1279 assertions OK**; suite completa: **1820 tests / 7207 assertions, 0 fallos** (45 skipped); frontend notificaciones: 9 OK | PASS | `NotificationPersistenceContractTest`, `NotificationProcessControlRecoveryTest`, `NotificationWebCronContractTest`, `NotificationPortImplementationContractTest`, etc. |
| Migraciones del subsistema aplican limpias | Instalación desde cero en esquema temporal aislado (motor MariaDB 11 real): las migraciones de notificaciones (`2026-08-12-120300/301/302/303/304`) aplican y crean los índices esperados | PASS | — |
| **Migraciones del proyecto aplican limpias (instalación nueva)** | Instalación limpia en esquema vacío: **falla en la migración #127 `2026-11-06-100000_AddPrincipalFlagToLocalities`** con `You have an error in your SQL syntax ... near ')' AND codigo_fuente IN (...)` en `markBrazil()` → `whereIn('provincia_id', [])` genera `provincia_id IN ()` | **FAIL** | **Defecto real.** La instalación nueva queda con 126/130 migraciones aplicadas: faltan `auditoria`, `viaje_operativo_logbook` y los fletes no opcionales. No afecta a los entornos existentes (allí la migración corrió con catálogos cargados) |
| Migraciones reversibles | `migrate:rollback` completo sobre la base temporal: **todas las 130 revertidas sin error** (0 migraciones y sólo la tabla `migrations` remanente) | PASS | Reversibilidad demostrada |
| Migraciones aplicadas en el entorno desplegado | Staging: 133 migraciones registradas; `php spark migrate` el 17/11 → "Migrations complete" sin pendientes. Producción: 135 migraciones (dump 17/11), última `2026-11-12-150000` | PASS | Producción está más actualizada que staging |
| **Smoke E2E: campana y centro interno** | 332 notificaciones `PENDIENTE`, 64 `LEIDA`, 7 `REGULARIZADA`; collector diario activo; bundle de producción con `Regularizada` | PASS (parcial) | No se ejecutó el recorrido HTTP autenticado (campana, `/notificaciones/resumen`, marcar leída) para no generar efectos |
| **Smoke E2E: email de prueba** | Staging tiene `configuracion_canales_globales.smtp_enabled = 0` (desde 24/10/2026 10:06). Resultado: **241 entregas `FALLIDA`** y **3 `REINTENTO`** por "el canal de correo está deshabilitado"; el ciclo diario figura `FALLIDA` de forma sostenida (7–17/11) | **FAIL** | El canal está apagado pero la cola sigue encolando EMAIL según la preferencia del usuario, de modo que cada corrida falla. Falta el smoke de email |
| **Smoke E2E: Web Push** | 2 suscripciones en `webpush_subscriptions`; 41 entregas `PUSH` en estado `OMITIDA` ("No existen dispositivos activos") | NO VERIFICADO | No hay entrega push real ni deep link comprobado |
| **Smoke E2E: reintentos ante falla simulada y concurrencia** | Los reintentos están evidenciados por datos reales (fallos de SMTP y de canal deshabilitado). La concurrencia no se probó en vivo | NO VERIFICADO | Cubierto por pruebas automatizadas, no por ejecución |
| Checklist de despliegue y rollback documentado | `docs/notificaciones-qa.md`: 14 pasos de smoke, consultas de auditoría, gate de cierre de #7, plan de rollback | PASS | Documento vigente y con el staging canónico correcto (`fasa_189`) |
| Cierre de #7 | Fuera del alcance de esta auditoría | — | #146 no puede cerrarse antes que su propio gate |

### 2.2 Defectos y riesgos detectados

1. **Defecto bloqueante — instalación limpia rota (contexto Localidades).**
   `app/Database/migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php:108-118`:
   `markBrazil()` ejecuta `whereIn('provincia_id', $provinceIds)` sin comprobar que la lista no
   esté vacía. En una base nueva no hay provincias cargadas, el array llega vacío y CI4 renderiza
   `provincia_id IN ()`, que MySQL/MariaDB rechaza. `markAll()` y `markCities()` no suffersen el
   problema porque recorren la lista por trozos y no iteran sobre una lista vacía.
   *Corrección propuesta:* no emitir la consulta cuando la lista de provincias está vacía
   (mismo criterio que `markAll`/`markCities`), y agregar una prueba de migración sobre base
   vacía. while `provincia_id IN ()` es SQL inválido en MySQL/MariaDB.

2. **Riesgo operativo — el ciclo de notificaciones falla todos los días en staging.**
   El canal de correo está deshabilitado (`smtp_enabled = 0`) pero `CodeIgniterNotificationDeliveryQueue::schedule()`
   encola entregas `EMAIL` según la **preferencia del usuario**, no según el estado del canal.
   Resultado: 241 entregas `FALLIDA` con 4 intentos cada una, y la etapa `notificaciones:ciclo:despacho`
   en `FALLIDA` todos los días desde el 28/10, lo que marca el ciclo completo como parcial fallido.
   *Corrección propuesta:* no encolar `EMAIL` cuando el canal global está apagado, o marcar esas
   entregas como `OMITIDA` en lugar de `FALLIDA`.

3. **Deuda operativa menor.** La tabla `migrations` de producción contiene versiones repetidas
   (por ejemplo `2026-08-20-200000` en los lotes 9 y 11): una migración se aplicó dos veces.
   No rompe nada, pero ensucia el historial de esquema.

### 2.3 Veredicto #146: `REQUIERE_CORRECCION`

El motor de notificaciones **sí** cumple los criterios de idempotencia, trazabilidad, reintentos,
bloqueo, permisos y aislamiento multiempresa, verificados tanto por pruebas (235 tests de
notificaciones, suite completa de 1820 tests en verde) como por datos reales de staging y de un
dump de producción. **Pero el primer criterio de aceptación ("las migraciones aplican limpias")
falla** por un defecto reproducible en una instalación nueva, y el smoke E2E obligatorio
(campana + email + push con datos controlados) **no puede considerarse ejecutado**: el canal de
correo está deshabilitado en staging y el push no tiene dispositivos activos.

### 2.4 Acciones pendientes exactas para #146

1. **Corregir `2026-11-06-100000_AddPrincipalFlagToLocalities::markBrazil()`** para no emitir la
   consulta con `provincia_id IN ()`, y agregar una prueba de base vacía.
2. **Repetir la instalación limpia** en un esquema vacío del motor real (MariaDB/MySQL) y dejar
   constancia del resultado: 130/130 migraciones aplicadas y rollback completo sin errores.
3. **Resolver el canal de correo en staging** (habilitar SMTP con destinatario de prueba
   controlado o dejar de encolar EMAIL con el canal apagado) y ejecutar el paso 9 del smoke de
   `docs/notificaciones-qa.md`.
4. **Ejecutar el smoke de Web Push** (paso 10) con una suscripción y un deep link reales.
5. **Probar la concurrencia en vivo** (paso 12) launching dos dispatch simultáneos.
6. **Ejecutar el recorrido HTTP autenticado** (pasos 2, 3, 6, 7 y 8: login, campana,
   `/notificaciones/resumen`, no duplicar en la misma clave horaria, marcar leída y todas).
7. **Adjuntar el resultado del smoke a `docs/notificaciones-qa.md`** con fecha, SHA y entorno,
   y recién entonces cerrar #146 y, por separado, evaluar el cierre de #7.

---

## 3. Resumen de auditoría

- **SHA de `main` revisado:** `41cf4dc47980a99d1375e7948f84e0ffd1cd77a8`.
- **PR fusionados:** #474 → `74fadec9` (09/10/2026); #152 → `6bd781c7` (26/08/2026). Ambos
  ancestros de `origin/main` y del SHA desplegado en staging.
- **Entornos comprobados:**
  - *Staging canónico* `fasa_189`, app Coolify `mantenimiento-staging`, imagen
    `dc4d3bd28fbf0a484aa8dca21270dec54c112a90` (11/11/2026), base `mantenimiento_staging`
    en MariaDB 11. Contiene ambos merges.
  - *Producción* Ferozo: código verificado por FTPS el 17/11/2026 (idéntico a `main`);
    bundle publicado `main-XNNWqsIi.js` con `Regularizada`; esquema con 135 migraciones.
    **Sin acceso de solo lectura a la base de datos de producción.**
- **Tests ejecutados (17/11/2026, WSL / PHP 8.5.4):** suite completa 1820 tests, 7207
  assertions, 0 fallos, 45 skipped; notificaciones 235 tests / 1279 assertions OK;
  `DriverPhoneAlertRegularizationTest` 21 tests / 85 assertions OK; frontend notificaciones
  9 tests OK.
- **Evidencia real de staging:** 7 avisos `REGULARIZADA` (1 de choferes, 6 de preventivos),
  0 `PENDIENTE` del tipo `chofer.telefono_faltante`, 0 duplicados lógicos, 0 avisos con
  empresa distinta del destinatario, 11 corridas COMPLETADA de la etapa de celulares
  (07–17/11), collector idempotente (93 eventos, 231 duplicados detectados),
  241 entregas `FALLIDA` con error persistido, `bloqueos_proceso` sin locks colgados.
- **Evidencia real de producción:** transición `PENDIENTE → REGULARIZADA` el 09/10/2026 16:30
  con el texto original conservado; 0 avisos `PENDIENTE` del tipo al 10/10/2026; índice único
  `(usuario_id, clave_evento)` presente; código desplegado verificado hoy.
- **Riesgos residuales:**
  1. La base de producción no pudo leerse hoy: el estado actual de los avisos de choferes en
     producción es **NO VERIFICADO** (la evidencia disponible es del 10/10/2026).
  2. La instalación limpia de una base nueva está rota por una migración de Localidades.
  3. En staging, el canal de correo apagado genera 241 entregas fallidas y un ciclo `FALLIDA`
     diario.
  4. La cobertura de detección de irregularidades telefónicas no alcanza a los números locales
     sin código de país fuera de 54/55/56.
- **Bloqueos de acceso o permisos:**
  - Sin SSH/CLI en Ferozo → sin lectura directa de la base de producción.
  - Sin credenciales de un usuario autenticado del sistema → el recorrido HTTP autenticado
    (campana, centro, marcar leída) no se ejecutó.
  - El canal SMTP de staging está deshabilitado → el smoke de email no puede completarse.
- **Incidente de auditoría y su restauración (debe quedar registrado):**
  durante la primera prueba de migraciones se usó el nombre de variable equivocado
  (`database.default.database`, con puntos), que CI4 ignora; por eso `php spark migrate:rollback`
  se ejecutó **contra la base de la aplicación de staging** y revirtió la migración
  `2026-11-11-090000_MakeOperationalTripLegAmountsOptional`. No hubo pérdida de datos (la
  migración exige que no existan importes nulos y en esa base no los había; su `down()` fija
  `NOT NULL DEFAULT 0` sobre columnas que no tenían nulos). Se restauró de inmediato con
  `php spark migrate`, verificando que las cuatro columnas de fletes volvieron a ser
  `NULL` y que la tabla `migrations` volvió a 133 registros. Efecto residual: el número de lote
  de esa migración quedó en 60 en lugar de 59 (metadato, sin efecto funcional).
  La prueba definitiva se hizo con el nombre correcto (`database_default_database`) contra un
  esquema temporal aislado, con verificación previa y posterior del conteo de migraciones de la
  base de la aplicación (133 antes y después).
- **Acciones pendientes:** las de la sección 2.4 (para #146) y la comprobación puntual opcional
  de la sección 1.3 (para #473).
- **Movimiento de `main` durante la auditoría.** Al terminar, `main` avanzó de `41cf4dc` a
  `bfa35f32` por el merge del PR #531 (lecturas / revisión de IA). Ese cambio **no toca el
  motor de notificaciones**: el diff contra `41cf4dc` sólo agrega la migración
  `2026-11-16-230000_CreateReadingEvidenceReviews` y sus archivos de lecturas. La migración que
  rompe la instalación limpia (`2026-11-06-100000_AddPrincipalFlagToLocalities`) sigue igual en
  `main`, de modo que el defecto reportado en 2.2.1 continúa vigente en el `main` actual.

---

## 4. Veredicto

| Issue | Veredicto | Fundamento |
|---|---|---|
| **#473** | `LISTO_PARA_CERRAR` | Código y pruebas completos, desplegados en producción (verificado por FTPS el 17/11/2026) y con la transición real `PENDIENTE → REGULARIZADA` documentada en producción (09/10/2026) y en staging (10/10/2026). Sin avisos obsoletos pendientes en los entornos verificables |
| **#146** | `REQUIERE_CORRECCION` | Un criterio de aceptación falla de forma reproducible (la instalación limpia se rompe en `2026-11-06-100000_AddPrincipalFlagToLocalities`) y el smoke E2E obligatorio no puede ejecutarse con el canal de correo apagado y sin dispositivos push activos. El resto del motor está verificado |

No se hizo merge, no se desplegó, no se modificaron datos de producción, no se cerraron issues y
no se creó ningún PR como parte de esta auditoría.
