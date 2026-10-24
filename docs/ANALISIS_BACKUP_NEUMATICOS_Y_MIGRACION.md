# Análisis del backup `neumaticos_pawluk` y plan de migración al sistema de mantenimiento

**Fecha:** 2026-10-06
**Archivo analizado:** `neumaticos_pawluk-20261006.backup` (365 KB)
**Destino:** sistema de mantenimiento (CodeIgniter 4, modular, Ferozo)
**Estado:** análisis inicial. No se modificó código ni base del sistema de mantenimiento.

---

## 1. Qué es el backup exactamente

| Atributo | Valor |
|---|---|
| Motor | **PostgreSQL 9.2.3** (EOL desde 2017) |
| Formato | `pg_dump -Ft` (tar), 365 KB |
| Base | `neumaticos_pawluk` |
| Encoding | UTF8, `LC_COLLATE = Spanish_Argentina.1252` |
| Objetos | 84 tablas, 13 vistas, 1 función, 83 constraints |
| Integridad referencial | **0 claves foráneas** |
| Origen aparente | aplicación estilo PowerBuilder (`punto_venta_sucursal`, `item`, `base_que_modifico`, `sucursal_paniol_proveedor`) |

El dump se descomprimió y se leyó el `restore.sql` embebido. Se dejaron dos artefactos para consulta:

- `docs/inventario_backup_neumaticos.csv` — las 84 tablas con filas reales, columnas y códigos sin catálogo.
- `docs/ddl_backup_neumaticos.sql` — DDL completo de tablas y vistas, por si hay que reproducir la base.

---

## 2. Hallazgo crítico: este backup no contiene datos operativos

**72 de las 84 tablas están vacías.** Solo 12 tienen filas, y casi todas son catálogos de consulta:

| Tabla | Filas | Contenido real |
|---|---|---|
| `estado_actual` | 11 | NUEVA, 1/2 USO, EN REPARACION, REPARADO, DESCARTE, BAJA DEFINITIVA, VENDIDA, TRANSFERIDA, RECAPADA, VULCANIZADA, P/VENTA |
| `modelos` | 5 | X MULTI T, X MULTI Z, X MULTI D, ECOPLUS |
| `marcas` | 4 | MICHELIN, CONTINENTAL, BRIDGESTONE, XBRI |
| `neumaticos` | 3 | TSA 181917, TSA 229704, TSA 001 |
| `origen` | 3 | ARGENTINA, BRAZIL, CHINA |
| `tipos_de_cubiertas` | 2 | LISA, TACO |
| `empresas` | 1 | **TRANSPORTE TSA** |
| `sucursales` | 1 | TSA (punto de venta 1) |
| `depositos` | 1 | Deposito Central - Obera |
| `empresas_x_sucursal` | 1 | TSA → TSA |
| `unidades` | 1 | unidad 1, móvil 1 |
| `usuarios` | 1 | ADMINISTRADOR, nivel 1 |

**Vacías, entre otras:** `orden_de_trabajo` (0 filas), `camiones_remolques` (0), `planilla_de_kilometros` (0), `historial_mantenimiento_x_patente` (0), `mantenimiento` (0), `plan_de_mantenimiento` (0), `proveedores_clientes` (0), `choferes` (0), todo el historial de neumáticos, y todo `stock` / `compras` / `ventas` / `remitos` / `gomeria`.

Conclusión: **esto es un esquema de desarrollo o una instancia recién inicializada, no el sistema en uso.** Sirve para entender el modelo, no para migrar datos. Para migrar hay que pedir el `pg_dump` de la base de producción.

---

## 3. Qué hace el sistema con esos datos

El sistema legado no es de mantenimiento de flota: es un **gestor de neumáticos y cubiertas para una empresa de transporte**. El mantenimiento existe, pero subordinado a la rotación de neumáticos. Se ordena en diez módulos.

### 3.1 Flotilla y armado de unidades
`camiones_remolques`, `tipos_camiones_remolques`, `unidades`, `armado_unidades`, `ubicacion_unidades`, `choferes`.

El corazón del modelo es `armado_unidades`: une un **tractor** con un **semi-acoplado**, con km de cada uno al enganchar y al desenganchar, fechas, chofer asignado y flag `activo`. La vista `armado_actual_unidades` toma el `item` más alto por unidad para saber con qué patente está armada hoy. `camiones_remolques` usa la **patente como clave primaria**: no hay código interno de equipo.

### 3.2 Neumáticos y cubiertas (módulo principal)
`neumaticos`, `marcas`, `modelos`, `tipos_de_cubiertas`, `posiciones`, `historial_cubiertas` (23 columnas), `historial_tipo_de_cubierta`, `historial_estado_cubiertas`, `kilometraje_de_neumaticos` (24 columnas), `historial_kilometraje_de_neumaticos`, `ubicacion_de_los_neumaticos`.

