# Bitácora 25/11/2026 — Neumáticos: dominio puro

**Contexto:** Neumáticos y gomería (agente 13).
**Origen:** `docs/PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md` §5 y especificación §5.12.
**Bloqueo vigente:** D7 (padrón definitivo de flota) sigue abierta. Este trabajo no depende de
ella: no toca equipos, tablas ni pantallas.

---

## 1. Qué se hizo

Se escribió el dominio del contexto, sin base, sin CodeIgniter y sin reloj global, en
`app/Domain/Neumaticos`:

| Clase | Qué es |
|---|---|
| `Neumatic` | Agregado raíz. Identidad por empresa + número, ciclo de vida, colocaciones y bitácora de estados. |
| `NeumaticNumber` | Número del neumático. Normalizado, obligatorio, máximo 20 caracteres (columna del sistema anterior). |
| `NeumaticStatus` | Los 11 estados del sistema anterior y sus transiciones. |
| `NeumaticStatusChange` | Entrada de la bitácora de estados (reemplaza `historial_estado_cubiertas`). |
| `NeumaticPosition` | Lugar, lado, posición y botón en el equipo. |
| `NeumaticMounting` | Una colocación, con su extracción cuando la hay. El equipo va por ID. |
| `NeumaticPerformance` | Km entre colocación y extracción, o `sin datos suficientes`. |
| `NeumaticDepositStock` | Existencia de un neumático en un depósito, con entradas y salidas por planilla. |

Pruebas: `tests/unit/Domain/Neumaticos` (40 pruebas). Suite completa: 2261 pruebas, sin fallos.

## 2. Invariantes del plan (§5.2) y dónde quedan

| # | Invariante | Dónde |
|---|---|---|
| 1 | El número no cambia | `Neumatic::$number` es `readonly`; `describe()` no lo recibe. |
| 2 | Una sola ubicación activa | `Neumatic::mount()` y `reconstitute()`. |
| 3 | Extracción no anterior a colocación | `NeumaticMounting`. |
| 4 | `km_extraccion < km_colocacion` deja el rendimiento no confiable | `NeumaticPerformance::between()`. No es excepción: se registra y se informa `sin datos suficientes`. |
| 5 | Stock no negativo sin ajuste documentado | `NeumaticDepositStock::dispatch()` / `adjust()`. |
| 6 | BAJA DEFINITIVA solo desde RECAPADA, VULCANIZADA o DESCARTE | `NeumaticStatus::nextStates()`. |

## 3. Supuestos tomados (revisar con el cliente)

Reglas que la documentación no dice de forma explícita y se dedujeron del significado de cada
estado:

1. **Estados en que un neumático se puede colocar:** NUEVA, 1/2 USO, REPARADO, RECAPADA y
   VULCANIZADA. No se coloca uno en reparación, descartado, a la venta ni fuera de la empresa.
2. **Un neumático colocado no cambia a un estado no montable:** primero se extrae.
3. **Un neumático colocado no cambia de sucursal:** acompaña al equipo.
4. **Una colocación nueva no empieza antes de la última extracción**, y la bitácora de estados
   no retrocede en el tiempo.
5. **No se da de alta en un estado final** (BAJA DEFINITIVA, TRANSFERIDA, VENDIDA).
6. Las posiciones no se cierran a un catálogo: solo se validan los largos del sistema anterior.

## 4. Preguntas abiertas sobre el ciclo de vida

El diagrama de §5.1 del plan está incompleto. Hasta que el cliente responda, se aplicó solo lo
que el diagrama y la invariante 6 dicen juntos:

1. **EN REPARACION → BAJA DEFINITIVA.** El diagrama la dibuja, pero contradice la invariante 6.
   Se aplicó la invariante y la transición **no** está permitida. ¿Un neumático que no tiene
   arreglo pasa a DESCARTE desde EN REPARACION?
2. **¿Vuelve a rodar un neumático RECAPADO o VULCANIZADO?** El diagrama no muestra una salida
   hacia EN REPARACION ni hacia 1/2 USO. Hoy, desde esos estados, solo se puede ir a BAJA
   DEFINITIVA. Si en la práctica un recapado se repara otra vez, falta esa transición.
3. **¿NUEVA puede ir a EN REPARACION** (pinchadura de una cubierta nueva) sin pasar por 1/2 USO?
4. **¿1/2 USO puede ir a la venta?** Hoy solo NUEVA llega a P/VENTA.

Cambiar cualquiera de estas reglas es editar `NeumaticStatus::nextStates()` y su prueba.

## 5. Qué sigue

- Casos de uso de §5.5 (`RegistrarNeumatico`, `ColocarNeumatico`, `ExtraerNeumatico`,
  `CambiarEstadoNeumatico`, movimientos de depósito) con puertos falsos.
- Migraciones de §5.12 y adaptadores de persistencia.
- Adaptadores de `NeumaticReadingRecorder`, `GomeriaOrderLauncher` y
  `PreventiveNeumaticEventPublisher` (ver `CONTRATOS_ENTRE_CONTEXTOS.md` §5).
- Todo lo que resuelve en qué equipo está el neumático sigue bloqueado por D7.
