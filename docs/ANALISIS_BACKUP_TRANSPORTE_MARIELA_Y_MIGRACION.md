# Análisis del backup `transporte_mariela_pawluk` y plan de migración al sistema de mantenimiento

**Fecha:** 2026-10-06
**Archivo analizado:** `transporte_mariela_pawluk-20261006.backup` (10,4 MB)
**Destino:** sistema de mantenimiento (CodeIgniter 4, modular, Ferozo)
**Estado:** análisis inicial. No se modificó código ni base del sistema de mantenimiento.

Documento complementario de `ANALISIS_BACKUP_NEUMATICOS_Y_MIGRACION.md`.

---

## 1. Conclusión primero

Este backup es un **sistema completamente distinto** al de neumáticos, y a diferencia de aquel **sí tiene datos reales y vigentes** (operación hasta el 21/10/2026).

Pero **no tiene módulo de mantenimiento**. Es un ERP de transporte con facturación, cobranzas y logística. Ni una sola orden de trabajo, ni historial de mantenimiento, ni proveedores de taller, ni repuestos.

Lo valuable es otro: **este es el único de los dos backup que tiene el padrón de flota y el histórico de odómetro**, que es exactamente lo que el sistema de mantenimiento necesita y lo que el backup de neumáticos no traía.

Los dos backup se complementan: el de neumáticos aporta el diseño del módulo preventivo (pero está vacío) y este aporta flota, kilometraje y choferes (pero sin mantenimiento).

---

## 2. Qué es el backup

| Atributo | Valor |
|---|---|
| Motor | **PostgreSQL 9.2.3** (misma versión que el otro backup) |
| Formato | `pg_dump -Ft` (tar), 10,4 MB |
| Base | `transporte_mariela_pawluk` |
| Encoding | UTF8, `Spanish_Argentina.1252` |
| Objetos | **109 tablas**, 4 vistas, 1 función |
| Integridad referencial | **0 claves foráneas** |
| Prefijos de tabla | `a*` = administración y facturación, `t*` = transporte |
| Datos | **51 tablas con datos, 58 vacías** |

Las 4 vistas son de cálculo de facturación: `calculo_de_netos`, `comprobantes_en_recibos`, `facturas_y_recibos`, `descuento_por_cliente_por_marca`.

Volumen de las tablas con datos relevantes:

| Tabla | Filas | Qué es |
|---|---|---|
| `a19_detalle_comprobantes_de_venta` | 10.309 | Renglones de factura |
| `t11_cuenta_corriente_x_viaje` | 10.113 | Debe y haber por viaje |
| `t07_cargas_combustible_por_viaje` | 10.002 | Cargas de combustible con km |
| `t08_gastos_por_viaje` | 8.022 | Gastos por viaje |
| `t06_origenes_destinos_por_viaje` | 4.000 | Tramos del viaje |
| `t13_otros_haberes_por_viaje` | 3.185 | Otros haberes |
| `a18_comprobantes_de_venta` | 1.783 | Facturas (2022-05-27 a 2026-10-05) |
| `t05_viajes` | 1.740 | Viajes (2022-01-08 a 2026-10-21) |
| `a48_conceptos_recibos` | 1.623 | Conceptos de recibo |
| `t03_origen_destino` | 1.106 | Rutas origen/destino |
| `a47_recibos` | 1.058 | Recibos |
| `a13_cta_cte_clientes` | 990 | Cuenta corriente de clientes |
| `a50_cheques_recibo` | 470 | Cheques de recibo |
| `t01_choferes` | 100 | Choferes |
| `a46_sucursales` | 42 | Matriz banco × sucursal |
| `t02_dominios` / `t10_dominios_por_empresa` | 28 / 28 | Padrón de flota |
| `a45_bancos` | 19 | Bancos |
| `t09_empresas` | 4 | Empresas del grupo |

---

## 3. Qué hace el sistema con esos datos

