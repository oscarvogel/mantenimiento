# Especificación funcional: costo teórico por km y tarifa gerencial

**Estado:** alcance funcional aprobado para implementación (2026-10-12).

## Objetivo

Dar al dueño o a una persona autorizada una referencia propia para responder dos preguntas:

1. ¿Cuánto debería costar operar este conjunto tractor y semi por kilómetro?
2. ¿Qué tarifa por kilómetro facturable debería cotizar, considerando los kilómetros que no se cobran y un recargo configurable?

El punto de partida es un modelo teórico por equipo, construido con supuestos que la empresa conoce y puede modificar. No depende de tener cargados gastos reales. Una comparación posterior con costos reales queda fuera de esta primera definición.

## Principios de funcionamiento

- Cada cálculo representa una configuración concreta de **tractor y semi**; sus valores de capital y vidas útiles se informan por separado.
- El dueño ingresa y mantiene los precios y supuestos de su empresa. El sistema no presenta precios o ponderaciones sectoriales como si fueran propios de esa flota.
- El sistema muestra los supuestos junto con el resultado para que el dueño pueda revisar qué explica cada cifra.
- Un rubro necesario sin configurar se identifica como faltante. No se interpreta como costo cero ni se publica una tarifa completa como si lo fuera.
- Cada supuesto conserva la moneda en que fue ingresado. Los totales se normalizan a ARS con la cotización de referencia guardada en el escenario y luego se muestran tanto en ARS/km como en USD/km.
- La cotización de referencia es explícita, editable y trazable: valor de ARS por USD, fecha y tipo/fuente seleccionados por un usuario autorizado. No se actualiza automáticamente ni se reemplaza sin que se vea el cambio.
- La vista identifica la tarifa como referencia teórica de gestión; no como liquidación contable, tarifa oficial ni garantía de rentabilidad.

## Usuarios y acceso

El costo teórico por km forma parte del módulo **Gerencial** que aparece en la plataforma como “Análisis, indicadores y visión estratégica”. No se agrega una tarjeta ni un módulo independiente a la plataforma. Al habilitar Gerencial para la cuenta, esta funcionalidad queda disponible dentro de ese espacio como **Costo teórico por km**.

Solo pueden entrar a Gerencial y consultar sus resultados el dueño y los usuarios que reciban autorización expresa. La configuración de supuestos también queda limitada a usuarios autorizados por la empresa.

Los datos corresponden a la empresa y al alcance de sucursales permitido para la persona que inició sesión. Gerencial y sus resultados no se muestran a usuarios sin permiso.

## Recorrido principal

1. El usuario abre **Gerencial** y entra a **Costo teórico por km**.
2. Selecciona el tractor y el semi que quiere analizar, o una configuración de equipo ya guardada.
3. Completa los supuestos de costos y utilización. La pantalla indica cuáles faltan y cuáles fueron actualizados.
4. Consulta el costo teórico por kilómetro recorrido, el detalle por rubro y la tarifa de referencia con el recargo elegido.
5. Si está evaluando un viaje, informa los kilómetros facturables, los kilómetros de regreso u otros tramos no facturados y los peajes. El sistema convierte el costo del recorrido completo a una tarifa sugerida por kilómetro facturable.
6. Guarda el escenario con un nombre para volver a consultarlo. Puede exportar a Excel cualquier escenario guardado al que tenga acceso.

Cambiar un supuesto recalcula el resultado en el escenario que se está consultando. Antes de guardar, cada resultado conserva visible la fecha de referencia de los parámetros utilizados. Guardar crea una versión consultable; no modifica una versión anterior.

## Escenarios guardados e historial

El usuario autorizado puede guardar el cálculo como un escenario identificable, por ejemplo, por nombre, configuración tractor/semi y fecha. Se conserva una fotografía de todos los datos necesarios para entender y reproducir lo consultado:

- valores, monedas, unidades y fechas de los supuestos;
- kilómetros mensuales previstos;
- cotización ARS/USD, tipo/fuente y fecha;
- recargo elegido;
- parámetros del simulador del viaje, si se usó;
- costo teórico, tarifa sugerida, desglose y estado de cobertura resultantes.

El usuario puede listar los escenarios guardados, filtrarlos por equipo o fecha, abrir uno y consultar sus resultados y supuestos originales. Si cambia esos datos y quiere conservar el nuevo cálculo, guarda una nueva versión; los registros previos permanecen disponibles para consulta. Un escenario que no se haya guardado no aparece en el historial.

El historial y las acciones de guardar/consultar respetan el mismo acceso gerencial y alcance de empresa/sucursal descritos en esta especificación. Exportar no amplía el acceso a información que el usuario no podría consultar en Gerencial.

## Exportación a Excel

Desde un escenario guardado, el usuario autorizado puede descargar un archivo `.xlsx` con una fotografía de esa versión, lista para analizar fuera del sistema. El libro organiza la información en hojas:

