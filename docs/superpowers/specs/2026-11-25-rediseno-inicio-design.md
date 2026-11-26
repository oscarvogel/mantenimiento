# Rediseño del portal `/inicio` y del shell

Fecha: 2026-11-25. Estado: borrador para aprobación.
Maqueta aprobada (fuera del repo): `maqueta-inicio.html`, revisada en claro/oscuro, escritorio/celular.

## Intención

El usuario quiere que toda la app adopte el lenguaje visual **y la estructura** de una referencia
de dashboard financiero (marco oscuro redondeado, tarjetas blancas suaves de radio grande,
accesos en píldora, columna derecha con dona y lista), manteniendo la identidad Vogel: paleta
marino/azul/amarillo, Inter, lenguaje humano. Es casi un rediseño completo. Se empieza por el
portal `/inicio` y el shell compartido; el contenido interno de cada módulo se rediseña después,
pantalla por pantalla.

## Objetivos

- Shell nuevo en **toda** la app: marco marino, barra lateral de íconos extensible, encabezado
  con búsqueda de equipos, campana y "Mi cuenta".
- Portal `/inicio` con la estructura de la maqueta.
- Nuevo dato: **disponibilidad de la flota** (dona + listas Detenidos y En taller).
- Nuevo dato: **búsqueda de equipos** por patente o código, que lleva a la ficha.
- Tema claro y oscuro equivalentes; celular sin marco.

## No objetivos

- Rediseñar el contenido interno de los módulos (siguiente etapa).
- Búsqueda global (órdenes, viajes, neumáticos).
- Foto de perfil de usuario.
- Descripciones en los ítems de navegación dentro de un módulo.

## Anti-objetivos

- Que un usuario vea equipos u OT de otra empresa o de una sucursal que no tiene asignada.
- Inventar números: sin datos, se informa `Sin datos`, no un cero.
- Romper las pruebas de contraste de ambos temas (`frontend/tests/tokens.contrast.test.js`).
- Perder accesos que hoy existen (tema, cerrar sesión, empresa/sucursal visibles).

## Enfoques considerados

1. **Maqueta primero y tres entregas chicas** — elegido. Lo más caro de corregir (el aspecto) se
   aprobó antes de escribir código; cada entrega es probable por separado.
2. Tres entregas sin maqueta — descartado: los ajustes visuales se descubren sobre código real.
3. Una sola entrega — descartado: mezcla shell, portal y backend de dos contextos; el proyecto
   exige PRs chicos.

## Decisiones del usuario

### Shell (todas las pantallas)

- **Marco**: `secondary-900` (oscuro: `secondary-800`), radio 40 px; lienzo `surface-subtle`
  con radio 32 px y líneas de ruta muy tenues de fondo (SVG decorativo, `aria-hidden`).
- **Celular** (< 640 px): sin marco ni barra lateral; contenido a ancho completo; se mantiene
  `MobileTabBar` actual.
- **Barra lateral**: cerrada muestra solo íconos (nombre en `title`/tooltip y nombre accesible);
  marca de página activa en amarillo (`accent-400`). Un botón la expande; **expandida se
  superpone al contenido** con sombra y se cierra al hacer clic afuera (y con Escape). La
  elección se conserva en la sesión de la pestaña, como hoy.
- **Descripciones**: expandida, cada ítem del **portal** muestra nombre + una línea de qué hace
  (de la descripción ya existente en el catálogo de módulos). Dentro de un módulo, solo nombres.
- **Encabezado**: avatar con **iniciales** (círculo `accent-400`, texto `secondary-900`),
  "Hola, {nombre}" y debajo "Empresa · Sucursal"; búsqueda en píldora; **campana visible**;
  "Mi cuenta" en píldora marino con menú que contiene tema y cerrar sesión.

### Portal `/inicio`

- **Mantenimiento preventivo**: dos tarjetas grandes — Vencidos (oscura, `secondary-900`;
  en oscuro `secondary-600`) y Próximos a vencer (clara) — con trama de puntos decorativa.
  Debajo, fila compacta: Órdenes abiertas y Planes al día.
- **Accesos rápidos**: píldoras con ícono en círculo; la primera destacada en `primary-300`.
- **Requiere atención hoy**: tabla de filas (equipo, motivo, estado en píldora, "Ver ficha").
  En celular se ocultan motivo y enlace; la fila entera navega.
