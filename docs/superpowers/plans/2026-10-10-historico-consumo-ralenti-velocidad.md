# Telemetría de flota — histórico, consumo, ralentí y velocidad Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guardar una serie temporal de muestras de telemetría cada 15 minutos y, sobre
esa serie, calcular el combustible por trayecto en l/100 km y l/h, el porcentaje de
ralentí y los excesos de velocidad, con cobertura explícita y alertas idempotentes.

**Architecture:** `InstantaneaEquipo` ya existe en el dominio y ya lleva todos los datos
que hacen falta: odómetro, horómetro en décimas, motor, ralentí, combustible, velocidad
y posición con su hora. Este plan no inventa un vocabulario nuevo: agrega una tabla
append-only de muestras y una capa de cálculo pura que corre **sobre la serie**, no sobre
el snapshot. El intervalo de consumo y el viaje son *derivados de lectura*, nunca filas
persistidas, para que una mejora de cobertura no deje un número congelado.

**Tech Stack:** CodeIgniter 4, PHP 8.2+, MariaDB/MySQL, Vue 3, PHPUnit, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-10-alertas-ralenti-velocidad-design.md` y
`docs/superpowers/specs/2026-10-10-consumo-por-viaje-design.md`.

**Alcance ampliado el 10/10/2026:** la primera versión del diseño de ralentí y velocidad
declaraba el cálculo de consumo fuera de alcance. Ese diseño ya fue actualizado con una
sección de ampliación que lo incorpora, y este plan ejecuta los cuatro módulos en un solo
ciclo para no construir la tabla de muestras dos veces.

---

## Global Constraints

- Respetar `empresa_id` y sucursales permitidas **en servidor**, en toda lectura del
  histórico. Una muestra sin empresa no se consulta.
- La tabla de última lectura se mantiene intacta: sigue alimentando mapa y vistas
  actuales. El histórico es una tabla nueva, no una conversión del snapshot.
- El cálculo de consumo, ralentí, velocidad y cobertura es **dominio puro**: sin
  CodeIgniter, sin base, sin reloj global no inyectado.
- **Nunca copiar el valor anterior como si fuese una lectura nueva.** Un campo ausente
  queda nulo.
- Un límite vacío significa desactivado y no genera alerta.
- No inferir capacidad de tanque ni cantidad de tanques sin configuración confiable.
- No almacenar ni mostrar credenciales del proveedor.
- Nada de esto alimenta `lecturas_equipo` ni `equipos.km_actual`: el odómetro de
  telemetría es calculado por GPS y no es el odómetro físico.
- Retención de muestras: 90 días, con backup verificado antes de purgar.

## Review Focus

- **La misma fila no se puede insertar dos veces.** Una corrida repetida el mismo día
  no duplica muestras, ni alertas, ni totales.
- Un delta de combustible acompañado de una subida en otro tanque **no** es una carga.
- Un intervalo con carga **no** computa consumo.
- División por cero: intervalos sin horas de motor no producen l/h, y un `null` no se
  muestra como `0`.
- `GZD587` tiene horómetro imposible (167.548 km con 8.763 h). Debe quedar excluido del
  cómputo de l/h, no producir un ratio absurdo.
- Dos unidades sin sensor de combustible (MRCDS 2035, RENAULT GZD587) dan `Sin dato`,
  nunca 0.
- Grilla, fila de total y bloque de estadísticas **salen del mismo cálculo**. Si pueden
  discrepar, es un defecto.

## File Map

### Dominio — cálculo puro

- `app/Domain/Telematic/Consumo/IntervaloConsumo.php`: VO de un intervalo con km, litros, horas de motor, calidad y sus componentes.
- `app/Domain/Telematic/Consumo/SerieConsumo.php`: VO de intervalos ordenados de un equipo en un período.
- `app/Domain/Telematic/Consumo/ViajeSegmentado.php`: VO de un trayecto derivado, con sus intervalos.
- `app/Domain/Telematic/Consumo/ReglaSegmentacion.php`: `SerieConsumo → list<ViajeSegmentado>`.
- `app/Domain/Telematic/Consumo/PoliticaCobertura.php`: decide `CONFIABLE` / `NO_CONFIABLE` con motivo.
- `app/Domain/Telematic/Consumo/LibroConsumo.php`: agrega litros, km y horas de una lista de viajes.
- `app/Domain/Telematic/Consumo/IndicadoresConsumo.php`: l/100 km y l/h con sus guardas de división.
- `app/Domain/Telematic/Uso/UsoOperativo.php`: porcentaje de ralentí y exceso de velocidad.
- `app/Domain/Telematic/MuestraEquipo.php`: VO append-only de una muestra persistida.

### Aplicación

- `app/Application/Telematic/RegistrarMuestras.php`: persiste las muestras del ciclo de ingesta, idempotente.
- `app/Application/Telematic/PurgarMuestrasVencidas.php`: retención de 90 días.
- `app/Application/Telematic/CalcularConsumoPorViaje.php`: caso de uso de lectura con alcance del actor.
- `app/Application/Telematic/ConfigurarUsoOperativo.php`: límites por empresa.
- `app/Application/Telematic/Port/SerieMuestrasReader.php`: puerto de lectura del histórico.
- `app/Application/Telematic/Port/MuestraStore.php`: puerto de escritura idempotente.
- `app/Application/Telematic/Port/UsoOperativoConfigReader.php`: puerto de configuración.

### Infraestructura

- `app/Database/Migrations/*_CreateTelemetrySamples.php`: tabla de muestras con unicidad de ingesta.
- `app/Database/Migrations/*_CreateTelemetryUsageSettings.php`: límites por empresa.
- `app/Infrastructure/Telematic/CodeIgniterMuestraStore.php`: persistencia idempotente.
- `app/Infrastructure/Telematic/CodeIgniterSerieMuestrasReader.php`: lectura por empresa y sucursales.
- `app/Infrastructure/Telematic/CodeIgniterConsumoPorViajeReader.php`: read model de viajes y totales.
- `app/Infrastructure/Notifications/TelematicOperationalNotificationEventSource.php`: eventos de uso y consumo.
- `app/Commands/TelemetriaConsumo.php`: comando de lectura y purga.

### Presentación

- `app/Controllers/Telemetria.php` + `app/Config/Routes.php` + `app/Config/Services.php`: endpoints y composición.
- `frontend/src/pages/operations/components/TelemetryUsageBlock.vue`: bloque de uso operativo en la ficha.
- `frontend/src/pages/operations/TelemetryIntegrationSettingsPage.vue`: límites por empresa.
- `frontend/src/pages/operations/FuelConsumptionPage.vue`: consumo por viaje.

## Corte de PR

El plan se entrega en cuatro PR, en este orden. Cada uno es ejecutable por sí solo:

| PR | Contenido | Por qué se separa |
|---|---|---|
| **1** | Tareas 1 a 4 — tabla de muestras e ingesta | Es la base; sin ella no hay nada. Sin cambio de comportamiento visible. |
| **2** | Tareas 5 a 8 — dominio puro de consumo | Cero infraestructura. Se prueba sin base. Sin efecto en la interfaz. |
| **3** | Tareas 9 a 12 — config, alertas, ficha | Sólo después de que el cálculo esté probado. |
| **4** | Tareas 13 a 15 — pantalla de consumo por viaje | Lee lo que ya existe. |

---

## Tasks

### 1. Tabla de muestras

- [ ] Escribir prueba de migración que falle si la tabla no existe o si su unicidad no impide una captura repetida.
- [ ] Ejecutar y confirmar fallo por contrato ausente.
- [ ] Crear migración reversible con: `empresa_id`, `integracion_id`, `equipo_id`, `unidad_externa`, `observada_en`, `registrada_en`, y las columnas canónicas de `InstantaneaEquipo` (`kilometraje`, `horas_decimales`, `motor_encendido`, `ralenti_activo`, `voltaje`, `combustible_litros`, `latitud`, `longitud`, `velocidad_kmh`, `satelites`) más `sensores_json`.
- [ ] Índice único sobre `(integracion_id, unidad_externa, observada_en)`.
- [ ] Índices de lectura sobre `(empresa_id, equipo_id, observada_en)`.
- [ ] Ejecutar migraciones desde cero y verificar `down()`.

### 2. VO de muestra y puertos

- [ ] Escribir prueba pura de `MuestraEquipo`: rechaza kilometraje, horómetro, voltaje y combustible negativos; mueve los imposibles a `anomalias` en vez de propagarlos.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar el VO reutilizando las reglas ya escritas en `InstantaneaEquipo`.
- [ ] Definir `MuestraStore` y `SerieMuestrasReader`; los puertos pasan identificadores enteros, nunca credenciales.
- [ ] Pruebas de contrato de los puertos con dobles.

### 3. Persistencia idempotente

- [ ] Escribir prueba que falle si el mismo `(integracion, unidad, observada_en)` se inserta dos veces.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar `CodeIgniterMuestraStore` con inserción que ignora duplicados por la clave única, no con `select` previo.
- [ ] Pruebas de integración con MariaDB real: la semántica de `INSERT IGNORE` / `ON DUPLICATE` difiere de SQLite y hay que probarla en la base verdadera.
- [ ] Verificar que una muestra con campos nulos los guarda como nulos y no como el valor anterior.

### 4. Cablear la ingesta

- [ ] Escribir prueba de `RegistrarMuestras` que falle si el ciclo refresca y no persiste muestra.
- [ ] Ejecutar y confirmar fallo.
- [ ] Cablear en `RecordTelemetrySnapshots`: **además** del snapshot actual, persistir la muestra. El snapshot no se toca.
- [ ] Verificar que reejecutar el cron dos veces no agrega muestras ni duplica alertas.
- [ ] Verificar que un sensor definido sin valor guarda la definición con `valor = null` y no crea una avería.
- [ ] Implementar `PurgarMuestrasVencidas` a 90 días, con backup verificado antes de borrar.
- [ ] Ejecutar la suite de telemetría.

### 5. Intervalos y atribución de litros

- [ ] Escribir pruebas puras de `IntervaloConsumo` con series de fixtures: descenso, ascenso, delta chico, sensor ausente, hueco.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar: `litros = combustible(desde) − combustible(hasta)` **sólo si no hay carga en el intervalo**.
- [ ] Ascenso por encima del umbral → evento `carga`, no consumo.
- [ ] Delta por debajo del mínimo configurable → `SIN_VARIACION`, no `0`.
- [ ] Delta negativo grande → `CARGA`, nunca consumo negativo.
- [ ] Transferencia entre tanques: subida en un tanque con bajada en el otro **no** es carga. Sólo si los tanques son identificables; si no, se opera sobre la suma y no se afirma que no hubo carga.
- [ ] Ejecutar pruebas.

### 6. Segmentación de viajes

- [ ] Escribir pruebas puras de `ReglaSegmentacion`: apertura, cierre, desplazamiento menor al mínimo, aislamiento entre equipos y entre períodos.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar sobre `Posicion::estaEnMovimiento()` y `motorEncendido`, ambos ya existentes.
- [ ] Un viaje es un grupo **contiguo** de intervalos. Sin solapamientos.
- [ ] Un viaje más corto que el intervalo de muestreo no se reconstruye: se informa como no detectable.
- [ ] Ejecutar pruebas.

### 7. Cobertura

- [ ] Escribir pruebas de `PoliticaCobertura`: 100 % con huecos cortos, cobertura menor al 70 %, hueco mayor a 30 minutos, fuente atrasada completa.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar con los umbrales del diseño (30 min de hueco, 70 % de cobertura) **en el mismo servicio de dominio**, no duplicados en el adaptador.
- [ ] Extender cada estado hasta la siguiente muestra válida; no inventar duración para cruzar un hueco.
- [ ] Ejecutar pruebas.

### 8. Indicadores l/100 km y l/h

- [ ] Escribir pruebas de `IndicadoresConsumo` con todas las guardas: km cero, horas de motor cero, ambos nulos, ninguno cero.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar `litrosPor100Km` y `litrosPorHoraMotor` sin división por cero en ningún camino.
- [ ] Excluir del cómputo de l/h las unidades con horómetro imposible (GZD587).
- [ ] Probar que la misma serie produce el mismo resultado en la grilla, en el total y en las estadísticas.
- [ ] Ejecutar pruebas.

### 9. Lectura de consumo con alcance

- [ ] Escribir pruebas de alcance: sin empresa, sin sucursales, sucursales autorizadas, aislamiento entre empresas.
- [ ] Ejecutar y confirmar los fallos esperados.
- [ ] Crear `CalcularConsumoPorViaje` con puertos de lectura y política de actor.
- [ ] Implementar el read model con scope SQL; agrupar y paginar en la consulta, no trayendo la serie completa.
- [ ] Pruebas de aplicación y de adaptador.

### 10. Ralentí

- [ ] Escribir pruebas del cálculo: minutos de ralentí / minutos con motor encendido, con extensiones hasta la siguiente muestra válida.
- [ ] Ejecutar y confirmar fallo.
- [ ] Implementar `UsoOperativo` con porcentaje, horas estimadas, horas consideradas, período y cobertura.
- [ ] Período inicial de 7 días, con opción de 14 y 30. Límite porcentual vacío = desactivado.
- [ ] Ejecutar pruebas.

### 11. Velocidad

- [ ] Escribir pruebas: dos muestras consecutivas sobre el límite activan exceso; una sola no.
- [ ] Ejecutar y confirmar fallo.
- [ ] Una interrupción de señal o un intervalo mayor a 30 minutos reinicia la secuencia.
- [ ] Mostrar la velocidad de la última muestra con su antigüedad.
- [ ] Ejecutar pruebas.

### 12. Configuración por empresa

- [ ] Escribir pruebas de aislamiento: los límites de una empresa no son visibles ni aplicables para otra.
- [ ] Ejecutar y confirmar fallo.
- [ ] Migración reversible para porcentaje de ralentí, período, velocidad máxima y
  [los umbrales de atribución: delta mínimo de litros, umbral de carga, desplazamiento
  mínimo y duración mínima de viaje].
- [ ] Límite vacío = desactivado, y la interfaz dice «Sin configurar».
- [ ] Ejecutar pruebas.

### 13. Alertas idempotentes

- [ ] Escribir prueba que falle si dos corridas del mismo día generan dos notificaciones para el mismo equipo y regla.
- [ ] Ejecutar y confirmar fallo.
- [ ] Publicar por el circuito existente: una por equipo y regla por día, con enlace a la ficha.
- [ ] Una mejora de datos **no** debe generar alerta nueva.
- [ ] El evento informa período, límite, valor medido y cobertura.
- [ ] Agregar el resumen de consumo al informe diario sin romper el resumen semanal.
- [ ] Ejecutar pruebas.

### 14. Bloque de uso operativo en la ficha

- [ ] Escribir pruebas de vista para cada estado distinguido: valor reciente, estado excedido, sin configurar, sin dato y datos insuficientes.
- [ ] Ejecutar y confirmar el contrato visual ausente.
- [ ] Agregar el bloque a la ficha: resumen de ralentí del período, estado de velocidad, y consumo en l/100 km y l/h.
- [ ] **No mostrar cero como resultado** cuando no hay dato.
- [ ] Mostrar siempre la hora de captura y la del proveedor.
- [ ] Agregar los controles de configuración a Administración → Integraciones → Telemetría.
- [ ] Verificar escritorio y móvil con evidencia; no afirmar aceptación visual sin captura.
- [ ] Ejecutar pruebas frontend.

### 15. Pantalla de consumo por viaje

- [ ] Escribir pruebas de la grilla: totales, cobertura por viaje, viajes no confiables marcados, períodos vacíos.
- [ ] Ejecutar y confirmar fallo.
- [ ] Rejilla por viaje con fecha, hora de inicio, km, litros, l/100 km, l/h, cobertura y estado.
- [ ] Fila de total **sin** horario agregado: los kilómetros y litros van por viaje; la fila total no inventa una hora de inicio y fin sobre varios días.
- [ ] Filtros por equipo, sucursal y período, paginados en servidor.
- [ ] Estado vacío: «Sin datos suficientes», sin inventar métricas.
- [ ] Contraste con la flota: banda 33 a 41 l/100 km del plan de integración.
- [ ] Ejecutar pruebas frontend y build.

### 16. Verificación integral

- [ ] Suite PHPUnit completa; comparar contra la línea de base conocida antes de atribuir un fallo a este trabajo.
- [ ] Suite Vitest completa y build del frontend.
- [ ] Migraciones desde cero y `down()` verificado.
- [ ] Pruebas HTTP de ruta, permiso, empresa y sucursal.
- [ ] Verificar que un token inválido del proveedor produce error registrado y **no** una lista vacía que se vea como flota sin datos.
- [ ] Revisar el diff: scoping tenant, migraciones reversibles, privacidad del token, responsive.

## Verification Commands

- `wsl php vendor/bin/phpunit --filter Telematic`
- `wsl php vendor/bin/phpunit --filter Consumo`
- `wsl php vendor/bin/phpunit` (completa)
- `cd frontend && npm test -- --run`
- `cd frontend && npm run build`
- `cd frontend && npm run verify:build-sync`

El entorno remoto de prueba sólo se utilizará si se solicita ejecutar o desplegar en
staging; su único destino permitido es Coolify en `fasa_189`.

## Plan Self-Review

- El histórico es append-only y la última lectura sigue siendo estado actual: no se
  convierte el snapshot en historial ni al revés.
- Los límites de PR dejan el cálculo puro aislado de la persistencia y de la interfaz.
- El viaje no se persiste; se deriva, de modo que una mejora de cobertura se refleja
  sin dejar números viejos.
- Los números de consumo se marcan como diagnóstico operativo. El odómetro de Wialon
  es calculado por GPS y no tiene valor contable.
- La tasa de consumo en ralentí **no está medida** y por eso el parámetro nace vacío:
  sin configurar, se muestra la proporción de tiempo, que sí es medible.
- Grilla, total y estadísticas comparten un único cálculo, con prueba que lo verifica.
- Los límites vacíos no generan alerta, y ninguna regla por equipo o sucursal en esta
  versión.