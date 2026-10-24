# Bitácora 2026-10-08 — Deploy PR #472 (corrección auditada de lecturas QR anónimas)

Octavo deploy del día. Hotfix quirúrgico de **2 archivos**, sin migraciones ni
cambio de esquema, que desbloquea la corrección manual de una lectura cargada
por QR anónimo.

| | |
|---|---|
| PR | #472 `fix: permitir corrección auditada de lecturas QR anónimas` |
| TARGET_SHA (= `origin/main`) | `30b6894bf20cce28699bd0453963fef8bc2c96cc` |
| BASE / PROD anterior | `c0d69f68e5fc9af2c196bb40667711ad60530b0a` (merge de #471) |
| PR head (squash) | `c493342f8e310c164fae4c2d9461d01025b9dc4d` |
| CI autorizado | run `37844946411` — completed / success |
| Archivos subidos | 2 |

## El CI autorizado no correspondía al SHA desplegado

El run `37844946411` tiene `headSha = c493342f`, no `30b6894b`. SeDZNERó:

- #472 se mergeó por **squash**: `30b6894b` es el commit de merge y `c493342f` no
  es ancestro suyo (`merge-base --is-ancestor` → 1).
- Los **árboles son idénticos** (`82eae145…` en ambos). O sea que el CI del PR sí
  validó exactamente el contenido que quedó en `main`.
- Además existe un **segundo CI verde para el SHA exacto**: run `37845125233`,
  evento `push`, `headSha = 30b6894b`, completed / success.

Se proceeded con las dos verificaciones en verde, y se reporta el run del push
porque es el que corresponde literalmente al archivo subido.

## Qué arregla

Una lectura por QR anónimo tiene `usuario_id = NULL`. Al rehidratarla, el
repositorio hacía `(int) $row['usuario_id']`, que convierte NULL en **0**, y el
constructor de `EquipmentReading` rechazaba `userId <= 0`. Resultado: la
corrección de la lectura de `10.017.497 km` era imposible.

El fix:

1. `EquipmentReading::$userId` pasa a `?int`.
2. Se acepta NULL **solo** si `$origin === QR_ANONYMOUS`. Cualquier otro origen
   con autor nulo sigue lanzando `DomainException`.
3. Nueva constante `QR_ANONYMOUS = 'QR_ANONIMO'` en la lista de orígenes válidos.
4. El repositorio preserva NULL en vez de castearlo a 0.

La invariante de seguridad queda intacta: una lectura no-QR sin autor sigue
siendo inválida. Hay un test que lo cubre explícitamente
(`Null author is still rejected for authenticated origins`).

## Riesgo colateral revisado: `userId(): int` → `?int`

El ensanchamiento de firma podría producir un fatal en un consumidor que exija
`int`. Se revisaron todos los usos:

- Los ~150 `->userId()` de `app/` son de `$actor` (`ActorContext`), otra clase,
  sin cambios.
- El **único** consumidor real de `EquipmentReading::userId()` es
  `CodeIgniterReadingRepository.php:30` → `'usuario_id' => $reading->userId()`.
  Es un valor de array hacia una columna nullable; no hay parámetro tipado.

Sin riesgo de fatal.

## Verificación

- Alcance: exactamente 2 runtime + 1 test.
- `git archive` desde TARGET_SHA (nunca working tree). `php -l` limpio en ambos.
- Remotos previos coincidieron con BASE/PROD. Backup en `.deploy/backup-pre-30b6894/`.
- `MATCH=2/2`, `HASH_VERIFICATION=OK`.
- Tests, con el bootstrap determinista de worktree:
  - `Domain/Measurement`: **17 tests, 36 assertions, OK**
  - `Application/Measurement`: **17 tests, 57 assertions, OK**
  - `Infrastructure/Measurement`: **15 tests, 117 assertions, OK**
  - `Domain/` completo: **115 tests, 285 assertions, OK**
- Se verificó sobre los **bytes descargados de producción** que el fix está
  presente y que la firma vieja `userId(): int` ya no existe.
- Smoke: `/login` 200, ruta pública con token inválido 200 controlado.
- Ruta de detalle de equipo: **302 → login**, no 500. La clase del controlador
  carga sin fatal.
- Log sin errores nuevos.

### Fallo de entorno que hubo que resolver (y una corrección que hice sin querer)

Los tests de `Domain/Measurement` fallaron primero con
`TypeError: Argument #9 ($userId) must be of type int, null given` y con un
`ReflectionException`. No era el código: el bootstrap anterior con `setPsr4()`
no alcanza, porque **CodeIgniter registra su autoloader con `prepend=true`**
(`spl_autoload_register($loadClass, true)`) y el binario de PHPUnit ya cargó el
Composer del repo principal. El stack lo delató: la clase venía de
`/mnt/c/Programacion/mantenimiento/app/...`, no del worktree.

Se reemplazó por un bootstrap **determinista**: un `spl_autoload_register`
propio con `prepend=true` que resuelve `App\` siempre contra el worktree, sin
depender del orden. Con eso: todo verde.

**Efecto secundario que debo registrar:** al preparar un `vendor` propio en un
intento anterior, el `vendor` del worktree seguía siendo un symlink al vendor
principal, y un `cp -r` terminó creando un directorio
`vendor/composer/composer` **dentro del vendor real del usuario**. Se detectó
inmediatamente, se verificó que los archivos de autoload originales seguían
intactos (fechas de agosto/septiembre) y el directorio accidental se eliminó de
forma recuperable. El `vendor/composer` principal quedó verificado sin directorios
anidados. Likewise se eliminaron los worktrees temporales con sus copias de `.env`.

## Estado funcional

**NO declarado.** El deploy está verificado a nivel de archivos, sintaxis,
pruebas y humo. Falta la verificación funcional que corresponde hacer el
administrador: corregir manualmente la lectura QR de `10.017.497 km`, confirmar
que se anula, que el nuevo registro tiene el kilometraje correcto y que usuario y
motivo quedan auditados.

No se hizo ninguna modificación de lecturas reales ni se tocó la base.

## Pendientes

1. **Verificación funcional manual** (la que define el éxito real).
2. Si la corrección sigue fallando, revisar en `writable/logs` y recordar que la
   lectura debe tener `origen = 'QR_ANONIMO'` y `usuario_id IS NULL`. Si el
   origen no fuera `QR_ANONIMO`, la invariante nueva lo rechazaría a propósito.
3. El PR ya está mergeado en `main`, sin riesgo de reversión por redeploy.