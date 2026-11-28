# Rediseño de los interiores de los módulos — diseño

**Fecha:** 26/11/2026 · **Rama:** `feat/rediseno-inicio-shell` · **Plan:** `docs/superpowers/plans/2026-11-26-rediseno-interiores.md`

**Maqueta aprobada:** `docs/superpowers/specs/2026-11-26-rediseno-interiores-maqueta.html`. Se abre con cualquier servidor estático y permite cambiar de patrón, de tema claro a oscuro y de escritorio a celular.

## Objetivo

Que el interior de cada módulo hable el mismo lenguaje visual que el inicio aprobado (`2026-11-25-rediseno-inicio-design.md`): tarjetas blancas sin borde, esquinas grandes y sombra suave, controles en píldora, estados como píldoras de color, y filas en lugar de tablas con líneas. Se conserva la identidad Vogel.

## Decisiones del usuario (26/11)

1. **El botón principal va en azul marino**, no en azul. Se agrega el token `action`: `secondary-900` en tema claro y `primary-300` con texto marino en oscuro. `primary` sigue siendo el azul de los enlaces, el foco y los estados informativos.
2. **Los contadores de estado de los listados son clickeables y filtran la lista.** Por ejemplo, en Órdenes: Abiertas, Emitidas, En proceso, Esperando repuestos, Demoradas y Finalizadas hoy. El filtro viaja por la URL, igual que los filtros que ya existen.
3. **Se aprueban los 5 patrones de la maqueta:**
   - P1 Resumen
   - P2 Listado
   - P3 Ficha
   - P4 Carga
   - P5 Configuración

   Las pantallas de superadministración entran como P5. El login queda afuera.

## Piezas compartidas

Se cambia el estilo de las piezas que ya existen, para que todas las pantallas que las usan se actualicen juntas. **No se crean piezas paralelas.**

| Pieza | Archivo | Cambio |
|---|---|---|
| Botones | `frontend/src/ui/buttons.js` | Píldora. El principal usa `bg-action`. Los secundario y peligro pasan de borde a sombra y anillo suave. Foco con anillo y separación. |
| Campo | `fieldClass` (mismo archivo) | Fondo `surface-muted` sin borde visible. Píldora salvo el `textarea`, que usa `rounded-2xl`. El rojo sigue con `aria-invalid`. |
| Encabezado de página | `pages/operations/components/PageHeading.vue` | Sin línea divisoria. La etiqueta va en `primary`. |
| Tarjeta | `pages/operations/components/PanelCard.vue` | `rounded-card` con `shadow-card`, sin borde ni separador bajo el título. |
| Estado | `pages/operations/components/StatusBadge.vue` | Sin cambios: ya es una píldora con un ícono por tono, que se lee sin depender del color. |
| Filtro con conteo | `frontend/src/components/ui/FilterChip.vue` (nueva) | Píldora con etiqueta y número. Es un enlace si el filtro va por URL (`aria-current`) o un botón si es local (`aria-pressed`). La activa usa `bg-action`. |

## Patrones

- **P2 Listado:**
  - Arriba, los contadores en `FilterChip`.
  - Debajo, una tarjeta con filtros en píldora. En el celular, los secundarios quedan detrás de "Más filtros".
  - Cada registro es una fila redondeada sobre `surface-subtle`, sin líneas. Los títulos de columna aparecen solo desde `md`.
  - En el celular, cada fila muestra lo esencial y el estado.
- **P1 Resumen:**
  - La cifra más urgente va en la tarjeta oscura (`surface-inverse`), como en el inicio.
  - Le siguen tarjetas claras con su enlace, indicadores compactos, la salud del preventivo con una barra y "Atención" en filas con píldoras de cantidad.
- **P4 Carga:**
  - Filtros como en P2.
  - Una fila por equipo con la última lectura, el campo nuevo y el estado del plan.
  - El error se muestra junto al campo.
  - En el celular, la fila se apila y conserva el estado del plan.
- **P3 Ficha:**
  - Volver, la identidad con su estado y las acciones.
  - Pestañas en un selector de píldoras.
  - Indicadores compactos y tarjetas de contenido en dos columnas.
- **P5 Configuración:**
  - Las secciones como tarjetas con su ícono.
  - Cada catálogo va en una tarjeta con pestañas, buscador y filas.

## Fuera de alcance

- El login.
- Cambios de datos o de permisos. Si una pantalla necesita un dato que hoy no existe, se consulta antes de agregarlo.