### 3.1 Transporte (`t*`)
`t01_choferes`, `t02_dominios`, `t03_origen_destino`, `t05_viajes`, `t06_origenes_destinos_por_viaje`, `t07_cargas_combustible_por_viaje`, `t08_gastos_por_viaje`, `t09_empresas`, `t10_dominios_por_empresa`, `t11_cuenta_corriente_x_viaje`, `t12_cuenta_corriente_origenes_destinos_por_viaje`, `t13_otros_haberes_por_viaje`, `t17_kilometraje_x_dominio`.

El núcleo es `t05_viajes`: cada fila es un viaje con dominio, número de viaje, fecha de salida, legajo del chofer, tres campos libres de observaciones, **`km_salida` y `km_regreso`**, fecha de regreso, porcentaje del chofer y estado de contabilización. Los tres campos de observaciones se usan como un cuaderno informal: "ARREGLADO CON CHOFER", "COBRO FLETE FREDDO 130000 ENTREGA 11000", "JOSE LUIS CID FUE A CHILE".

`t06` descompone cada viaje en tramos origen/destino con km y monto. `t07` y `t08` cargan combustible y gastos (peajes,rates,restaurant) con fecha, importe y **kilometraje actual**. `t11` y `t12` son la cuenta corriente por viaje: lo que la empresa debe y lo que el chofer tiene que cobrar.

`t17_kilometraje_x_dominio` sería la tabla ideal de odómetro por dominio (con `registro_service`), pero **está vacía**.

### 3.2 Facturación y cobranzas (`a*`)
`a18_comprobantes_de_venta`, `a19_detalle_comprobantes_de_venta`, `a47_recibos`, `a48_conceptos_recibos`, `a91_otros_conceptos_recibo`, `a13_cta_cte_clientes`, `a50_cheques_recibo`, `a51_movimiento_cheques`, `a45_bancos`, `a46_sucursales`, `a57_caja`, `a63_facturas_a_imprimir`, `a71_comprobantes_pendientes`.

Facturación completa con IVA, DGR (Retención de Ganancias de Río Negro), bonificaciones, netos, comprobantes pendientes de impresión, caja, cheques y su ciclo de vida.

### 3.3 Módulos de esquema definidos pero sin uso
Compras, proveedores, órdenes de pago, stock, proformas, remitos, mecánicos, vehículos de clientes, kits, descuentos y liquidaciones están **definidos pero vacíos**. Es un ERP comprensivo que se usó solo en su parte de transporte y facturación.

---

## 4. Qué se migra al sistema de mantenimiento

### 4.1 Mapeo

| Origen | Destino | Nota |
|---|---|---|
| `t09_empresas` (4) | `empresas` | CUIT, razón social, domicilio. Sin email ni teléfono. |
| `t10_dominios_por_empresa` (28) | scope de `empresa_id` | Las 28 unidades son de un solo CUIT (ver R7). |
| `t02_dominios` (28) | `equipos` | **Fuente principal del padrón.** Ver R2 y R3. |
| `t01_choferes` (100) | `employees` | Sin documento ni CUIL. `codigo_chofer` no es legajo. |
| `t05_viajes.km_regreso` (1.636 útiles) | `lecturas_equipo` | Odómetro al cierre de cada viaje. |
| `t07_cargas_combustible_por_viaje.kilometraje_actual` | `lecturas_equipo` | 10.002 puntos, más densos que los viajes. |
| `t08_gastos_por_viaje` (con "!ACEITE") | `orden_repuestos` parcial | Solo 30 líneas con texto de mantenimiento, y son gastos, no repuestos. Ver R6. |

### 4.2 El padrón de flota (28 camiones)

