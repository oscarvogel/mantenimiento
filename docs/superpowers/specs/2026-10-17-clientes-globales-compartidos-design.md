# Clientes globales compartidos — Diseño

**Fecha:** 2026-10-17  
**Estado:** Propuesto para revisión del usuario  
**Contextos:** Importaciones, Viajes y Arquitectura/Integración

## Problema

El importador guardó 356 clientes de TSA en `clientes`, pero el módulo Viajes continúa consultando `viajes_clientes`. En staging, ambas tablas están en la base `mantenimiento_staging`; el lote 1 figura confirmado para la empresa TSA (empresa_id 4), hay 356 filas en `clientes` y Viajes muestra 1 fila en `viajes_clientes`. El cliente de Viajes no coincide por CUIT ni por nombre exacto con el padrón importado, y tiene un domicilio guardado.

La duplicación impide que los módulos compartan el mismo cliente de una empresa y puede producir datos divergentes.

## Decisión de dominio

`clientes` es el registro canónico de clientes, propiedad de una empresa y compartido por todas sus sucursales. Ningún cliente pertenece exclusivamente a una sucursal ni a un módulo. El importador continúa creando y deduplicando en `clientes` por `(empresa_id, origen, codigo_origen)`.

Viajes consulta y modifica los mismos registros canónicos. Los domicilios operativos de Viajes continúan en `viajes_domicilios_clientes`, porque sus alias y datos de entrega pertenecen al módulo; su `cliente_id` pasa a referenciar `clientes.id` con el mismo `empresa_id`.

## Migración de datos

Una migración reproducible copiará cada registro existente de `viajes_clientes` a `clientes`, identificándolo con un origen reservado (`viajes_legacy`) y el ID anterior como código de origen. No fusionará ni reutilizará registros por CUIT o nombre: así cada domicilio conserva una conversión reversible y no se mezclan historiales por una coincidencia. La comprobación en staging no encontró coincidencias exactas de CUIT ni de nombre entre el cliente legado y el padrón importado.

La migración remapea cada domicilio existente al nuevo ID canónico y comprueba que todas las relaciones tengan destino. Solo después retira la tabla `viajes_clientes`. `down()` podrá reconstruir esa tabla y devolver sus domicilios al ID legado.

La información general del padrón permanece en `clientes` y en el lote de importación. Los domicilios de Viajes conservan sus valores y su historial. Los campos de dirección, provincia y localidad originales del CSV se mantienen en `clientes` y en las equivalencias geográficas; no se crean domicilios operativos ni se deduce el país a partir del país fiscal.

## Aplicación

- `TripMasterCatalog` y su repositorio listan, crean y actualizan clientes canónicos respetando `empresa_id`.
- La pantalla de Viajes muestra los campos comunes del cliente y conserva la sección de domicilios operativos vinculada al mismo ID global.
- El importador conserva vista previa, confirmación, claves de origen, auditoría e idempotencia; su resultado cuenta registros insertados en el padrón común.
- Las demás sucursales de la empresa consultan los mismos clientes. Otras empresas mantienen padrones separados por `empresa_id`.
- No se conserva una tabla paralela con copias de los datos del cliente.

## Seguridad y límites

La migración de datos se prueba primero en el Docker/Coolify de staging `fasa_189`, base `mantenimiento_staging`, preservando volúmenes. El `DROP` de `viajes_clientes` solo ocurre después del remapeo y la verificación de domicilios. Producción Ferozo requiere un procedimiento separado por FTPS y herramientas de base disponibles allí; esta decisión no autoriza ejecutarlo.

No se importan viajes, no se asignan clientes a sucursales individualmente, no se fusionan por coincidencia aproximada y no se normalizan domicilios sin evidencia de país/localidad.

## Verificación de aceptación

1. Confirmar que los 356 clientes de TSA aparecen en Viajes tras actualizar su consulta al registro canónico.
2. Confirmar que el cliente legado de Viajes y su domicilio siguen disponibles tras el remapeo.
3. Confirmar que reimportar el mismo origen/código omite esos clientes y no duplica filas.
4. Confirmar que una segunda empresa no puede ver ni modificar los clientes de TSA.
5. Confirmar que un cliente aparece para las sucursales de la misma empresa.
6. Confirmar que el rollback de la migración reconstruye `viajes_clientes` y conserva los domicilios.
