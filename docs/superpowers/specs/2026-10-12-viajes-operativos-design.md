# Borrador de especificación: módulo Viajes

**Estado:** borrador para revisión del usuario. No autoriza implementación.
**Fecha:** 2026-10-12
**Alcance:** planificación y ejecución de viajes, documentación internacional, caja, fletes/cobros, liquidación de choferes y análisis económico.

## 1. Propósito

Dar a administración y operaciones un lugar único para planificar, seguir y cerrar viajes de transporte, incluidos recorridos con varios puntos de carga y descarga. El sistema debe conservar la trazabilidad operativa y financiera del viaje, permitir viajes internacionales y evitar que saldos pendientes impidan cerrar el recorrido.

El módulo se presenta como **Viajes**. Las pantallas podrán separar recorrido, documentación, caja, fletes/cobros y liquidaciones, pero no deben crear entradas principales de menú independientes.

## 2. Decisiones confirmadas

- El recorrido puede incluir múltiples cargas y descargas, alternadas en varias paradas.
- Cada tramo registra cliente, carga, descripción, cantidad, unidad seleccionable y peso/cantidad cuando corresponda. Puede elegirse un domicilio guardado del cliente o informarse una dirección puntual. Se permite adjuntar remito, foto o constancia.
- Se crea un padrón básico de clientes con nombre/razón social, identificación fiscal y domicilios. Este módulo no mantiene cuenta corriente global del cliente.
- El viaje pertenece a la sucursal de origen durante todo su ciclo.
- Estados operativos: **Planificado**, **En curso**, **Finalizado** y **Cancelado**. Se guardan fechas/horas planificadas y reales de salida y regreso.
- La numeración del viaje es correlativa automática por empresa.
- La asignación debe bloquear conflictos de horario para el mismo camión/tractor o chofer e indicar cuál viaje se superpone.
- Administración/operaciones carga los avances, gastos y rendiciones. Los choferes son empleados existentes y no usan el módulo para autogestionarse.
- Un viaje puede asignar camión/tractor y acoplado/semirremolque. Los equipos pueden cambiar durante el viaje; cada cambio posterior al inicio conserva fecha, odómetro y motivo. El acoplado queda en el historial sin actualización de kilometraje.
- Odómetro de salida y regreso del camión/tractor son obligatorios. El regreso actualiza su lectura mediante el caso de uso de Lecturas, con origen **VIAJE**, validaciones y trazabilidad.
- Un viaje puede finalizar operativamente aunque queden pendientes la caja, cobros o liquidación. Los pendientes financieros deben permanecer visibles.

## 3. Recorrido y paradas

Cada parada tendrá orden, tipo (carga/descarga u otro hito operativo que se acuerde en diseño), ubicación, cliente asociado cuando aplique, fecha/hora estimada y real, carga vinculada y constancia adjunta. El borrador no presupone que todas las paradas deban tener cliente ni flete.

### Estimación automática de kilómetros de ruta

Al cargar el origen, el destino y las paradas intermedias, el usuario podrá solicitar el cálculo de una ruta vial sobre el mapa. Para las ubicaciones seleccionadas, el sistema resolverá coordenadas y consultará un motor de ruteo basado en datos de OpenStreetMap, como OSRM. Mostrará el trazado y los kilómetros estimados por tramo y para el recorrido completo; deberá recalcular al cambiar el orden o ubicación de las paradas y conservar la fecha/fuente de la estimación.

La distancia de ruta es una **estimación para planificar y presupuestar**. No reemplaza el odómetro real de salida, regreso o relevo, ni se usa para calcular la liquidación del chofer: esa liquidación sigue basándose en las lecturas reales de odómetro acordadas en §4 y §9. Si el proveedor no encuentra una ruta, el sistema informa que no pudo calcularla; no muestra una distancia inventada.

OpenStreetMap aporta datos, mientras que el cálculo lo hace un motor de ruteo. OSRM documenta el cálculo de rutas entre coordenadas y perfiles de transporte; los perfiles incluidos son auto, bicicleta y peatón. Por eso, el dueño debe confirmar si una ruta vial estándar alcanza o si se necesitan restricciones específicas de camión/semirremolque (por ejemplo, altura, peso o largo). La cobertura y las restricciones disponibles dependen del perfil y de los datos cartográficos existentes.

