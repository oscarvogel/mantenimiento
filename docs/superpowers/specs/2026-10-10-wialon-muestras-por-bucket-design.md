# Histórico Wialon por buckets de cinco minutos — Diseño

**Estado:** Aprobado por el usuario el 10 de octubre de 2026
**Contexto:** Adaptador de telemetría Wialon del monolito CI4
**Fecha:** 10 de octubre de 2026

## Objetivo

Conservar la resolución de cinco minutos que necesita consumo y uso operativo sin guardar una fila por cada mensaje que Wialon recibe aproximadamente cada dos minutos. Cada bucket cerrado guarda una síntesis temporal suficiente para reconstruir la serie y deja el cálculo de consumo en el dominio.

## Estado verificado

- `WialonRemoteApiGateway` obtiene la última posición y valores actuales con `core/search_items` y `unit/calc_last`, y el histórico por unidad con `messages/load_interval`.
- `RecordTelemetrySnapshots` mantiene la última lectura para mapa/ficha y sincroniza el histórico Wialon por buckets cuando la ruta está conectada. Otros proveedores conservan la ingesta puntual.
- El ciclo HTTP/cron operativo confirmado por el usuario corre cada cinco minutos; su guía de Ferozo documenta las doce ejecuciones horarias.
- Los buckets cerrados se guardan en `telematia_buckets` y el avance confirmado por unidad en `telematia_cursor`. Wialon deja de añadir puntos nuevos a `telematia_muestras` solo cuando el sincronizador histórico está habilitado; filas previas se conservan.
- El histórico conserva 90 días. La purga requiere JSONL completo y verificado antes de borrar; el comando está disponible en entornos con CLI, no en Ferozo de producción.
- La rama de dominio de consumo es `feat/consumo-atribucion` (PR #476). Este trabajo parte de ella para poder adaptar el lector sin mezclar commits en ese PR.

## Volumen con la frecuencia confirmada

Para 15 unidades en operación continua:

| Captura | Filas por unidad/día | Filas totales/día |
|---|---:|---:|
| Cron actual cada 5 min | 288 | **4.320** |
| Un registro por mensaje cada 2 min | 720 | **10.800** |
| Un registro por bucket de 5 min | 288 | **4.320** |

Un bucket de cinco minutos conserva la cantidad actual de filas y reduce un **60 %** el volumen frente a persistir todos los mensajes. La cifra previa de 21.600 para mensajes cada dos minutos duplicaba el conteo; la resolución de cinco minutos coincide con el cron real, no con la descripción antigua de quince minutos.

Estas son proyecciones para una unidad que reporta sin interrupciones. Los buckets sin mensajes no generan filas; una ausencia se conserva como hueco temporal, no como dato inventado.

## Decisiones propuestas

### Lectura de Wialon

- Mantener `unit/calc_last` para actualizar la instantánea actual del mapa y las fichas.
- Agregar al adaptador Wialon una lectura histórica por unidad mediante `messages/load_interval`; usar solo unidades vinculadas y activas.
- Solicitar mensajes de datos y valores de sensores calculados. Normalizar en el anti-corruption layer existente posición, odómetro, horómetro, combustible, motor y ralentí cuando el mensaje realmente los proporcione.
- No copiar valores desde `calc_last` hacia mensajes históricos. Una métrica ausente en un mensaje permanece ausente.
- Procesar la ventana desde el cursor confirmado de esa unidad hasta el último límite de cinco minutos ya cerrado. La API entrega mensajes ordenados desde el más antiguo y reporta `count` aunque `loadCount` limite la carga; si se alcanza el límite del proveedor, dividir la ventana y completar cada parte antes de persistirla. Una respuesta parcial o un error no se interpreta como una ventana vacía.
- Agrupar los mensajes por intervalos UTC semiabiertos `[inicio, fin)`, con límites alineados a múltiplos de 300 segundos. La zona horaria de presentación no cambia la identidad del bucket.

La API oficial permite solicitar el período con `timeFrom` y `timeTo`, devuelve `count` y `messages`, y documenta el error 1004 cuando se alcanza el límite de mensajes. El formato permite pedir valores de sensores calculados en mensajes de datos; estos se calculan solo para mensajes de datos.

### Contenido del bucket

Cada bucket no vacío registra una sola fila con:

- identidad: empresa, integración, equipo, unidad externa y proveedor;
- inicio y fin UTC del bucket, y primer y último instante observado dentro de él;
- combustible al inicio y al fin;
- kilometraje y horómetro al inicio y al fin;
- estado de motor y ralentí en los extremos;
- duración de ralentí computable dentro del bucket, o `null` si faltan datos válidos;
- los datos de posición/velocidad extremos que el lector actual necesite, sin conservar el cuerpo crudo de cada mensaje.

Los campos de límites se toman del primer y último mensaje válido, no del valor máximo ni del último valor consultado en el presente. Si una métrica falta en uno de los extremos, ese extremo queda `null`; no se interpola. El tiempo de ralentí se calcula solo entre mensajes consecutivos válidos, se limita al fin del bucket y no cruza huecos mayores a 30 minutos.

### Cierre, duplicados y mensajes tardíos

- Solo se finalizan buckets para los que ya se recibió al menos un mensaje válido con timestamp igual o posterior al fin del bucket. Ese mensaje siguiente permite cerrar el intervalo que cruza el límite y repartir su ralentí sin inventar duración. Si no llega, el bucket pendiente permanece sin persistir hasta que Wialon entregue ese mensaje; el cálculo no extiende el último estado indefinidamente.
- La persistencia es append-only. La clave única será `(integracion_id, unidad_externa, bucket_inicio_utc)` y el insert idempotente usará `INSERT IGNORE`.
- Una transacción guarda todos los buckets completos de una ventana y avanza el cursor de la unidad. Si falla la lectura, la división por límite, o la escritura, el cursor no avanza y la ventana se puede reintentar.
- Un mensaje posterior cuyo timestamp pertenezca a un bucket ya cerrado no modifica ese bucket. La escritura idempotente devuelve los buckets nuevos y los ya existentes que ignoró. Este segundo conteo incluye tanto relecturas esperadas del solape como buckets que puedan contener mensajes tardíos; con extremos agregados no se pueden distinguir de forma confiable sin guardar identidad o huella de cada mensaje. Nunca se sobrescribe silenciosamente un resultado publicado.
- La ventana consultada puede solaparse con el último bucket para tolerar reintentos. La clave única elimina las repeticiones sin consulta previa.

### Persistencia y compatibilidad

La tabla vigente representa puntos y no puede guardar el contenido anterior de cada bucket. Se agregan dos tablas mediante migración aditiva y reversible: `telematia_buckets` (una fila por unidad/bucket) y `telematia_cursor` (un cursor por integración/unidad). Esta separación mantiene el contrato de las muestras existentes y da identidad explícita al bucket.

La ingesta de buckets sustituye el guardado puntual Wialon en el histórico después del corte; `telematia_ultima_lectura` sigue actualizándose. Si aparece otro proveedor sin lectura histórica agregada, puede seguir usando `telematia_muestras`. El despliegue preserva las filas históricas previas, permite que la retención las purgue con respaldo verificado y evita guardar permanentemente tanto el punto del cron Wialon como el bucket del mismo período.

El lector de serie adapta cada bucket a sus extremos ordenados para `SerieMuestrasReader`; `IntervaloConsumo`, segmentación, cobertura e indicadores no conocen Wialon ni la forma de la tabla. Los datos agregados de ralentí se exponen por un read model apropiado, sin alterar la fórmula pura de consumo.

### Alcance de tamaño y concurrencia

- El intervalo de cinco minutos es una política de ingesta configurable, no parte de la clave lógica fuera de `bucket_inicio_utc`.
- El procesamiento puede ejecutarse en lotes de varias unidades; cambiar el tamaño del lote no modifica la tabla ni las fórmulas.
- No se persisten mensajes crudos. La agregación ocurre en memoria y conserva únicamente el resumen por bucket.
- El refresco actual y la lectura histórica deben compartir la sesión de Wialon de forma secuencial; no se ejecutan peticiones incompatibles en paralelo dentro de la misma sesión.

## Fuera de alcance

- Cambiar la frecuencia real del cron, que ya está confirmada en cinco minutos.
- Persistir cada mensaje o almacenar respuestas crudas de Wialon.
- Cambiar las reglas de consumo, cobertura o segmentación del dominio.
- Corregir automáticamente mensajes tardíos de buckets publicados.
- Consultar o modificar staging o producción durante este diseño.

## Criterios de aceptación del diseño

1. Con 15 unidades y flujo continuo se conserva una fila por bucket de cinco minutos: 4.320/día.
2. El mapa y la ficha siguen usando la última instantánea de `unit/calc_last`.
3. Una falla o respuesta truncada de Wialon no avanza el cursor ni deja una serie presentada como completa.
4. Repetir una ventana no crea buckets duplicados.
5. Una repetición o mensaje tardío para un bucket cerrado no modifica sus valores y queda contado como escritura de bucket ya existente; el contador incluye el solape normal y no pretende identificar mensajes tardíos individualmente.
6. Cada fila contiene ambos extremos válidos y la duración de ralentí calculable; faltantes permanecen nulos.
7. La serie entregada al dominio está ordenada y produce el mismo cálculo con los mismos extremos, sin conocer el adaptador ni la base.
8. Las migraciones conservan historia previa, tienen `down()` seguro cuando sea viable y la retención mantiene respaldo antes de purgar tanto muestras como buckets vencidos.

## Fuentes

- Implementación verificada: `app/Infrastructure/Telematic/WialonRemoteApiGateway.php`, `app/Application/Telematic/RecordTelemetrySnapshots.php`, `app/Application/Telematic/SincronizarBucketsTelemetria.php`, `app/Infrastructure/Telematic/CodeIgniterTelemetryBucketStore.php` y migración `2026-10-10-170000_CreateTelemetryBuckets.php`.
- Frecuencia del ciclo: confirmación del usuario; esta guía actualiza las referencias operativas previas de quince minutos.
- Wialon API: [messages/load_interval](https://help.wialon.com/en/api/user-guide/api-reference/messages/load_interval) y [formato de mensajes](https://help.wialon.com/en/api/user-guide/data-format/messages).
