# Bitácora 2026-11-27 — Corrección visual de acciones de chofer (#593)

## Problema

Después del despliegue del PR #596, en la ficha del equipo, sección **“Chofer asignado”**, los botones **“Cambiar chofer”** y **“Desvincular chofer”** quedaron en filas separadas y con dimensiones distintas: el primario usaba `min-h-11`, `px-4`, `py-2.5`; el danger outline usaba `min-h-10`, `px-3.5`, `py-2`. Además, el segundo era el `<summary>` de un `<details>` ubicado debajo del formulario.

## Corrección

- Los dos botones comparten una única fila (`grid max-w-md grid-cols-1 gap-3 sm:grid-cols-2`), con **12 px** de separación (`gap-3`) y alineación a la izquierda.
- Ambos usan las mismas dimensiones: `min-h-11`, `px-4`, `py-2.5`, `text-sm font-semibold`, `rounded-lg`.
- “Cambiar chofer” conserva `primaryButton` (azul).
- “Desvincular chofer” conserva el estilo **danger outline** (`border border-danger/30`, `bg-surface-raised`, `text-danger-strong`) en una variante local `dangerButtonLarge` con las dimensiones del primario.
- En móvil (`< sm`) se apilan; en escritorio quedan en dos columnas iguales.
- El botón “Desvincular chofer” es ahora un `<button type="button">` que abre/cierra el `<details>` existente mediante un ref. El formulario de desvinculación, sus campos, la acción POST y la confirmación `data-confirm` no cambiaron. El `<summary>` queda `sr-only` para evitar un segundo control visible.
- “Cambiar chofer” sigue siendo el submit del formulario de asignación; no se modificaron campos, permisos, validaciones ni lógica.

## Archivos

- `frontend/src/pages/operations/EquipmentDetailPage.vue`
- `frontend/tests/operations/EquipmentDetailDriverActions.test.js`
- `tests/unit/Controllers/UnassignDriverContractTest.php`
- `docs/evidencia/593-botones/` (8 capturas)

## Evidencia visual

Capturas antes/después, escritorio y móvil, tema claro y oscuro:

- `docs/evidencia/593-botones/before-desktop-light.png`
- `docs/evidencia/593-botones/before-desktop-dark.png`
- `docs/evidencia/593-botones/before-mobile-light.png`
- `docs/evidencia/593-botones/before-mobile-dark.png`
- `docs/evidencia/593-botones/after-desktop-light.png`
- `docs/evidencia/593-botones/after-desktop-dark.png`
- `docs/evidencia/593-botones/after-mobile-light.png`
- `docs/evidencia/593-botones/after-mobile-dark.png`

Las capturas se tomaron en el staging aislado de `fasa_189` con la empresa demo, sobre el código del PR #596 (antes) y sobre esta rama (después).

## Verificación funcional de las acciones

Con Playwright sobre staging:

- “Desvincular chofer” abre el `<details>` sin navegar y sin enviar el formulario de asignación.
- Un segundo clic lo cierra.
- El submit del formulario de desvinculación sigue mostrando la confirmación (`data-confirm`); cancelar no navega ni modifica datos.
- “Cambiar chofer” dispara un `POST` al endpoint de asignación (`/mantenimiento/equipos/109/chofer`), interceptado en la prueba para no modificar datos.

## Pruebas

- Frontend Vitest: **98 archivos, 626 tests OK**.
- Contrato PHP de la vista: **5 tests, 30 assertions OK**.
- Suite PHPUnit: **2485 tests, 9343 assertions, 52 skipped**; único error local conocido de Windows (`symlink(): Permission denied` en `ArchivoRespaldoBucketsTest`), ajeno a este cambio.

## Alcance y pendientes

- Corrección exclusivamente visual, como parte del #593. No se crea otro issue.
- No se incluyen #594 ni #595.
- No se modifican migraciones, base de datos, permisos, validaciones ni confirmaciones.
- El despliegue a producción de este fix solo requiere los **assets** compilados (`assets/dashboard`); no hay cambios PHP ni de esquema.
- #593 permanece **abierto** hasta completar la desvinculación real de TSA y la validación posterior.
