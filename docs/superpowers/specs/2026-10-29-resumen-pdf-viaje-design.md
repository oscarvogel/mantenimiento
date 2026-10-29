# Diseño: resumen PDF del viaje operativo

## Intención

Permitir descargar desde el detalle de un viaje finalizado un PDF basado en el resumen del sistema anterior, con el recorrido y los importes guardados en el viaje operativo actual. El objetivo es entregar una constancia imprimible del cierre sin crear una segunda fuente de cálculo.

## Alcance

- El enlace de descarga aparece solo cuando el viaje está finalizado.
- El endpoint respeta autenticación, permiso `viajes.ver`, empresa y sucursales del actor.
- El PDF presenta encabezado del viaje, unidad y chofer asignados, kilometraje, tramos/fletes, cargas de combustible, gastos, movimientos de caja, otros haberes, liquidación y margen.
- Se reutilizan los totales del caso de uso `GetOperationalTrip`.
- Conceptos que el modelo operativo no calcula (por ejemplo seguro, cubiertas o amortización) se indican como no incluidos/sin dato; no alteran los totales.

## Enfoques

1. Generar el PDF con datos directos de MariaDB: descartado porque duplicaría consultas, cálculos y reglas de scoping.
2. Añadir una salida PDF al detalle del viaje mediante un caso de uso y un puerto de renderizado: elegido porque reutiliza el read model autorizado y mantiene separado el formato PDF de las reglas económicas.
3. Mostrar HTML y depender de “Imprimir > Guardar como PDF”: descartado porque no entrega un PDF descargable uniforme ni el flujo pedido.

## Decisiones de implementación

- La salida será un PDF descargable desde el detalle del viaje cerrado.
- Se aprovechará una librería PDF mantenida compatible con el PHP del proyecto.
- No se modifican ni se recalculan datos del viaje al descargar el informe.
- Los viajes planificados, en curso, cancelados o fuera del scope no pueden producir el informe.

## Verificación

- Pruebas del caso de uso para viaje finalizado, estado inválido y scoping.
- Pruebas del generador para estructura y contenido del PDF.
- Prueba de interfaz para mostrar el enlace solo en viajes finalizados.
- Validación manual con Playwright contra el staging canónico, incluyendo descarga y lectura del PDF generado.

## Datos de staging

El destino elegido por el usuario es `TSA Argentina` en `fasa_189`. El viaje histórico importado #102 ya contiene sus cuatro fletes, seis cargas de combustible, cinco gastos, seis movimientos de cuenta y un haber; no se duplican esas filas ni se modifica el historial importado.

Para validar las pantallas operativas y la descarga se crea un viaje separado, identificado en observaciones como `PRUEBA PLAYWRIGHT`. Se usa el tractor AF 117 ZK con su lectura vigente como odómetro de salida y se conserva el recorrido de 6.139 km con una lectura de regreso posterior. Las fechas del viaje de prueba corresponden a la fecha de la validación. Así se prueba el circuito actual sin retroceder la lectura del equipo ni alterar el viaje legacy #102.
