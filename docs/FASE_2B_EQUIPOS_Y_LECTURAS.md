# Fase 2B — Consolidación de equipos y lecturas

## Resultado

Esta fase convierte el alta mínima del circuito preventivo en una ficha de
equipo navegable y protegida. Desde `GET /mantenimiento/equipos/{id}` se puede:

- consultar perfil, estado, sucursal y valores actuales;
- editar código, patente y observaciones;
- trasladar el equipo entre sucursales autorizadas, conservando origen,
  destino, fecha, autor y motivo;
- darlo de baja de forma lógica sin eliminar su historia;
- consultar lecturas válidas, anuladas y correctivas;
- corregir una lectura mediante un reemplazo vinculado, con autor, fecha y
  motivo, sin sobrescribir el registro original.

Lecturas y traslados se paginan del lado del servidor. La autorización de la
ficha se decide por empresa y sucursal actual del equipo; una vez autorizado,
el historial abarca todas las sucursales anteriores del mismo equipo.

## Reglas aplicadas

- Toda consulta o mutación comienza con `empresa_id` y scope de sucursal.
- Un traslado exige destino activo, de la misma empresa y autorizado.
- Un equipo dado de baja no admite edición, traslado ni lecturas nuevas.
- La baja se rechaza mientras exista una orden de trabajo abierta.
- Una lectura corregida queda anulada, pero nunca se elimina.
- La corrección conserva la fecha efectiva de la lectura original.
- El kilometraje y horómetro actuales se recalculan desde la última lectura
  válida de toda la vida del equipo, incluso si hubo traslados.
- Las mutaciones de Activos y Medición se ejecutan dentro de sus respectivas
  transacciones de aplicación.

## Arquitectura

Se mantuvieron los bounded contexts definidos en `AGENTS.md`:

- `Assets`: entidad `Equipment`, casos de uso de edición, traslado, baja y
  consulta; puertos para persistencia, scope, órdenes abiertas y read model.
- `Measurement`: historial paginado y caso de uso de corrección; puertos de
  lectura, persistencia y unidad de trabajo.
- `Presentation`: `EquipmentManagement` traduce HTTP y llama casos de uso; no
  usa Query Builder ni repositorios directamente.
- `Infrastructure`: adaptadores CodeIgniter implementan los puertos con la
  misma conexión cuando una operación es transaccional.

## Esquema

La migración `110050_CreateEquipmentBranchHistoryTable` agrega
`equipo_sucursal_historial`. La migración previa `110022` ya contenía el vínculo
`lectura_corregida_id` y los campos de anulación, por lo que no se modificó una
migración ya aplicada.

`InitialSeeder` quedó idempotente y resuelve roles/permisos por sus claves
naturales. Esto evita colisiones de IDs cuando `110012` crea permisos antes del
primer seed en una instalación limpia.

## Pruebas locales

Suite completa:

```powershell
php -d extension=sqlite3 vendor\bin\phpunit --no-coverage
```

Aceptación E2E descartable sobre MariaDB:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-phase2b-e2e.ps1
```

El script crea un checkout, `.env` y base temporal, y siempre los elimina. No
usa la base principal. Valida migración y seed desde cero, login, edición y
no-op, traslado, historial anterior al traslado, corrección y recálculo,
aislamiento entre empresas, CSRF 403, bloqueo de baja con OT abierta, baja
lógica y rechazo de lectura posterior.

Evidencia del 2026-08-08:

- PHPUnit: 112 pruebas, 282 aserciones, sin fallos.
- E2E MariaDB temporal: aprobado.
- Instalación limpia y seed: aprobados.
- Base local principal: migración `110050` aplicada en batch 7.
- HTTP local autenticado: ficha del equipo respondió 200 y renderizó edición,
  historiales, corrección y baja.
- La automatización visual de navegador no estuvo disponible; la aceptación
  visual manual en escritorio y móvil sigue pendiente.

No se realizó commit, push ni despliegue en Ferozo.

## Pendientes de Etapa 2

- marca, modelo y datos técnicos completos;
- adjuntos privados y fotografías;
- relaciones temporales tractor-acoplado;
- QR imprimible y acceso móvil asociado;
- importación CSV/Excel con vista previa y errores por fila;
- decisión funcional sobre trasladar un equipo que tiene una OT abierta;
- auditoría transversal explícita de edición y baja, más allá de `updated_by`.
