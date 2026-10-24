# Bitácora 2026-10-08 — Deploy PR #468 (validación de evidencia de odómetro)

Cuarto deploy del día. Agrega **validación de evidencia**: no alcanza con subir
cualquier imagen; la IA debe decidir si la foto sirve como evidencia del
odómetro, y si no sirve, el registro se bloquea en el servidor.

| | |
|---|---|
| PR | #468 `feat: validar que la foto sea evidencia real del odómetro` |
| TARGET_SHA | `b310d2fa464cfd01872694c7c18af4238015ce31` |
| PROD anterior | `fae37e6c3159baabc1cd18c487d04158d2fc8fbb` (PR #467) |
| BASE | `a7a607b29f66a3397f7b7b0cf308a1e8e378fb08` (merge de #467) |
| CI | run `37827357516` — completed / success, `headSha` = TARGET |
| Archivos subidos | 4 |

A diferencia de los deploys anteriores, este **incluye una vista** con JS. Es el
primer cambio de frontend desde el arranque del circuito de foto/IA.

## Qué se desplegó

- `app/Application/Measurement/OdometerImageAnalysis.php` — el modelo gana
  `evidenceValid` e `invalidReason`.
- `app/Infrastructure/Measurement/MiniMaxOdometerImageAnalyzer.php` — el prompt
  pide `evidencia_valida` y `motivo_invalido`, con los cinco motivos:
  `NOT_DASHBOARD`, `ODOMETER_NOT_VISIBLE`, `TRIP_ONLY`, `TOO_BLURRY`, `OTHER`.
- `app/Controllers/PublicEquipmentReadings.php` — bloqueo en servidor.
- `app/Views/public_equipment/reading.php` — bloqueo en la UI.

## Verificación

### Alcance

`git diff --name-only fae37e6c..b310d2fa` = los 4 runtime + 2 tests. Coincide con
lo pedido. No se subieron tests, migraciones, assets ni `.env`.

### El bloqueo es de servidor, no solo de UI

Este era el punto que más importaba comprobar, porque un bloqueo solo en el
frontend seKindallá con un POST directo. En `store()`:

```php
if ($aiAnalysis !== null && ! $aiAnalysis->evidenceValid) {
    throw new DomainException($this->invalidEvidenceMessage($aiAnalysis->invalidReason, $locale));
}
```

Está en L269, muy antes del `transBegin()`. Es un rechazo real del servidor.

En la vista, el JS consume `payload.evidenceValid` y muestra `payload.evidenceError`
(L186/L193), y ese bloque se verificó **en los bytes descargados del servidor**,
no en el repositorio local.

### Validación estática

Comprobado archivo por archivo: las dos claves del JSON, los cinco motivos, las
reglas semánticas del prompt (>1.000.000 km, rechazo de Trip, no inventar
dígitos, borrosa/reflejada/oscura), los campos del modelo, el bloqueo de servidor
y el de la UI. Todo presente.

### `php -l` y tests

Los 4 archivos: sin errores de sintaxis.
`tests/unit/Infrastructure/Measurement/`: **13 tests, 103 assertions, OK**,
incluido el nuevo `Invalid odometer evidence is blocked server side`.

### Prueba negativa REAL (sí se ejecutó)

Se llamó al analizador de verdad contra dos imágenes que **no** son tableros,
usando la configuración existente. El script nunca imprime la API key (solo
reporta que está configurada y su longitud).

| imagen | evidenceValid | invalidReason | kilometers |
|---|---|---|---|
| captura de login | `false` | `NOT_DASHBOARD` | `NULL` |
| pantalla de reportes (con muchos números) | `false` | `NOT_DASHBOARD` | `NULL` |

El segundo caso es el interesante: una pantalla llena de cifras que una IA
descuidada podría confundir con un tablero, y la rechazó igual. La observación
devuelta fue correcta en ambos casos.

### Backup, upload y hash

Los 4 remotos coincidieron con estados conocidos (`PROD` y `BASE`) antes de
subir: no había drift. Backup en `.deploy/backup-pre-b310d2fa/`.

```
MATCH=4/4
HASH_VERIFICATION=OK
```

Revalidado al cierre: **4/4**.

### Smoke y logs

`/login` → 200. Ruta pública con token inválido → 200 controlado. Log sin
errores nuevos.

## Lo que NO se pudo verificar

**Las 3 fotos reales de Oscar no están en el workspace.** Se buscó en todo el
repositorio: lo único en formato imagen son capturas de UI, iconos de PWA y
dos importes de agosto. No se amplió la búsqueda a carpetas del usuario sin
preguntar.

Consecuencia: **`CASE_1`, `CASE_2` y `CASE_3` quedaron `NOT_RUN`**. El protocolo
condicionaba esa prueba a que las fotos estuvieran disponibles localmente, así
que no bloquea el deploy, pero significa que **el caso de uso real todavía no
fue probado contra el analizador nuevo**.

El riesgo concreto que queda abierto: que la validación sea demasiado estricta y
**rechace una foto de tablero legítima** en producción. La parte negativa está
muy probada; la positiva, no. El caso A de la prueba con teléfono es justamente
el que lo cierra.

## Pendientes

1. **Prueba con teléfono.** Casos A (foto válida → acepta), B (foto cualquiera →
   rechaza y deshabilita el botón), C (tablero sin odómetro visible), D (solo
   Trip → no tomar Trip como ODO).
2. **Si el caso A falla con un rechazo indebido**, el ajuste es del prompt del
   analizador, no del modelo ni del controlador: revisar los criterios de
   `evidencia_valida` y `TOO_BLURRY`.
3. **PR #468 sigue abierto** y `b310d2fa` no está en `main`.
4. **Nota operativa:** este deploy cambia la vista. Si el navegador cachea el
   HTML público, conviene probar en incógnito o con recarga forzada para no
   mezclar la UI vieja con la nueva.