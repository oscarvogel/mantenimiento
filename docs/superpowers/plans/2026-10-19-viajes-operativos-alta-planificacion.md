# Plan: Viajes operativos, alta y planificación

## Alcance de este corte

- Alta de un viaje operativo en estado `PLANIFICADO`, con numeración correlativa por empresa.
- Recorrido con paradas ordenadas de carga, descarga e hitos.
- Asignación inicial de tractor/camión, acoplado opcional y uno o más choferes.
- Detección de superposición de equipo o chofer contra viajes planificados o en curso.
- Inicio del viaje con fecha real de salida y odómetro, pasando a `EN_CURSO`.
- Cancelación con motivo de un viaje aún no iniciado.
- Listado y ficha de lectura con scope de empresa y sucursales autorizadas.

## Fuera de este corte

- Cierre operativo, odómetro de regreso y lectura con origen `VIAJE`.
- Fletes, cobros, caja, gastos, liquidación y rentabilidad.
- Documentación internacional y checklist por corredor.
- Cálculo de ruta estimada sobre mapa.
- Relevos y cambios de equipo posteriores al inicio.

## Decisiones de diseño

- Los viajes operativos viven en tablas propias (`viajes_operativos` y sus hijas) y no
  comparten esquema con `viajes`, que conserva el historial legacy importado.
- La sucursal de origen se toma del alta y no cambia durante el ciclo del viaje.
- La numeración se reserva dentro de la transacción del alta mediante una fila de
  contador por empresa bloqueada con `FOR UPDATE`.
- La detección de superposición se consulta en la misma transacción y devuelve el
  viaje que se superpone para que el mensaje sea accionable.
- El dominio no conoce CodeIgniter: valida estados, paradas, asignaciones y odómetro.
- Los permisos nuevos son `viajes.operar` (alta, inicio y cancelación) y se suman a
  los roles Administrador y Responsable de mantenimiento. La lectura usa `viajes.ver`.
- Las fechas se guardan en hora local de Argentina, igual que el resto del sistema.

## Validación

- Pruebas puras del agregado: transiciones, asignaciones obligatorias, paradas y
  odómetro.
- Pruebas de aplicación con puertos falsos: permisos, scope, conflictos y numeración.
- Pruebas de read model con SQLite en memoria.
- Pruebas HTTP de acceso anónimo y CSRF.
- Verificación final en `fasa_189` antes de afirmar aceptación en staging.
