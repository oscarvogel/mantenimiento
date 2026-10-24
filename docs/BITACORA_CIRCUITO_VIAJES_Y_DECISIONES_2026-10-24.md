# Bitácora — Circuito de Viajes: definiciones de la reunión y decisiones pendientes

**Fecha:** 2026-10-24
**Origen:** respuestas de Mariela (11 notas de voz de WhatsApp del 23/10/2026) al correo de
Oscar con las 8 preguntas del circuito de Viajes (apertura, tramos, combustible, gastos,
cuenta corriente y liquidación).
**Alcance:** informe de definiciones funcionales. No incluye implementación ni cambios de código.
**Estado:** a revisar con Mariela antes de cerrar el documento del circuito.

---

## 1. Resumen ejecutivo

Las 8 preguntas del correo de Oscar tienen respuesta. Siete son inequívocas; la de **moneda de
los fletes** tiene respuesta pero revela que el concepto "flete de facturación" no es único, y
eso obliga a cambiar una parte de la propuesta original.

De las 4 decisiones que Oscar ya había dado por confirmadas, 3 quedan igual y 1 necesita una
palabra más precisa.

Adicionalmente, las respuestas descubren **4 requisitos que el documento del circuito todavía no
contiene**:

1. Cantidad de pallets por tramo y tipo de semirremolque (la base real del importe del flete).
2. Moneda y cotización en el flete del tramo (hoy solo existen en la cuenta corriente).
3. Importe de venta sin factura ("flete en negro") que igual debe quedar reflejado.
4. Cierre de viaje con pendientes marcados, en lugar de bloqueo.

La respuesta a la pregunta 8 (el tilde de "Contabilizado") **corrige el supuesto del correo**:
no es un control de contabilidad ni de facturación, es el estado de pago de la liquidación del
chofer, y lo marca la persona que entrega el dinero.

---

## 2. Transcripción de las notas de voz

Orden cronológico. Archivo de origen: `WhatsApp Ptt 2026-10-23 at HH.MM.SS PM.ogg`.

### 2.1 — 16:12:01

> Hola Oscar, ¿cómo estás? Mirá, yo estoy justo esperando un turno acá y aprovecho para
> contestarte las preguntas, estoy leyendo acá el PDF del programa.
>
> Y respecto a la pregunta, ¿el viaje se abre y sale en el mismo momento o se planifica para
> otra fecha? Yo abro el viaje cuando le doy la orden de carga, que yo sé que seguro que va a
> cargar, por ejemplo, el lunes cargan en Tol Maderas para Chile. Entonces ya se abre el viaje
> de esa patente, origen destino,

*[La grabación se interrumpe en este punto. La respuesta queda completa en lo esencial.]*

### 2.2 — 16:15:01

> Y la consulta segunda, siempre hay un solo chofer responsable de la liquidación. Cada chofer
> está asignado a un camión. Mm, nunca le cambio los camiones, excepto que quedó con algún
> problema de semi o de tractor. Es muy excepcional, ¿viste? Es por caso de emergencia.
> Siempre cada chofer tiene asignado un camión y no se le cambia.

### 2.3 — 16:17:40

