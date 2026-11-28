# Bitácora — Destinatario del resumen diario por usuario (28/11/2026)

## Problema

El resumen diario usaba `usuarios.email`, el mismo campo del login. Cambiarlo para arreglar la entrega cambiaba el usuario de acceso. Mariela y Carlos necesitaban recibir el resumen en otras direcciones sin modificar sus credenciales de ingreso.

## Decisión

- Campo opcional `usuarios.email_notificaciones`, resuelto por `UserNotificationEmailPolicy` con fallback a `usuarios.email`.
- Snapshot del destinatario efectivo en `notificacion_entregas.destinatario`. La clave de idempotencia no cambia (`{$eventKey}:usuario:{$userId}:email` / `:push`).
- La política es la única fuente de precedencia; los adaptadores no repiten el condicional. Si el correo de resumen es inválido o vacío, cae al correo de ingreso.
- Informes gerenciales y alertas de empresa no se tocan.

## Contextos afectados

Notifications (política, resolver, cola y publicación) y Organización/Identidad (ABM de usuarios del tenant). Integrador: Arquitectura e integración. La dirección de dependencias se preserva: `Presentation / Infrastructure → Application → Domain`; la precedencia es una política pura de `Application/Notifications`.

## Commits de tareas 1-7

| Tarea | Commit | Mensaje |
|---|---|---|
| 1 | `a339b392` | `feat(notificaciones): politica de correo de resumen con fallback al login` |
| 2 | `04d049ba` | `feat(notificaciones): migracion del correo de resumen y del destinatario por entrega` |
| 3 | `8fee8ec1` | `refactor(notificaciones): destinatario opcional en la firma de la cola` |
| 4 | `398a72ae` | `feat(notificaciones): resolver el resumen con el correo de notificaciones del usuario` |
| 5 | `6e37be90` | `feat(notificaciones): guardar y usar el destinatario efectivo de cada entrega` |
| 6 | `6ac418e4` | `feat(organizacion): correo de resumen en altas y ediciones de usuario` |
| 7 | `77328d84` | `feat(admin): correo para resumenes separado del ingreso en usuarios` |

## Archivos y migración

### Tarea 1 — Política pura
- `app/Application/Notifications/UserNotificationEmailPolicy.php`
- `tests/unit/Application/Notifications/UserNotificationEmailPolicyTest.php` (4 tests)

### Tarea 2 — Migración correctiva
- `app/Database/Migrations/2026-11-28-100000_AddUserNotificationEmailSnapshot.php`
  - `usuarios.email_notificaciones VARCHAR(255) NULL`
  - `notificacion_entregas.destinatario VARCHAR(255) NULL`
  - `up()`/`down()` idempotentes con guardas; no toca `usuarios.email` ni filas existentes.

### Tarea 3 — Firma opcional del puerto
- `app/Application/Notifications/Port/NotificationDeliveryQueue.php`
- `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php`
- 7 dobles de tests actualizados con `?string $deliveryEmail = null`
- `tests/unit/Application/Notifications/NotificationDeliveryQueueContractTest.php`

### Tarea 4 — Resolver
- `app/Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php`
- `app/Config/Services.php`
- `tests/unit/Infrastructure/Notifications/NotificationRecipientEmailTest.php`

### Tarea 5 — Snapshot y despacho
- `app/Application/Notifications/PublishNotifiableEvent.php`
- `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php`
- `tests/unit/Application/Notifications/NotificationChannelAvailabilityTest.php`
- `tests/unit/Application/Notifications/NotificationUseCasesTest.php`

### Tarea 6 — Organización/ABM backend
- `app/Application/Organization/TenantAdministrationService.php`
- `app/Application/Organization/Port/TenantAdministrationPort.php`
- `app/Infrastructure/Organization/CodeIgniterTenantAdministration.php`
- `tests/unit/Application/Organization/TenantAdministrationServiceTest.php`

### Tarea 7 — ABM visible
- `app/Controllers/TenantAdmin.php`
- `app/Presentation/AdministrationPayload.php`
- `app/Models/UsuarioModel.php`
- `frontend/src/pages/admin/UsersAdminPage.vue`
- `frontend/tests/admin/fixtures.js`
- `frontend/tests/admin/AdminPages.test.js`
- `tests/unit/Application/Organization/TenantUserNotificationEmailContractTest.php`