Cada neumático tiene identidad propia (`id_sucursal` + `numero_neumatico`), marca, modelo, medida, año de fabricación, estado del ciclo de vida (los 11 de `estado_actual`), origen y precio. `ubicacion_de_los_neumaticos` dice **dónde está montado**: patente, lugar, lado, posición, botón. `historial_cubiertas` e `historial_kilometraje_de_neumaticos` son los bitácarios de colocación y extracción por planilla.

Las vistas calculan los indicadores clave del negocio:
- `informe_rendimiento_colocacion_extraccion` — km entre colocación y extracción (vida útil del neumático).
- `total_cubiertas_x_movil` — cuántas cubiertas tiene cada unidad.
- `vista_ultima_reparacion_por_neumatico` y `vista_neumaticos_reparados_disponibles` — qué neumáticos salieron de reparación y están disponibles para reponer.

### 3.3 Gomería (reparación)
`planilla_gomeria`, `envios_planilla_gomeria`, `actividades_gomeria`, `tipo_de_banda`.

Envío de neumático a reparar y recepción: actividad solicitada contra actividad realizada, importe, tipo de banda, km al momento. La vista `costo_reparacion_neumaticos` suma el costo por neumático.

### 3.4 Órdenes de trabajo
`orden_de_trabajo` (42 columnas), `fallas_orden_de_trabajo`, `tareas_orden_de_trabajo`, `tareas_adicionales_orden_de_trabajo`, `repuestos_orden_de_trabajo`, `repuestos_tareas_adicionales_ot`, `personal_ejecutante_ot`, `salida_articulos`.

La OT tiene: patente, móvil, tipo de intervención, tipo de falla, equipo detenido, operador, inicio y fin, **tiempo programado contra tiempo real**, km, estado, tractor y semi con sus ítems, personal, proveedor, comprobante del proveedor, precio hora hombre. Lleva **clave de mantenimiento** y **clave de historial de mantenimiento**, es decir que la OT cierra y renueva el plan preventivo del activo. Las tareas salen del catálogo de mantenimiento; las "adicionales" son texto libre con precio.

### 3.5 Mantenimiento preventivo
`mantenimiento`, `detalle_mantenimiento`, `plan_de_mantenimiento`, `detalle_plan_mantenimiento`, `frecuencia_de_mantenimiento`, `repuestos_necesarios`, `historial_mantenimiento_x_patente`, `detalle_historial_mantenimiento_x_patente`, `tareas`, `descripcion_de_tareas`, `marcas_maquinas`, `modelo_maquinas`.

El plan se define **por marca y modelo de máquina**, no por equipo: `plan_de_mantenimiento` dice qué mantenimientos incluye y con qué frecuencia, y `frecuencia_de_mantenimiento` registra la frecuencia real aplicada a una patente concreta. `historial_mantenimiento_x_patente` es el estado vivo por patente, con `kilometraje_programado`, `kilometraje_actual`, `proximo_kilometraje` y `proxima_fecha_mantenimiento`: el legado **ya calcula el próximo vencimiento**, pero solo sobre km y fecha. No hay horómetro.

### 3.6 Kilometraje
`planilla_de_kilometros`: patente, fecha, motivo, odómetro y km del tractor. Es la bitácora de lecturas con motivo.

### 3.7 Stock, depósito y logística
`depositos`, `stock`, `planillas`, `detalle_planillas_entrada`, `detalle_planillas_salida`, `ingreso_articulos`, `salida_articulos`, `detalle_ingreso_articulos`, `detalle_salida_articulos`, `articulos`, `articulos_paniol`, `marcas_articulos`, `stock_paniol`.

Control de depósitos con entradas y salidas por planilla, artículos con imagen, marca, precio y stock mínimo.

### 3.8 Compras, ventas y remitos
`compras`, `detalle_compras`, `detalle_cubiertas`, `ventas`, `detalle_ventas`, `planilla_baja`, `detalle_planilla_baja`, `remitos`, `detalle_remitos`, `proveedores_clientes`.

Baja definitiva y venta definitiva de bootstrap con doble estado (preparación y confirmación) y usuario que confirmó.

### 3.9 Vencimientos documentales
`conceptos_vencimientos`, `vencimientos_x_chofer`, `vencimientos_x_dominio`, `vencimientos_x_empresa`, `detalle_vencimientos_x_chofer`, `dealle_vencimientos_x_dominio`, `detalle_vencimientos_x_empresa`.

Vencimientos de documentación de choferes, por dominio (patente) y de la empresa, con días de vencimiento, renovación y observaciones. La vista `vencimientos_x_documentacion` une los tres con `UNION` y agrega chofer, móvil, empresa y dominio.

