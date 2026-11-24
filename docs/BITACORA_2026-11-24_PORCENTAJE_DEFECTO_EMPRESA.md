# Bitácora — Porcentaje de liquidación por defecto de la empresa (2026-11-24)

## Qué se implementó

El **porcentaje de liquidación por defecto de la empresa**: un único valor de
respaldo que se aplica cuando ni la copia del viaje ni el maestro de
porcentajes tienen un valor.

El hueco era estructural: el maestro `viajes_porcentajes_liquidacion_chofer`
se carga por lotes, y un chofer que se da de alta después de la última carga
no tiene fila. El 11/11/2026 entró ANIBAL MELA (emp. 98) y MIGUEL SEVERO
(emp. 95) con el maestro cargado el 04/11: sus viajes quedaron sin comisión.
La corrección de datos de esa día (bitácora
`BITACORA_2026-11-24_PORCENTAJES_LIQUIDACION_CHOFER_TSA.md`) cerró el caso
concreto, pero el mecanismo podía volver a romper en el próximo alta.

### Orden de resolución

```text
copia del viaje  →  fila vigente del maestro  →  porcentaje por defecto de la empresa
```

La regla vive en el dominio (`App\Domain\Trips\DriverLiquidationPercentage`)
y se aplica en un solo lugar: `GetOperationalTrip::financials()`.

### Piezas

| Capa | Pieza |
| --- | --- |
| Dominio | `app/Domain/Trips/DriverLiquidationPercentage.php` |
| Migración | `2026-11-24-160000_CreateDriverLiquidationDefault` → tabla `viajes_porcentaje_liquidacion_defecto` (una fila por empresa) |
| Aplicación | `TripMasterCatalog::saveLiquidationDefault()` con validación de rango (0–100) y permiso `viajes.maestros.editar` |
| Puerto | `TripMasterRepository::liquidationDefault()` / `saveLiquidationDefault()` |
| Infraestructura | `CodeIgniterTripMasterRepository`, `DriverLiquidationDefault` (lectura), read models del viaje y del alta |
| Presentación | Campo «Porcentaje por defecto de la empresa» en Maestros de Viajes + origen del % en el detalle del viaje |

La tabla **arranca vacía**: ninguna empresa cambia de comportamiento sin que
alguien defina su valor a propósito. Si no hay valor, el porcentaje queda sin
resolver y la comisión sigue mostrando "Sin datos" con el aviso de siempre.

## Publicación

- PR **#564** (`feat/viajes-porcentaje-defecto-empresa`) → merge squash en `main`
  con el commit `ff90cc77`.
- CI verde sobre el mismo SHA.
- Spec de viajes actualizada: decisión de diseño 4, glosario y estado de la
  implementación.

## Deploy a producción (Ferozo)

Orquestador `scripts/deploy-ferozo.py --yes`, incremental desde `6eb62d7b`:

```text
PHPUNIT=RUNNING          (2160 tests, 0 fallos)
FRONTEND_BUILD=RUNNING
UPLOAD=OK
MIGRATION_STATUS_HTTP=200
MIGRATIONS=OK            (2026-11-24-160000_CreateDriverLiquidationDefault)
MIGRATE_PHP_REMOVED=OK
HTTP login           200
HTTP favicon.ico     200
HTTP .env            403
HTTP app/            403
HTTP vendor/         403
HTTP writable/       403
HTTP migrate.php     404
SMOKE_TESTS=OK
DEPLOY_STATUS=OK
```

## Carga del 14% de TSA

Helper `scripts/operacion-2026-11-24-porcentaje-defecto-empresa.php` (subido
por FTPS a la raíz del release, llamado con `X-Migrate-Token` y borrado al
terminar), con dry-run obligatorio:

```text
DRY-RUN: valor_actual=null, cambio=ALTA,
         choferes_sin_fila_en_maestro=0, viajes_sin_porcentaje_copiado=0
APLICA : maestro_id=2, porcentaje=14.00
```

Verificación posterior (sonda de solo lectura): `valor_actual=14`,
`cambio=SIN_CAMBIO`.

## Verificación en producción (sonda de solo lectura)

- Viajes con chofer: **42 de 42** con porcentaje resuelto.
- Maestro de porcentajes: **27 filas**.
- Choferes reales sin fila en el maestro: **0**.
- Viaje 4 (OIU270): % 14 (origen `VIAJE`), tramo 1 comisión **424.200** /
  margen **2.605.800**; tramo 2 comisión **289.100** / margen **1.740.900**.

## Lo que NO se pudo verificar

**Staging (`fasa_189`) no se pudo desplegar ni verificar en esta
intervención:** el host `192.168.0.189:22` no respondía desde la máquina de
desarrollo (la VPN a esa LAN no estaba activa), y el procedimiento de staging
de `docs/DEPLOY_COOLIFY.md` exige SSH al host para cambiar la referencia de la
aplicación en Coolify y disparar el rebuild.

Como no se pudo correr el smoke de staging, el deploy a producción se apoyó
en la evidencia automatizada: suite PHPUnit completa en verde (2160 tests),
Vitest en verde (544 tests), `verify:build-sync` OK y CI verde sobre el mismo
SHA. **Queda pendiente repetir la verificación en staging** cuando el host
vuelva a estar accesible.

## Pendientes que no son de este cambio

1. **22 tramos en 18 viajes (todos `EN_CURSO`) sin flete de liquidación.** La
   comisión se calcula sobre `flete_liquidacion_ars`, así que sin ese dato el
   % no alcanza. Los carga la operación en la app: la app nombra los tramos y
   bloquea el cierre hasta que estén completos. No se completaron por dato
   porque en los 40 tramos con ambos fletes la liquidación coincide con la
   facturación en solo 7 (17,5%).
2. **Los 3 gastos del viaje 4 (71.000 ARS)** siguen sin tramo asignado:
   aparecen en "Costos sin asignar" y no se descuentan del margen. Imputar un
   gasto a un tramo es decisión operativa.
