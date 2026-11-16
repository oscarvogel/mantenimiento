# Bitácora 2026-11-16 - Detección IA de odómetro

## Caso

Lectura de **RHB1B55** del **16/11/2026 08:21**:

- La foto muestra **507.738,4 km**.
- El operador confirmó **507.738 km**.
- La IA detectó **17 km** con **92% de confianza**.
- El método quedó como **Foto - IA corregida**.

La lectura confirmada es correcta. El problema es la detección de la IA: no hay
un “17” visible en la foto. El valor se guardó como detección cruda y la pantalla
lo mostraba sin indicar que había sido descartado.

## Causa

1. El modelo de visión devolvió un valor implausible (17 km) con alta confianza.
2. El flujo público precargaba ese valor en el campo de kilometraje.
3. El sistema conservaba la detección cruda, pero no la marcaba como descartada.
4. La alerta de discrepancia solo buscaba usuarios con rol **Responsable de
   mantenimiento**. Si la empresa no tiene ese rol asignado, no se generaba
   destinatario y la alerta no salía.

## Cambios

- **Regla de plausibilidad** en `app/Domain/Measurement/AiOdometerPlausibility.php`:
  una detección menor al kilometraje actual del equipo se descarta.
- **Flujo público** (`PublicEquipmentReadings`): si la detección se descarta, no
  se precarga, se pide carga manual y se conserva el valor crudo para auditoría.
- **Evidencia**: la pantalla de lectura y el control de lecturas muestran
  **“(descartado)”** junto al valor de la IA cuando el método es
  `FOTO_IA_CORREGIDA`.
- **Alerta de discrepancia**: si no hay Responsable de mantenimiento, ahora cae
  en los usuarios con rol **Administrador**.
- **Pruebas**: política de plausibilidad y etiqueta de evidencia.

## Evidencia de pruebas

- PHPUnit completo: 1820 pruebas, 7206 aserciones, 45 skipped, 0 fallos.
- Vitest completo: 85 archivos, 478 pruebas, 0 fallos.
- `BUILD_SYNC=OK`.
- `php -l` sin errores en el controlador y el dominio.

## Publicación

- PR **#528** mergeado a `main` (`9a341171`).
- Deploy incremental a Ferozo: **28 archivos**, 0 migraciones, `UPLOAD=OK`.
- Smoke tests: login 200, `favicon.ico` 200, `.env`/`app/`/`vendor/`/`writable/`
  403, `migrate.php` 404.
- Estado productivo: `.deploy/production.json` → `9a341171`.
- Bundle servido: `main-XNNWqsIi.js`; contiene la etiqueta `(descartado)`.

## Pendiente

- Evaluar una segunda pasada de OCR o un modelo más preciso para odómetros.
- Revisar en producción que la alerta de discrepancia llegue al Administrador
  cuando no exista Responsable de mantenimiento.