### 3.10 Configuración
`parametros` (días de mantenimiento, costo hora hombre, días de renovación), `costo_hora_hombre`, `usuarios`, `sucursales`, `empresas`.

---

## 4. Qué entra en el sistema de mantenimiento y qué queda afuera

La especificación (§2.2) deja **fuera de alcance v1** la gestión de neumáticos por posición y la gomería. Eso elimina de entrada más de la mitad de las tablas.

### 4.1 Mapeo tabla por tabla

| Legado (`neumaticos_pawluk`) | Destino | Observación |
|---|---|---|
| `empresas` | `empresas` | Directo. |
| `sucursales` | `sucursales` | `punto_venta_sucursal` es el código; `id_sucursal` interno. |
| `usuarios` | `usuarios` | **No migrable.** Ver riesgo R1. |
| `camiones_remolques` | `equipos` | Requiere sintetizar `codigo`. Ver R2. |
| `tipos_camiones_remolques` | `tipos_equipo` | Solo camión y remolque; el destino admite más tipos. |
| `marcas_maquinas` | `marcas` | Es el catálogo de máquinas, no el de neumáticos. |
| `modelo_maquinas` | `modelos` | Requiere `marca_id` y `tipo_equipo_id` válidos. |
| `planilla_de_kilometros` | `lecturas_equipo` | `motivo` y `descripcion_motivo` → `origen` + `observaciones`. |
| `historial_mantenimiento_x_patente` | `planes_mantenimiento` + historial | `proximo_kilometraje` → `proximo_km`; `proxima_fecha_mantenimiento` → `proxima_fecha`. |
| `mantenimiento` + `detalle_mantenimiento` | `tipos_servicio` + `tareas_mantenimiento` + `tipo_servicio_tareas` | El legado separa mantenimiento de tarea igual que el destino. |
| `plan_de_mantenimiento` + `detalle_plan_mantenimiento` | `plantillas_mantenimiento` + `plantilla_items` | Plantilla por marca y modelo. |
| `frecuencia_de_mantenimiento` | intervalos en `planes_mantenimiento` | Fuente de `intervalo_km` y `intervalo_dias`. |
| `repuestos_necesarios` | `plan_repuestos_sugeridos` | Esa tabla no figura en las migraciones actuales del destino. |
| `orden_de_trabajo` | `ordenes_trabajo` | 42 columnas → encabezado + `orden_estado_historial`. Ver R3. |
| `fallas_orden_de_trabajo` | `causa_codigo` + `falla_informada` | El destino no tiene tabla de fallas por OT. |
| `tareas_orden_de_trabajo` | `orden_tareas` | Requiere `tarea_id` del catálogo destino. |
| `tareas_adicionales_orden_de_trabajo` | `orden_tareas` con `tarea_id` nulo | Encaja como tarea ad hoc. |
| `repuestos_orden_de_trabajo` | `orden_repuestos` | Sin garantía: el legado no la tiene. |
| `personal_ejecutante_ot` | personal de la OT | El destino no tiene tabla de asignación; queda en `responsable_usuario_id` más descripción. |
| `proveedores_clientes` | `proveedores` | `sucursal_paniol_proveedor` se descarta. |
| `choferes` + `armado_unidades` | `employees` + `employee_equipment_assignments` | **Ya existe en el destino** (migraciones 2026-09-07). |
| `vencimientos_*` | tablas de vencimientos | **Ya existe en el destino** (2026-09-08). |
| `tipos_de_falla` | catálogo de causas | El destino usa `causa_codigo` pero no tiene catálogo. |

### 4.2 Fuera de alcance v1 (se pierden o quedan en el legacy)

`neumaticos`, `ubicacion_de_los_neumaticos`, `historial_cubiertas`, `historial_kilometraje_de_neumaticos`, `kilometraje_de_neumaticos`, `historial_tipo_de_cubierta`, `historial_estado_cubiertas`, `posiciones`, `tipos_de_cubiertas`, los catálogos `marcas` y `modelos` de neumático, `depositos`, `stock`, `stock_paniol`, `planillas`, `detalle_planillas_entrada/salida`, `ingreso_articulos`, `salida_articulos`, `articulos_paniol`, `planilla_gomeria`, `envios_planilla_gomeria`, `actividades_gomeria`, `tipo_de_banda`, `compras`, `detalle_compras`, `ventas`, `detalle_ventas`, `planilla_baja`, `detalle_planilla_baja`, `remitos`, `detalle_remitos`, y `resultado_sql` (tabla basura de pruebas, sin clave primaria).

**Esto no es una pérdida menor.** El valor operativo real de la empresa —vida útil de cada neumático, rendimiento por cubierta, costo de reparación, historial de gomería— vive íntegramente en ese módulo, y el sistema de mantenimiento v1 lo descarta por decisión de alcance. Hay que decidir explícitamente si se acepta esa pérdida o si se agrega un bounded context de gomería en una etapa posterior.

