# Bitácora 2026-11-22 - Estado de la ingesta de telemetría y hora de Autotrac

## Análisis de la ingesta

Exportación de producción de solo lectura (empresa 4, 90 días, 22/11/2026 07:36), con el mismo exportador del 20/11
(`chore/exportador-telemetria`): helper subido, sesión `READ ONLY`, sin token ni endpoint, borrado y verificado 404.

### En vivo

- Las dos integraciones (Obersat/Wialon y Autotrac) responden: último éxito 07:31, 0 fallos, sin error.
- El cron consulta cada 15 minutos todo el día, igual que antes del corte.
- Wialon entrega 367 a 449 muestras por día desde el 19/11 (550 a 650 antes del corte). No es la ingesta: los
  equipos mandan menos mensajes nuevos entre consultas y no hay muestras repetidas.

### Recuperación del histórico (buckets)

- El cursor pasó del 11/10 13:05 (20/11) al 17/10 ~05:40 UTC (22/11): unas 3,3 h de historia por hora, el techo de
  una hora por corrida. A ese ritmo llega al corte del 23-25/10 en ~2 días, al del 08-19/11 en ~7 y se pone al día
  hacia el 03/12.
- **Los buckets no traen odómetro ni horas de motor** (0 de 6.594, igual que el 20/11). `WialonHistoricalMessageMapper`
  los lee de `p.mileage` y `p.engine_hours` de cada mensaje, que esta flota no manda: en Wialon el odómetro es un
  contador de la unidad, que el dato en vivo sí toma. Sí traen motor, ralentí, velocidad, posición y combustible (77%).
  Consecuencia: rellenar un corte recupera excesos, ralentí y cargas de combustible, pero **no** km para anclar los
  services estimados por GPS.

### Equipos (campo, no sistema)

- **NEB021 no transmite desde el 14/11 05:10 y OJG526 desde el 17/11 22:16.** La integración volvió el 19/11 y Wialon
  sigue devolviendo esas fechas como último mensaje: es el equipo.
- **Sensor del tanque desconectado** (−348.201 L) en NEB021, HWW968, JLH877 y AD738EA. JLH877 reportó tanque en el
  61% de las lecturas desde el 19/11.
- Calidad del resto: sin odómetros que retrocedan, sin velocidades imposibles, pocos saltos (0 a 10 por camión en
  90 días).

## Bug: la hora de Autotrac estaba corrida 3 h

En el 100% de las muestras de Autotrac (5 unidades, 4.329 muestras) la hora observada era posterior a la hora en
que la registramos, con mediana de +172 a +179 min y rango de +152 a +181. En Wialon la diferencia es de 0 a 9 min.
ATIC informa en UTC sin zona (`2026-11-22T10:11:41`) y `AutotracSnapshotMapper::date()` la interpretaba como hora de
Argentina.

Afectaba a todo lo que lee horas de Autotrac: última señal, horas de excesos y cortes, el día de cada muestra, la
continuidad de la serie y el ancla de los services de BEN5D63 y BEN6G63.

### Corrección

1. **Mapeo:** una fecha sin zona se interpreta como UTC y se devuelve en la zona de la aplicación, como hace Wialon.
   Una fecha con zona (`Z`, `-03:00`) se respeta. Las consultas a la API no mandan fechas, así que la ventana de
   consulta no estaba afectada.
2. **Datos guardados:** migración `2026-11-22-090000_FixAutotracObservedTimezone`, sobre `telematia_muestras` y
   `telematia_ultima_lectura`.
   - Corrige solo filas de Autotrac con la hora observada más de 60 min posterior a la registrada, algo imposible: lo
     que entre bien después del arreglo no se toca y correrla dos veces no corre nada dos veces.
   - Decide primero la hora final de cada fila y solo borra cuando dos filas terminan en la misma (la misma captura
     leída antes y después del arreglo). Una primera versión comparaba contra las horas viejas y habría borrado 24
     muestras reales separadas exactamente 3 h; lo detectó la simulación sobre los datos reales.
   - Actualiza de a una, de la más antigua a la más nueva, para no chocar con la clave única a mitad de camino.
   - Sin `down()`: una fila corregida no se distingue de una que entró bien. Para revertir está el backup previo.
3. **Simulación sobre la exportación de hoy:** 4.329 muestras y 1 última lectura a corregir, 0 duplicados reales.
   Después de corregir, la demora mediana queda en 5 min y ninguna muestra queda más de 43 s en el futuro: el desfase
   era exactamente de 3 h.

### Pruebas

- `AutotracSnapshotMapperTest` (4): UTC sin zona a hora local, zona explícita respetada, posición más reciente y fecha
  ilegible.
- `FixAutotracObservedTimezoneMigrationTest` (2): corrige solo lo imposible, borra el duplicado real, no toca Wialon
  ni filas ya bien, dos capturas reales separadas 3 h y idempotencia.
- Suite completa: 2.125 pruebas, sin fallos (51 salteadas que piden MySQL).

## Pendiente

- Probar en staging y desplegar con backup previo de `telematia_muestras` y `telematia_ultima_lectura` (Autotrac).
- Odómetro del histórico de Wialon: buscar otra fuente (contador de la unidad o distancia por posiciones).
- Acelerar la recuperación del cursor.
- Consultar con Obersat por NEB021 y OJG526, y por los tanques desconectados.
