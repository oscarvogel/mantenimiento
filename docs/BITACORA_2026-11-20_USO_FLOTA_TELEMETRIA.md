# Bitácora 2026-11-20 - Uso de la flota, excesos de velocidad y cortes de la telemetría

## Contexto

PR 2 del tablero gerencial de flota (el PR 1 fue la conciliación de combustible, #550). Igual que el anterior,
trae dominio y caso de uso, sin pantalla, ruta ni migración del permiso. Calcula por camión, en un período de
hasta 31 días:

- km, km por día y horas de motor;
- porcentaje de motor encendido con el camión detenido y porcentaje de ralentí;
- velocidad máxima y excesos de velocidad;
- último dato y estado de la señal;
- cortes de cada integración.

## Decisiones

1. **Cada indicador tiene su condición de suficiencia** (regla 17 de la spec):
   - Km y horas salen de la diferencia del odómetro y del horómetro (en décimas), y necesitan dos lecturas.
   - Los porcentajes necesitan al menos 60 min de motor medido en tramos de 30 min o menos.
   - El ralentí necesita además sensor en el 80% de ese tiempo.
   
   Sin sensor de ralentí no hay "0% de ralentí": hay null.
2. **Exceso de velocidad**: dos muestras seguidas por encima de 90 km/h. Una sola muestra alta puede ser un salto
   del GPS, y los camiones circulan gobernados cerca de 86 km/h.
3. **Corte de una integración**: la mitad o más de sus unidades, y al menos dos, sin datos a la vez durante 12 h o más.
   - Con 6 h, las noches de Autotrac parecían cortes: Autotrac no manda nada con el camión parado y Wialon sí,
     cada hora. Con media flota en la base había huecos simultáneos de 6 a 8 h.
   - El solapamiento también tiene que durar el mínimo.
   - El hueco antes de la primera muestra de una unidad no cuenta: una unidad recién vinculada todavía no existía.
4. **Un camión sin datos durante un corte abierto de su integración no es "sin reportar"**, es `CORTE_INTEGRACION`:
   el problema no es el camión.
5. **Período máximo de 31 días y cálculo unidad por unidad.** Para los cortes solo se guardan los instantes como
   enteros, no las muestras. Prueba sintética del peor caso (19 unidades × 31 días × una muestra cada 2,5 min, el
   ritmo del histórico de 5 min): 1,1 s y 42 MB de pico. Falta medir el tiempo de las consultas en Ferozo.
6. **Scoping** por `FleetTelemetryBoardReader`, el mismo lector que usa «Consumo por viaje».

## Validación con datos reales (exportación del 20/11, empresa 4)

- Km, horas de motor, motor detenido, ralentí y velocidad máxima por camión coinciden con el análisis del 20/11.
  NEB021 y JLH877 tienen el motor encendido detenido el 66% y el 53% del tiempo, y HLD283 un 30% de ralentí.
- 35 excesos de más de 90 km/h; BEN4G47 tiene 15.
- Detecta exactamente los dos cortes de Wialon: del 23/10 al 25/10 (47 h) y del 08/11 al 19/11 (263 h), con las 14
  unidades. Ninguno en Autotrac.

## Pruebas

- Dominio: `UsoEquipoTest` (7) y `DetectorCortesIntegracionTest` (6).
- Caso de uso con puertos falsos: `GetUsoFlotaTest` (7). Cubre permiso, período, sucursales, scoping por empresa,
  sin reportar contra corte de la integración y totales sin lo no medido.

## Pendiente

- PR 3: service con km del GPS.
- PR de la pantalla «Tablero de flota» en Gerencial, con la migración del permiso `gerencial.flota.ver`.
