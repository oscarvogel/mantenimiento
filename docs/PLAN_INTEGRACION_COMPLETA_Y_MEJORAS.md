# Plan de integración completa y mejoras

**Fecha:** 2026-10-06
**Estado:** propuesta para ejecución
**Documentos relacionados:** `PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md`, `ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` v1.2, `ANALISIS_BACKUP_TRANSPORTE_MARIELA_Y_MIGRACION.md` §8

---

## 1. Qué significa implementarlo todo junto

Una sola aplicación, una sola base, un solo login. **Tres contextos nuevos** (Viajes, Neumáticos,
Facturación) sobre los diez que ya existen. No tres sistemas conviviendo, no integración, no API: un monolito modular
con trece contextos.

La diferencia importante con el planteo de "migrar el ERP al sistema de mantenimiento" es que **no se trata de meter
pantallas de facturación dentro de la aplicación de mantenimiento**. Eso mezclaría dos trabajos que no tienen nada que
ver y le volvería la interfaz más difícil al técnico que la usa todos los días.

### 1.1 Un sistema, tres áreas, separados por rol

El sistema se organiza en tres áreas de navegación, y el acceso a cada una depende del rol:

| Área | Quién entra | Contextos |
|---|---|---|
| **Operación** | Técnico, responsable de mantenimiento, chofer | Equipos, Lecturas, Preventivo, Solicitudes, Órdenes, Neumáticos, Viajes |
| **Análisis** | Responsable, administración | Dashboard, Reportes, Costo por equipo, Consumo, Ingresos por unidad |
| **Administración** | Facturación, administración, superadministrador | Facturación, Clientes, Bancos, Empresas, Sucursales, Usuarios, Auditoría |

El mecánico **nunca ve una pantalla de facturación**, y el de facturación **nunca ve una pantalla de taller**. Es la
misma base y el mismo login, pero cada rol ve su área y nada más. La navegación por defecto depende del rol, que es
justo lo que la especificación ya pide en §6.1.

### 1.2 Lo que cambia para el usuario

Hoy el sistema de mantenimiento lo usa el responsable de mantenimiento. Después:

- El técnico sigue viendo lo mismo, más la posibilidad de abrir una incidencia desde el teléfono.
- El responsable pasa de mirar vencimientos a mirar **costo por equipo y disponibilidad**.
- Administración pasa del ERP a un sistema donde la factura convive con el historial del camión facturado.

Nadie tiene que aprender dos sistemas. Ese es el punto.

---

## 2. El concepto central: la intersección

Esta es la parte que justifica el proyecto, y no aparece en ninguno de los tres sistemas por separado.

Hoy existen tres sistemas que no se hablan:

| Sistema | Qué sabe | Qué no sabe |
|---|---|---|
| El ERP de transporte | Combustible, gastos, flete facturado, viajes | Nada de mantenimiento. Nada de neumáticos. |
| El gestor de neumáticos | Vida útil del neumático, recapados, depósito | Nada del costo de operar el camión |
| El sistema de mantenimiento | Órdenes, repuestos, mano de obra, preventivo | No sabe cuánto cuesta operar la flota ni cuánto factura |

El producto no es la suma de los tres. Es lo que aparece cuando se cruzan:

- **El odómetro se actualiza solo.** El viaje es la fuente real de kilómetros. Se acaba el problema de leer un panel
  y cargar el km a mano, que es el motivo por el que todo sistema de mantenimiento se desactualiza.
- **El combustible se vuelve un diagnóstico.** Con el km real y los litros reales de cada viaje se puede detectar un
  desvío mecánico.
- **La facturación dice qué unidad produce.** El renglón `UNIDAD: <patente>` se asocia al equipo y permite medir
  margen por unidad.
- **El tiempo en taller tiene costo.** La OT dice que el equipo está detenido; el viaje dice qué dejó de facturar.

Ese cruce no lo puede hacer ninguno de los tres sistemas por separado, y es exactamente lo que ninguno tiene hoy.

---

## 3. Las mejoras que solo existen si todo va junto

### 3.1 Costo real por kilómetro de cada unidad

Hoy, con los datos de este ERP, ya se puede calcular:

| Magnitud | Valor medido |
|---|---|
| Kilómetros realmente recorridos en viajes registrados | 8.405.695 |
| Viajes utilizables (con km de salida y de regreso válidos) | 1.604 de 1.740 |
| Combustible | 2.319.883.936 |
| Gastos de viaje | 131.197.923 |
| **Costo operativo por kilómetro** | **292** |

Ese número **existe hoy y nadie lo tiene a mano.** Es solo combustible y gastos: no incluye mano de obra, repuestos,
gomería nineuún.

Al sumarle lo que aporta el sistema de mantenimiento aparece el número que toda empresa de transporte quiere y ninguno
de los tres sistemas entrega: **cuánto cuesta mantener un kilómetro de un camión, comparado con lo que ese kilometer factura**.

### 3.2 El combustible como diagnóstico mecánico

Los datos del ERP muestran que el consumo real de la flota es:

