# Histórico de ralentí y alertas de velocidad — Diseño

**Estado:** Propuesta para revisión de producto
**Contexto:** Telemetría de flota en el monolito CI4 existente

## Objetivo

Ayudar a cada empresa a detectar uso excesivo de ralentí y excesos de velocidad en sus equipos. Mostrar en la ficha del equipo un resumen comprensible del período evaluado y enviar avisos accionables sin generar duplicados ni presentar estimaciones como mediciones exactas.

## Estado actual

- La integración consulta periódicamente los proveedores y actualiza una instantánea por equipo y fuente.
- `telematia_ultima_lectura` se sobrescribe en cada ingesta; no guarda una serie temporal.
- Ralentí se interpreta actualmente para Wialon si el nombre del sensor contiene “ralentí”; valor mayor a cero significa activo. Un sensor no disponible o no reconocido es `sin dato`.
- El panel de problemas actual solo muestra valores numéricos imposibles; `sin dato` no se considera anomalía. Una fuente completa atrasada ya tiene un estado separado de señal.
- El ciclo de notificaciones ya refresca telemetría antes de evaluar y publicar eventos.

Por lo tanto, los porcentajes históricos no pueden calcularse con los registros actuales: hay que empezar a guardar las muestras de cada ejecución.

## Decisiones de producto propuestas

### Captura e histórico

- Guardar una muestra por ejecución programada, prevista cada 15 minutos, para cada equipo vinculado a su fuente principal. Conservar tanto la hora de captura local como la hora reportada por el proveedor.
- Guardar las métricas normalizadas disponibles (posición, velocidad, kilometraje, horómetro, estado de motor y ralentí, voltaje, combustible) y la lista completa de sensores que el proveedor devuelve, con identificador, nombre, tipo, unidad y valor. Esto permite estudiar sensores que hoy no tienen un campo canónico.
- Para Wialon, unir las definiciones de `core/search_items` con los valores de `unit/calc_last`. Guardar el registro normalizado, no la respuesta cruda completa ni datos de sesión/autenticación del proveedor.
- Si Wialon define un sensor pero la captura no trae un valor, conservar su definición con `valor = null`; si el proveedor no define el sensor, no crear una falla de sensor.
- Los campos ausentes permanecen nulos; nunca copiar el valor anterior como si fuese una lectura nueva.
- Retener las muestras por 90 días. Antes de purgar las anteriores, realizar un backup en una ubicación segura y verificar que se haya completado correctamente.
- Mantener intacta la tabla de última lectura, que seguirá alimentando mapa y vistas actuales.

### Estado de sensores

- Distinguir tres situaciones: valor fuera de rango, sensor definido que no entrega valor y telemetría completa atrasada.
- Una captura válida para este diagnóstico requiere que la consulta al proveedor haya terminado bien y que la fuente siga dentro de la frescura existente. Un sensor que el proveedor declara como configurado y permanece sin valor en dos de esas capturas consecutivas se marca “Sin lectura · revisar”. Una muestra ausente aislada no genera alerta.
- Si el estado general de la fuente ya es atrasado según la regla de frescura existente, informar “Telemetría desactualizada”; no señalar sensores individuales como averiados por valores faltantes.
- Si no existe una definición de ese sensor en el proveedor, mostrar “No configurado” o “Sin dato” sin tratarlo como avería.
- Las métricas canónicas (combustible, batería, ralentí, motor) se relacionan con el sensor de origen para mostrar cuál dejó de entregar valor.

### Ralentí

- Configuración a nivel empresa: porcentaje máximo y período móvil de 7, 14 o 30 días. Período inicial: 7 días. Límite porcentual inicialmente vacío; vacío significa desactivado.
- Porcentaje estimado = minutos de ralentí / minutos con motor encendido × 100. Se incluyen intervalos cuando hay muestras válidas de motor y ralentí, y las muestras consecutivas están separadas como máximo por 30 minutos. Cada estado se extiende hasta la siguiente muestra válida.
- Un período con menos de 70% de cobertura válida se considera insuficiente y no produce alerta. La ficha muestra la cobertura para que el operador entienda el resultado.
- Mostrar porcentaje, horas estimadas en ralentí, horas de motor consideradas, período y límite configurado.
- El mismo intervalo alimenta el cálculo de consumo: el bloque de la ficha puede decir cuántos litros del período se explican por el camión parado. Ver la ampliación de abajo.

### Velocidad

- Configuración a nivel empresa: velocidad máxima en km/h; inicialmente vacía y desactivada.
- Marcar exceso cuando dos muestras consecutivas válidas superen el límite. Una interrupción de señal o un intervalo mayor a 30 minutos reinicia la secuencia.
- Mostrar en la ficha la velocidad de la última muestra con su antigüedad y si excede el límite configurado.

### Alertas

- Evaluar las dos reglas después de cada ingesta en el ciclo existente.
- Publicar una notificación por equipo y regla por día como máximo, con enlace directo a su ficha. Una mejora de datos no debe emitir alertas duplicadas en cada ejecución del cron.
- El evento informa el período, el límite, el valor medido/estimado y la cobertura disponible.
- Las preferencias y destinatarios siguen usando el circuito existente de notificaciones de la empresa.

## Presentación y configuración