> Y después en el tema de los fletes, ¿se cargan siempre en pesos o puede haber tramos en otra
> moneda?
>
> Te explico, por ejemplo, hay empresas, eh Arauco, eh los chilenos. Por ejemplo Arauco, yo a
> ellos le paso la cotización en dólares y el día que el camión descarga vemos la cotización del
> banco, tipo vendedor, y ahí le hago la factura a Arauco. Esa cotización en dólares los
> choferes ni saben qué se pasa, ni idea ellos. Yo lo sé cuándo va a pasar a pesos, en qué
> monto, el día que él descarga. Porque viste que eso fluctúa ahí la cotización del dólar.
>
> Pero podría eh tener la… el monto exacto para facturar el día que descarga el camión. Ellos
> generan un memo, los de Arauco, y ahí queda asentado el valor el flete.
>
> Y los chilenos, por ejemplo, eh ellos directamente me pagan en billete por transferencia a otra
> cuenta que es solo dólares y bueno, y ahí yo voy viendo el valor de la liquidación del
> chofer.
>
> Eh y la cuestión de facturación a los chilenos de forma directa no se hace. Es una factura
> manual en dólares que yo le paso así escaneado, ellos no tienen ningún problema. Es como una
> constancia de recibo por lo que te diré.
>
> Y para mí, para mi contabilidad, eh estoy pensando, yo sé esos cuatro mil dólares a cuánto me
> pesificados. Me mandan la transferencia mañana o no, yo sé que eso tengo que pesificar a 1530.
>
> Pero Oscar, por una cuestión de no meter todo el valor del flete para no pagar ganancias, ya
> sabemos que te cortan la cabeza con el 35% ahí, entonces yo no pongo el valor total de los
> cuatro mil dólares pesificados, ¡ni loca! Yo declaro una factura E de un valor menor.
>
> Entonces ahí estoy pensando en ese ítem de mi venta en valor eh digamos real cuántos pesos me
> van a entrar a mí por esos cuatro mil dólares. ¿Dónde yo puedo reflejar en el sistema eso?
>
> Porque la factura E va a ser del mismo valor de la liquidación del chofer. Porque es una factura
> E que es solo para informar el movimiento de la salida del camión y pagar menos ganancias y
> para tener la base de la liquidación del chofer.

### 2.4 — 16:17:49

> En cambio la factura E que le hago a Arauco sí es mi valor real porque Arauco me paga todo por
> transferencia en pesos.

### 2.5 — 16:18:24

> Y otro tema, la los fletes que no son facturados eh quedan también reflejados ahí en el
> sistema, ¿sí? Porque por ejemplo, puede ser que me vaya con un flete totalmente en negro, sin
> meter factura, al chofer le puedo bajar la el porcentaje para la liquidación, pero no hay
> ninguna factura A que avale ese flete. Igualmente queda reflejado en el sistema, ¿sí?, como una
> venta.

### 2.6 — 16:19:25

> Vos me preguntabas a los haberes respecto de del chofer o de los viajes, mejor sería, es el
> viaje, el monto, digamos, se paga del flete, a veces es, bueno, son 2 millones de pesos el
> flete, a veces es, bueno, es 85.000 pesos por pallet, okey, tengo que cuántos pallet cargó,
> porque hay semis que cargan 30 pallet y hay semis que cargan 26 o 28 pallet.
>
> Entonces ahí se hace el prorrateo del flete según la cantidad de pallet, porque hay dos clases
> de de semirremolques, los de 14.50 que llevan 26 pallet, quizás 28 si son esos pallet
> angostos, o los semis de 15.30 que llevan 30 pallet. ¿Viste? Ahí tengo esa clasificación según
> el flete.

### 2.7 — 16:20:52

> Y ahora en el tema de el cierre del flete, solo se hace cuando están completos todos los
> gastos. Por eso cuando a veces en los últimos días del mes o los primeros días del mes quiero
> ver un viaje y ahí le reclamo a mi secretario que me cargó los litros de la carga de
> combustible, pero no me asentó eh los pesos, porque muchas veces ellos se manejan con remitos
> nada más y en el remito está reflejado la cantidad de litros, que a la hora del promedio del
> chófer, que eso sí lo hago ahí en el momento cuando el tipo está para cobrar, se le hace el
> promedio de combustible para ver cuánto gastó en el viaje.
>
> Y bueno, entonces, el secretario tiene ahí los remitos de carga de combustible y no tiene los
> pesos, para eso tiene que entrar, por ejemplo, al programa de YPF y ahí saca el valor en pesos
> o eh si son facturas de otras estaciones de servicio, hay un sistema que tenemos ahí con los
> mendocinos y con los de Entre Ríos que cada vez que carga el camión, ellos van agregando ahí
> en una cuenta corriente en un drive, ¿viste? De ahí vamos viendo el valor en pesos.

### 2.8 — 16:21:17

> Y el tema de cómo yo le pago al chofer. Eh mirá, la ideal es que sea una vez al mes pero
> últimamente están haciendo un viaje y están cobrando por un tema de caja, por supuesto, ¿no?
> Estamos al moño todos con los gastos.

### 2.9 — 16:22:08

