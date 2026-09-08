# Vencimientos de equipos y empleados

Este módulo registra vencimientos administrativos y operativos sin mezclarlos con los vencimientos de mantenimiento preventivo.

## Alcance

- Equipos/móviles: VTV/ITV, póliza, SENASA, CRVL y otros tipos configurables.
- Empleados/choferes: licencia de conducir y otros tipos configurables.
- Cada tipo pertenece a una empresa, define a qué sujeto aplica, cuántos días antes debe avisarse y si requiere número de documento.
- Cada vencimiento pertenece siempre a una empresa y a un único sujeto: equipo o empleado.
- Los registros retirados quedan conservados fuera del circuito activo.

## Carga manual

Desde la ficha del equipo se pueden registrar, editar y retirar vencimientos del móvil.

Desde **Mantenimiento > Empleados y choferes** se pueden registrar, editar y retirar vencimientos de empleados. En la misma pantalla se administra el catálogo de tipos, incluido el plazo de aviso, si exige documento y su estado activo/inactivo.

## Importación TSA

La pantalla de importaciones acepta:

- **UNIDADES_TRANSPORTE**: XLSX de unidades TSA.
- **VENCIMIENTOS**: XLSX con hojas de unidades y choferes.

En unidades, el código/patente se resuelve contra equipos activos de la misma empresa.

En choferes, el nombre se normaliza y se busca contra empleados activos de la misma empresa. Se aceptan tanto `Nombre Apellido` como `Apellido Nombre`. Si el nombre no identifica exactamente a un empleado, la fila queda con error y no se importa silenciosamente.

La importación siempre pasa por vista previa y confirmación. Los duplicados se omiten por empresa, sujeto, tipo y fecha de vencimiento.

## Estados y avisos

El estado no se guarda: se calcula respecto de la fecha actual y de los días de aviso del tipo.

- **VIGENTE**: fuera de la ventana de aviso.
- **PROXIMO**: dentro de la ventana de aviso.
- **VENCIDO**: la fecha ya pasó.

El ciclo de notificaciones existente emite eventos para vencimientos próximos y vencidos de equipos y empleados. No existe un cron separado para este módulo.

## Seguridad multiempresa

Todas las lecturas, altas, importaciones, tipos y búsquedas están acotadas por `empresa_id`. Las relaciones con equipos y empleados se validan contra la misma empresa antes de persistir.

## Despliegue

Requiere aplicar la migración:

`2026-09-08-181500_CreateExpirationTables.php`

No reemplaza ni modifica las tablas de mantenimiento preventivo.