La geocodificación de domicilios es una función separada del trazado. Si se usa el Nominatim público, se deben respetar sus límites de uso, identificación, atribución y cacheo; la política establece un máximo absoluto de una solicitud por segundo, desaconseja uso intensivo y advierte sobre privacidad. Para uso productivo se debe poder cambiar el proveedor, cachear resultados y evaluar un proveedor alojado o una instancia propia, sin depender de una instancia comunitaria como si tuviera SLA. Referencias técnicas: [API de ruteo OSRM](https://project-osrm.org/docs/), [perfiles OSRM](https://project-osrm.org/docs/v26.4.0/profiles), [política de Nominatim](https://operations.osmfoundation.org/policies/nominatim/) y [política de tiles OSM](https://operations.osmfoundation.org/policies/tiles/).

Los fletes se informan por tramo/cliente. En el tramo deben poder relacionarse uno o más puntos de carga y descarga, cliente pagador, carga y los importes correspondientes. Debe conservarse el vínculo entre el ingreso y el recorrido que lo originó.

## 4. Asignaciones y relevos

La asignación inicial incluye tractor/camión, acoplado o semirremolque y uno o más choferes. Para un relevo se registra quién entrega y quién toma, fecha/hora, odómetro y motivo. La diferencia entre lecturas determina los kilómetros atribuidos a cada chofer; deben validarse lecturas faltantes, retrocesos y consistencia con el kilometraje total del viaje.

Los cambios de equipo o recorrido posteriores al inicio son movimientos históricos, no una sobrescritura silenciosa de la planificación original.

## 5. Documentación internacional

El recorrido internacional registra país de origen, país de destino, países de tránsito y pasos fronterizos/aduanas previstos. Antes de iniciar el viaje, la pantalla muestra un checklist documental con vencimientos y alertas. La lista exacta de requisitos por país/corredor queda pendiente de validación del dueño (ver §12).

### 5.1 CRT

- El sistema registra CRT físicos preimpresos y permite adjuntar su copia; **no genera ni imprime el formulario oficial**.
- Se pueden asociar varios CRT a un viaje, normalmente uno por remesa.
- Cada empresa configura el próximo número físico disponible al habilitar el control. El sistema propone el siguiente correlativo, no permite saltos ni reutilizaciones y reserva cada número de forma atómica para evitar duplicados ante operaciones concurrentes.
- Una anulación expresa consume el número y conserva el registro, quién la realizó, cuándo y el motivo. El número anulado no vuelve a estar disponible.
- Antes de iniciar un viaje internacional debe haber un número CRT asociado a cada remesa. La copia escaneada puede adjuntarse después.
- Remitente, destinatario y consignatario se registran como roles separados del cliente pagador y pueden ser personas/organizaciones distintas.

La guía oficial de la ANTT para el formulario indica que cada empresa da a sus formularios un número correlativo que no puede repetirse. El control de no dejar huecos y documentar una anulación es una regla operativa acordada con el usuario; no se presenta como una exigencia legal universal. Referencias: [guía/formulario CRT de ANTT](https://www.gov.br/antt/pt-br/assuntos/sistemas-antt-1/tric-1/anexo-portaria-no-05-2024/xvi-reuniao-de-ministros-de-obras-publicas-e-transportes-do-conesul-compactado-1-1.pdf) y [Acuerdo ATIT, artículo 28](https://anttlegis.antt.gov.br/action/UrlPublicasAction.php?acao=abrirAtoPublico&cod_menu=7114&cod_modulo=421&num_ato=00099704&seq_ato=000&sgl_orgao=NI&sgl_tipo=DEC&vlr_ano=1990).

### 5.2 MIC/DTA y demás documentos

Se registra número y copia del MIC/DTA, sin generación. CRT y MIC/DTA son documentos separados. La regulación argentina consultada enumera ambos; la aceptación de formatos digitales depende de acuerdos entre los países involucrados y de mecanismos de verificación. Por eso, una copia cargada al sistema no sustituye por defecto el documento físico ni una presentación exigida por la autoridad. Referencia: [Resolución 320/2022, texto oficial](https://www.argentina.gob.ar/normativa/nacional/norma-365668/texto).

El checklist puede contemplar permisos, seguros, inspecciones técnicas y documentos del chofer y los vehículos, con fechas de vigencia/alertas. La matriz obligatoria por país y corredor no se fijará hasta recibir validación del dueño.

## 6. Monedas y cotizaciones

Monedas admitidas: **ARS, CLP, BRL, UYU y USD**. Cada movimiento monetario registra moneda, importe original, fecha y cotización manual aplicada. La cotización se expresa como cuántos ARS vale una unidad de moneda extranjera. ARS es la moneda de consolidación; se conservan siempre los valores originales y su conversión.

La cotización queda congelada en cada movimiento. No se recalculan operaciones históricas cuando cambie una cotización. Las diferencias de cambio entre el valor de origen y pagos posteriores se muestran por separado. Importes persistidos como `DECIMAL`, nunca `FLOAT`/`DOUBLE`.

## 7. Fletes y cobros a clientes

- Por tramo/cliente se registra el importe del flete acordado o facturado y, por separado, su base para liquidación al chofer. Pueden tener monedas distintas.
- El sistema no emite facturas; registra fletes y cobros facturados fuera del sistema.
- Se permiten cobros parciales o totales asociados a uno o varios tramos concretos, con fecha, importe, moneda, cotización, medio de pago y referencia/comprobante. Medios iniciales: efectivo, transferencia, cheque y otros configurables. No se manejan bancos.
- El saldo pendiente se muestra por tramo/cliente. Puede cargarse vencimiento opcional y consultar vencidos.
- Los pagos en moneda distinta se registran en su moneda y cotización; se conserva el importe original y se aplica a la deuda por equivalente ARS, mostrando por separado la diferencia de cambio.
- No se implementa cuenta corriente global del cliente.

## 8. Caja del chofer y gastos

La caja de viaje y la liquidación del chofer son cuentas separadas; el anticipo de caja no reduce la liquidación.

- Se registran anticipos autorizados, gastos rendidos y saldo devuelto o a favor.
- Cada chofer tiene su propia rendición dentro del viaje. Al relevo se cierra la caja del que entrega y se abre/continúa la del que toma.
- Anticipos y gastos pueden usar distintas monedas; cada movimiento conserva importe original, cotización, fecha y equivalente ARS.
- Categorías iniciales: combustible, peajes, viáticos, carga/descarga y otros gastos.
- El combustible real se carga manualmente desde comprobantes, con litros e importe. La telemetría queda como referencia diagnóstica, no como costo contable.
- Se permite adjuntar comprobante. Si no existe, se requiere motivo de excepción.
- Si los gastos superan el anticipo, el saldo a favor del chofer queda como reintegro pendiente separado, con moneda y cotización propias; no se compensa automáticamente con la liquidación.
- Una persona autorizada de administración/operaciones aprueba anticipos y rendiciones.

## 9. Liquidación de choferes

Cada empleado/chofer tiene un porcentaje habitual con vigencia desde/hasta. El sistema copia al viaje el porcentaje efectivamente aplicado para preservar el historial. Puede aplicarse una excepción por viaje si se registra el motivo.

Fórmula acordada por tramo:

1. distribuir la base de liquidación del tramo entre choferes según los kilómetros atribuibles a cada uno;
2. aplicar a la porción de cada chofer su porcentaje vigente o la excepción aprobada;
3. sumar adicionales o descuentos del viaje con motivo explícito.

Se permiten pagos parciales de liquidación, guardando fecha, medio, moneda, cotización e importe; el saldo pendiente queda visible. Los pagos se aprueban/marcan como pagados por un responsable autorizado. Cambios posteriores del porcentaje habitual no recalculan liquidaciones históricas.

## 10. Presupuesto y análisis de costos

El presupuesto interno de gastos es opcional y estima rubros como combustible, peajes y viáticos para comparar planificado contra real. No bloquea la creación, inicio ni finalización operativa.

La carga de costos reales y sus monedas queda definida en este borrador, pero **la fórmula de rentabilidad no está aprobada**. No se asumirá si incluye solo costos directos del viaje o también mantenimiento, neumáticos, seguros, depreciación, costos fijos/administrativos u otros conceptos. Tampoco se inventarán costos por kilómetro ni fuentes de datos faltantes. Si falta información, los informes deben decir “sin datos suficientes”.

## 11. Límites de arquitectura y permisos

Implementación prevista en el monolito modular CI4 existente; no es una propuesta de microservicios. El contexto Viajes coordina casos de uso y mantiene separadas las responsabilidades de recorrido, documentación y registros financieros, para que cerrar el recorrido no obligue a saldar todas las cuentas.

- La sucursal de origen y el `empresa_id` se validan en servidor en cada operación.
- Responsables autorizados de administración/operaciones pueden crear, asignar, iniciar, modificar y cancelar viajes, aprobar anticipos/rendiciones y registrar pagos de liquidación.
- Viajes referencia Equipo, Empleado y Lectura por ID y coordina sus contratos/casos de uso; no comparte modelos ORM ni consulta tablas ajenas desde Presentation.
- El regreso del viaje solicita al contexto de Medición de uso la actualización validada del kilometraje, con origen `VIAJE`.
- Las operaciones multi-registro con consistencia inmediata usan una transacción de aplicación. Los registros sensibles y anulaciones conservan autoría y motivo.
- Los adjuntos se validan por tipo, extensión y tamaño, y se almacenan en el filesystem privado conforme a las reglas actuales.

## 12. Decisiones para consultar al dueño

1. **Criterio de rentabilidad:** ¿qué costos deben restarse del flete para calcular el resultado del viaje? Definir costos directos e indirectos incluidos (combustible, peajes, gastos, liquidación del chofer, mantenimiento, neumáticos, seguro, depreciación, administración u otros), fórmula y fuentes de cada valor. ¿Necesita margen por tramo, viaje o ambos?
2. **Requisitos internacionales por corredor:** ¿qué países y pasos fronterizos se utilizan? Para cada combinación de origen, destino y tránsito, confirmar documentos, permisos, vencimientos y responsable de validarlos. ¿En qué casos se acepta presentación digital y cuál es el mecanismo oficial de verificación? Confirmar si hay requisitos adicionales por tipo de carga/vehículo.
3. **Perfil para estimar rutas:** ¿alcanza con una ruta estándar para vehículos, o la estimación debe considerar dimensiones y peso del camión/semirremolque, restricciones por carga y preferencias como evitar peajes? La ruta estimada no determinará la liquidación del chofer.

Hasta que estas respuestas estén documentadas, los reportes de rentabilidad y el checklist internacional deben señalar que la configuración está pendiente; no presentar un cálculo o una lista parcial como completos.

## 13. Criterios de aceptación para una futura implementación

- Crear, numerar, planificar, asignar e iniciar un viaje con ruta de varias cargas/descargas y detectar superposición de equipo/chofer.
- Calcular y mostrar kilómetros de ruta estimados por tramo y en total usando el orden de paradas; actualizarlos si cambia la ruta, indicar fuente/fecha y distinguirlos de los kilómetros reales por odómetro. Si no se obtiene una ruta, informar el problema sin inventar kilómetros.
- Registrar cambios de chofer/equipo trazables; calcular kilómetros por chofer desde lecturas de relevo y bloquear datos imposibles con explicación accionable.
- Exigir odómetros de salida/regreso; al finalizar, actualizar la lectura del tractor por el caso de uso de Medición con origen `VIAJE`, sin alterar el kilometraje de acoplados.
- Finalizar el recorrido con cuentas pendientes y mostrar claramente cada saldo posterior.
- Registrar múltiples CRT por remesa y viajes, proponer secuencia sin duplicar ni saltar; una anulación conserva y consume el número; exigir CRT numerado por remesa antes de iniciar viaje internacional, permitiendo adjuntar copia después.
- Registrar MIC/DTA sin generarlo y mostrar el checklist/vencimientos definido para el corredor.
- Conservar moneda, cotización y equivalente ARS por movimiento, y mostrar pagos parciales, saldos y diferencias de cambio sin borrar el valor original.
- Separar flete, cobros, caja, gastos y liquidación; el anticipo no altera liquidación; el pago de una cuenta no cancela otra.
- Aplicar porcentaje por chofer/kilómetros por tramo, excepciones justificadas, pagos parciales y adicionales/descuentos auditables.
- No emitir factura, no crear cuenta corriente global ni inferir rentabilidad cuando la regla del dueño no esté definida.
- Cubrir aislamiento por empresa/sucursal, permisos, transacciones, concurrencia de numeración CRT, adjuntos y auditoría con pruebas de dominio, aplicación, DB y HTTP.

Antes de programar, revisar este borrador y cerrar o registrar explícitamente las respuestas pendientes del dueño.
