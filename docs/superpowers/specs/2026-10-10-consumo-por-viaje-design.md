# Consumo por viaje — Diseño

**Estado:** Propuesta para revisión de producto
**Contexto:** Telemetría de flota, sobre la tabla de muestras que introduce
`2026-10-10-alertas-ralenti-velocidad-design.md`
**Antecedente externo:** reporte «Consumo por viaje» de Observat (captura del 10/10/2026)

---

## 1. Objetivo

Explicar el combustible de cada trayecto con la trazabilidad del resto del sistema:
de dónde sale cada litro, cuánta confianza tiene, y qué parte se explica por el
camión parado. Sin depender de que alguien marque el viaje, y sin presentar
estimaciones como si fueran mediciones.

## 2. Qué hace Observat y por qué no alcanza

El reporte que aparece en la captura trae una grilla por agrupación diaria con
`Comienzo`, `Kilometraje`, `Total consumido` y `Fin`, más un bloque de estadísticas. Es la
misma forma que queremos. Tres cosas lo hacen básico, y las tres se pueden
corregir:

| Observat | Problema | Nosotros |
|---|---|---|
| Los límites del viaje (`Comienzo` / `Fin`) hay que definirlos | Nadie los carga a mano, o quedan mal | El viaje se segmenta solo desde la serie |
| El total de la grilla y el de estadísticas **no coinciden** | Ver abajo | Un solo cálculo, tres vistas |
| `Total-row` agrega `06:33` y `17:16` sobre 4 días distintos | Es el mínimo y el máximo, no un total | El total no inventa una hora que no existe |
| Muestra `134 l` sin más contexto | Parece exacto | Muestra cobertura, huecos y por qué |

**La diferencia aritmética es verificable en la propia captura.** Período del 01 al
10 de octubre, IVECO 360 AA569RM:

| | Grilla de viajes | Bloque de estadísticas |
|---|---|---|
| Kilometraje | **917 km** (224 + 151 + 265 + 277) | **926 km** |
| Litros consumidos | **511 l** (134 + 78 + 174 + 125) | **551 l** |
| Consumo | — | 59 l/100 km |

Dos conjuntos de datos distintos para el mismo período, en la misma pantalla. El
bloque de estadísticas es internamente coherente (551 / 926 = 59 l/100 km y 926 /
551 = 1,68 km/l), pero **no es el mismo período que la grilla**: sobran 40 litros y
9 kilómetros sin explicación. A eso se suma un tercer número, `Total litros
cargados 299 l`, que no reconcilia con ninguno de los dos.

Además, 59 l/100 km es aproximadamente el doble de lo razonable para un tractor
de 360 CV cargado. Con una sola captura no se puede determinar si el desvío está en
los kilómetros, en los litros o en el criterio de atribución — y esa indeterminate
es justamente lo que el reporte no señala.

## 3. Punto de partida real

| Pieza | Estado | Dónde |
|---|---|---|
| `telematia_ultima_lectura` | Snapshot, se sobrescribe. **Sin serie temporal** | migración `2026-10-09-145500` |
| Plan de telemetría de flota | «Persistir solo el estado más reciente; el histórico temporal queda fuera de este alcance» | `plans/2026-10-10-telemetria-de-flota.md` L19, L99 |
| Diseño de ralentí y velocidad | Crea la tabla de muestras cada 15 min y **ya guarda `combustible`** entre las métricas normalizadas. Excluía el cálculo de consumo; **el 10/10/2026 se incorporó** | `specs/2026-10-10-alertas-ralenti-velocidad-design.md`, secciones «Captura e histórico» y «Ampliación del 10/10/2026» |
| Plan de implementación | Secuencia en cuatro PR para histórico, consumo, ralentí y velocidad | `plans/2026-10-10-historico-consumo-ralenti-velocidad.md` |

**Estado de implementación al 10/10/2026:** el mapa de flota y las alertas de sensores
están desplegados. El cálculo de ralentí y velocidad, y el de consumo de este documento,
**siguen especificados y no implementados**: no existe todavía la tabla de muestras.

**Conclusión:** la base no hay que discutirla. La muestra cada 15 minutos con
kilometraje, horómetro, motor, ralentí, voltaje y combustible ya está especificada,
junto con las reglas de hueco de 30 minutos y cobertura mínima del 70 %. Lo que
falta es la capa de agregado: convertir intervalos en viajes y litros en un número
explicable.

Por eso esto **no es un módulo nuevo con su propio almacenamiento**. Es una lectura
sobre la tabla que el diseño de ralentí ya crea. Sin esa tabla, no hay nada.

## 4. La unidad real es el intervalo, no el viaje

Decisión de diseño: **no se persiste el viaje como entidad.**

