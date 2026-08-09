# FASE 1C - Administración de sucursales y usuarios

Fecha: **8 de agosto de 2026**.

Estado: **implementada y validada localmente**.

## Alcance

Este incremento completa la administración básica dentro de una empresa:

- listado, alta, edición e inactivación de sucursales;
- listado, alta, edición e inactivación de usuarios;
- restablecimiento administrativo de contraseña;
- asignación conjunta de roles y sucursales;
- revocación de sesiones cuando una cuenta deja de estar activa.

El Superadministrador continúa usando `/superadmin`. Las pantallas tenant viven
en `/administracion/sucursales` y `/administracion/usuarios` y requieren una
cuenta común con los permisos correspondientes.

## Invariantes

- El `empresa_id` siempre proviene de `ActorContext`; ningún formulario puede
  elegirlo o modificarlo.
- Las consultas de usuarios y sucursales incluyen el scope de empresa desde el
  inicio.
- Un usuario común pertenece a una sola empresa.
- Un Administrador accede automáticamente a todas las sucursales activas de su
  empresa y no necesita filas en `usuario_sucursales`.
- Un usuario que no es Administrador debe conservar al menos una sucursal
  activa.
- Rol y sucursales se reemplazan dentro de la misma transacción.
- No se puede modificar un usuario o una sucursal de otra empresa.
- Un Administrador no puede desactivar su propia cuenta ni modificar sus
  propios roles y sucursales desde estas pantallas.
- Una sucursal no puede inactivarse si deja a un usuario activo sin alcance.
- Las contraseñas no se almacenan ni registran en auditoría.

## Arquitectura

`TenantAdministrationService` contiene los casos de uso y valida el
`ActorContext`. Define sus necesidades mediante `TenantAdministrationPort`.
`CodeIgniterTenantAdministration` implementa el puerto con Query Builder,
consultas parametrizadas y transacciones MariaDB. Los controladores solamente
traducen formularios y respuestas.

La migración `110011` generaliza el actor de
`usuario_acceso_historial`: tanto el Superadministrador como el Administrador de
empresa pueden quedar registrados sin confundir sus capacidades.

## Evidencia automatizada

- Casos de uso con puerto falso: scope, permisos, normalización y protección de
  autoservicio sensible.
- E2E tenant con MariaDB temporal (`scripts/run-tenant-admin-e2e.ps1`):
  aislamiento entre dos empresas, CRUD, acceso atómico, auditoría, contraseña,
  inactivación segura y revocación de sesión.
- E2E global (`scripts/run-organization-e2e.ps1`): continúa aprobado después de
  la migración de auditoría y del cambio de dashboard.
- Migraciones completas desde una base vacía.

## Próximo incremento

Con la base organizativa lista, el próximo desarrollo es el circuito vertical
del corazón del sistema: equipo, lectura, plan preventivo, vencimiento, orden,
cierre y recálculo.
