# Bitácora 2026-10-08 — Deploy PR #471 (generador único de recordatorio)

Séptimo deploy del día y el primero que es un **refactor estructural**: dos
generadores de mensaje paralelos pasan a ser uno solo compartido.

| | |
|---|---|
| PR | #471 `Merge PR #471: unificar mensaje de recordatorio de kilometraje` |
| TARGET_SHA (= `origin/main`) | `c0d69f68e5fc9af2c196bb40667711ad60530b0a` |
| FEATURE_SHA | `d05380487831a4b920dd1ad6e757de0f325ed8fd` |
| BASE | `bac54e9806b6715c4f8c49e5a26cbec86780a41d` (merge de #470) |
| PROD anterior | `bfbe3c2b6d21742f018ec7c3279aed98a35a88c6` (PR #470) |
| CI | run `37837926526` — completed / success, `headSha` = FEATURE_SHA |
| Archivos subidos | 3 |

Este TARGET sí es `origin/main`, a diferencia de los cinco anteriores.

## El riesgo que el protocolo no mencionaba

Es el primer deploy que introduce una **clase nueva**. Dos archivos que ya
existen en producción van a pasar a instanciarla:

- `ClaimReadingReminder.php` → `(new ReadingReminderMessageBuilder())->build(...)`
- `CodeIgniterWhatsAppNotificationDeliveryQueue.php` → ídem

Si se subieran los consumidores primero, habría una ventana en la que producción
referencia una clase inexistente: **fatal 500** en el reclamo manual y en el
recordatorio semanal (que corre por cron).

Por eso el upload se hizo en **orden de dependencia**: primero el builder
(verificado con relectura y hash antes de seguir), después los dos consumidores.
Con ese orden la ventana no existe: la clase nueva está en disco antes de que
nadie la referencie. El script aborta si un archivo no cuadra, en vez de seguir.

## Generador único, verificado estructuralmente

| comprobación | resultado |
|---|---|
| existe `app/Application/Notifications/ReadingReminderMessageBuilder.php` | ✔ (6074 b, **no existía antes**) |
| contiene `class ReadingReminderMessageBuilder` | ✔ |
| `ClaimReadingReminder` usa `new ReadingReminderMessageBuilder()` | ✔ |
| `Queue` usa `new ReadingReminderMessageBuilder()` | ✔ |
| `Claim` **ya no** tiene `private function claimMessage` | ✔ |
| `Queue` **ya no** tiene `private function weeklyReadingMessage` | ✔ |
| texto viejo ausente en los 3 archivos | ✔ |

No quedan dos generadores paralelos.

## Texto (ES y PT) en el builder compartido

Presentes y completos: frase de foto obligatoria, pasos 2 a 5 (foto, lectura
automática, revisar/corregir, Registrar lectura), `ABRIR PARA CARGAR LOS KM` y
`No hace falta responder este WhatsApp`. Equivalente PT verificado.

## Ambos caminos renderizados, sin enviar WhatsApp

Se invocó `ReadingReminderMessageBuilder::build()` directamente con escalares, sin
base de datos ni gateway, sin abrir ninguna conexión saliente.

**A) Recordatorio semanal automático** (`stage=weekly`, lo llama la Queue) y
**B) Reclamo manual** (`stage=manual`, lo llama `ClaimReadingReminder`), más el
manual en PT.

Ocho chequeos automáticos, todos OK:

```
OK  ambos caminos traen la foto obligatoria
OK  ambos caminos traen el paso de IA
OK  ambos caminos traen la correccion
OK  ambos caminos traen "Registrar lectura"
OK  ambos caminos traen el enlace publico
OK  el manual conserva la ultima lectura (122.600)
OK  el manual NO inventa lectura cuando no hay
OK  TEXTO VIEJO AUSENTE en ambos caminos
```

El reclamo manual conserva lo que debía:

```
*Última lectura registrada:*
122.600 km — 01/10/2026 09:14
```

…y el bloque de instrucciones sale del mismo builder que el automático.

### Un falso positivo que casi se reporta como bug

En la primera corrida el chequeo "el manual conserva la última lectura" falló:
el mensaje decía "Todavía no tenemos una lectura registrada". **Era el fixture,
no el código**: `lastReadingText()` lee la clave `fecha_lectura`, y el fixture
pasaba `created_at`. Con la clave correcta, la lectura aparece. Se deja
anotado porque el builder cae al mensaje "sin lectura" de forma silenciosa si
falta esa clave: no es un error visible.

## Resto de la verificación

- `php -l`: los 3 sin errores.
- `ClaimReadingReminderTest`: **OK (21 tests, 62 assertions)**.
- `WhatsAppWeeklyReadingReminderContractTest`: **OK (1 test, 73 assertions)**.
- `WeeklyReadingComplianceContractTest`: **OK (1 test, 36 assertions)**.
- Suite completa `tests/unit/Infrastructure/Notifications/`: **67 tests, 580
  assertions, OK**.
- Remotos previos coincidieron con BASE y PROD. Backup en
  `.deploy/backup-pre-c0d69f68/`.
- `MATCH=3/3`, `HASH_VERIFICATION=OK`, revalidado 3/3 al cierre.
- Smoke: `/login` 200, ruta pública con token inválido 200 controlado.
- Log sin errores nuevos.

## Nota de método

Se usó el bootstrap de worktree (`.deploy/worktree_bootstrap.php`), igual que en
PR #469. Sin él, el autoloader resuelve `App\` contra el repo principal. Aquí
además pasó al rodar el render: la clase nueva no existe en el checkout
principal, así que hay que fijar el PSR-4 antes de instanciarla.

## Pendientes

1. **No hay prueba urgente que hacer.** El texto entra en vigor en el próximo
   envío; no se tocó la cola ni se forzó ningún envío real.
2. **Los mensajes ya encolados mantienen la redacción anterior.**
3. **La observación del punto anterior:** si algún día `fecha_lectura` llegara
   nula, el reclamo manual va a decir "todavía no tenemos una lectura
   registrada" aunque exista. Vale una aserción que cubra ese caso.
4. PR #471 ya está mergeado en `main`, a diferencia de #466 a #470, que siguen
   abiertos.