- **Módulos**: grilla de tarjetas compactas (ícono, nombre, descripción corta).
- **Estado de la flota** (columna derecha; abajo del contenido en < 1100 px):
  - Visible para quien tenga `equipos.ver`, filtrado por su empresa y sus sucursales. Sin el
    permiso, la columna no se muestra y el contenido ocupa el ancho.
  - Dona de **disponibilidad** con tres tramos excluyentes sobre equipos con
    `estado = 'ACTIVO'` (el resto queda fuera). Centro: total de equipos activos.
    - **OT abierta** = estado distinto de `FINALIZADA` y `CANCELADA`, misma definición que
      `EquipmentWorkStatus::hasOpenOrders`.
    - **Detenido**: tiene al menos una OT abierta con `equipo_fuera_servicio` marcado **o** con
      `inicio_detencion` cargado y `fin_detencion` vacío. Una OT cerrada nunca cuenta, aunque le
      falte la fecha de fin. "Desde cuándo" = `inicio_detencion`; si falta, `fecha_apertura`.
    - **En taller**: tiene OT abierta y no es Detenido.
    - **Operativo**: no tiene OT abierta.
  - Colores: Operativos `primary-300`, En taller `accent-400`, Detenidos `danger`.
  - Debajo, **las dos listas**: Detenidos (equipo, sucursal, OT, desde cuándo) y En taller
    (equipo, sucursal, motivo, OT, estado de la OT), con "Ver todos".
  - Selector de sucursal: "Todas las sucursales" o una de las permitidas.
- Hoy los indicadores y la tarjeta de atención solo se muestran al perfil de gerencia, y los
  accesos rápidos solo al perfil de campo; **las dos reglas se mantienen** (gerencia sin
  píldoras). La columna de flota sigue su propia regla (`equipos.ver`).

### Decisiones del implementador (revisables)

- El filtro de sucursal de la flota usa la **sucursal actual del equipo**, no la de la OT.
- Las listas muestran **una fila por equipo**, con su OT abierta más antigua; el motivo es
  `diagnostico` y, si falta, el tipo de servicio.
- La campana se muestra solo cuando los avisos están habilitados (`notifications.enabled`), como hoy.
- El Superadministrador (sin empresa propia) no ve la columna de flota ni la búsqueda.

### Tokens

Se agregan a `frontend/tailwind.config.js` / `styles.css` y a `docs/DESIGN_TOKENS.md`:
radios `pill`, `card` (24 px), `panel` (32 px), `frame` (40 px) y una sombra suave tintada con
`--shadow-rgb`. No se agregan colores nuevos.

## Entregas

1. **Base visual y shell** — tokens nuevos, marco, barra lateral extensible superpuesta,
   encabezado con "Mi cuenta", campana y búsqueda (la búsqueda puede quedar oculta hasta la
   entrega 3). Afecta a todos los módulos.
2. **Portal** — layout de `/inicio` con los datos que ya existen (`GetPortalHome`).
3. **Datos nuevos** — disponibilidad de flota y búsqueda de equipos, con pruebas de dominio,
   casos de uso con puertos falsos, adaptador sobre MariaDB y HTTP (permiso, empresa, sucursal).

## Límites de contexto

- Disponibilidad combina **Registro de activos** (equipo activo, sucursal) y **Ejecución de
  mantenimiento** (OT abierta, detención). Se expone como un contrato de lectura explícito que
  consume el portal (Plataforma), registrado en `docs/CONTRATOS_ENTRE_CONTEXTOS.md`. El
  controlador no consulta tablas de dos contextos.
- Búsqueda de equipos reutiliza `ListEquipment` (ya filtra por texto y valida sucursal).
- "OT abierta" usa la misma definición que `EquipmentWorkStatus::hasOpenOrders`.

## A cargo del implementador

- Estructura de componentes Vue y nombres de archivos.
- Íconos de `@heroicons/vue` equivalentes a los de la maqueta.
- Textos de estado vacío ("No hay equipos detenidos") y de error.
- Cantidad de filas visibles en cada lista antes de "Ver todos".
- Destino de los "Ver todos" y de los "Ver detalle" (pantallas existentes con filtro).