| Patente | Marca / modelo |
|---|---|
| AD738EA, AE 223 WN, RHB2H00, BEN9A03, RHB1B55, BEN6G63, BEN5D63, BEN4G47 | VOLVO FH500 / FH 500 6X2T |
| NEB021 | VOLVO FH 440 6X2 T |
| AC 532 DD | VOLVO FH460 6X2T |
| GZD 587, OIU 270 | RENAULT PREMIUM 440dXi |
| OJG 526 | MERCEDES BENZ AXOR 2035 S |
| JLH 877 | SCANIA G420 A4X2 |
| HLD283, HWW 968 | SCANIA G380 A4X2 |
| AB 499 OK | SCANIA G360 A4X2 |
| AF 117 ZK | SCANIA R450 A6X2 |
| IUU5G88, IWE2I14, IYT2F23, ITV9J84, IUR1E71 | SCANIA (R440 y sin modelo) |
| FKU3A05 | SCANIA 440 |
| AG007VR | IVECO STRALIS H/W 600 |
| AF 081 MJ | IVECO STRALIS |
| AA PRUEBA | DOMINIO DE PRUEBA (basura) |

**Todas son tractores.** No hay ni un semirremolque en esta base, aunque el sistema de neumáticos sí manejó `armado_unidades` con tractor y semi, y `patente_tanque_semi`. Las relaciones tractor-acoplado del destino no tienen origen acá: quedan para carga manual.

### 4.3 Lo que NO se migra porque no existe

`ordenes_trabajo`, `planes_mantenimiento`, `proveedores`, `orden_repuestos`, `solicitudes_mantenimiento`, `lecturas` horómetro, historial de mantenimiento: **cero filas de origen**. No hay nada que migrar en el dominio núcleo del proyecto.

Eso es una pregunta abierta para vos: **si hay 28 camiones operando desde 2022 y no hay un solo registro de mantenimiento en ninguno de los dos sistemas, ¿dónde se están anotando los services hoy?**

---

## 5. Riesgos y problemas de calidad de datos

**R1 — La tabla de odómetro dedicada está vacía.** `t17_kilometraje_x_dominio` tiene la estructura ideal (dominio, fecha, km, empresa, número de viaje) pero 0 filas. El odómetro real está disperso en `t05_viajes.km_salida` / `km_regreso` y en `t07.kilometraje_actual`. Hay que **agregarlo y decidir la fuente de verdad** antes de importar: para `lecturas_equipo` conviene el `km_regreso` del viaje (cierre de trayecto, fecha fiable) y descartar el `km_salida` o tomarlo solo cuando no hay regreso.

**R2 — El equipo no tiene código ni ficha técnica.** `t02_dominios` tiene solo `dominio`, `marca_modelo`, `codigo_chofer`, `valor_unidad`, `cantidad_neumaticos`. **No hay año, chasis ni motor**, que son campos esenciales de la ficha del destino. `marca_modelo` es texto libre ("VOLVO-FH500 6X2T", "SCANIA", "VOLVO/FH 5006X2T") y hay que parsearlo a `marcas` + `modelos` a mano, con criterio acordado. `codigo` hay que sintetizarlo, igual que en el otro backup.

**R3 — El odómetro no es homogéneo.** En `t05_viajes` hay dominio con km en un solo sentido (`AB 499 OK` viaje 1: `km_salida` 258.741 y `km_regreso` 528.616; `OJG 526` viaje 2: salida 0, regreso 786.638) y `AF 117 ZK` viaje 8 va 24.666 → 29.334, o sea que el odómetro **arranca en un número distinto por dominio**. Hay que normalizar por dominio antes de calcularlo y validar que nunca retroceda, que es la invariante de `lecturas_equipo`.

**R4 — 30% de los viajes tienen fecha de regreso inválida.** 519 de 1.740 viajes tienen `fecha_regreso` nula, o anterior a la salida (el viaje 11 de `AF 117 ZK` sale 26/05/2022 y "regresa" 08/04/2018). Para lectura de odómetro **solo sirven los 1.636 con km de regreso y fecha coherente**.

