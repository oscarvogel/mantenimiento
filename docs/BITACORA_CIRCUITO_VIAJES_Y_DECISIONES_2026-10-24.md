# Bitácora — Circuito de Viajes: definiciones de la reunión y decisiones pendientes

**Fecha:** 2026-10-24
**Origen:** respuestas de Mariela (11 notas de voz de WhatsApp del 23/10/2026) al correo de
Oscar con las 8 preguntas del circuito de Viajes (apertura, tramos, combustible, gastos,
cuenta corriente y liquidación), más una grabación de pantalla del sistema actual (21/10/2026)
auditada en §7.
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

**Auditoría sobre el video del sistema actual (21/10/2026).** Se revisó una grabación de pantalla
del sistema de gestión de viajes en uso ("Key Sistemas Software"). El video aporta el dato que
faltaba: **la fórmula exacta de la liquidación del chofer** y la estructura real de la cuenta
corriente con monedas y cotización. También muestra que "Liquidado" y "Contabilizado" son **dos
estados distintos** y no uno, lo que corrige el §4.2 de este informe. Los hallazgos están en §7.

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
| 8 | ¿Usan el tilde "Contabilizado" y la fecha? ¿Quién lo completa? | Es el tilde de **"se le pagó el haber al chofer"**, no un control contable. Lo marca **Carlos**, que entrega el dinero. | El tilde corresponde a **Liquidado**. El video muestra que el sistema tiene **dos columnas de estado** ("Liquidado" y "Contabilizado") y que el legacy guarda **dos banderas** (`contabilizado` y `marcar`) más una fecha (`fecha_contabilizacion`). Ver §4.2 y §7.4. |

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

### 4.2 "Liquidado" y "Contabilizado" son dos estados distintos

Si se modela como "facturado / contabilizado", se construye el flujo equivocado. Por la voz de
Mariela, el tilde es:

> **Haber del chofer liquidado y pagado**, con fecha y con el usuario que paga (Carlos).

La auditoría del video (§7.4) muestra que el sistema tiene **dos columnas de estado** en la grilla
de planillas: "Liquidado" (la del tilde rojo) y "Contabilizado" (con su fecha). Son dos controles
distintos y hay que separarlos.

En el legacy la tabla `viajes` guarda **dos banderas** (`contabilizado` y `marcar`) y una fecha
(`fecha_contabilizacion`). Los dos campos ya están en el modelo importado, así que la pregunta
correcta no es "qué reinterpretamos" sino **"qué bandera es cuál"**, y eso hay que preguntarlo
(queda como punto 9 de §6).

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

Ordenadas por impacto. La columna de estado refleja lo que se pudo verificar contra el video del
sistema actual (§7): algunas preguntas quedaron contestadas y otras aparecieron nuevas.

| # | Pregunta | Estado |
|---|---|---|
| 1 | **¿Qué importe queda en el viaje?** | **Parcial.** El video muestra que el flete se liquida por líneas de moneda con cotización e imputación por tramo. Falta decidir si al viaje se guarda el importe declarado (factura E) o conviven el facturado y el real. |
| 2 | **¿Qué tipo de cambio acompaña al viaje?** | **Contestada.** Cada movimiento guarda moneda + cotización + importe pesificado (§7.2). Falta definir cuál de las dos pesificaciones del dólar se usa cuando hay más de una. |
| 3 | **Semántica de `precio`, `importe`, `importe_inicial` y `porcentaje_facturacion`** en `viaje_tramos` (hipótesis de §4.1) | **Abierta.** El video no muestra el detalle de tramos. |
| 4 | **¿Pallets y tipo de semi se cargan o el prorrateo sigue siendo manual?** | **Abierta.** No se ve en el video. |
| 5 | **¿Quién carga gastos y combustible?** | **Abierta.** Mariela menciona a un "secretario" que los carga. Si tiene usuario propio, hay que definir permiso y alcance por sucursal; si no, el papel se importa y el sistema muestra el pendiente. |
| 6 | **¿Los adelantos son un tipo de "otro haber" o una cuenta corriente aparte?** | **Contestada.** Los adelantos son movimientos de la cuenta corriente del chofer, con concepto propio ("Adelanto Viaje"), y se descuentan del haber. Los "otros haberes" son otra cosa: cruces, estadías, con su propio código de concepto. |
| 7 | **¿Cómo se registra el flete en negro?** | **Abierta**, con más contexto: el flete se imputa por tramo y el tramo tiene cliente (§7.5). |
| 8 | **¿Quién aprueba y quién paga la liquidación?** | **Parcial.** Paga Carlos. La aprobación no se ve en el video. |
| 9 | **¿Cuál bandera es "Liquidado" y cuál es "Contabilizado"?** | **Nueva, abierta.** El sistema tiene dos columnas de estado y el audio habla de la tilde (Liquidado). Hay que saber quién completa cada una y con qué fecha. |
| 10 | **¿Seguro, cubiertas y amortización de capital entran en el margen?** | **Nueva, abierta.** Aparecen en el "Rendimiento del Viaje" y no están en el documento de Oscar. |
| 11 | **¿Hay tarifario (baremos) y devoluciones en el alcance?** | **Nueva, abierta.** El legacy tiene un módulo "Baremos - Devoluciones". |
| 12 | **¿El flete del viaje va con una base única o se reparte por tramo?** | **Nueva, abierta.** El video muestra imputación de fletes por tramo y un viaje con dos clientes, pero no se ve el detalle completo. |
| 13 | **¿Los gastos del camión se descuentan del margen de la empresa?** | **Nueva, abierta.** Son un concepto reembolsado al chofer (§7.6), así que son gasto de la empresa aunque figuren en la liquidación del chofer. |