- **Resumen:** empresa, equipo, nombre y fecha del escenario, costo/km y tarifa sugerida en ARS y USD, recargo, cotización usada y cobertura.
- **Parámetros:** cada supuesto con valor, moneda, unidad, fecha y fuente/tipo de cotización cuando corresponda.
- **Detalle de costos:** rubros, componente fijo o variable, valor por km, moneda normalizada, subtotal y estado de configuración.
- **Viaje:** kilómetros facturables y no facturados, peajes, costo estimado del trayecto y tarifa sugerida por km facturable; identifica si el escenario no tiene una simulación de viaje guardada.

El archivo incluye suficientes datos de origen y unidades para seguir de dónde sale cada cifra y realizar análisis locales. Exportar no cambia ni sincroniza el escenario guardado; las modificaciones que se hagan en Excel quedan en el archivo local. Los avisos de cobertura parcial se conservan en el resumen y el detalle.

## Supuestos que configura el usuario

### Utilización prevista

- Kilómetros operativos esperados por mes, incluidos tramos vacíos.
- Esta proyección distribuye entre los kilómetros previstos los costos mensuales y la depreciación. No se infiere automáticamente de lecturas reales.
- El sistema impide calcular un costo por km completo si el valor es nulo o no positivo.

### Tractor y semi

Para cada activo por separado, los valores se ingresan en USD:

- valor actual de reposición estimado;
- valor residual esperado;
- vida útil estimada en años.

El costo de depreciación mensual se calcula como `(valor actual USD − valor residual USD) / meses de vida útil`. Para combinarlo con otros rubros se convierte a ARS usando la cotización de referencia del escenario. El costo por km se obtiene dividiendo ese importe mensual por los kilómetros operativos mensuales previstos.

### Cotización de referencia del dólar

- valor de referencia expresado como `ARS por 1 USD`;
- fecha a la que corresponde;
- tipo o fuente identificable (por ejemplo, una cotización seleccionada por la empresa).

La cotización se ingresa y mantiene manualmente por un usuario autorizado. El reporte siempre identifica el valor, la fecha y la fuente usados. Una variación de cotización recalcula las conversiones y deja claro que cambió un supuesto del escenario.

### Combustible

- consumo esperado en litros cada 100 km;
- precio del gasoil por litro y fecha de referencia.

Fórmula: `litros cada 100 km × precio por litro / 100`. El precio del gasoil puede ingresarse en ARS o USD; si está en USD, se convierte a ARS con la cotización del escenario.

### Neumáticos y recapados

- costo del juego de neumáticos para la configuración;
- costo total esperado de recapados durante su ciclo de uso;
- vida útil total prevista en kilómetros.

Fórmula: `(costo del juego + recapados previstos) / vida útil en km`. Los importes pueden ingresarse en ARS o USD y se normalizan con la cotización del escenario.

### Mantenimiento y reparaciones

El usuario puede expresar mantenimiento y reparaciones como porcentaje del costo teórico total. Esa opción estima ambos conceptos en conjunto. Para evitar una referencia circular, sea `B` la suma por km de los otros rubros configurados y `p` el porcentaje de mantenimiento/reparaciones expresado como decimal:

- `costo total = B / (1 − p)`;
- `mantenimiento por km = costo total × p`.

El porcentaje debe ser mayor o igual a cero y menor al 100 %. Si se usa este porcentaje no se agrega otro importe independiente de reparaciones en el mismo escenario; el sistema evita esa doble contabilización. Los costos asociados a mantenimiento no se cargan como gastos reales en esta primera versión.

### Costos mensuales y otros rubros

El usuario puede configurar importes mensuales para costos que no varían directamente con la distancia, por ejemplo seguro, patente y tasas, personal y gastos generales. Cada importe mensual se divide por los kilómetros operativos mensuales previstos para mostrar su incidencia por km. Cada importe conserva su moneda de entrada y se convierte a ARS con la cotización del escenario cuando corresponda.

Otros rubros de la estructura de costos —como lubricantes, costo financiero o reparaciones separadas— pueden expresarse con el supuesto explícito disponible para ese rubro. El sistema etiqueta su unidad para no mezclar ARS/mes con ARS/km. Los rubros sin valor se marcan sin configurar y limitan la cobertura del resultado.

Los peajes dependen del trayecto. Se ingresan en el simulador del viaje, no como un valor general por km del equipo. Se informa la moneda del importe y, si está en USD, se convierte con la misma cotización de referencia.

## Cálculos y resultados

El sistema suma los componentes variables por km, la depreciación y los costos mensuales distribuidos por la utilización prevista. Sobre ese subtotal aplica el porcentaje de mantenimiento configurado, si corresponde.

### Costo teórico por km recorrido

`costo teórico/km = suma de los rubros configurados por km operativo`.

Se refiere a un kilómetro que el equipo recorre, cargado o vacío. Presenta:

- total teórico por km;
- componentes por rubro;
- componentes variables y mensuales distribuidos;
- parámetros y fechas de referencia;
- costo por km en ARS y su equivalente en USD con la cotización identificada;
- estado completo o parcial y explicación de datos faltantes.

