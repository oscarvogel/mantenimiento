# Bitácora — Fase 0: alcance y contratos

**Fecha:** 24/10/2026
**Rama:** `feat/telemetria-autotrac-atc`
**Alcance:** Fase 0 del `docs/PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md` §9.
**Estado:** todo lo que no dependía de una respuesta del cliente, cerrado. Quedan 7 decisiones esperando a TSA.

---

## 1. Conclusión primero

La Fase 0 tenía cuatro ítems pendientes. **Tres se cerraron completos y el cuarto avanza a medias:**

| Ítem | Estado |
|---|---|
| Actualizar `ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` | **Cerrado** — quedó en versión 1.3 |
| Actualizar `AGENTS.md` con los tres contextos | **Cerrado** — contextos 12, 13 y 14 con agente propietario |
| Escribir los contratos (`Puerto` interfaces) | **Cerrado** — 5 interfaces nuevas + 3 documentadas, con firma y estado |
| Resolver D1 a D8 con el cliente | **Parcial** — D3 ya estaba cerrada; las otras 7 dependen de TSA |

La Fase 0 **no está cerrada** y no se puede cerrar sin el cliente. Lo que sí se dejó listo es el
material para que esa conversación sea corta: cada decisión con su evidencia, su impacto y la
pregunta escrita.

---

## 2. Hallazgos que cambiaron el alcance de la tarea

### 2.1 La especificación ya estaba en versión 1.2

El plan pedía actualizar la especificación en §2.2, §3.3, §5, §6, §11, §13 y §15. Al abrirla,
**§3.3, §5.12-5.14, §6.11-6.13, §11 y §13 ya estaban escritas** con los tres dominios nuevos, y
§13 ya listaba 42 pruebas en lugar de 26. La versión 1.2, del 6 de octubre, ya había incorporado el
cambio de alcance.

**Lo que faltaba era otra cosa:** la especificación describía un modelo de viajes que **no es el
que está implementado**. Su §5.13 documentaba las tablas del historial importado (`viajes`,
`viaje_tramos`, `viaje_combustible`, `viaje_gastos`, `viaje_lecturas`) como si fueran el modelo
único, y no mencionaba `viajes_operativos` ni ninguna de sus nueve tablas hijas. Tampoco incluía
los tramos con dos fletes, la imputación de costos por tramo, la caja del chofer ni los otros
haberes, que sí están en el código desde el 19 al 23 de octubre.

El trabajo real de la Fase 0 en la especificación fue **reconciliarla con el código**, no agregarle
secciones nuevas.

### 2.2 §2.2 contradecía al módulo implementado

La especificación ponía fuera de alcance "cuenta corriente por viaje y liquidación de haberes
pendientes del chofer". Esa funcionalidad **existe y está probada**: `viaje_operativo_caja_movimientos`,
`viaje_operativo_otros_haberes` y el agregado `TripSettlement`.

Se corrigió para que quede fuera de alcance **solo la aprobación y el pago** de la liquidación, que
es lo que realmente falta.

### 2.3 Dos documentos estaban vencidos

- `docs/BITACORA_IMPORTACION_VIAJES_HISTORICOS.md` (17/10) afirma que *"el importador operativo de
  Viajes todavía NO está implementado y ningún dato fue importado"*. Es cierto para esa fecha y
  falso hoy: el importador se escribió el 18 y el 19 de octubre y ya funciona.
- `docs/ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md` (21/10) sigue con *"Estado: borrador para
  evaluación. No autoriza implementación"* y su §4.2 lista como faltantes siete piezas que ya
  están hechas.

**No se tocaron.** Son documentos de otra entrega y de otra rama de trabajo; corregirlos es una
tarea aparte. Quedan anotados como pendientes en §6.

### 2.4 El módulo de Viajes estaba mucho más avanzado de lo que decía la documentación

Al revisar el código, el circuito económico completo está implementado y con pruebas en verde:
tramos con dos fletes, imputación de combustible y gastos por tramo, cierre con lectura del
equipo, caja del chofer, otros haberes, liquidación y margen por viaje y por tramo.

---

## 3. Qué se hizo

### 3.1 Contratos entre contextos (`app/Application/`)