| Indicador | Valor |
|---|---|
| Mediana de consumo | 37,36 l/100km |
| Promedio | 49,06 l/100km (inflado por dos errores, ver 3.3) |
| 27 camiones con datos válidos | agrupados entre 33 y 41 l/100km |

Esa agrupación es el hallazgo útil. **La variación normal de la flota es de 33 a 41.** Un camión dentro de esa banda
está normal. Un camión fuera de la banda tiene algo:

- Un aumento sostenido de 5 l/100km es inyección, alignment, presión de neumáticos o filtro.
- Una caída brusca puede ser una cuenta de peaje o carga registrada en el viaje equivocado.

Además es dinero directo: el combustible es el 59% de la facturación. Si una unidad sube de 37 a 42 l/100km, en un año
son millones de pesos.

Y el conector con neumáticos es directo: **presión y alineación deficientes consumen más y desgastan el neumático al
mismo tiempo.** Con los tres sistemas juntos, un desvío de consumo y un patrón de desgaste de cubiertas señalan el mismo
problema.

### 3.3 Dos datos de carga que hay que corregir antes de medir

La medición de consumo también encontró dos errores que conviene conocer:

- **`GZD 587` (Renault Premium)**: 1.401.016 litros en 371.798 km, es decir **376,82 l/100km**, diez veces la mediana.
  Es un error de carga de datos, no un problema mecánico. Hay que revisarlo antes de mostrar cualquier indicador.
- **`AB 499 OK` (Scania G360)**: 23,38 l/100km, 37% por debajo de la mediana, con 7.361 km por viaje cuando el resto
  promedia 4.000. Probablemente haya un emparejamiento incorrecto entre km de salida y km de regreso.

**Conclusión práctica:** el indicador funciona, pero necesita la validación que ya está especificada en §9.1 y §9.17.
Sin ese control, un error de carga se convierte en un diagnóstico falso.

### 3.4 Disponibilidad contra rentabilidad

Con el sistema actual se sabe que un equipo está detenido. No se sabe lo que cuesta.

Combinando: las horas en el taller contra los viajes facturados del mismo período, se puede informar

- **Ingresos perdidos por equipo detenido.**
- **Costo de mantenimiento por unidad frente a su facturación.**
- **Ranking de unidades por costo total de operación.**

Es un cambio de valor en la conversación con el cliente: de "¿está el service vencido?" a "este camión me está costando
el doble que el otro y factura menos".

### 3.5 El neumático pasa de ser un problema a ser un activo medible

Con el patrón de uso que dan los viajes (kilómetros por mes, tipo de carga) y el historial de rotación y recapado, se
puede **predecir el recambio** en lugar de reaccionar al pinchazo.

Además se responde una pregunta que hoy no tiene respuesta: **¿conviene recapar o conviene cambiar?** Con el costo del
recapado, la vida posterior a la recapada y el costo de la cubierta nueva, la cuenta se hace sola. Con la gomería
registrada como orden de trabajo, todos esos números quedan en el mismo historial del equipo.

### 3.6 Margen por unidad

El renglón `UNIDAD: <patente>` que ya existe en las facturas se asocia al equipo. Cada unidad queda con ingresos y
costos: combustible, gastos de viaje, mantenimiento y gomería.

Es el reporte que decide si un camión se renueva, se vende o se repara. Hoy nadie lo tiene.

### 3.7 El preventivo deja de ser un calendario

Hoy los planes se configuran por equipo a mano. Con la flota real y el kilometraje real de cada unidad se puede:

- **Generar los planes desde las plantillas** con las frecuencias reales de cada marca y modelo, sin cargar 28
  equipos uno por uno.
- **Ajustar por intensidad de uso**: un camión que hace 9.000 km por mes necesita un plan distinto al que hace 2.000,
  aunque sean el mismo modelo.
- **Programar el service alrededor de los viajes** que la unidad ya tiene asignados.

### 3.8 El sistema se mantiene actualizado solo

Este es el improvement más básico y el que más se subestima. Hoy, si nadie carga el odómetro, el preventivo no se
dispara y el sistema miente. Con el viaje como fuente, **el sistema se actualiza con la operación diaria**. La lectura
del equipo llega sola.

---

## 4. Mejoras al propio sistema de mantenimiento

Más allá de los cruces, estos tres contextos cambian cosas que ya funcionan:

### 4.1 Asignación real de choferes

Hoy existe `employee_equipment_assignments` pero no hay origen fiable para llenarla. El ERP tiene la asignación del
chofer por viaje, que es real aunque esté sucia. Sirve para cargar el padrón y detectar rotaciones.

### 4.2 Alertas que hoy no existen

- **Consumo fuera de rango** (3.2).
- **Equipo con mantenimiento caro respecto de su grupo**: si un Volvo FH500 cuesta tres veces lo que cuestan los otros
  cuatro FH500, hay que investigar por qué.
- **Neumático con rendimiento bajo lo esperado** para su marca y modelo.
- **Equipo facturando por debajo de su peers**, combinando facturación y costo.

### 4.3 El historial deja de estar partido

Hoy, un recapado y una orden de trabajo son dos registros en dos sistemas. Con la gomería sobre `WorkOrders` y el
neumático como activo, la ficha del equipo muestra **todo**: órdenes, recapados, colocaciones de cubiertas, viajes,
consumo y facturación. Una sola línea de tiempo.

