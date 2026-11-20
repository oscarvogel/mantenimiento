# Bitácora 2026-11-20 - Conciliación de combustible: declarado vs. telemetría

## Contexto

Gerencia quiere comparar las cargas de combustible que **declara el chofer** (módulo Viajes,
`viaje_operativo_combustible`) con lo que **mide el sensor del tanque** (Telemetría, `telematia_muestras`).
Hoy ninguna pantalla lo hace. La pantalla «Consumo por viaje» de Telemetría **no** usa lo declarado:
también calcula sobre la telemetría, y su "viaje" es cada tramo de manejo detectado en las muestras,
no el viaje operativo.

Este es el PR 1 del tablero gerencial de flota: dominio, caso de uso y contrato. No tiene pantalla,
ruta ni permiso migrado; eso llega en el PR de la pantalla.

## Análisis previo (exportación de producción, empresa 4, 12/10 al 20/11/2026)

- 19 camiones con telemetría: 14 Wialon (Obersat) y 5 Autotrac. Autotrac no manda combustible.
- El sensor de tanque de Wialon suma dos tanques (T1 + T2). Cinco camiones tienen la entrada
  desconectada en más del 10% de las lecturas.
- **El sensor puede subir y volver con el camión parado.** HWW968 el 25/10, detenido y con el motor
  apagado: 276 L a las 09 h, 337 L a las 14 h y 277 L a medianoche. No hubo carga.
- La integración Wialon no grabó datos del 23/10 ~10 h al 25/10 ~09 h (47 h) ni del 08/11 ~10:30 al
  19/11 ~08:30 (262 h).
- **Las cargas se declaran al volver del viaje**, todas juntas: en el viaje 21 (JLH877) las 8 cargas
  tienen hora entre 18:41 y 18:48 de días distintos. La hora declarada no sirve para emparejar.

## Decisiones

1. **Comparación por viaje, no carga por carga.** Por la hora declarada (ver arriba).
2. **Detector de cargas propio, sobre el nivel.** `IntervaloConsumo` compara muestra contra muestra:
   sirve para repartir consumo en el tiempo, pero parte una carga de dos surtidores en escalones y cuenta
   una lectura suelta mala como carga. `DetectorCargasCombustible` usa medianas de tres lecturas, junta
   los escalones dentro de 6 h tomando el **pico** del nivel y exige que el nivel siga arriba entre 4 y 8 h
   después si el camión no se movió.
   - Tomar el pico y no la última posición de la ventana es deliberado: con la última, un camión que
     carga y sale a la ruta tiene la carga subestimada en lo que ya consumió, y lo declarado parece mayor
     que lo medido.
   - El inicio de la carga es la última lectura antes de que el nivel suba la mitad de la carga, no el
     borde de la ventana que la alcanzó (eso la adelantaba hasta 6 h).
3. **Sin veredicto si la telemetría no vio el viaje completo** (regla 17 de la spec). `SIN_DATOS_SUFICIENTES`
   con motivo si no hay muestras, si el sensor reporta en menos del 80% de las lecturas, si hay un hueco de
   más de 6 h (pudo haber una carga invisible) o si falta el nivel al salir o al volver.
4. **Un viaje en curso no se compara** (`EN_CURSO`): las cargas se declaran al volver. Se muestra lo medido.
5. **Tolerancia de 15%** sobre lo detectado: el surtidor y el nivel del tanque no miden igual.
6. **Los totales solo suman viajes con veredicto.** Sumar los litros de un viaje sin telemetría contra cero
   litros detectados inflaría la diferencia de toda la flota.
7. **Contrato `ViajesDeclaradosReader`** (registrado en `CONTRATOS_ENTRE_CONTEXTOS.md` §4.12): Telemetría no
   lee las tablas de Viajes; el adaptador vive en `App\Infrastructure\Trips`.
8. **Permiso `gerencial.flota.ver`**: el caso de uso ya lo exige. La migración que lo crea y lo asigna a
   Administrador y Gerencial va con la pantalla.

## Validación con datos reales

El dominio se corrió sobre la exportación de producción:

- El detector encuentra **82 cargas** en 11 camiones, las mismas que el análisis previo, y ninguna el 25/10
  en HWW968.
- **Los 30 viajes operativos están `EN_CURSO`**: hoy ninguno tendría veredicto. Además, casi todos caen en
  `SIN_DATOS_SUFICIENTES` por el corte de Wialon del 08/11 o porque el camión es Autotrac.
- El viaje 21 (JLH877, 2.812 L declarados en 8 cargas) da `SIN_DATOS_SUFICIENTES`: el sensor reportó en el 47%
  de las lecturas. Es justamente el caso que no se debe imputar al chofer.
- Un viaje simulado de AB499OK del 26/10 al 03/11, con datos completos, da veredicto con 1.134 L detectados y
  31,2 L/100 km medidos.

## Pruebas

- Dominio: `DetectorCargasCombustibleTest` (8) y `ConciliacionCombustibleViajeTest` (9).
- Caso de uso con puertos falsos: `GetConciliacionCombustibleTest` (10). Cubre permiso, Superadministrador,
  período, sucursales propias y ajenas, scoping de la serie por empresa, totales y zona horaria.
- Adaptador contra SQLite con los tipos de las migraciones: `CodeIgniterViajesDeclaradosReaderTest` (4).
- Suite completa: 2.058 pruebas, sin fallos (49 salteadas que piden MySQL).

### Corrección de paso: test del cursor de buckets contra MySQL

`CodeIgniterTelemetryBucketStoreTest` fallaba contra MariaDB real también en `main`: armaba la tabla solo con
`CreateTelemetryBuckets` y el adaptador ya graba `mensajes_fuente_json`, que agregó
`AddRawWialonMessagesToTelemetryBuckets`. Dentro de la transacción el error de columna no lanza excepción y
la transacción se revierte. Ahora el test aplica las dos migraciones. Con eso pasa contra MariaDB 10.11, y
falla si se reintroduce el bug del cursor de #549, que queda confirmado contra una base real.

### Hallazgo previo, fuera de este PR: zonas horarias mezcladas en la serie

`CodeIgniterBucketSerieMuestrasReaderTest::testElLectorCompuestoConservaBucketsFueraDelSolapamientoDePuntos`
falla contra MySQL real también en `main` (normalmente se saltea porque pide `TELEMETRIA_BUCKETS_TEST_DSN`). El
lector compuesto devuelve cada muestra en su zona de origen: las de `telematia_muestras` en hora de Buenos Aires
y los buckets en UTC. El orden no se rompe (compara instantes), pero una hora formateada sin `setTimezone` sale
corrida 3 h. `GetConciliacionCombustible` ya normaliza todas las horas a la zona del reloj del sistema y tiene un
test para eso; el lector se corrige aparte.

## Pendiente

- PR 2: uso de la flota, excesos de velocidad y estado de la telemetría.
- PR 3: service con km del GPS.
- PR de la pantalla «Tablero de flota» en Gerencial, con la migración del permiso.