### Tarifa de referencia

El usuario configura un **recargo sobre el costo**. La pantalla lo denomina “recargo” para dejar clara la base del porcentaje.

`tarifa de referencia/km = costo teórico/km en ARS × (1 + recargo)`.

La pantalla muestra la tarifa sugerida tanto en ARS/km como en USD/km. El equivalente en USD se calcula como `tarifa ARS/km / cotización ARS por USD`.

El costo y la tarifa se presentan como cifras distintas. El sistema no los llama margen neto, utilidad garantizada ni precio después de impuestos.

### Tarifa por km facturable para un viaje

El usuario informa kilómetros facturables, kilómetros recorridos que no se facturan (por ejemplo, un regreso vacío), peajes y recargo. El simulador muestra el recorrido operativo total, el costo estimado del viaje y la tarifa por km facturable:

`costo del viaje = costo teórico/km × (km facturables + km no facturados) + peajes`.

`tarifa sugerida/km facturable en ARS = costo del viaje en ARS × (1 + recargo) / km facturables`.

El simulador también muestra el equivalente en USD/km usando la cotización del escenario.

Si no hay kilómetros facturables válidos, no se calcula una tarifa. Los peajes se muestran separados como componente del viaje, aunque se incluyan en el total sugerido.

## Presentación y comprensión

La pantalla prioriza cuatro elementos:

1. **Costo teórico por km recorrido.**
2. **Tarifa de referencia por km** con el recargo elegido.
3. **Simulación del viaje**, separando kilómetros facturables, kilómetros vacíos y peajes.
4. **Desglose de parámetros y rubros**, con avisos de faltantes o valores no válidos.

El costo y la tarifa se muestran en ARS/km y USD/km. Junto a ambos valores aparece la cotización usada (ARS por USD), su fecha y su fuente/tipo.

Los valores parciales se etiquetan expresamente y no se presentan con apariencia de cálculo completo. El usuario puede corregir los supuestos y ver cómo cambia la cifra antes de tomar una decisión.

## Referencia sectorial

La estructura toma como orientación los rubros usados en el Índice de Costos del Transporte de FADEEAC: combustible, lubricantes, neumáticos, reparaciones, material rodante, personal, seguros, patentes y tasas, costo financiero, gastos generales y peajes. El índice orienta la clasificación y el seguimiento sectorial; sus valores no se asignan automáticamente al camión de esta empresa.

La [Disposición 273/2011](https://www.argentina.gob.ar/normativa/nacional/disposici%C3%B3n-273-2011-182568/texto) presenta un modelo elaborado junto con FADEEAC y contempla que una tarifa de referencia por tramo absorba el recorrido de vuelta del camión vacío. Se usa como antecedente metodológico, no como tabla vigente de precios. La empresa define sus propios consumos, valores de activos, vidas útiles y costos.

## Fuera de alcance

- Captura o conciliación de gastos reales y órdenes de trabajo.
- Comparación de costo teórico contra costo real.
- Integración automática de precios, ponderaciones o índices de FADEEAC.
- Tarifas oficiales, condiciones comerciales por cliente o rentabilidad por viaje real.
- Integración con contabilidad, nómina, viajes operativos, facturación o liquidación de choferes.
- Cálculo automático de impuestos de facturación. La tarifa indicada es antes de impuestos de venta.

## Criterios de aceptación funcional

- Un dueño o usuario autorizado puede configurar y consultar el modelo de una combinación tractor/semi.
- La pantalla presenta el costo teórico por km operativo, detallado por rubro, a partir de los parámetros cargados.
- El cálculo incorpora el valor residual, vida útil y uso esperado del tractor y del semi por separado.
- El combustible y los neumáticos se normalizan por km mediante sus fórmulas y unidades visibles.
- El porcentaje de mantenimiento produce un resultado consistente sin sumar mantenimiento dos veces.
- Los valores de tractor y semi pueden expresarse en USD y se combinan con rubros en ARS mediante la cotización explícita del escenario.
- Costo y tarifa sugerida se muestran en ARS/km y USD/km; la pantalla identifica la cotización, fecha y fuente utilizadas.
- Sin una cotización válida cuando existen supuestos en USD, el sistema identifica el faltante y no presenta conversiones ni un total combinado como completo.
- La tarifa de referencia aplica el recargo configurable sobre el costo teórico.
- El simulador de viaje incorpora kilómetros no facturados y peajes al calcular la tarifa por km facturable.
- Los faltantes, divisores nulos y parámetros inválidos se explican; no se sustituyen silenciosamente por cero.
- Personas sin autorización no acceden al módulo ni a sus resultados.
- Un escenario guardado puede reabrirse con sus supuestos, cotización y resultados originales después de cambiar o guardar una versión nueva.
- La exportación `.xlsx` contiene el resumen, los supuestos, el desglose y la simulación de viaje del escenario seleccionado, sin modificar el registro del sistema.