- Agregar los parámetros en Administración → Integraciones → Telemetría, junto a la configuración de proveedores de esa empresa.
- En la ficha del equipo, agregar un bloque de uso operativo con el resumen de ralentí del período configurado y el estado de velocidad respecto del máximo.
- Cuando el límite está vacío, indicar “Sin configurar”. Si no hay muestras suficientes o el proveedor no aporta un dato, indicar “Datos insuficientes” o “Sin dato”, sin mostrar cero como resultado.
- Mostrar siempre fecha/hora de captura o antigüedad para que una muestra atrasada no parezca actual.
- En la tarjeta de sensores, incluir anomalías numéricas y sensores definidos sin lectura; presentar pérdida completa de señal en su indicador de telemetría.

## Arquitectura y seguridad

- Contexto propietario: Telemetría. La política, el cálculo y la evaluación viven en Application/Domain; CI4 y MariaDB quedan como adaptadores.
- Contexto de Notificaciones recibe eventos con clave idempotente; no consulta directamente la tabla histórica.
- Toda lectura y escritura de configuración e histórico se limita por `empresa_id`, equipo y sucursal/permiso aplicables.
- Crear migraciones reproducibles; no modificar migraciones ya aplicadas.

## Fuera de alcance

- Reconstrucción exacta segundo a segundo o consulta de informes históricos específicos del proveedor.
- Reglas diferentes por camión o sucursal; esta primera versión configura límites por empresa.
- Alertas por el primer único punto de velocidad, para evitar que una muestra aislada o un salto GPS dispare un aviso.
- **Valores de combustible con significado fiscal.** El odómetro de Wialon es calculado por distancia GPS y no es el odómetro físico del vehículo; el consumo derivado de él es diagnóstico operativo, no respaldo contable. Los litros que se facturan son los de la tarjeta o el ERP.

## Ampliación del 10/10/2026: consumo de combustible

La primera versión de este documento declaraba el cálculo de consumo fuera de alcance.
**Se incorpora.** La tabla de muestras que este diseño crea ya guarda `combustible`
entre las métricas normalizadas, y el plan de implementación ejecuta los cuatro módulos
en un solo ciclo: no tiene sentido construir el histórico dos veces.

Decisión de producto: **el viaje no se persiste.** El intervalo es la unidad con
identidad propia; el trayecto se deriva de la serie en el momento de leer. Persistir el
viaje congelaría una interpretación que hay que corregir cada vez que mejora la
cobertura, y esa corrección no tendría dónde esconderse.

El combustible se expresa en **dos indicadores que se leen juntos**, porque cada uno
tapa el punto ciego del otro:

| l/100 km | l/h de motor | Lectura |
|---|---|---|
| Alto | Normal | Quemando rodando: carga, ruta, presión, inyección |
| Normal | Alto | Quemando parado: ralentí, o algo arrastrando |
| Alto | Alto | Problema global, o deriva del sensor de tanque |

El denominador de l/h son las **horas de motor**, no las horas de reloj. Una unidad con
horómetro implausible queda excluida del cálculo en lugar de producir un ratio absurdo.

La atribución de litros tiene cuatro reglas, y cada una existe porque sin ella el
número miente: un descenso es consumo y un ascenso es carga; un intervalo con carga no
computa consumo; un delta por debajo del mínimo se informa como «sin variación» y no
como 0; y una subida en un tanque con una bajada en el otro es una transferencia, no
una carga.

Ralentí y combustible comparten el mismo intervalo y la misma política de cobertura. Ése
es el motivo de que el bloque de la ficha diga cuántos litros se quemaron con el
camión parado, y no sólo el porcentaje de tiempo. La **tasa de consumo en ralentí no
está medida en ninguna parte**: el parámetro nace vacío y desactivado, igual que el
límite de velocidad. Sin configurar, se muestra la proporción de tiempo, que sí es
medible; no se inventa una tasa para llenar un espacio en la pantalla.

Detalle completo en `2026-10-10-consumo-por-viaje-design.md`. Secuencia de ejecución en
`docs/superpowers/plans/2026-10-10-historico-consumo-ralenti-velocidad.md`.

## Criterios de aceptación

1. Una nueva ejecución agrega muestras sin reemplazar las históricas y no duplica una misma captura.
2. Muestras con campos ausentes, lecturas repetidas y huecos mayores a 30 minutos no producen duración inventada.
3. El porcentaje usa solo intervalos válidos con motor encendido y ralentí conocido; cobertura menor a 70% se marca insuficiente.
4. Límites vacíos no generan avisos. Los límites guardados para una empresa no son visibles ni aplicables a otra.
5. Dos muestras válidas consecutivas sobre el máximo de velocidad activan exceso; la muestra aislada no.
6. El ciclo repetido varias veces el mismo día no duplica la notificación por equipo y regla.
7. La ficha distingue valor reciente, estado excedido, sin configurar, sin dato y datos insuficientes.
8. Un sensor definido sin valor en dos capturas válidas se marca para revisión; una sola ausencia no lo hace.
9. Una fuente completa desactualizada se informa como telemetría caída y no genera averías individuales por los sensores que no reportaron.
10. El histórico conserva y expone las definiciones y valores normalizados de sensores, incluso si no son uno de los campos canónicos.
11. Pruebas cubren cálculo puro, política de cobertura, detección de sensor sin valor, puertos de aplicación, scoping tenant, migraciones/adaptadores, eventos idempotentes y vistas móvil/escritorio.