**R5 — `codigo_chofer` no es un legajo y está sucio.** Mezcla códigos de 13 dígitos con enteros chicos (28, 50, 4, 8) y hay un registro que no es un chofer: "Joselo Key Sistemas" (un proveedor de software, código 20233478629). Además **el mismo código aparece en varios dominios** (11000000000 en AC 532 DD, AB 499 OK, RHB2H00 y BEN9A03), así que es chofer por defecto, no asignación real. Para `employee_equipment_assignments` hay que confirmar con el cliente cuál es la asignación verdadera, porque acá no la hay.

**R6 — No hay datos de mantenimiento, ni siquiera de repuestos.** Solo 30 líneas de `t08_gastos_por_viaje` mencionan mantenimiento, y son gastos de viaje ("ACEITE IDRAULICO + 1 ROTACION", 1.280,00). `a32_mano_de_obra` tiene 2 filas y `a61_mecanicos` está vacía. No se puede reconstruir costo de mantenimiento ni historial desde acá.

**R7 — Las 4 empresas no son 4 tenants.** `t09_empresas` lista PAWLUK DUBLIN ARIEL EDUARDO, PAWLUK DUBLIN GRISELDA, PAWLUK DUBLIN MARIELA ELISA y PAWLUK MARIO, pero **los 1.740 viajes y las 28 unidades son del CUIT 23-28059786-4 (PAWLUK DUBLIN MARIELA ELISA)**. Si se crean 4 empresas en el destino, tres quedan sin nada. Hay que decidir: ¿una sola empresa, o se cargan las 4 como estructura societaria sin flota?

**R8 — `a46_sucursales` no es una tabla de sucursales.** Son 42 combinaciones `codigo_banco` + `sucursal` + `nombre`, es decir la matriz de bancos con sus sucursales (OBERA, POSADAS, CORRABA, ROSARIO, MENDOZA…), y los nombres se repiten: OBERA aparece 6 veces. **No se debe importar como `sucursales`.** El destino va a necesitar que definan las sucursales reales de la empresa.

**R9 — Ruido en el padrón.** `AA PRUEBA` / "DOMINIO DE PRUEBA" es basura de prueba; `RHB1C77` tiene `marca_modelo` = "2021" (un año, no un modelo) y sin chofer; `BEN6G63` y `RHB1B55` también están sin chofer. `valor_unidad` está en cero en 26 de 28 casos, así que no hay valuación de activos. `cantidad_neumaticos` solo tiene dato en `ITV9J84` (10).

**R10 — Cambio de motor y volumen.** PostgreSQL 9.2.3 a MySQL/MariaDB, con ~57.000 filas en las tablas de transporte y 13.000 en facturación. `numeric(19,4)` en la facturación y `numeric(10,2)` en transporte, y hay que revisar `timestamp with time zone`. Sin FKs, el orden de carga es manual.

---

## 6. Los dos backup juntos

| | `neumaticos_pawluk` | `transporte_mariela_pawluk` |
|---|---|---|
| Tamaño | 365 KB | 10,4 MB |
| Que es | Gestor de neumáticos y cubiertas | ERP de transporte y facturación |
| Estado | **Vacío** (12 tablas de catálogos) | **Con datos vigentes** (51 tablas) |
| Mantenimiento | Modelo preventivo completo, sin datos | **Ninguno** |
| Aporta a la migración | Diseño de OT, planes, historial, gomería | **Flota (28), odómetro (12.652 puntos), choferes (100)** |

Conclusión conjunta: **la migración de datos tiene una sola fuente real, y es este**. De acá sale `equipos`, `lecturas_equipo` y `employees`. El sistema de neumáticos sirve como referencia de diseño del módulo preventivo y de gomería, pero no aporta ni una fila. Y las órdenes de trabajo, los planes y los repuestos **no tienen origen en ninguno de los dos**: arrancan de cero, que es consistente con el piloto controlado que pide la especificación (§17).

---

## 9. Qué hace falta para seguir