---

## 5. Riesgos y huecos a resolver antes de migrar

**R1 — Las contraseñas no se pueden migrar.** `usuarios.contrasenia` es `character(10)`: texto plano (o hash roto) con 10 caracteres máximo. Además `nivel_usuario` es un entero sin tabla de catálogo, así que los roles tampoco son derivables. **Todos los usuarios deben resetear su clave en el sistema nuevo.** No intentar migrar ese campo.

**R2 — El equipo no tiene código interno.** La clave primaria de `camiones_remolques` es la patente, pero `equipos.codigo` es `NOT NULL` y es la clave de deduplicación de importaciones. Hay que **sintetizar un código por equipo** (patente, o `MOVIL-n`) y dejarlo como convención acordada con la empresa, porque después se usa en QR, en reportes y en deduplicación.

**R3 — La OT tiene 42 columnas y mezcla encabezado con datos derivados.** Campos a decidir uno por uno: `tiempo_programado` y `tiempo_real` (no existen en el destino: ¿van a `orden_tareas` o se descartan?), `clave_mantenimiento` y `clave_historial_mantenimiento` (llaves hacia el historial de preventivo, equivalentes a `plan_id` más historial), `base_que_modifico` y `fecha/hora_modificacion` (auditoría de la app legacy, sin equivalente), y los campos `nombre_*` desnormalizados (`nombre_usuario`, `nombre_chofer`, `nombre_empresa`, `nombre_proveedor`, `nombre_intervencion`, `nombre_personal_conformidad`) que el destino no quiere porque referencia por ID.

**R4 — Hay códigos enteros sin catálogo.** `orden_de_trabajo.estado_orden` no tiene tabla de estados; tampoco hay catálogo para `camiones_remolques.estado`, `planilla_de_kilometros.motivo`, `orden_de_trabajo.tipo_comprobante_proveedor` ni `usuarios.nivel_usuario`. Para las OT históricas **no se puede reconstruir el estado** sin preguntar al cliente qué código significa qué. Sin eso, las OT cerradas quedan en estado desconocido.

**R5 — El preventivo se hereda de marca y modelo, no del equipo.** `plan_de_mantenimiento` define el plan por marca y modelo, y `frecuencia_de_mantenimiento` lo especializa por patente. En el destino el plan es **por equipo materializado**. La migración tiene que expandir: una fila de plan por marca y modelo se vuelve N filas, una por cada equipo que lo hereda. Es la transformación más delicada de toda la migración.

**R6 — No hay horómetro en el legacy.** Todo el preventivo es por km y fecha. El destino soporta km, horas y fecha. Al migrar, los planes quedan solo con `intervalo_km` e `intervalo_dias`; las columnas de horas quedan nulas, que es el comportamiento correcto.

**R7 — Cambio de motor.** PostgreSQL 9.2.3 a MySQL/MariaDB. El `pg_dump` hay que procesarlo igual: el orden de importación debe respetar las dependencias a mano (no hay FKs que lo hagan por nosotros) y hay que revisar `character(n)` (el destino usa `VARCHAR`), `timestamp with time zone` y los tipos `money`.

**R8 — Numeración de OT.** El legacy numera por `(punto_venta_sucursal, id_numero_orden)`. El destino usa `numero` de tipo texto con formato `OT-AAAA-000001` y numerador transaccional por empresa. Hay que decidir si se **respeta la numeración histórica** (importar el número original, para que las OT viejas conserven su identidad visible) o se renumera (más limpio, pero rompe la referencia física en el taller y con proveedores).

---

## 6. Qué hace falta para poder seguir

1. **`pg_dump` de la base de producción** (`neumaticos_pawluk` real, con órdenes, camiones, kilometrajes y preventivo cargados). Sin eso no hay migración posible: el backup adjunto no tiene un solo dato operativo.
2. **Diccionario de códigos**: qué significa cada valor de `estado_orden`, `estado` en camiones, `motivo` de kilometraje, `nivel_usuario` y `tipo_comprobante_proveedor`. Idealmente de la aplicación original, o preguntándole al cliente.
3. **Decisión sobre neumáticos** (apartado 4.2): se acepta perder ese módulo, o se cotiza un bounded context aparte.
4. **Criterio de numeración de OT** para el histórico (R8).

Con esos cuatro puntos se puede escribir el importador real. Mientras tanto, el destino ya soporta casi todo lo migrable: empresas, sucursales y usuarios, más `equipos`, `lecturas_equipo`, `planes_mantenimiento`, `ordenes_trabajo`, `orden_tareas`, `orden_repuestos`, `proveedores`, `solicitudes_mantenimiento`, y también `employees` y las tablas de vencimientos que ya están implementadas.