---

## 7. Auditoría sobre el video del sistema actual

**Fuente:** grabación de pantalla del 21/10/2026 (3 minutos 25 segundos) del sistema de gestión de
viajes en uso: "Sistema de Gestión de Viajes - Key Sistemas Software". Empresa
`23-28059786-4 PAWLUK DUBLIN MARIELA ELISA`, viaje Nro. 74, dominio `AG007VR`, chofer
`RACHOSKI OSCAR MARCELO` (legajo 9000000000).

Es el mismo sistema del que salen los backups del ERP que ya se importó: el CUIT de la pantalla
coincide con la base `transporte_mariela_pawluk` documentada en
`BITACORA_IMPORTACION_VIAJES_HISTORICOS.md`. Por lo tanto, lo que se ve en pantalla es la
**semántica real** de las tablas que ya están modeladas.

> **Nota de alcance:** el video es una grabación de pantalla con moiré y algún número borroso. Todo
> lo que se afirma acá está respaldado por texto legible o por una cuenta que cierra; lo que no
> se pudo leer está listado en §7.8.

### 7.1 La fórmula de la liquidación del chofer

Es el hallazgo más importante del video. El informe "Liquidación Chofer" del viaje 74 muestra
literalmente cuatro renglones y un total:

| Renglón | Importe |
|---|---|
| Porcentaje Chofer % 14.00 | 1.149.762,00 |
| Otros Haberes: | 120.000,00 |
| Cuenta Corriente: | -339.856,00 |
| Gastos Camión: | 78.500,00 |
| **Total:** | **1.008.406,00** |

La cuenta cierra exacto: 1.149.762 + 120.000 - 339.856 + 78.500 = 1.008.406.

De ahí sale la fórmula de la liquidación, que es **por viaje**:

```text
Total a cobrar al chofer = (flete del viaje x % del chofer)
                         + Otros Haberes
                         + Gastos del Camion (reembolso al chofer)
                         - Saldo de la Cuenta Corriente (lo que ya cobro de mas)
```

Cuatro consecuencias:

1. **La unidad de cálculo es el viaje, no el período.** El informe es del viaje 74 y no menciona
   otros viajes. Responde la pregunta 6 del correo: la liquidación se calcula por viaje y se
   acumula; el pago es mensual en la práctica, pero la pantalla que hay que construir es la del
   viaje.
2. **El % del chofer se aplica sobre el flete.** 1.149.762 / 0,14 = 8.212.585,71, que es el
   importe del flete del viaje. Confirma la decisión de Oscar y la respuesta de Mariela.
3. **"Cuenta Corriente" entra en negativo** porque es el saldo en pesos de la cuenta corriente del
   chofer: si el chofer ya cobró adelantos, se le descuentan. Es exactamente el "tenés tanto de
   adelanto, entonces para cobrar ahora tenés esto" que dijo Mariela.
