# Telemetría de flota Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar una pantalla de telemetría de flota con mapa, estado por unidad y problemas vigentes de sensores; incluir esos problemas en el resumen diario.

**Architecture:** La lectura de flota será un caso de uso con un puerto de lectura y un adaptador CI4 que limita datos por empresa y sucursales autorizadas. Las anomalías vigentes se persistirán junto al snapshot actual y se expondrán como una lectura explícita para la pantalla y el resumen diario. La interfaz renderizada en Vue usará Leaflet con OpenStreetMap; no consultará al proveedor al abrirse.

**Tech Stack:** CodeIgniter 4, PHP, MariaDB/MySQL, Vue 3, Leaflet, OpenStreetMap, PHPUnit, Vitest.

**Spec:** `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` §§ 4.2, 8, 9, 10, 13 y 18; diseño aprobado de “Telemetría de flota”.

## Global Constraints

- Respetar empresa y sucursales permitidas en servidor para todo read model.
- Mantener dependencias hacia dentro: Presentation → Application → Domain.
- No leer al proveedor al renderizar una pantalla; mostrar snapshot vigente y su antigüedad.
- No inferir capacidad ni cantidad de tanques sin configuración confiable.
- Persistir solo el estado más reciente de telemetría; el histórico temporal queda fuera de este alcance.
- No almacenar ni mostrar credenciales del proveedor.

## Review Focus

- El alcance de sucursales debe negar listas vacías y no filtrar solo en el navegador.
- Valores imposibles no deben llegar al resumen como lecturas válidas ni borrarse de la alerta.
- Una corrida sin anomalías debe limpiar las anomalías anteriores del snapshot vigente.
- El mapa debe tolerar equipos sin coordenadas, sin ubicación, con señal vieja y viewport angosto.
- Un único valor de combustible no debe mostrarse junto a un porcentaje o capacidad inventados.

## File Map

- `app/Database/Migrations/*TelemetrySensorAnomalies.php`: agrega anomalías a la tabla actual.
- `app/Infrastructure/Telematic/CodeIgniterTelemetrySnapshotStore.php`: persiste anomalías de la última señal.
- `app/Application/Telematic/Port/FleetTelemetryBoardReader.php`: define lectura por empresa/sucursales.
- `app/Application/Telematic/GetFleetTelemetryBoard.php`: aplica alcance del actor y devuelve datos de pantalla.
- `app/Infrastructure/Telematic/CodeIgniterFleetTelemetryBoardReader.php`: consulta equipos vinculados y snapshots con scope SQL.
- `app/Controllers/Telemetria.php`, `app/Config/Routes.php`, `app/Config/Services.php`: endpoint/página protegida y composition root.
- `frontend/src/pages/operations/FleetTelemetryPage.vue`: filtros, mapa, anomalías y lista de equipos.
- `frontend/src/pages/operations/components/FleetTelemetryMap.vue`: ciclo de vida Leaflet y marcadores.
- `frontend/src/pages/operations/components/TelemetryBoard.vue`: retira capacidades/tanques no configurados y mantiene total de litros.
- `app/Application/Notifications/Port/TelemetrySensorIssueSummaryReader.php`: puerto explícito para que el informe diario consuma problemas activos.
- `app/Application/Notifications/ScheduleManagementReports.php` y adaptador correspondiente: agrega cantidad/detalle acotado de problemas al informe diario.
- Pruebas PHP y frontend correspondientes; `assets/dashboard` se actualiza mediante build.

## Tasks

### 1. Capturar y leer la anomalía vigente

- [ ] Escribir prueba de persistencia que falle si `anomalias()` no queda serializada en el snapshot vigente.
- [ ] Ejecutar la prueba y confirmar fallo por contrato ausente.
- [ ] Agregar migración reversible y guardar etiqueta, valor, motivo y firma de cada lectura imposible como JSON.
- [ ] Verificar que una instantánea nueva sin anomalías borre las alertas antiguas.
- [ ] Ejecutar pruebas de persistencia y aplicación de snapshots.

### 2. Caso de uso y adaptador de lectura de flota

- [ ] Escribir pruebas para alcance sin empresa, sin sucursales, sucursales autorizadas y aislamiento entre empresas.
- [ ] Ejecutarlas y confirmar los fallos esperados.
- [ ] Crear puerto y caso de uso que acepten empresa y sucursales autorizadas.
- [ ] Implementar consulta server-side de equipos activos vinculados, última señal, estado, sensores y alertas, agrupados por equipo.
- [ ] Ejecutar las pruebas del caso de uso y del adaptador.

### 3. Notificación diaria

- [ ] Escribir prueba del informe diario con problemas activos y otra sin datos/anomalías.
- [ ] Ejecutar prueba y confirmar que actualmente falta la sección.
- [ ] Implementar puerto de lectura de incidencias de sensor activas y añadir al informe diario conteo, equipos y enlace a telemetría.
- [ ] Mantener el resumen semanal sin duplicar ni crear alertas por unidad individual.
- [ ] Ejecutar pruebas del informe diario y de su adaptador.

### 4. Pantalla Vue y navegación

- [ ] Escribir pruebas del mapa/lista, filtros, equipos sin coordenadas y tarjeta vacía de anomalías.
- [ ] Ejecutarlas y confirmar el contrato visual aún ausente.
- [ ] Agregar Leaflet y el plugin de agrupación; construir el mapa con marcadores accesibles y atribución de OpenStreetMap.
- [ ] Implementar filtros de búsqueda, sucursal, estado y “solo con problemas”; conectar al payload real.
- [ ] Retirar de la ficha actual los 780 l y capacidades 550/230 no configuradas; conservar lectura de litros y mostrar porcentaje solo si existe capacidad real.
- [ ] Añadir navegación con permiso `equipos.ver`, empty/loading/error states y links a la ficha del equipo.
- [ ] Ejecutar pruebas frontend.

### 5. Integración y verificación

- [ ] Agregar pruebas HTTP de ruta y autorización para la pantalla.
- [ ] Ejecutar suite PHPUnit y Vitest completas, lint disponible, build y sincronización de assets.
- [ ] Revisar diff y comprobar los filtros de empresa/sucursal, migración reversible, privacidad del token y responsive.
- [ ] Registrar evidencia y limitaciones; no desplegar ni subir a GitHub como parte de esta autorización.

## Verification Commands

- `wsl php vendor/bin/phpunit --filter Telemetry`
- `cd frontend && npm test -- --run`
- `cd frontend && npm run build`
- `cd frontend && npm run verify:build-sync`

El entorno remoto de prueba solo se utilizará si se solicita ejecutar o desplegar en staging; su único destino permitido es Coolify en `fasa_189`.

## Plan Self-Review

- El alcance se limita al estado actual y al informe diario; no promete evolución temporal ni cálculo de consumo.
- Los accesos nuevos comparten `equipos.ver` y aplican alcance de empresa/sucursales en servidor.
- La migración mantiene la última tabla como estado actual, no convierte el snapshot en historial.
- La UI no inventa capacidad/porcentaje ni desglose de tanques.