### Task 8 — Documentación
- `docs/CONTRATOS_ENTRE_CONTEXTOS.md`
- `docs/superpowers/specs/2026-11-28-destinatario-resumen-diario-por-usuario-design.md`
- `docs/BITACORA_2026-11-28_DESTINATARIO_RESUMEN_DIARIO.md`

## Evidencia

### Pruebas por tarea (esperado/verificado en su momento; no reproducido en este cierre)

| Tarea | Resultado esperado/verificado en su momento (rojo) | Resultado esperado/verificado en su momento (verde) |
|---|---|---|
| 1 | Clase no encontrada al ejecutar `UserNotificationEmailPolicyTest` (esperado del plan) | `OK (4 tests)` (reportado en la tarea/plan; no reproducido en este cierre) |
| 2 | Migración no encontrada (esperado del plan) | `OK (2 tests)` en SQLite (reportado en la tarea/plan; no reproducido en este cierre) |
| 3 | Firma sin `deliveryEmail` en contrato (esperado del plan) | `OK` contrato + suite de notificaciones sin errores de firma (reportado en la tarea/plan; no reproducido en este cierre) |
| 4 | Constructor/SELECT sin campo nuevo (esperado del plan) | `OK (3 tests)` resolver SQLite + regresión `NotificationUseCasesTest` (reportado en la tarea/plan; no reproducido en este cierre) |
| 5 | Columna `destinatario` ausente y despacho al login (esperado del plan) | `OK` en `NotificationChannelAvailabilityTest`, `NotificationUseCasesTest`, `NotificationPersistenceContractTest` (reportado en la tarea/plan; no reproducido en este cierre) |
| 6 | Payload sin `email_notificaciones` (esperado del plan) | `OK` en `TenantAdministrationServiceTest` + `php -l` en puerto/adaptador (reportado en la tarea/plan; no reproducido en este cierre) |
| 7 | Validaciones/POST/frontend sin campo (esperado del plan) | `OK (3 tests)` contrato PHP, Vitest `AdminPages.test.js` verde, build frontend correcto (reportado en la tarea/plan; no reproducido en este cierre) |

**Nota de honestidad:** esta tabla no es evidencia literal reproducida en el cierre. Los commits 1-7 tienen cuerpo vacío y el plan registra valores `Esperado:`; aquí se dejan como resultado esperado/verificado en su momento según lo reportado durante la ejecución de esas tareas, **no** como salida observada en esta sesión. La única evidencia real comprobada en este cierre es la sección «Verificación local completa (Task 8, evidencia real)» abajo (PHPUnit completo 2566 tests, 1 error; aislamiento del symlink; Vitest 627/627; build frontend con QA de encoding).

### Verificación local completa (Task 8, evidencia real)

Entorno: Windows, PHP 8.2.12, PHPUnit 10.5.64, Node/npm. Sin WSL. `sqlite3` disponible en PHP; PHPUnit se ejecutó sin el flag `-d extension=sqlite3` (ver observación g).

#### PHPUnit completo

Comando:

```bash
php vendor/bin/phpunit --no-coverage
```

Resumen literal:

```text
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: D:\notebook\active\mantenimiento\phpunit.dist.xml

............................................................... 2566 / 2566 (100%)

Time: 00:18.575, Memory: 128.00 MB

There was 1 error:

1) Tests\Unit\Infrastructure\Telematic\ArchivoRespaldoBucketsTest::testRechazaUnEnlaceSimbolicoQueSaleDeWritable
ErrorException: symlink(): Permission denied

D:\notebook\active\mantenimiento\tests\unit\Infrastructure\Telematic\ArchivoRespaldoBucketsTest.php:61

ERRORS!
Tests: 2566, Assertions: 9671, Errors: 1, Skipped: 52.
```

Aislamiento del error:

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Telematic/ArchivoRespaldoBucketsTest.php
```

Resumen literal:

```text
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: D:\notebook\active\mantenimiento\phpunit.dist.xml

..E                                                                 3 / 3 (100%)

Time: 00:00.047, Memory: 12.00 MB

There was 1 error:

1) Tests\Unit\Infrastructure\Telematic\ArchivoRespaldoBucketsTest::testRechazaUnEnlaceSimbolicoQueSaleDeWritable
ErrorException: symlink(): Permission denied

D:\notebook\active\mantenimiento\tests\unit\Infrastructure\Telematic\ArchivoRespaldoBucketsTest.php:61