Se escribieron **5 interfaces nuevas**:

| Archivo | Contrato | Responde |
|---|---|---|
| `app/Application/Assets/Port/EquipmentByPlateLookup.php` | Resolver patente → equipo | Assets |
| `app/Application/Trips/Port/OperationalCostByEquipmentQuery.php` | Costo operativo por equipo y período | Viajes |
| `app/Application/Neumaticos/Port/NeumaticReadingRecorder.php` | Registrar lectura por colocación o extracción | Measurement |
| `app/Application/Neumaticos/Port/GomeriaOrderLauncher.php` | Abrir orden de gomería | WorkOrders |
| `app/Application/Neumaticos/Port/PreventiveNeumaticEventPublisher.php` | Publicar `placed` / `removed` / `retreaded` | PreventiveMaintenance |

Con sus DTO: `app/Application/Neumaticos/LaunchGomeriaOrderCommand.php`,
`app/Application/Facturacion/ComprobanteParaAutorizar.php`,
`app/Application/Facturacion/ComprobanteParaAnular.php` y
`app/Application/Facturacion/AutorizacionResult.php`.

El puerto `ArcaGateway` (`app/Application/Facturacion/Port/ArcaGateway.php`) traduce el puerto
`ArcaGateway` que la propia especificación §5.14 ya nombraba.

**Decisión de diseño:** los contratos de solo lectura los declara **el proveedor** y los consumen
los que los necesitan; los de acción los declara **el consumidor**. Así `EquipmentByPlateLookup`
no se triplica y la semántica del proveedor —el origen de una lectura, por ejemplo— no se filtra
hacia el consumidor. Se documentó el criterio completo en `docs/CONTRATOS_ENTRE_CONTEXTOS.md` §2.

**Los nombres de las interfaces son en inglés**, como el resto del código. El plan usa nombres en
español; la equivalencia está en la tabla de `CONTRATOS_ENTRE_CONTEXTOS.md` §3 para que la traza
se pueda seguir.

### 3.2 `docs/CONTRATOS_ENTRE_CONTEXTOS.md` (nuevo)

El registro: regla, criterio de ubicación, tabla de equivalencia con el plan, firmas completas,
qué falta implementar y qué bloquea cada adaptador.

### 3.3 `docs/DECISIONES_PENDIENTES_FASE_0.md` (nuevo)

Estado de las ocho decisiones con su evidencia y con las preguntas redactadas para mandar.

### 3.4 `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` → versión 1.3

- **§2.2**: sacada la cuenta corriente por viaje y la liquidación del chofer de "fuera de
  alcance"; queda solo la aprobación y el pago. Corregida también la exclusión de la
  facturación, que ahora es del sistema propio.
- **§5.13**: reescrita. Se separa el **modelo operativo** (nueve tablas, con sus campos reales) del
  **historial importado** (siete tablas legacy con su tabla de origen) y de la **facturación
  histórica** (tres tablas). Se documentan los estados del viaje, los dos fletes por tramo, la
  imputación de costos por tramo, que la caja no es costo del viaje, el cierre que genera lectura
  y los cálculos derivados.
- **§15**: la Etapa 6 pasa a marcar qué está hecho y qué queda, con las casillas de las etapas.
- **Cabecera**: versión 1.3 con la nota del cambio.

### 3.5 `AGENTS.md`

- Tres contextos nuevos con su agente propietario: **12. Viajes y costos operativos**, **13.
  Neumáticos y gomería**, **14. Facturación electrónica**. Plataforma y Calidad pasan a 15 y 16.
- Cada contexto nuevo lleva su lenguaje, su código actual, sus invariantes, sus contratos y qué
  decisión del cliente lo bloquea.
- "Contratos entre contextos" ahora apunta al registro, para que un agente que vaya a cruzar dos
  contextos busque ahí antes de inventar una interfaz.
- "Fuentes de verdad": 26 pruebas → 42.
- "Estado base" actualizado del 7/08/2026 al 24/10/2026.

### 3.6 El plan de ampliación

El checklist de la Fase 0 quedó con lo hecho marcado y con el estado escrito.

---

## 4. Evidencia