> liquidación nunca le imprimo, nunca. Es mostrarle en la pantalla eh cómo le dio los números.
> Ellos se anotan en su cuaderno si quieren o simplemente leen ahí el número que aparece en la
> pantalla, porque ahí se le marca en claro cuál es el porcentaje total, cuál es el total de
> viáticos, y cuánto suma todo su su haber.
>
> Y ahí bueno, se le dice tenés tanto de adelanto, entonces para cobrar ahora tenés esto, porque
> por ahí se confunden si vos le decís tu haber es qué sé yo, 500.000 pesos, dicen "ay, ¿mi
> sueldo es 500.000?", "no", le digo, "tu sueldo es de 2 millones, pero retiraste esto,
> retiraste aquello". Entonces siempre se le aclara bien la cuenta.

### 2.10 — 16:22:23

> Ese tilde de contabilizado es cuando se le paga al chofer eh su haber. Eh a eso le para eso está
> esa tilde roja.

### 2.11 — 16:22:29

> Eso lo hace Carlos, ¿viste? Porque él entrega el dinero ahí.

---

## 3. Respuestas punto por punto

| # | Pregunta | Respuesta | Consecuencia para el diseño |
|---|---|---|---|
| 1 | ¿El viaje se abre y sale en el mismo momento, o se planifica para otra fecha? | Se abre en un solo paso, cuando se da la orden de carga, que ya implica que va a cargar. | No hace falta el estado "Planificado". El viaje nace **En curso**. El estado "Cerrado" sigue haciendo falta porque el cierre exige km de regreso. |
| 2 | ¿Siempre hay un solo chofer que liquida, o puede haber varios? | Siempre uno solo. Cada chofer tiene camión fijo; el cambio es excepcional (problema de semi o de tractor). | Se descarta el modelo multi-chofer. El viaje referencia la asignación vigente chofer-equipo. La excepción se registra como cambio con motivo. |
| 3 | ¿Los fletes se cargan siempre en pesos o puede haber tramos en otra moneda? | Hay tramos en dólares (Arauco y los chilenos). Pero conviven **varios importes distintos** en el mismo flete. | Ver §4. Requiere moneda y cotización en el flete del tramo, no solo en la cuenta corriente. |
| 4 | ¿Los otros haberes se cargan por viaje completo o por tramo? | Por viaje. Y el haber se calcula sobre el flete **prorrateado por pallets** según el tipo de semi. | Los viáticos, adelantos y bonificaciones son por viaje. Aparece un requisito nuevo: pallets por tramo y tipo de semi. |
| 5 | ¿El cierre debe bloquearse si no hay combustible o gastos cargados? | En la práctica hoy **bloquea**: "solo se hace cuando están completos todos los gastos". Pero el combustible entra en dos tiempos (litros por remito, pesos después). | Contradice la recomendación de "advertir, no bloquear". Propuesta: cierre permitido con **pendiente marcado y responsable**. |
| 6 | ¿La liquidación se paga por viaje o por período? | El ideal es mensual, pero hoy se cobra **por viaje** (caja). El chofer ya descuenta adelantos: "tenés tanto de adelanto, entonces para cobrar ahora tenés esto". | La pantalla muestra haber acumulado, cobros ya hechos y total a cobrar hoy. No hace falta el modelo de pagos parciales. |
| 7 | ¿Necesitan imprimir la liquidación y el resumen? | **No.** Nunca imprime. Se muestra en pantalla; el chofer se anota en su cuaderno. | **Impresión fuera del alcance.** |
| 8 | ¿Usan el tilde "Contabilizado" y la fecha? ¿Quién lo completa? | Es el tilde de **"se le pagó el haber al chofer"**, no un control contable. Lo marca **Carlos**, que entrega el dinero. | El campo `contabilizado` se reinterpreta como *liquidación pagada* (con fecha y usuario). No es un estado de facturación. |

### 3.1 Estado de las 4 decisiones ya confirmadas por Oscar