4. **"Gastos Camión" se le SUMA al chofer.** Son los gastos que el chofer pagó de su bolsillo y se
   le reembolsa. El sistema distingue dos cosas que el documento de Oscar trata como una:
   "Gasto de Combustible" y "Gastos del Camión" son conceptos separados, con botones separados.

### 7.2 La cuenta corriente del chofer: moneda, cotización e importes pesificados

La ventana "Cuenta Corriente por Chofer" del viaje 74 tiene las columnas:

```text
Item | Fecha | Cod. | Denominacion | Cot. | Moneda | Debitos | Creditos | Importe en Pesos | Observaciones
```

con estas dos líneas:

| Fecha | Cod. | Denominación | Cot. | Moneda | Débitos | Créditos | Importe en pesos |
|---|---|---|---|---|---|---|---|
| 20/08/2026 | 2 | Adelanto Viaje | 1,00 | 1 Pesos Argentinos | 0,00 | 260.000,00 | 260.000,00 |
| 27/08/2026 | 47 | GASTOS BRASIL | 280,00 | 2 Reales | 814,80 | 1.100,00 | 228.144,00 |

Totales: débitos 228.144,00, créditos 568.000,00, **saldo -339.856,00**.

Ese saldo es exactamente el renglón "Cuenta Corriente" de la liquidación. Y la aritmética
confirma las tres monedas en juego:

- 814,80 Reales x 280,00 = 228.144,00 pesos.
- 1.100,00 Reales x 280,00 = 308.000,00 pesos; 260.000,00 + 308.000,00 = 568.000,00 de créditos.
- 228.144,00 - 568.000,00 = -339.856,00.

Esto **resuelve la pregunta abierta #2** (qué tipo de cambio va en el viaje): cada movimiento se
guarda **en su moneda, con su cotización y con su importe pesificado**. La línea de la cuenta
corriente tiene las tres cosas, que es exactamente el juego de columnas `debitos_moneda`,
`creditos_moneda`, `importe_moneda`, `cotizacion` e `id_moneda` de la tabla `viaje_cuenta_corriente`
que ya se importó.

Además aparecen **tres monedas**: pesos argentinos, reales y (por el audio) dólares. El 1.530 del
audio es la pesificación del dólar; el 280,00 es la del real. **No son la misma cotización y no se
pueden mezclar.**

Y un detalle que cambia el modelo: la cuenta corriente del chofer **no es solo de adelantos**. La
línea "Adelanto Viaje" es un concepto (código 2) y "GASTOS BRASIL" es otro (código 47). El saldo
mezcla lo que el chofer ya cobró con lo que la empresa le debe.

### 7.3 Otros Haberes: por viaje, con código de concepto y sin moneda

La ventana "Otros Haberes Chofer" del viaje 74 tiene las columnas `Item | Fecha | Codigo | Concepto
| Importe | Observaciones` y dos líneas:

| Fecha | Código | Concepto | Importe | Observaciones |
|---|---|---|---|---|
| 15/08/2026 | 5 | Cruce | 20.000,00 | 1tbr |
| 28/08/2026 | 4 | Estadía | 100.000,00 | finde cargado br/flete lottus |

"Total de Haberes: 120.000,00", que es el renglón "Otros Haberes" de la liquidación.

Confirma tres cosas: los otros haberes son **por viaje**, llevan **código de concepto** (o sea,
catálogo, no texto libre) y **no tienen moneda** (la tabla `viaje_otros_haberes` del modelo nuevo no
tiene `id_moneda` ni `cotizacion`, y en pantalla es así). El importe va directo a la liquidación.

### 7.4 "Liquidado" y "Contabilizado" son dos estados distintos

En la grilla "Planillas de Viaje" aparecen **dos columnas de estado**, no una:

```text
Nro. Viaje | Fecha Salida | Fecha Regreso | Legajo | Chofer Nombre | Odometro Salida |
Odometro Regreso | Recorrido | Liquidado | Contabilizado | Fecha Cierre
```

