![Vogel Consultoría](assets/vogel-mark-white.png)

# Circuito de viajes, gastos y liquidación

**Propuesta de funcionamiento para la empresa**

Documento de trabajo · 21/10/2026

---

## 1. Para qué sirve este documento

Queremos ordenar en un solo circuito todo lo que pasa alrededor de un viaje:

- desde que el camión sale,
- los recorridos que hace,
- el combustible y los gastos,
- los adelantos y movimientos de la cuenta corriente del chofer,
- hasta el regreso, el cierre y la liquidación.

La idea es que la empresa pueda saber, para cada viaje y para cada tramo:

- cuánto se factura,
- cuánto se gasta,
- cuánto se le paga al chofer,
- y cuánto margen queda.

Hoy parte de esta información está en pantallas separadas, planillas o controles manuales. La propuesta es unificarla sin perder el detalle que ya usan.

---

## 2. Cómo funciona hoy, en simple

El circuito actual, a grandes rasgos, es este:

1. Se abre el viaje con el dominio, el chofer, la fecha y los kilómetros de salida.
2. Se cargan los recorridos o tramos.
3. Se cargan combustible y gastos.
4. Se anotan adelantos y gastos en la cuenta corriente del chofer.
5. Al volver, se cierra el viaje y se calcula la liquidación del chofer.

El sistema ya tiene partes de este circuito, pero no está todo integrado. La propuesta es que cada paso quede conectado con el anterior y que los números se calculen solos.

---

## 3. Cómo va a funcionar

### Paso 1. Abrir el viaje

Se elige el camión y el sistema completa:

- empresa,
- dominio,
- sucursal,
- chofer asignado,
- porcentaje de liquidación,
- último kilometraje conocido.

La persona revisa y cambia lo que haga falta: fecha de salida, chofer y kilómetros de salida.

### Paso 2. Cargar los tramos

Un viaje puede tener uno o varios tramos. Por ejemplo:

- Rosario → Córdoba,
- Córdoba → Rosario,
- o los recorridos adicionales que se hagan antes de volver.

Cada tramo tiene dos importes distintos:

- **Flete de facturación:** lo que se le cobra al cliente.
- **Flete de liquidación:** la base sobre la que se calcula el porcentaje del chofer.

Estos dos importes no tienen por qué ser iguales.

### Paso 3. Salir

Se registra la salida real y el viaje queda **En curso**.

### Paso 4. Cargar combustible y gastos

Se cargan las cargas de combustible y los gastos del viaje.

Cada gasto o carga de combustible puede asignarse a un tramo. Si no se asigna, queda como gasto general del viaje.

Esto permite saber:

- cuánto costó cada tramo,
- y cuánto costó el viaje completo.

### Paso 5. Cargar la cuenta corriente y otros haberes

Acá se registran los movimientos entre la empresa y el chofer:

- adelantos,
- devoluciones,
- gastos pagados por el chofer,
- otros haberes o adicionales,
- ajustes.

La cuenta corriente muestra el saldo: si la empresa le debe al chofer o si el chofer le debe a la empresa.

### Paso 6. Cerrar el viaje

Cuando el camión vuelve, se cargan:

- fecha de regreso,
- kilómetros de regreso.

El sistema calcula los kilómetros recorridos, genera la lectura del equipo y deja el viaje cerrado.

### Paso 7. Liquidar al chofer

Con el viaje cerrado, el sistema muestra:

- comisión del chofer,
- otros haberes,
- gastos pagados por el chofer,
- adelantos y devoluciones,
- total a liquidar.

### Paso 8. Ver el resultado

Por cada tramo y por el viaje completo se muestra:

- facturado,
- combustible,
- gastos,
- comisión,
- otros haberes,
- margen.

Así se puede saber si un viaje o un tramo es rentable.

---

## 4. Qué datos se cargan

| Etapa | Datos principales |
|---|---|
| Viaje | Empresa, dominio, sucursal, chofer, porcentaje, fecha y km de salida, fecha y km de regreso. |
| Tramo | Origen, destino, cliente, kilómetros, flete de facturación y flete de liquidación. |
| Combustible | Fecha, litros, importe, moneda y tramo al que se imputa (opcional). |
| Gasto | Fecha, concepto, importe, moneda, quién lo pagó y tramo al que se imputa (opcional). |
| Cuenta corriente | Fecha, tipo de movimiento, concepto, importe, moneda y observaciones. |
| Otros haberes | Fecha, concepto, importe, moneda y observaciones. |
| Cierre | Fecha de regreso, km de regreso y confirmación de los datos. |

