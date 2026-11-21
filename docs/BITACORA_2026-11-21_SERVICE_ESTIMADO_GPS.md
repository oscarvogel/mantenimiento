# Bitácora 2026-11-21 - Service estimado con los km del GPS

## Contexto

PR 3 del tablero gerencial de flota (PR 1: conciliación de combustible, #550; PR 2: uso de la flota, #552).
Los choferes cargan el odómetro cada 4 a 11 días; entre lectura y lectura un camión hace miles de km y el
próximo service aparece más lejos de lo que está. Este PR estima el km actual con el GPS y muestra, por plan
con criterio de km, el estado real y el estimado lado a lado.

Igual que los anteriores: dominio, caso de uso, contratos y cableado. Sin pantalla, ruta ni permiso migrado.

## Decisiones

1. **Solo lectura** (decisión del usuario). No graba lecturas `TELEMETRIA`, no mueve el odómetro del equipo, no
   cambia el estado real del plan ni genera avisos. Grabar lecturas desde el GPS sigue en pausa desde el 09/10:
   necesita actor de sistema, nuevo origen y precedencia frente al chofer.
2. **La regla es la del preventivo.** `EvaluadorVencimiento` evalúa el plan dos veces: con el km real y con el
   estimado. El caso de uso es de Preventivo (`GetServicesEstimadosPorGps`).
3. **Solo diferencias del GPS.** El odómetro del GPS tiene otra base: en la flota de la empresa 4 la diferencia
   con el tablero va de -195 a +3.955 km según el camión. El km estimado es la lectura vigente más lo que sumó el
   GPS desde ese momento.
4. **Ancla dentro de 6 h.** El valor del GPS que corresponde a la lectura es el más cercano dentro de 6 h (a igual
   distancia, el anterior). Más lejos no se estima: el camión pudo hacer cientos de km entre uno y otro.
5. **Sin estimación, con motivo**: sin lectura vigente, lectura de más de 60 días, equipo sin odómetro de GPS, sin
   ancla, odómetro del GPS que retrocede o que salta (más de 120 km/h de media). Nunca se devuelve la lectura sola
   como si el camión no se hubiera movido. Cero km del GPS sí es un dato.
6. **Lectura vigente = la última no anulada cuyo km es el odómetro actual.** Una lectura histórica de viaje, que no
   mueve el odómetro, no sirve de base.
7. **Dos contratos nuevos** (`CONTRATOS_ENTRE_CONTEXTOS.md` §4.13 y §4.14): `LecturaKmVigenteReader` lo responde
   Medición y `OdometroGpsReader` lo responde Telemetría. Preventivo no lee sus tablas.
8. **Permiso `gerencial.flota.ver`**, como el resto del tablero.

## Validación con datos reales (exportación del 20/11)

- **Hoy se estiman 3 de 26 planes con km.** Los Autotrac sin odómetro y los equipos sin GPS no se estiman. En 13
  de los 14 Wialon la última lectura (09/11 al 17/11) cae en el corte de Wialon del 08/11 al 19/11 y no tiene GPS
  dentro de 6 h. Es el comportamiento buscado: el análisis inicial había anclado esas lecturas en el último dato
  antes del corte y sumaba km que no correspondían.
- **El corte se va a rellenar solo, pero despacio.** Con el cursor de buckets arreglado (#549), la serie histórica
  de Wialon se completa a razón de una hora de historia por corrida; en la exportación el cursor estaba en el
  11/10. Con unas cuatro corridas por hora de día, ponerse al día lleva del orden de 10 a 12 días desde el 20/11.
  A partir de ahí las lecturas nuevas tienen ancla y la estimación cubre la flota Wialon.
- **El GPS cuenta menos km que el tablero, y la diferencia crece con el uso**: entre 0 y 9% según el camión
  (AE223WN, +461 km de desfase en 4.900 km; NEB021, estable). La estimación tiende a quedarse corta: puede avisar
  tarde, pero no adelanta un service que no corresponde. Una corrección por camión es posible con el informe de
  confiabilidad (#546) y queda pendiente.

## Pruebas

- Dominio: `EstimacionKmGpsTest` (9).
- Caso de uso con puertos falsos: `GetServicesEstimadosPorGpsTest` (8). Cubre permiso, Superadministrador,
  sucursal ajena y recorte, actor sin sucursales, estado real contra estimado, planes sin km, una estimación por
  equipo, orden por urgencia y totales.
- Adaptador de Medición contra SQLite con los tipos de las migraciones: `CodeIgniterLecturaKmVigenteReaderTest` (4).

## Pendiente

- Pantalla «Tablero de flota» en Gerencial, con la migración del permiso.
- Corregir la estimación por el desfase propio de cada GPS.
- Evaluar acelerar la recuperación del cursor de buckets: hoy un atraso de semanas tarda semanas en cerrarse.