| Decisión | Estado | Observación |
|---|---|---|
| El % del chofer se aplica sobre el flete de liquidación del tramo, no sobre el de facturación | **Vigente con un matiz** | La base es el **valor declarado** (el de la factura E), que puede ser menor que la venta real. Conviene decirlo así: "el % se aplica sobre el importe declarado del tramo, que es la base de la liquidación". |
| El margen del viaje se calcula con la comisión, no con la liquidación final; adelantos y devoluciones afectan la liquidación del chofer, no el margen | **Vigente** | Sin objeciones. |
| Gastos y combustible se pueden asignar opcionalmente a un tramo; si no, suman al viaje pero no al tramo | **Vigente** | Consistente con que los otros haberes (viáticos, adelantos) son por viaje. |
| El cierre del viaje requiere km de regreso y genera la lectura del equipo | **Vigente** | Sin objeciones. Se combina con el pendiente de gastos: el cierre exige km de regreso, pero los gastos pendientes no bloquean. |

---

## 4. Hallazgos que modifican la propuesta original

### 4.1 "Flete de facturación" no es un concepto único

En un mismo tramo conviven cinco importes distintos:

| # | Importe | Qué es | Dónde vive hoy |
|---|---|---|---|
| 1 | **Precio pactado** | 85.000 por pallet, o 2.000.000 por el flete completo. | `viaje_tramos.precio`, `importe_inicial` |
| 2 | **Importe prorrateado real** | 85.000 por los pallets que efectivamente cargó. | `viaje_tramos.importe` |
| 3 | **Valor real de la venta** | 4.000 USD, pesificado a la cotización del banco del día de descarga. | No está en el viaje; se refleja en la cuenta corriente. |
| 4 | **Valor declarado** | El de la factura E, deliberadamente menor para no-NCear con el 35% de ganancias. **Es la base de la liquidación del chofer.** | No está modelado como tal. |
| 5 | **Valor de contabilidad** | 4.000 USD pesificados a la tasa fija de 1.530, como anticipo hasta que llega la transferencia. | `viaje_cuenta_corriente.cotizacion`, `*_moneda` |

Consecuencias:

- La propuesta original asumía que "flete de facturación" y "flete de liquidación" difieren solo
  por el porcentaje. En realidad difieren en **moneda**, **tipo de cambio** y **monto declarado**.
- Hay **dos tipos de cambio distintos** en juego para un mismo flete: la cotización del banco del
  día de descarga y el fijo de 1.530 de contabilidad. Hay que decidir cuál acompaña al viaje.
- La factura E no la emite el sistema de Viajes. La arquitectura ya lo define así: Viajes no crea
  facturas fiscales.

> **Hipótesis a confirmar, no un hecho.** El repositorio no documenta la semántica de las columnas
> `precio`, `importe`, `importe_inicial` y `porcentaje_facturacion` de `viaje_tramos`. La lectura
> que calza exacto con lo que dijo Mariela es: `importe_inicial` = total pactado (2.000.000),
> `precio` = 85.000 por pallet, `importe` = prorrateo real (85.000 x pallets cargados),
> `porcentaje_facturacion` = porcentaje del flete imputado a ese tramo. Hay que validarlo contra un
> viaje real antes de escribir código.

### 4.2 El tilde de "Contabilizado" es estado de pago, no estado contable

Si se modela como "facturado / contabilizado", se construye el flujo equivocado. Es:

> **Haber del chofer liquidado y pagado**, con fecha y con el usuario que paga (Carlos).

Los campos `contabilizado` y `fecha_contabilizacion` ya existen en la tabla `viajes` importada y
hay que reinterpretarlos, no duplicarlos.

### 4.3 Los fletes "en negro" son una categoría de primera clase

Un flete puede ir sin factura A y aun así:

- el viaje **debe reflejar la venta**;
- el % del chofer se puede **bajar** para ese viaje.

Hoy no hay dónde guardar "importe de venta sin respaldo documental": `viaje_tramos` no tiene
moneda, ni marca de "sin factura", ni referencia al comprobante. El comprobante sí existe en
`viaje_cuenta_corriente_tramos` (`tipo_comprobante`, `punto_venta`, `numero_comprobante`), lo que
sugiere que en el legacy los comprobantes vivían en la cuenta corriente y no en el flete.

### 4.4 El cierre no puede ser un bloqueo simple

