# Integración de páginas operativas

Estas páginas son adaptadores de presentación. Reciben read models ya autorizados y URLs construidas por CodeIgniter; no consultan la base, no determinan permisos y no recalculan estados de dominio.

## Montaje

`index.js` exporta los seis componentes y el registro `operationPageComponents`:

| `pageType` | Componente |
|---|---|
| `maintenance-overview` | `MaintenanceOverviewPage` |
| `preventive-plans` | `PreventivePlansPage` |
| `equipment-detail` | `EquipmentDetailPage` |
| `assets-index` | `AssetsIndexPage` |
| `imports-index` | `ImportsIndexPage` |
| `imports-show` | `ImportsShowPage` |

Cada raíz recibe una única prop obligatoria `data`. Renderiza un contenedor de contenido para el `<main>` provisto por `ApplicationShell`; el shell sigue siendo propietario del landmark principal, navbar, sidebar, sesión y contexto global.

Todos los formularios usan submit HTML nativo. El backend debe serializar:

```js
csrf: { name: 'csrf_test_name', hash: 'token-actual' }
flash: { success: '', error: '' }
```

Las URLs son valores del payload, no se concatenan en Vue. Los permisos sólo controlan visibilidad; CodeIgniter debe volver a autorizar cada request.

## `preventive-plans`

`GET /mantenimiento/planes` requiere `planes.ver` y `POST /mantenimiento/planes` requiere `planes.editar`. El payload incluye `canEdit`, rutas, filtros, catalogos autorizados y `plans.items` paginados. Cada plan identifica equipo, sucursal, servicio, prioridad y estado; sus criterios activos exponen intervalo, anticipacion, base, proximo y valor actual. Los estados `AL_DIA`, `PROXIMO`, `VENCIDO` y `SIN_DATOS` llegan calculados por el dominio.

## `maintenance-overview`

```js
{
  csrf, flash,
  currentDateTime: 'Y-m-d H:i:s',
  old: { codigo, patente, fecha_alta, anio, chasis, motor, observaciones },
  routes: { equipmentIndex, createEquipment, detectDue },
  can: { createEquipment, registerReading, assignPlan, detectDue, generateOrder, editOrder, closeOrder },
  catalogs: {
    branches: [{ id, code, name }],
    equipmentTypes: [{ id, name }],
    brands: [{ id, name }],
    models: [{ id, name, brandName, typeName }],
    serviceTypes: [{ id, name }],
    users: [{ id, name }]
  },
  equipments: [{
    id, code, plate, typeName, branchName, status,
    controlsKm, controlsHours, currentKm, currentHours,
    routes: { detail, registerReading, assignPlan }
  }],
  plans: [{ id, equipmentCode, serviceName, computedState, nextKm, nextHours, nextDate }],
  notices: [{ id, equipmentCode, serviceName, triggerCriteria, generateOrderUrl }],
  orders: [{
    id, number, equipmentCode, serviceName, ownerName, status, controlsKm, controlsHours, currentKm, currentHours, startUrl, closeUrl,
    tasks: [{ id, description, status }]
  }],
  readings: [{ id, equipmentCode, recordedAt, kilometers, hours, origin, branchName }]
}
```

Los nombres POST se conservan: `codigo`, `patente`, `sucursal_id`, `tipo_equipo_id`, `fecha_alta`, `marca_id`, `modelo_id`, `anio`, `chasis`, `motor`, `observaciones`, `kilometraje`, `horometro`, `fecha_lectura`, `tipo_servicio_id`, intervalos/anticipaciones, `responsable_usuario_id`, `trabajo_realizado`, `fecha_servicio`, `km_salida` y `horas_salida`.

## `assets-index`

```js
{
  csrf, flash, canEdit,
  routes: { index, maintenance, createBrand, createModel },
  filters: { q, typeId, brandId, branchId, status },
  catalogs: {
    types: [{ id, name, active }],
    brands: [{ id, name, active, updateUrl, inactivateUrl }],
    models: [{ id, name, brandName, typeName, active, updateUrl, inactivateUrl }]
  },
  equipment: {
    total,
    items: [{ id, code, typeName, plate, brandName, modelName, year, branchCode, branchName, currentKm, currentHours, status, detailUrl, qrUrl }],
    pagination
  }
}
```

El filtro continúa siendo GET con `q`, `tipo_id`, `marca_id`, `sucursal_id`, `estado`. Las mutaciones de catálogos continúan siendo POST y sólo se muestran con `canEdit`.

## `equipment-detail`

```js
{
  csrf, flash, maxUploadMb,
  can: { edit, correctReadings },
  routes: { index, maintenance, qr, update, transfer, decommission, createRelation, uploadAttachment },
  equipment: {
    id, code, typeName, branchCode, branchName, branchId, status,
    currentKm, currentHours, plate, startDate, endDate,
    brandId, modelId, year, chassis, engine, notes, controlsKm, controlsHours
  },
  catalogs: { brands: [{ id, name }], models: [{ id, name, brandName, typeName }] },
  availableBranches: [{ id, code, name }],
  relatedCandidates: [{ id, code, typeName }],
  relations: {
    total, pagination,
    items: [{ id, principalCode, relatedCode, type, from, to, userName, notes, finishUrl }]
  },
  attachments: {
    total, pagination,
    items: [{ id, originalName, mimeType, sizeKb, type, description, createdAt, createdByName, retiredAt, retirementReason, downloadUrl, retireUrl }]
  },
  readings: null | {
    total, pagination,
    items: [{ id, recordedAt, kilometers, hours, origin, userName, branchId, annulled, annulmentReason, replacementReadingId, correctedReadingId, correctionReason, correctUrl }]
  },
  transfers: {
    total, pagination,
    items: [{ id, date, originCode, originName, destinationCode, destinationName, reason, userName }]
  }
}
```

`readings: null` representa falta de permiso, mientras que `items: []` representa historial vacío. Baja, retiro y corrección conservan sus confirmaciones/motivos y se envían al backend; Vue no decide si la operación es válida.

## `imports-index`

```js
{
  csrf, flash, canUpload, maxSizeMb,
  routes: { upload, templates: { equipment, readings } },
  imports: {
    total, pagination,
    items: [{ id, date, userName, originalFile, type, importedRows, errorRows, duplicateRows, summary, status, detailUrl }]
  }
}
```

La carga conserva `multipart/form-data`, `archivo`, `tipo=EQUIPOS|LECTURAS` y `accept=.csv,.xlsx`.

## `imports-show`

```js
{
  csrf, flash, canMutate,
  routes: { back, confirm, cancel },
  header: { id, originalFile, type, status, summary, totalRows, validRows, errorRows, duplicateRows },
  rows: {
    total, pagination,
    items: [{ rowNumber, status, normalizedData, issues: [{ field, message }], result }]
  }
}
```

Confirmar y cancelar sólo aparecen con `canMutate` y estado `BORRADOR_VALIDADO`. Confirmar queda deshabilitado si `validRows === 0` y mantiene la confirmación explícita antes del submit.

## Paginación

Todas las colecciones paginadas usan:

```js
pagination: {
  page: 1,
  totalPages: 3,
  total: 42,
  previousUrl: null,
  nextUrl: '?page=2'
}
```

El backend debe incluir en cada URL los parámetros de las otras paginaciones que deban conservarse, especialmente en `equipment-detail`.