Un viaje es una hipótesis sobre la serie de muestras. Si se guarda, se congela una
interpretación que hay que corregir cada vez que mejora la cobertura o llega un
retardo del proveedor, y esa corrección no tiene dónde esconderse. El viaje se
**deriva** en el momento de leer, y por eso siempre es consistente con las muestras
que lo sostienen.

El dato que sí tiene identidad propia es el **intervalo**:

```
IntervaloConsumo
  equipo_id · desde · hasta
  km          = odómetro(hasta) − odómetro(desde)
  litros      = combustible(desde) − combustible(hasta)     // solo si no hay carga
  movimiento  = velocidad máxima del intervalo
  motor       = encendido / apagado / sin dato
  ralentí     = minutos estimados / sin dato
  calidad     = COMPLETO | HUECO | SIN_COMBUSTIBLE
```

`ConsumoPorIntervalo` es un value object puro: sin CodeIgniter, sin base, sin reloj
global. La cobertura se decide con los mismos umbrales del diseño de ralentí, en el
mismo servicio de dominio. No se inventan duraciones para cruzar un hueco.

## 5. Segmentación de viajes

Regla pura, sin dependencias, evaluable sobre la serie de intervalos:

- **Apertura:** motor encendido **y** desplazamiento por encima del umbral en un intervalo.
- **Cierre:** motor apagado, o desplazamiento nulo durante N intervalos consecutivos.
- **Un viaje es un grupo contiguo de intervalos.** No hay viajes solapados.
- **Un desplazamiento menor al mínimo no es un viaje:** es una maniobra.
- **Un viaje más corto que el hueco de muestreo no se reconstruye.** No existe: con
  muestras cada 15 minutos, un trayecto de 8 minutos ocupa cero o una muestra. Se
  informa como no detectable en lugar de estimarlo.

El umbral de movimiento y el mínimo de duración se configuran por empresa, igual que
el límite de ralentí.

## 6. Atribución de litros — la parte difícil

Cuatro reglas, en este orden. Cada una existe porque sin ella el número miente:

1. **Un descenso es consumo. Un ascenso es carga.** Nunca semixed: un delta negativo
   grande no es un llenado, y un delta positivo no es consumo.
2. **Un intervalo con carga no computa consumo.** El ascenso se registra como evento
   `carga` con su propio origen. Sumar subida y bajada en el mismo intervalo produce
   el doble error, y es la causa más probable de la discrepancia 511 / 551.
3. **Ruido de sensor:** un delta por debajo del mínimo configurable se informa como
   `sin variación`, no como 0 l consumidos.
4. **Dos tanques, sólo si se pueden separar.** La bitácora documenta dos entradas
   analógicas (550 l y 230 l). Una subida en un tanque con una bajada en el otro es
   una **transferencia**, no una carga. Si los tanques no son identificables de forma
   confiable, se opera sobre la suma y no se afirma que no hubo carga.

**La capacidad no se infiere.** El plan de flota ya decidió retirar los 780 l y las
capacidades 550/230 de la ficha mientras no exista configuración confiable. El mismo
criterio aplica acá: sin capacidad configurada, se informa litros y no porcentaje.

El odómetro que se usa es el del intervalo, no el que carga el chofer. La bitácora lo
validó contra 13 unidades con una diferencia máxima de 0,3 %.

## 7. Ralentí como explicación, no como alerta aparte

Esta es la diferencia de producto central, y sale gratis del diseño de ralentí.

Un `Consumo por viaje` que sólo dice «este viaje gastó 134 l» no ayuda a nadie.
Uno que dice **cuántos de esos 134 litros se quemaron con el camión parado** sí:

| Dato del viaje | Lectura para el operador |
|---|---|
| 224 km · 134 l · 55 l/100 km | El número, con su cobertura |
| 18 l estimados en ralentí | Cuánto es reversible sin tocar el motor |
| 96 min de motor, 31 min de ralentí | 32 % del tiempo parado |

**Restricción honesta:** la tasa de consumo en ralentí **no está medida**. Nadie tiene
ese número. Por eso el parámetro nace **vacío y desactivado**, igual que el límite de
velocidad: cuando está vacío se dice «Sin configurar» y el bloque muestra sólo la
proporción de tiempo, que sí es medible. No se inventa una tasa para llenar un
espacio en la pantalla.

La proporción de ralentí ya está definida y probada en el diseño de 10/10: minutos
de ralentí sobre minutos con motor encendido, con extensiones hasta la siguiente
muestra válida.

## 8. Confianza, o el riesgo de falsa exactitud

Cada viaje muestra su cobertura y sus huecos:

- Cobertura ≥ 70 % y ningún hueco > 30 min → **confiable**.
- Por debajo → **«Consumo no confiable»** con el motivo. El número se muestra
  atenuado en lugar de oculto, porque tapar un dato también es ocultar información.
- Sin sensor de combustible (MRCDS 2035 y RENAULT GZD587, según la bitácora) →
  **«Sin dato»**. Nunca 0, que es indistinguible de «no consumió».