---

## 5. Cómo se calcula la liquidación del chofer

Ejemplo con un viaje de dos tramos:

| Concepto | Importe |
|---|---:|
| Flete de liquidación total | 1.300.000 |
| Porcentaje del chofer | 14% |
| **Comisión** | **182.000** |
| Otros haberes | 20.000 |
| Gastos pagados por el chofer | 80.000 |
| Adelantos | −200.000 |
| Devoluciones | 30.000 |
| **Total a liquidar** | **112.000** |

La comisión se calcula **sobre el flete de liquidación**, no sobre el flete de facturación.

Los adelantos no son un costo del viaje: se descuentan de la liquidación del chofer.

---

## 6. Cómo se calcula el margen

### Margen del viaje

| Concepto | Importe |
|---|---:|
| Flete de facturación total | 1.600.000 |
| Combustible | −320.000 |
| Gastos | −80.000 |
| Comisión del chofer | −182.000 |
| Otros haberes | −20.000 |
| **Costo total** | **−602.000** |
| **Margen del viaje** | **998.000** |
| **Margen %** | **62,4%** |

### Margen por tramo

Cada tramo muestra su propio flete de facturación, sus costos asignados y su comisión.

Si un gasto o una carga de combustible no se asigna a un tramo, suma al margen del viaje pero no al margen de ningún tramo. El sistema muestra por separado los **costos sin asignar** para que la cuenta cierre.

---

## 7. Qué incluye la primera etapa

La primera etapa incluye:

- viajes con uno o varios tramos;
- dos fletes por tramo: facturación y liquidación;
- porcentaje del chofer editable para el viaje;
- combustible y gastos, con tramo opcional;
- cuenta corriente del chofer y otros haberes;
- cierre del viaje con km de regreso y lectura del equipo;
- liquidación del chofer;
- margen por viaje y por tramo;
- vista de viajes por dominio y chofer.

En esta etapa no se incluye:

- facturación electrónica;
- integración contable;
- liquidación de haberes fuera del viaje;
- pagos bancarios.

---

## 8. Qué necesitamos confirmar

Para terminar de definir el circuito, necesitamos responder estas preguntas:

| Pregunta | Por qué lo preguntamos |
|---|---|
| ¿El viaje se abre y sale en el mismo momento, o se planifica para otra fecha? | Define si el viaje queda “Planificado” hasta que sale o si directamente queda “En curso”. |
| ¿Siempre hay un solo chofer responsable de la liquidación? | Si hay varios choferes, hay que definir cómo se reparte la comisión. |
| ¿Los fletes se cargan siempre en pesos o puede haber tramos en otra moneda? | Define si necesitamos manejar moneda y cotización también en los fletes. |
| ¿Los otros haberes son por viaje o por tramo? | Define si se cargan una sola vez para todo el viaje o por cada recorrido. |
| ¿El cierre se bloquea si falta combustible o gastos, o solo se advierte? | Un viaje puede no tener gastos o cargar combustible en otro lado. |
| ¿La liquidación se paga por viaje o se acumula por chofer y período? | Define si la pantalla es por viaje o una cuenta corriente mensual. |
| ¿Necesitan imprimir la liquidación y el resumen del viaje? | Define si incluimos impresión en esta etapa o después. |
| ¿Hoy usan el tilde “Contabilizado” y la fecha? ¿Quién lo completa? | Si lo usan, lo agregamos como control; si no, lo dejamos fuera. |

---

## 9. Próximos pasos

1. Revisar esta propuesta y responder las preguntas de la sección 8.
2. Ajustar el circuito con las respuestas.
3. Implementar la primera etapa.
4. Probar con viajes reales de la empresa.
5. Revisar los resultados y definir la segunda etapa (aprobación y pagos de liquidación).

---

## 10. Qué no cambia

- Los viajes históricos se siguen consultando como hasta ahora.
- No se borra información.
- Los usuarios y permisos actuales se mantienen.
- La empresa sigue decidiendo quién carga cada dato y quién lo aprueba.

---

## 11. Beneficios

- Un solo lugar para seguir el viaje completo.
- Menos planillas paralelas y menos controles manuales.
- Mayor claridad sobre lo que se factura, lo que se gasta y lo que se liquida.
- Posibilidad de saber si un viaje o un tramo es rentable.
- Historial ordenado y trazable de cada movimiento.

---

**Vogel Consultoría**

Documento de trabajo para revisión y validación.