La columna "Liquidado" es la que lleva la tilde roja que describió Mariela ("cuando se le paga al
chofer su haber, a eso le para, para eso está esa tilde roja"). La columna "Contabilizado" es
otra, con su fecha.

Esto obliga a **corregir el §4.2**: el tilde del audio corresponde a **Liquidado**, no a
Contabilizado. Como el legacy guarda dos banderas (`contabilizado` y `marcar`) y una fecha
(`fecha_contabilizacion`), hay que determinar cuál bandera es cuál. Queda como pregunta 9 de §6.

### 7.5 Un viaje puede tener varios clientes y el flete se imputa por tramo

La ventana del viaje 74 muestra **dos clientes**:

```text
4 ARAUCO ARGENTINA SA
311 LOTTUS TRANSPORTES LTDA
```

(son los dos nombres de los que habla el audio: Arauco y los chilenos, Lottus). Y el detalle de
fletes tiene un bloque "Imputación en Fletes" con débitos y créditos por línea.

Consecuencia: **el flete del viaje se reparte entre los tramos y cada tramo tiene su cliente**, y
el reparto se hace por imputación, no por un importe único. Es el mecanismo que responde "a quién
se factura cada parte del flete", y es el que hay que modelar para el caso del flete en negro
(§4.3).

### 7.6 El modelo de costos del viaje es más caro que el que asume el documento

El panel "Rendimiento del Viaje" lista ocho renglones:

```text
Flete | Combustible | Chofer | Gastos Camion | Seguro | Cubiertas | Amortizacion Capital | Total
```

"Seguro", "Cubiertas" y "Amortización Capital" **no están en el documento de Oscar**. Si el
rendimiento del viaje es el margen que se quiere medir, el documento tiene que definir también esos
tres costos, o dejar constancia de que quedan fuera de la primera etapa.

Además, "Gastos del Camión" son un concepto **reembolsado al chofer** (§7.1, punto 4): son gasto
de la empresa, aunque figuren en la liquidación del chofer. Si el margen se calcula con la comisión
y no con la liquidación final, hay que decidir si esos gastos se descuentan del margen de la
empresa. Es la misma pregunta, en otro lugar.

### 7.7 Lo que el video muestra del menú y de las pantallas

Módulos del menú lateral: Viajes (gestión de viajes, gastos de fletes, choferes, dominios, baremos
y devoluciones, distribución por empresa), Productos, Ventas (clientes), Compras, Proveedores y
Gestión de Cheques.

Botones del grupo "Viaje": Agregar Viaje, Modificar Viaje, Imprimir, Borrar Viaje, Salir.

"Conceptos Relacionados": Gasto de Combustible, Gastos del Camion, Cuenta Corriente Chofer, Detalle
de Fletes, Otros Haberes Chofer.

Dos capturas que el documento de Oscar no menciona y que conviene tener presentes:

- **"Imprimir" existe.** El informe "Liquidación Chofer" es una plantilla de reporte (vista previa
  con zoom, "Across", "Down", "Ancho de P"). Mariela dijo que nunca lo imprime, así que la
  impresión sigue fuera de alcance, pero **la plantilla y el cálculo ya existen** y son la
  especificación literal de la pantalla nueva.
- **"Baremos y devoluciones"** es un módulo de tarifas. Habría que confirmar si el nuevo circuito
  necesita tarifario por corredor o por cliente, o si se sigue trabajando con el precio pactado en
  cada viaje.

### 7.8 Lo que el video NO permite concluir

- El importe del flete del viaje 74 (la base 8.212.585,71) no se lee con certeza: los números de
  la zona "Imputación en Fletes" están borrosos por el moiré de la grabación.
- No se ve el **detalle de los tramos** (el equivalente a `viaje_tramos`) con sus columnas de
  precio, importe, porcentaje de facturación y remito. Sigue sin poder confirmarse la hipótesis de
  §4.1 sobre `precio`, `importe` e `importe_inicial`.
- No se ve la carga de **pallets** ni el tipo de semirremolque: siguen siendo requisito abierto
  (§6, punto 4).
- El video no muestra cómo se cierra un viaje ni qué pasa con los gastos faltantes: la regla de
  cierre sigue siendo la de la voz de Mariela (§4.4).

---

## 8. Borrador de respuesta a Mariela

> **Hola Mariela,**
>
> Gracias por las respuestas y por el PDF, ya está claro el circuito. Te devuelvo las 8 respuestas
> con un comentario de implementación cada una, y al final te dejo las cosas que necesito confirmar
> antes de arrancar la primera etapa.
>
> Además revisé el video del sistema que me pasaste, y ahí pude ver cómo se calcula hoy la
> liquidación del chofer. Eso nos cierra la fórmula y la estructura de la cuenta corriente, así que
> lo incorporé.
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
>
> En el video vi que la cuenta corriente ya guarda las tres cosas de cada movimiento: la moneda
> (Pesos Argentinos, Reales), la cotización y el importe en pesos. Vamos a llevar ese mismo
> esquema, que además sirve para el Real y para el Dólar con la cotización de cada día.
> **¿Te sirve esa separación?**
>
> **4. Otros haberes.** Confirmado: por viaje. En el video lo vi claro: son los cruces, las estadías
> y por el estilo, con su código de concepto y su total, y van directo a la liquidación. Sin moneda,
> como hoy.
>
> Pero anoté el detalle que mencionaste del prorrateo por pallets según el tipo de semi (14.50 ->
> 26/28 pallets; 15.30 -> 30). Para que el sistema calcule el importe del tramo necesito **la cantidad
> de pallets cargados** y **el tipo de semi**. Hoy el legacy guarda toneladas y kilómetros, no
> pallets. **¿Cargamos la cantidad de pallets y el tipo de semi, o seguís calculando el prorrateo a
> mano y cargás el importe final?**
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
> En el video vi la liquidación del viaje 74 y el cálculo es exactamente este:
>
> **Total a cobrar = (flete del viaje x % del chofer) + Otros Haberes + Gastos del Camión - lo que
> el chofer ya cobró de la cuenta corriente.**
>
> Así que la pantalla que hay que hacer es la del viaje, con esos cuatro renglones y el total, que
> es como lo mostrás hoy. **No vamos a imprimir la liquidación** (me confirmaste que nunca se
> imprime), así que lo dejo fuera del alcance, aunque el reporte ya exista en el sistema.
>
> **7. Impresión.** No la hacemos, según lo que me contaste. Si más adelante hace falta, lo vemos
> aparte.
>
> **8. Contabilizado.** Este lo tenía mal yo en el documento, gracias por aclararlo: **no es un
> control de contabilidad, es el tilde de "se le pagó el haber al chofer"**, y lo marca Carlos.
>
> Mirando el video con más detalle veo que en la grilla de planillas hay **dos columnas**: una con
> tilde y otra con fecha. Entonces me queda una sola duda: **¿cuál de las dos es la del tilde que
> marcás vos y cuál es la de la fecha?** En la base ya vienen los dos campos, así que no duplicamos
> nada, pero quiero nombrarlos bien.
>
> **Las decisiones que ya estaba confirmadas quedan como están**: el % del chofer se aplica sobre el
> flete de liquidación del tramo (que es el importe declarado, como aclaraste); el margen del viaje
> se calcula con la comisión y no con la liquidación final; los gastos y el combustible se pueden
> imputar a un tramo o no; y el cierre exige km de regreso y genera la lectura del equipo.
>
> **Para cerrar el documento necesito estas confirmaciones:**
> 1. ¿El importe que queda en el viaje es el declarado (factura E) y el real en dólares va a la
>    cuenta corriente?
> 2. En el histórico, ¿el importe del tramo es el pactado o el prorrateado por pallets? (en el
>    legacy veo dos columnas y no quiero asumirlas)
> 3. ¿Cargamos pallets y tipo de semi, o el prorrateo se sigue haciendo a mano?
> 4. ¿Quién carga los gastos y el combustible? ¿El secretario tiene usuario propio en el sistema?
> 5. ¿Cuál de las dos columnas de estado es la del tilde de pago (Liquidado) y cuál la que lleva la
>    fecha (Contabilizado)?
> 6. En el "Rendimiento del Viaje" aparecen Seguro, Cubiertas y Amortización de Capital, que no
>    están en mi documento. ¿Entran en el margen de esta etapa o quedan para después?
> 7. Vi un módulo de "Baremos y devoluciones". ¿El tarifario por corredor o por cliente entra en esta
>    etapa, o seguimos con el precio pactado viaje por viaje?
> 8. El viaje puede tener más de un cliente y el flete se reparte entre los tramos. ¿El flete se
>    carga una sola vez y se imputa, o se carga en cada tramo?
>
> Con esto cerramos el documento y arrancamos con la primera etapa. Gracias!
>
> **Oscar**

---

## 9. Anexo — referencias de código

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

**Fuente de la auditoría de §7:** grabación de pantalla del sistema actual, 21/10/2026, 3 min 25 s,
enviada por WhatsApp (`WhatsApp Video 2026-10-21 at 2.43.21 PM.mp4`). No se versiona en el
repositorio.