```
php -l de las 10 interfaces y DTO nuevos            sin errores
Suite PHPUnit completa (WSL, PHP 8.5.4)              1478 pruebas, 5268 aserciones, 4 fallos, 44 omitidas
  Dominio + aplicación de Viajes                     88 pruebas, 268 aserciones, OK
  Base de datos + feature                            57 pruebas, 149 aserciones, OK (22 omitidas)
  Frontend de Viajes (vitest)                        9 archivos, 28 pruebas, OK
```

**Los 4 fallos de la suite no son de esta entrega ni de Viajes.** Son un doble prefijo de URL:
`base_url('mantenimiento/...')` lleva el subpath hardcodeado mientras `app.baseURL` ya trae
`/mantenimiento/`, y sale `/mantenimiento/mantenimiento/...`. Afecta Home, Login, el informe de
fallos de QR y Planes. **Es real en producción**, donde la baseURL tiene subruta, así que merece
ticket aparte. Queda anotado en `AGENTS.md`.

**Los 22 tests omitidos** son los que exigen el esquema MariaDB descartable `clientes_test_` en
`fasa_189`. No se ejecutaron porque esta tarea no toca la base: no se corrieron migraciones, no se
tocó `fasa_189` y no se desplegó nada.

---

## 5. Qué NO se hizo, y por qué

- **No se implementó ningún adaptador de los contratos nuevos.** La Fase 0 es sin código de
  implementación, y además cada adaptador depende de una decisión del cliente que todavía no
  llegó (ver la tabla de bloqueos en `CONTRATOS_ENTRE_CONTEXTOS.md` §5).
- **No se rehizo `CodeIgniterTripFleetLookup`**, que consulta `equipos` y `empleados`
  directamente. Funciona y está probado, pero ata Viajes al esquema de otros dos contextos.
  Corregirlo es la Fase 1, cuando el padrón de flota esté confirmado. Queda registrado como deuda
  en `CONTRATOS_ENTRE_CONTEXTOS.md` §6.
- **No se agregaron los orígenes de lectura** `NEUMATICO` a `EquipmentReading`. Es un cambio de
  dominio y depende de que Neumáticos empiece a implementarse.
- **No se tocaron** `BITACORA_IMPORTACION_VIAJES_HISTORICOS.md` ni
  `ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md`, que están vencidos pero pertenecen a otra
  entrega.
- **No se resolví D1 a D8.** No se deducen de los datos: inventar una respuesta sería peor que
  dejarla abierta.

---

## 6. Pendientes

| Pendiente | Quién lo cierra |
|---|---|
| Respuesta de TSA a D1, D2, D4, D5, D6, D7 y D8 | Cliente. Las preguntas están en `DECISIONES_PENDIENTES_FASE_0.md` §4. |
| Respuesta a las 8 preguntas del circuito de viajes | Cliente. Están en `PROPUESTA_VIAJES_CIRCUITO_LIQUIDACION.md` §8. |
| Aprobación y pago de liquidaciones del chofer (`viajes.liquidar`) | Falta de código. Es el único hueco del circuito de viajes. |
| Importación de la cuenta corriente legacy (`t11`, `t12`, `t13`) | Falta de código. La decisión D3 ya está tomada. |
| Actualizar `ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md` (estado y §4.2) | Tarea de documentación. |
| Actualizar `BITACORA_IMPORTACION_VIAJES_HISTORICOS.md` | Tarea de documentación. |
| Corregir el doble prefijo de URL con subruta | Tarea de código. Afecta 4 tests y producción. |
| Adaptador de `EquipmentByPlateLookup` | Fase 1, bloqueado por D7. |
| Validar en `fasa_189` los contratos nuevos | Fase 1. |

---

## 7. Criterio de cierre de la Fase 0

La Fase 0 **se cierra cuando** las ocho decisiones tienen respuesta y la especificación está
actualizada con ellas. Hoy está en el último paso: la especificación está actualizada y falta la
respuesta del cliente.

Lo razonable ahora es **mandar las siete preguntas** de `DECISIONES_PENDIENTES_FASE_0.md` §4 y
seguir con el único hueco de código que no depende de nadie: el ciclo de aprobación y pago de
liquidaciones del chofer.
