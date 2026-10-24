# Bitácora 2026-10-08 — Deploy PR #466 v2 (odómetro real + persistencia)

Segunda parte del deploy de PR #466, 40 minutos después del primero. Este
TARGET agrega el endurecimiento del odómetro y mueve **dos** archivos runtime.

## La desviación que hubo que resolver antes de subir

El protocolo pedía comparar producción contra `BASE_SHA = c18b533c` y **detenerse**
si no coincidía. Producción **no** coincidía, y la causa no era drift desconocido:
en el deploy anterior (ver `BITACORA_2026-10-08_DEPLOY_PR466.md`) ya se había subido
`f8a6f993`. La regla literal habría frenado un deploy que era correcto.

Lo que faltaba era probar que la diferencia fuera **conocida**. Se comprobó:

```
git merge-base --is-ancestor f8a6f993 670a83fb  ->  exit 0
```

Es decir, lo que estaba en producción es un ancestro del nuevo objetivo: se está
avanzando sobre la misma rama, no pisando trabajo ajeno. Con eso, la comparación
deja de ser "remoto == BASE" y pasa a ser "remoto == **algún** estado que ya
sabemos que estaba":

| archivo | BASE `c18b533c` | PROD `f8a6f993` | TARGET `670a83fb` |
|---|---|---|---|
| `PublicEquipmentReadings.php` | `40cb82a0` | `6a9ceac0` | `6a9ceac0` |
| `MiniMaxOdometerImageAnalyzer.php` | `f3638c0e` | `f3638c0e` | `6ced081a` |

Los dos remotos cayeron dentro de estados conocidos (`PROD` y `BASE`), así que se
siguió. Si alguno hubiera caído fuera de los tres, el script habría cortado con
`VERDICT=STOP_DESCONOCIDO`.

**Consecuencia útil que salió de la matriz:** el controlador **ya estaba** en el
contenido exacto del TARGET (`6a9ceac0`), porque entre `f8a6f993` y `670a83fb`
nadie lo tocó. Lo único que cambia de verdad es el analizador. El controlador se
volvió a subir igual para cumplir el contrato de verificación 2/2, pero sube bytes
idénticos a los que ya funcionaban.

## Qué se desplegó

| | |
|---|---|
| PR | #466 |
| TARGET_SHA | `670a83fb9002a03e9cf569566d9bc4cc55c46793` |
| BASE_SHA | `c18b533c7867bd522e9379e74b5525c4c463a83e` |
| CI | run `37822150534` — completed / success, `headSha` = TARGET |
| Archivos subidos | 2 |

- `app/Controllers/PublicEquipmentReadings.php` (idéntico al ya desplegado)
- `app/Infrastructure/Measurement/MiniMaxOdometerImageAnalyzer.php` (cambio real)

El rango `BASE..TARGET` toca 4 archivos: los 2 runtime y sus 2 tests. Solo se
subieron los 2 runtime.

## Verificación

### Backup y comparación previa

`.deploy/backup-pre-670a83fb/` con los 2 archivos y la estructura del repo
preservada. Hashes remotos calculados antes de tocar nada.

### Upload

Bytes extraídos con `git show TARGET_SHA:<path>` en binario, nunca del working
tree. Verificación posterior con relectura de ambos archivos:

```
[1/2] PublicEquipmentReadings.php        6a9ceac0…  34828 b  COINCIDE=SI
[2/2] MiniMaxOdometerImageAnalyzer.php  6ced081a…   7456 b  COINCIDE=SI
HASH_VERIFICATION=OK   MATCH=2/2
```

Reconfirmado al cierre del deploy: **2/2 coinciden con TARGET_SHA**.

### `php -l` sobre ambos archivos

`PHP 8.5.4` (WSL): sin errores de sintaxis. Esto importa por una razón concreta:
**el smoke HTTP no carga el analizador.** La ruta pública con token inválido corta
antes, en `resolve()`. Un error de sintaxis en el analizador habría pasado el smoke
y reventado recién en la primera foto real. El lint cubre ese hueco.

### Casos reales de camiones, ejecutados

En un worktree temporal en `TARGET_SHA` se correron los tests del analizador:

```
OK (2 tests, 15 assertions)
```

Cubren exactamente los tres casos del encargo:

| entrada | esperado | test |
|---|---|---|
| `497997.6` / `'497.997,6'` | `497998` | ✔ |
| `491138` / `'491138'` | `491138` | ✔ |
| `1194076.9` / `'1.194.076,9'` | `1194077` | ✔ |

Más la aserción de que el prompt rechaza explícitamente el Trip.

**El test muerde: comprobado, no supuesto.** Se mutiló a propósito el regex del
formato europeo (`(?:.\d{3})+` → un solo grupo de miles) y el test murió en la
aserción correcta:

```
Failed asserting that null is identical to 1194077.
```

Es decir, el test detecta justamente la regresión de ">1.000.000 km con decimal".
Se restauró (`git checkout`), se verificó que el hash volvía a `6ced081a…` y se
volvieron a correr los 2 archivos de test juntos:

```
OK (5 tests, 47 assertions)
```

El worktree se eliminó después. Había quedado una copia de `.env` adentro (se
necesita para el bootstrap de PHPUnit) y se removió con el worktree.

### Smoke (solo GET)

- `/login` → **HTTP 200**
- Ruta pública con token inválido → **HTTP 200 controlado**, "Acceso no
  disponible". Sin 500 ni error fatal.

### Logs

`log-2026-10-08.log`: 1 línea, **0 errores**, ningún marcador de etapa disparado
(`STAGE_FAILED=NONE`).

## Pendientes

1. **Prueba funcional con teléfono.** Sigue siendo la única evidencia que falta.
   Lo importante ahora es que MiniMax **no confunda el Trip** con el odómetro y
   que acepte el ">1.000.000 km con decimal". El código ya lo cubre por test, pero
   la foto real es otra cosa.
2. **PR #466 sigue abierto** y `670a83fb` **no** está en `main` (que sigue en
   `c18b533c`). Mientras no se mergee, un redeploy routine desde `main` revierte
   este hotfix. Sigue siendo el pendiente más operativo.
3. **Si falla el guardado**, correr `.deploy/_logcheck_466.py` y reportar
   `STAGE_FAILED`, `DB_ERROR_CODE` y `DB_ERROR_MESSAGE` sanitizado. No tocar
   producción sin autorización.
4. **Trampa registrada:** `git checkout` desde WSL **no funciona** sobre un
   worktree creado por git de Windows (el `gitdir` apunta a una ruta Windows).
   Restaurar desde Windows sí. Importante para cualquier verificación futura con
   worktrees.