Mariela sostiene que hoy el cierre exige gastos completos, y en el mismo mensaje explica por qué
eso duele: los **litros se cargan por remito** y los **pesos a veces cargan después** (hay que
entrar al portal de YPF, o a la cuenta corriente en Drive de las estaciones de Mendoza y Entre
Ríos). Si el cierre bloquea, el viaje no cierra nunca y se acumula el gris que hoy se resuelve
reclamando a mano al secretario.

Propuesta: **el cierre se permite y deja el pendiente marcado** ("falta el importe de la carga de
combustible"), visible en el dashboard de Viajes hasta que se complete. El pendiente automatiza
exactamente el reclamo que hoy es manual.

### 4.5 Falta el dato de pallets

La regla real de cálculo del importe del tramo es **prorrateo por pallets según tipo de
semirremolque**:

| Tipo de semi | Pallets |
|---|---|
| 14,50 m | 26 (28 con pallets angostos) |
| 15,30 m | 30 |

Pero `viaje_tramos` guarda `cantidad_kilometros` y `toneladas`, **no pallets**. El catálogo
`viajes_unidades_carga` tiene "Pallet" con símbolo `PLT`, pero sin cantidad ni tipo de semi. Sin
eso, el importe del tramo no se puede calcular en el sistema nuevo: hay que modelar **cantidad de
pallets por tramo** y **capacidad del equipo**.

---

## 5. Panorama del proyecto

**Dónde vive el trabajo:** `C:\Programacion\mantenimiento-viajes`, worktree del repositorio
`mantenimiento`, rama `feat/viajes-dashboard`. Estado frente a `origin/main`: **1 commit adelante y
48 atrás**. La rama está desactualizada y conviene reconciliarla antes de abrir la primera etapa.

### 5.1 Ya implementado

| Pieza | Ubicación |
|---|---|
| Lector de backups `pg_dump -Ft` del ERP de transporte (PostgreSQL 9.2.3) | `app/Legacy/Transporte/LegacyBackupReader.php` |
| Paquete portable ZIP (manifest + CSV) para subir al hosting sin shell | `app/Legacy/Transporte/LegacyTransportPackage.php` |
| Mapper de las 7 tablas del agregado legacy -> dominio nuevo | `app/Infrastructure/TripImports/LegacyTripRowMapper.php` |
| Esquema del agregado: `viajes`, `viaje_tramos`, `viaje_combustible`, `viaje_gastos`, `viaje_cuenta_corriente`, `viaje_cuenta_corriente_tramos`, `viaje_otros_haberes` | `app/Database/Migrations/2026-10-17-140000_CreateTripHistoryAggregate.php` |
| Maestros: clientes, domicilios, tipos de carga, unidades de carga, categorías de gasto, medios de pago, tipos de documento y porcentajes de liquidación por empleado con vigencias | `app/Database/Migrations/2026-10-13-090000_CreateTripMasterCatalogs.php`, `app/Application/TripMasters/TripMasterCatalog.php` |
| Pantallas: `/viajes` (dashboard de actividad, km y combustible), `/viajes/maestros`, `/superadmin/viajes/importar` (vista previa + confirmación transaccional) | `app/Controllers/TripsDashboard.php`, `TripMasters.php`, `TripImports.php` |
| Pruebas | `tests/feature/TripImportsHttpTest.php`, `TripsDashboardHttpTest.php`, `tests/unit/Infrastructure/TripImports/LegacyTripRowMapperTest.php`, `LegacyTripPackageReaderTest.php`, `tests/unit/TripMasters/TripMasterCatalogTest.php` |

Volumen histórico real de la base de Mariela, verificado sobre los backups
(`docs/BITACORA_IMPORTACION_VIAJES_HISTORICOS.md`):

| Tabla legacy | Tabla nueva | Filas (mariela) |
|---|---|---|
| `t05_viajes` | `viajes` | 1.740 |
| `t06_origenes_destinos_por_viaje` | `viaje_tramos` | 4.000 |
| `t07_cargas_combustible_por_viaje` | `viaje_combustible` | 10.002 |
| `t08_gastos_por_viaje` | `viaje_gastos` | 8.022 |
| `t11_cuenta_corriente_x_viaje` | `viaje_cuenta_corriente` | 10.113 |
| `t12_cuenta_corriente_origenes_destinos_por_viaje` | `viaje_cuenta_corriente_tramos` | 1.242 |
| `t13_otros_haberes_por_viaje` | `viaje_otros_haberes` | 3.185 |
| **Total del agregado** | | **38.304** |

Maestras legacy disponibles: 100 choferes (`t01`), 28 dominios (14 AR / 13 Mercosur-BR / 1 sin
clasificar), 1.106 orígenes-destinos (`t03`), 97 conceptos de chofer (`t04`), monedas (`t18`).
Clientes: 240 códigos usados por Mariela, 239 resueltos contra el padrón canónico; queda sin
correspondencia el código `0`.

Casos que el importador ya marca y **no corrige** en silencio: 2 viajes de patente `AA PRUEBA`
(sin clasificar), `NEB021` con la evidencia de país más débil, 6 viajes con `fecha_regreso`
anterior a `fecha_salida`, 1 viaje con `fecha_salida` posterior a la fecha del backup y 6 filas
hijas huérfanas conservadas enteras.

### 5.2 Lo que todavía no existe

Es justamente el circuito que Oscar quiere construir:

- **Alta manual de viaje**, apertura y **estado** del viaje. La tabla `viajes` no tiene columna de
  estado: solo `fecha_salida`, `fecha_regreso`, `km_salida` y `km_regreso`.
- **Caso de uso de cierre** de viaje, con la lectura de equipo que lo acompaña.
- **Todo el circuito de liquidación**: no hay caso de uso, ni tabla de liquidaciones, ni adelantos,
  ni cuenta corriente por chofer. El catálogo de porcentajes existe, pero la propia documentación
  aclara que "configura porcentajes; no calcula ni contabiliza liquidaciones"
  (`docs/MAESTROS_VIAJES.md`).
- **Moneda en el flete**: `viaje_tramos` no tiene moneda ni cotización; la moneda solo existe en
  `viaje_cuenta_corriente`.
- **Pallets** como unidad con cantidad, y **tipo o capacidad de semi**.
- **Asignación chofer-camion dentro del viaje**. La tabla `employee_equipment_assignments` ya
  existe como maestro transversal (`app/Database/migrations/2026-09-07-171000_CreateEmployeeEquipmentAssignmentsTable.php`)
  y es el lugar natural; el viaje debe referenciarla, no duplicarla.
- **Facturación** (factura E). La arquitectura lo define explicitamente: Viajes no crea facturas
  fiscales; eso es del contexto Liquidaciones y Facturación
  (`docs/ARQUITECTURA_PLATAFORMA_GESTION_OPERATIVA.md`).
- **Impresión**: no hace falta, Mariela la descartó.

### 5.3 Decisiones de arquitectura ya tomadas que encajan con las respuestas

- Viajes es dueño de *planificación, ejecución, hitos, carga, cliente operativo, cierre e
  incidencias*. Consume Activos, Empleados y Medición. **No** modifica `equipos.km_actual` ni
  emite facturas fiscales.
- Estados sugeridos para el viaje: `borrador -> planificado -> asignado -> en curso -> finalizado
  -> validado`. **Con la respuesta 1, "Planificado" sobra**: el viaje nace En curso.
- La Fase 2 del roadmap es justamente "Viajes y catálogos comerciales mínimos", con circuito
  `planificar -> asignar -> ejecutar -> finalizar -> validar`.
- Los estados de liquidación previstos (`borrador -> calculada -> revisada -> aprobada ->
  exportada/facturada`) son hipótesis a validar con casos reales, y este informe aporta los
  primeros.

---

## 6. Definiciones pendientes de confirmar

Ordenadas por impacto. Las seis primeras bloquean el diseño de tablas.

1. **¿Qué importe queda en el viaje?** Propuesta: el **declarado** (base de la liquidación del
   chofer) con su moneda; el valor real en dólares en la cuenta corriente, con la cotización del
   día. La diferencia queda visible.
2. **¿Qué tipo de cambio acompaña al viaje?** ¿La cotización del banco del día de descarga o el
   fijo de 1.530? Hoy coexistieron los dos.
3. **Semántica de `precio`, `importe`, `importe_inicial` y `porcentaje_facturacion`** en
   `viaje_tramos` (ver la hipótesis de §4.1).
4. **¿Pallets y tipo de semi se cargan o el prorrateo sigue siendo manual?** Si se cargan, hace
   falta cantidad de pallets por tramo y capacidad del equipo.
5. **¿Quién carga gastos y combustible?** Mariela menciona a un "secretario" que los carga. Si
   tiene usuario propio, hay que definir permiso y alcance por sucursal; si no, el papel se
   importa y el sistema muestra el pendiente.
6. **¿Los adelantos son un tipo de "otro haber" o una cuenta corriente aparte?** Hoy viven en la
   cuenta corriente por viaje (`t11`), no en el catálogo de conceptos.
7. **¿Cómo se registra el flete en negro?** Importe, % aplicado y marca de "sin comprobante".
8. **¿Quién aprueba y quién paga la liquidación?** Hoy paga Carlos. Si la liquidación se revisa
   antes de pagarse, hay que separar los dos roles.

---

## 7. Borrador de respuesta a Mariela

> **Hola Mariela,**
>
> Gracias por las respuestas y por el PDF, ya está claro el circuito. Te devuelvo las 8 respuestas
> con un comentario de implementación cada una, y al final te dejo 6 cosas que necesito confirmar
> antes de arrancar la primera etapa.
>
> **1. Apertura.** Entendido: el viaje se abre y sale en el mismo paso, cuando das la orden de
> carga. Entonces queda "En curso" desde el inicio y **no necesitamos estado "Planificado"**.
> Nos queda una diferencia con el plan: el cierre requiere km de regreso, así que el viaje pasa a
> "Cerrado" recién cuando vuelve.
>
> **2. Chofer.** Un solo chofer responsable por viaje, y el chofer tiene camión fijo. Lo modelamos
> así: el viaje referencia la asignación vigente del chofer al equipo, con la excepción (semi o
> tractor roto) registrada como cambio con motivo. No vamos a permitir asignación múltiple en el
> mismo viaje.
>
> **3. Moneda de los fletes.** Acá es donde necesito que miremos juntos el documento, porque me
> parece que hay **tres valores distintos** en un mismo flete y el sistema tiene que saber cuál es
> cuál:
> - el **precio pactado** (85.000 el pallet, o 2 millones el flete);
> - el **importe real prorrateado** por los pallets que cargó;
> - y el **valor de la venta** en dólares, que para Arauco y los chilenos se define el día de la
>   descarga.
>
> Además, para los chilenos hay **dos tipos de cambio** en juego: la cotización del banco del día de
> descarga y tu pesificación a 1.530 para contabilidad.
>
> Lo que propongo: en el viaje guardamos el **importe declarado** (el de la factura E, que es la
> base de la liquidación del chofer) **con su moneda**, y el valor real en dólares va en la cuenta
> corriente, con su cotización del día. La factura E en sí no la emite el sistema: eso es del
> módulo de facturación, no de Viajes.
> **¿Te sirve esa separación?**
>
> **4. Otros haberes.** Confirmado: por viaje. Pero anoté el detalle que mencionaste del prorrateo
> por pallets según el tipo de semi (14.50 -> 26/28 pallets; 15.30 -> 30). Para que el sistema calcule
> el importe del tramo necesito **la cantidad de pallets cargados** y **el tipo de semi**. Hoy el
> legacy guarda toneladas y kilómetros, no pallets. **¿Cargamos la cantidad de pallets y el tipo de
> semi, o seguís calculando el prorrateo a mano y cargás el importe final?**
>
> **5. Cierre sin gastos.** Acá no estoy de acuerdo con la recomendación de "advertir sin bloquear",
> por lo que vos misma contás. Si el cierre exige gastos completos, y los litros se cargan por
> remito y a veces los pesos se cargan después (YPF o la cuenta corriente de las estaciones), el
> viaje **no cierra nunca** y se te acumula el gris. Lo que propongo es: **el cierre se permite,
> pero deja marcado el pendiente** ("falta el importe de la carga de combustible"), visible en el
> dashboard hasta que el secretario lo complete. El pendiente es el que te reclama a vos hoy, pero
> automatizado. **¿Lo probamos así?**
>
> **6. Pago de la liquidación.** Entendido: el ideal es mensual, pero hoy se cobra por viaje.
> Entonces la pantalla tiene que mostrar **el haber acumulado, los adelantos ya cobrados y el total
> a cobrar hoy**, que es como lo mostrás hoy en pantalla. **No vamos a imprimir la liquidación** (me
> confirmaste que nunca se imprime), así que lo dejo fuera del alcance.
>
> **7. Impresión.** No la hacemos, según lo que me contaste. Si más adelante hace falta, lo vemos
> aparte.
>
> **8. Contabilizado.** Este lo tenía mal yo en el documento, gracias por aclararlo: **no es un
> control de contabilidad, es el tilde de "se le pagó el haber al chofer"**, y lo marca Carlos. Lo
> modelamos como el estado del pago de la liquidación, con fecha y usuario, no como una casilla de
> "facturado". En la base ya viene el campo así que lo vamos a reinterpretar, no duplicar.
>
> **Las decisiones que ya estaba confirmadas quedan como están**: el % del chofer se aplica sobre el
> flete de liquidación del tramo (que es el importe declarado, como aclaraste); el margen del viaje
> se calcula con la comisión y no con la liquidación final; los gastos y el combustible se pueden
> imputar a un tramo o no; y el cierre exige km de regreso y genera la lectura del equipo.
>
> **Para cerrar el documento necesito 6 confirmaciones:**
> 1. ¿El importe que queda en el viaje es el declarado (factura E) y el real en dólares va a la
>    cuenta corriente?
> 2. ¿Qué tipo de cambio queda asociado al viaje: la cotización del banco del día de descarga o el
>    fijo de 1.530?
> 3. En el histórico, ¿el importe del tramo es el pactado o el prorrateado por pallets? (en el
>    legacy veo dos columnas y no quiero asumirlas)
> 4. ¿Cargamos pallets y tipo de semi, o el prorrateo se sigue haciendo a mano?
> 5. ¿Quién carga los gastos y el combustible? ¿El secretario tiene usuario propio en el sistema?
> 6. ¿Los adelantos del chofer son un tipo de "otro haber" o los llevamos como cuenta corriente
>    aparte?
>
> Con esto cerramos el documento y arrancamos con la primera etapa. Gracias!
>
> **Oscar**

---

## 8. Anexo — referencias de código

| Tema | Referencia |
|---|---|
| Agregado legacy y contrato de columnas | `app/Legacy/Transporte/LegacyTransportPackage.php` |
| Lector de backups `pg_dump -Ft` | `app/Legacy/Transporte/LegacyBackupReader.php` |
| Mapeo de columnas legacy a dominio | `app/Infrastructure/TripImports/LegacyTripRowMapper.php` |
| Esquema del agregado de Viajes | `app/Database/Migrations/2026-10-17-140000_CreateTripHistoryAggregate.php` |
| Catálogos maestros de Viajes | `app/Database/Migrations/2026-10-13-090000_CreateTripMasterCatalogs.php` |
| Reglas de los maestros | `app/Application/TripMasters/TripMasterCatalog.php` |
| Maestros iniciales de TSA | `app/Application/TripMasters/SeedTsaTripMasterCatalogs.php` |
| Dashboard de Viajes (lectura) | `app/Infrastructure/Trips/CodeIgniterTripDashboardReadModel.php` |
| Asignacion empleado-equipo | `app/Database/Migrations/2026-09-07-171000_CreateEmployeeEquipmentAssignmentsTable.php` |
| Bitacora de la importacion historica | `docs/BITACORA_IMPORTACION_VIAJES_HISTORICOS.md` |
| Doc de maestros de Viajes | `docs/MAESTROS_VIAJES.md` |
| Arquitectura de la plataforma y ownership de Viajes | `docs/ARQUITECTURA_PLATAFORMA_GESTION_OPERATIVA.md` |