ERRORS!
Tests: 3, Assertions: 6, Errors: 1.
```

**Conclusión PHPUnit:** la suite completa **no está 100% en verde**. Resultado: 2566 tests, 9671 assertions, 1 error, 52 skipped. El único error es ambiental/preexistente y ajeno a este cambio: `ArchivoRespaldoBucketsTest::testRechazaUnEnlaceSimbolicoQueSaleDeWritable` falla por `symlink(): Permission denied` en Windows; fue verificado en aislamiento con el mismo resultado.

#### Vitest frontend

Comando:

```bash
npm --prefix frontend test
```

Resumen literal:

```text
 Test Files  98 passed (98)
      Tests  627 passed (627)
   Start at  09:31:21
   Duration  33.27s (transform 12.75s, setup 18.73s, collect 160.28s, tests 34.41s, environment 153.23s, prepare 37.03s)
```

#### Build frontend

Comando:

```bash
npm --prefix frontend run build
```

Resumen literal:

```text
> mantenimiento-dashboard@0.1.0 build
> npm run qa:encoding:source && vite build && node scripts/normalize-vite-manifest.mjs && npm run qa:encoding:build

ENCODING_QA=OK mode=source
vite v6.4.3 building for production...
✓ 852 modules transformed.
✓ built in 8.94s
ENCODING_QA=OK mode=build
```

Los artefactos regenerados en `assets/dashboard/` fueron revertidos después de capturar la salida (`git restore --source=HEAD -- assets/dashboard && git clean -fd -- assets/dashboard`); `git status --short -- assets/dashboard` quedó limpio.

## Observaciones no bloqueantes registradas

- **(a) Snapshot con cadena vacía/inválida no se revalida al despachar.** Decisión: el snapshot es una foto del destinatario efectivo al encolar; se respeta. Las entregas antiguas con `destinatario IS NULL` se resuelven en vivo con la política vigente.
- **(b) `...$data` protegido por whitelist del servicio/puerto.** La persistencia usa el payload ya normalizado por `TenantAdministrationService` y el contrato del puerto; no se expone una ruta alternativa para escribir `email` por error.
- **(c) Bool de `updateUser()` no asertado directamente en todos los tests.** El contrato del servicio devuelve si cambió el correo de ingreso; la cobertura se apoya en tests de contrato/servicio existentes y nuevos; la aserción directa del bool no se añadió como requisito extra en esta tarea de documentación.
- **(d) POST antiguo sin `email_notificaciones` limpia el valor a NULL.** Decisión explícita: vacío/ausente → `null` tras normalización. El formulario del ABM siempre envía el campo; un POST antiguo que no lo envíe lo normaliza como vacío.
- **(e) Alerta de cache/bundle viejo en el ABM.** Tras desplegar, el navegador puede conservar JS viejo; el build genera hashes nuevos y el despliegue debe publicar `assets/dashboard/` coherente con el manifiesto.
- **(f) Tipos SQLite de columnas no usadas en tests del resolver difieren levemente de la migración.** Los schemas SQLite replican el tipo real de las columnas relevantes para la consulta/regresión; columnas no usadas pueden tener diferencias menores sin afectar la semántica ejercida.
- **(g) Warning cosmético de `-d extension=sqlite3`.** En este entorno PHP 8.2.12 ya carga `sqlite3`; pasar `-d extension=sqlite3` emite warning de carga duplicada/cosmético, por eso la verificación completa se ejecutó sin ese flag.

## Datos operativos / estado pendiente

- **Staging `fasa_189`**: pendiente de verificación. No se tocó staging ni producción en esta tarea (Task 9). Antes de cualquier acción en staging hay que identificar host `fasa_189`, stack `mantenimiento-staging`, commit/checkout, puerto y volúmenes.
- **Correos reales de Mariela/Carlos**: pendiente de confirmación por escrito. No se cargan direcciones inventadas. Solo actualizar `usuarios.email_notificaciones` por `usuario_id` + `empresa_id` tras recibir: correo de ingreso vigente y correo de resumen deseado para cada uno.
- **Producción Ferozo**: no tocar sin aprobación explícita. Ferozo no tiene CLI; seguir `docs/DEPLOY_FEROZO.md` y validar con smoke HTTP cuando corresponda.
- **Aceptación visual/móvil y piloto**: **no hubo aceptación visual/móvil ni piloto con usuarios reales** en este cierre. Vitest verde no equivale a verificación visual. La verificación HTTP real y la verificación visual/móvil quedan para la Task 9 en `fasa_189`.