1. **Confirmar el origen del mantenimiento.** Si la empresa hoy anota services en una planilla, un chat o directamente en `t08`, hay que definir cómo se carga ese histórico. Sin esto, el sistema arranca sin historial.
2. **Definir el padrón de flota definitivo:** las 28 unidades, cuáles están activas, cuál es el código interno y la ficha técnica (año, chasis, motor) que acá no existe.
3. **Decidir la empresa:** una sola (la del CUIT con flota) o las cuatro.
4. **Confirmar las sucursales reales**, porque `a46_sucursales` no sirve.
5. **Confirmar la asignación real de choferes** a cada unidad, si se quiere cargar.

Con esto ya se puede escribir el importador de `equipos`, `lecturas_equipo` y `employees`, que es el 80% del valor de la migración. El resto se carga por la interfaz durante el piloto.
---

## 8. La cuenta corriente por viaje (investigación detallada)

Esta sección amplía la decisión D3 del plan. Las tres tablas juntas son 14.540 filas y representar el dinero que la
empresa mueve con sus choferes.

### 8.1 Qué es cada tabla

| Tabla | Filas | Significado |
|---|---|---|
| `t11_cuenta_corriente_x_viaje` | 10.113 | Movimiento de dinero entre empresa y chofer, anotado sobre un viaje |
| `t12_cuenta_corriente_origenes_destinos_por_viaje` | 1.242 | Comprobantes facturados por tramo (importe total: 609.900, muy menor al resto) |
| `t13_otros_haberes_por_viaje` | 3.185 | Haberes adicionales del viaje, sobre todo viáticos |

`t13` es claramente **viático por día**: las observaciones son `"4 dias"`, `"2 dias chile y 4 dias brasil"`, `"2 dias
mendoza"`. Importe total 183.076.360.

`t12` está casi sin usar: solo 609.900 en total, frente a 3.943.266.395 de facturación real.

### 8.2 La escala real, comparada con el resto del sistema

| Magnitud | Importe |
|---|---|
| Facturación (`a18`, 1.783 comprobantes) | 3.943.266.395 |
| Combustible (`t07`, 4.585.552,92 litros) | 2.319.883.936 |
| Gastos de viaje (`t08`) | 131.197.923 |
| Viáticos (`t13`) | 183.076.360 |
| Débitos de `t11` | 119.507.235 |
| Créditos de `t11` | 1.020.250.027 |

El combustible representa el **59% de la facturación**, lo que es coherente con transporte. Esa es la única cuenta que
cuadra con el resto del sistema.

### 8.3 Los tres problemas que la hacen no migrable

**Problema 1 — Se accounting en seis monedas y los campos no son confiables.** `t11` tiene `debits` y `credits` en
moneda original, más `debits_moneda` y `credits_moneda`, más `importe_moneda` y `cotizacion`. Según qué columna se lea,
el total cambia por completo:

| Lectura | Débitos | Créditos |
|---|---|---|
| Columna original | 119.507.235 | 1.020.250.027 |
| Columna `_moneda` | 74.478.784 | 877.981.023 |
| Original × cotización | 9.852.929.279 | 33.740.172.547 |

Ninguna de las tres se acerca a una cifra confiable. La columna `_moneda` viene nula en las filas de moneda extranjera
(4.921 de 10.113 movimientos), y multiplicar por la cotización infla el resultado cuatro veces. Hay monedas con
cotización 1, 8, 30 y más. Se lee "300 DOLARES" y "brasil" entre las observaciones, así que hay operación
internacional real.

**Problema 2 — El catálogo de conceptos está perdido.** `t04_conceptos_choferes` tiene **0 filas**, y `t11` usa **45
códigos de concepto distintos** y `t13` usa 11 más. No hay forma de saber qué significa el 47, el 65, el 94 o el 98
salvo por el texto libre de `observaciones`. Y ese texto libre es del tipo `"RETIRO DE RUMAOS"`, `"CHEQUE GUILLERMO"`,
`"MERCOVIA// 270 PAGO PAOMA"`, `"saldo deudor viaje 11-12"`, `"PESAJE SAN LUIS"`.