- La hora de captura y la del proveedor se muestran siempre: una muestra de hace
  tres días no puede parecer actual.

## 9. Una sola verdad

Criterio de aceptación, y el que Observat no cumple: **la grilla, la fila de total y
el bloque de estadísticas salen del mismo cálculo**. Si pueden discrepar, es un
defecto, y hay una prueba que lo verifica.

Corolario de alcance: el total no muestra «06:33 → 17:16» sobre un período de varios
días. Esa fila dice kilómetros y litros; las horas van por viaje.

## 10. Cruce con mantenimiento

Es el valor que separa esto de un reporte más. `PLAN_INTEGRACION_COMPLETA_Y_MEJORAS.md`
§3.2 ya establece la banda de la flota: **33 a 41 l/100 km**. Sobre esa línea:

- Un equipo que se sale de la banda de forma sostenida es candidato a una revisión de
  inyección, alineación, presión o filtros.
- Un viaje con desvío de consumo **y** una orden de trabajo abierta en la misma fecha
  es un candidato a causa raíz.
- Las horas en taller contra los viajes del período dan disponibilidad real (§4.4).

La alerta usa el circuito de notificaciones existente: una por equipo y regla por
día, con enlace a la ficha. La mejora de datos no debe generar una alerta por ejecución.

## 11. Arquitectura

```
Domain/Telematic/Consumo/
  IntervaloConsumo.php          VO puro: km, litros, calidad, componentes
  SerieConsumo.php              VO: intervalos ordenados de un equipo en un período
  ReglaSegmentacion.php         puro: SerieConsumo → lista de viajes
  PoliticaCobertura.php         puro: decide confiable / no confiable
  LibroConsumo.php              agrega litros y km de una lista de viajes
Application/Telematic/
  CalcularConsumoPorViaje       caso de uso de lectura, con alcance del actor
  RegistrarCargaCombustible     caso de uso de escritura del evento de carga
  Port/ConsumoPorViajeReader    puerto con empresa_id y sucursales permitidas
  Port/SerieMuestrasReader      puerto de lectura de la tabla de muestras
Infrastructure/Telematic/
  CodeIgniterConsumoPorViajeReader   read model con scope SQL
Notifications/
  TelematicOperationalNotificationEventSource  evento idempotente
```

Todo el cálculo es testeable sin base ni framework. MariaDB entra sólo como
adaptador, y el agrupamiento por día y las ventanas de 7/14/30 días se resuelven en
la consulta, no trayendo la serie entera al proceso.

## 12. Fuera de alcance

- **Litros con valor fiscal.** El odómetro de Wialon es calculado por distancia GPS, no
  es el odómetro físico, y la bitácora lo dice explícitamente: sirve para planning,
  no como respaldo contable. Este número es un diagnóstico operativo. Los litros que
  se facturan son los de la tarjeta o el ERP.
- Reconstrucción exacta segundo a segundo.
- Límites distintos por camión o por sucursal en esta primera versión.
- Comparar o reemplazar el consumo del ERP: se cruzan como control, no como
  sistema de verdad.

## 13. Riesgos

| Riesgo | Mitigación |
|---|---|
| Muestreo cada 15 min no ve viajes cortos | Regla explícita de «no detectable»; no se estima |
| Ruido de sensor en intervalos cortos | Umbral mínimo configurable; delta chico = `sin variación` |
| Transferencia entre tanques como carga falsa | Regla 6.4, sólo con tanques identificables |
| Dos unidades sin sensor de combustible | `Sin dato`, nunca 0 |
| `GZD587` con horómetro imposible (8.763 h) | Se excluye del cómputo hasta revisarlo, como ya está anotado |
| Congelar el viaje persistido | El viaje no se persiste; se deriva |

## 14. Criterios de aceptación

1. La grilla, el total y las estadísticas no pueden discrepar; hay prueba que falla si lo hacen.
2. Un delta de combustible acompañado de una subida en otro tanque no se cuenta como consumo.
3. Un intervalo con carga queda registrado como carga, no como consumo.
4. Viajes más cortos que el intervalo de muestreo no aparecen, y el sistema dice por qué.
5. Cobertura menor al 70 % produce «no confiable» con motivo, no un número limpio.
6. Sin sensor de combustible el resultado es «Sin dato» en todos los niveles.
7. El consumo en ralentí aparece sólo con tasa configurada, y se etiqueta como estimado.
8. El odómetro del cálculo es el de telemetría, con la diferencia contra el del chofer a la vista.
9. Limites vacíos no generan alerta. Los de una empresa no son visibles para otra.
10. El ciclo repetido el mismo día no duplica la notificación por equipo y regla.
11. Pruebas cubren cálculo puro, segmentación, cobertura, atribución, puertos, scoping
    tenant, migraciones/adaptadores, eventos idempotentes y vistas móvil/escritorio.