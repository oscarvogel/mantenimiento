# Bitácora 2026-11-16 - Alertas y revisión de IA en lecturas

## Contexto

Issue #529. El caso de **RHB1B55** mostró que una detección errónea de IA (17 km
contra 507.738 km) podía quedar guardada sin una revisión humana explícita. La
alerta existía, pero no era accionable y no medía recurrencia.

## Cambios

### Revisión humana

- Migración `2026-11-16-230000_CreateReadingEvidenceReviews`: crea
  `lecturas_equipo_evidencias_revisiones`, una revisión por evidencia, con
  empresa, lectura, motivo, observación, usuario y fecha.
- Caso de uso `ReviewReadingEvidence` y puerto
  `ReadingEvidenceReviewRepository`.
- Ruta `POST mantenimiento/lecturas/{id}/evidencia/revisar`, permiso
  `lecturas.cargar`.
- Motivos: `FALSO_POSITIVO_IA`, `LECTURA_CORREGIDA`, `REVISAR_MODELO`, `OTRO`.
  `OTRO` exige observación.
- La evidencia ya revisada no se puede volver a revisar.
- La revisión queda registrada en la propia tabla, con usuario y fecha.

### Severidad y destinatarios

- `AiDiscrepancySeverityPolicy`: `ADVERTENCIA` para una discrepancia aislada;
  `CRITICA` con 3 en 30 días por equipo o 5 en 7 días por empresa.
- `RESPONSABLE DE MANTENIMIENTO` como destinatario principal; si no existe,
  `Administrador`. En una alerta crítica se incluyen ambos.
- La alerta informa la reincidencia del equipo y de la empresa.

### Calidad de IA

- `CodeIgniterAiOdometerQualityReadModel`: total de lecturas con foto,
  discrepancias con confianza >= 80%, tasa y detalle por equipo y por mes en
  los últimos 90 días.
- La pantalla de control de lecturas muestra el resumen y los equipos con más
  fallas.

### Interfaz

- La evidencia muestra el valor de IA como **“(descartado)”** cuando fue
  corregido.
- Si la evidencia tiene una discrepancia pendiente, muestra el formulario
  **“Marcar como revisada”**.
- Si ya fue revisada, muestra usuario, fecha, motivo y observación.

## Pruebas

- PHPUnit: política de severidad, caso de uso de revisión, contrato de
  migración y lectura de calidad con SQLite.
- Vitest: modal de evidencia y formulario de revisión.
- Se regeneran los bundles del dashboard.

## Pendiente

- Desplegar la migración y el código a producción.
- Validar en producción que la revisión y la alerta funcionan con datos reales.