Es el mismo problema de los códigos sin catálogo que ya se había detectado en el otro backup, pero acá es dinero.

**Problema 3 — No reconcilia con el combustible ni con los gastos.** De 1.703 viajes que tienen movimiento en `t11` y
también combustible o gastos, **solo 3 coinciden exactamente**. La razón media entre los débitos de `t11` y el
combustible más los gastos es **4,0**, no 1,0. Son dos libros distintos que nadie cruzó.

Ejemplo, viaje `AC 532 DD` número 4: el combustible y los gastos suman 239.131, y los débitos anotados en `t11` suman
14.122,50 repartidos en tres monedas distintas. Con la conversión da 243.000, casi exacto, pero ese viaje es de los 3
que cierran. El viaje `HWW 968` número 1 tiene 169.622 de combustible y **cero débitos** en `t11`.

### 8.4 El saldo nunca se liquida

`porcentaje_chofer` es 14,00 en los 1.739 viajes con dato, sin excepción. Pero el saldo acumulado de `t11` crece todos
los años y nunca se limpia:

| Año | Débitos | Créditos | Saldo del año |
|---|---|---|---|
| 2022 | 10.374.459 | 30.630.089 | 20.255.630 |
| 2023 | 12.828.509 | 64.870.578 | 52.042.069 |
| 2024 | 11.997.407 | 178.599.047 | 166.601.640 |
| 2025 | 21.917.655 | 326.283.388 | 304.365.733 |
| 2026 | 17.269.584 | 277.567.820 | 260.298.237 |

**Los 28 dominios tienen saldo negativo**, es decir, hay plata a favor de los choferes en las 28 unidades, sin
excepción. `AA PRUEBA`, la patente de prueba, es la única con saldo positivo.

### 8.5 Las tablas de liquidación existen y están vacías

Este es el hallazgo más útil de toda la investigación. Existen dos tablas diseñadas exactamente para cerrar esta cuenta:

```
t15_liquidaciones(cuit_emrpesa, numero_liquidacion, legajo_chofer, fecha, observaciones...)
t16_detalle_liquidaciones(cuit_empresa, numero_liquidacion, item, dominio,
                          numero_viaje, flete, combustible, chofer, gastos)
```

`t16` tiene **exactamente el desglose que hace falta**: flete, combustible, chofer y gastos, por viaje y por dominio.

**Las dos tienen 0 filas.** En esta base nunca se registró una liquidación.

Eso significa dos cosas: primero, que el saldo creció sin control durante cuatro años porque el circuito de liquidación
nunca se usó. Segundo, que **el cliente ya había diseñado el modelo correcto** y nunca lo implementó.

### 8.6 Recomendación

**No migrar la cuenta corriente por viaje como libro operativo. Cerrarla antes.**

El理由 es concreto, no prudencia:

1. Sin catálogo de conceptos no se puede interpretar ni un solo movimiento.
2. Con las monedas inconsistentes, ningún total es confiable.
3. Nunca se liquidó, así que no hay una forma de saber qué parte está realmente pendiente.
4. No reconcilia con el combustible, así que导入lo al sistema de mantenimiento contaminaría los costos por equipo que
   la especificación §11.14 pide informar.

Lo que sí corresponde migrar es lo que **reconcilia**: viajes, tramos, combustible y gastos. Ahí la aritmética cierra:
el combustible es el 59% de lo facturado, que es lo esperable en transporte, y eso alimenta el costo por kilómetro.

Y hay un regalo del legacy para la especificación: **el desglose de `t16_detalle_liquidaciones` es el modelo del
informe de costo por equipo que la versión 1.2 de la especificación agrega en §11.14.** Flete, combustible, chofer y
gastos por viaje y por dominio. Es lo mismo que se pidió, y el cliente ya lo había pensado.

Los 800 millones de saldo son un hecho económico del legacy. La decisión de liquidarlo o nó es del cliente, pero no es
información que un sistema de mantenimiento deba heredar y calcular.