### 4.4 MTTR y downtime reales

La especificación (§11) dice no mostrar MTTR sin datos válidos. Con los viajes se sabe cuándo el equipo se movió y
cuándo no, así que el tiempo de detención se puede medir en lugar de inferir.

### 4.5 El chatbot y el QR se vuelven útiles de verdad

El sistema ya tiene chatbot y QR. Con el chofer como entidad y el equipo con datos reales, el flujo
**"el chofer escanea el QR del camión desde el teléfono y reporta una pinchazo con foto"** pasa de ser una idea a ser
una operación de un minuto. Ese es el escenario de uso §6.2 de la especificación, pero con la información que hace
falta para que funcione.

---

## 5. Orden por valor, no por contexto

El orden importa menos que en el plan por contextos, porque hay dependencias duras. La secuencia recomendada es por lo
que el usuario puede hacer:

| Paso | Se entrega | Valor para el usuario |
|---|---|---|
| **1** | Equipos, choferes, odómetro del ERP | Ver la flota real con su km vigente. Lo primero que se pregunta siempre. |
| **2** | Viajes con lectura automática | El odómetro se actualiza solo. Se termina de cargar km a mano. |
| **3** | Costo y consumo por unidad | El primer número nuevo: 292 por km, y consumo por camión. |
| **4** | Órdenes de trabajo y planes | El corazón del mantenimiento, ya en marcha. |
| **5** | Neumáticos y gomería | Vida útil del neumático y recapado como OT. |
| **6** | Facturación | Emisión con CAE e ingresos por unidad. |

Los pasos 1 a 3 dependen solo del ERP y dan valor antes de tocar la facturación. El paso 6 es el último porque tiene la
dependencia regulatoria del certificado de ARCA.

---

## 6. Costos y riesgos honestos de integrar todo

### 6.1 El riesgo grande: la interfaz

Meter 13 contextos en una aplicación multiplica las pantallas. Si el menú tiene 80 ítems, el técnico deja de usar el
sistema y se vuelve al papel en blanco. **La mitigación es el área por rol de 1.1**, y es trabajo de diseño, no de código.

### 6.2 Volumen de datos

~57.000 filas de viajes, 22.000 hijas, 13.000 de facturación. No es mucho para MariaDB, pero los reportes de costo por
equipo sobre datos de cuatro años exigen índices pensados y consultas paginadas. La especificación ya exige paginación
y filtrado del lado del servidor (§9.11).

### 6.3 Calidad de datos heredada

Se encontró en el análisis:

- 519 de 1.740 viajes con fecha de regreso inválida o nula.
- Dos camiones con datos de consumo que hay que corregir (3.3).
- Facturas con `cuit_empresa` lleno de espacios.
- Neumáticos: el backup está vacío, así que no hay dato malo, pero tampoco hay dato.

Cada migración necesita su validación y su reporte de errores. Es lo que ya existe en `importaciones` e
`importacion_errores`.

### 6.4 Capacitación

Hoy el sistema lo usa una persona o un equipo chico. Después lo usan técnicos, responsables y administración, con
necesidades distintas. **Sin capacitación por rol, el sistema se usa a medias.** La especificación §17 ya pide
capacitación con ejemplos del trabajo real.

### 6.5 Lo que no se resuelve con esto

- No hay integración con el sistema contable: si la empresa tiene uno externo, habrá duplicación de facturación.
- La cuenta corriente con choferes queda fuera (§2.2 de la v1.2). Si la empresa la necesita operativa, es otro proyecto.
- No hay telemetría ni GPS: el odómetro viene del viaje que el chofer carga, no del camión.

---

## 7. Cómo medir si funcionó

Un plan sin indicadores se repite en tres años sin saber si sirvió. Estos son los números que hay que mirar:

| Indicador | Línea de base | Objetivo |
|---|---|---|
| Lecturas de km cargadas a mano por semana | Hoy: todas | 0 después del paso 2 |
| Órdenes cerradas sin trabajo cargado | Sin medir | Menos del 10% |
| Tiempo de detección de un service vencido | Sin medir | Antes de que el camión entre en taller |
| Costo de mantenimiento por unidad | 292 por km solo operativo | El número completo |
| Neumáticos que llegan a descarte antes de su vida esperada | Sin medir | Medir y bajar |
| Unidades con costo de mantenimiento en el doble de la mediana de su modelo | Sin medir | Investigar y corregir |

El primero y el quinto son los que más valor dan, porque los dos son consecuencia directa de tener los datos en un solo
lugar.

---

## 8. Resumen

Implementar todo junto no es meter tres sistemas en uno. Es hacer que **los kilómetros del viaje actualicen el
odómetro, que el combustible detecte un problema mecánico, que la factura diga qué unidad produce, y que el recapado
sea una orden de trabajo más**.

El número que resume por qué vale la pena: **292 por kilómetro es lo que hoy se puede medir, y ese número excluye
mantenimiento, repuestos y gomería.** Con todo integrado, se puede saber si un camión se está dando bien.