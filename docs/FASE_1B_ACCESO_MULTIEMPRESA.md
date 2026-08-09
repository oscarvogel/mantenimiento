# FASE 1B - Acceso multiempresa y Superadministrador

Fecha: **7 de agosto de 2026**.

Estado del incremento: **implementado y validado localmente**.

## Decisiones confirmadas

- Existe un Superadministrador con alcance global.
- Cada usuario común pertenece a una sola empresa.
- El Superadministrador puede asignar o trasladar usuarios entre empresas.
- El Administrador no es global: administra su empresa.
- El Administrador ve automáticamente todas las sucursales activas de su
  empresa; otros usuarios solo ven las sucursales asignadas.
- El Superadministrador no hereda permisos de mantenimiento. Su capacidad
  global se comprueba de forma separada de los roles empresariales.

## Implementación

### Persistencia

Las migraciones incrementales agregan `usuarios.es_superadmin` y lo dejan como
`NOT NULL DEFAULT 0`. No se modificaron las ocho migraciones ya existentes.

La migración `110010` agrega `usuario_acceso_historial` para dejar trazabilidad
del cambio de empresa y de roles, incluyendo el valor anterior, el nuevo, el
motivo y el Superadministrador responsable.

Reglas de aplicación:

- Superadministrador: `es_superadmin = 1` y `empresa_id IS NULL`.
- Usuario común: `es_superadmin = 0` y `empresa_id` obligatorio.
- No existe tabla `usuario_empresas` porque la relación confirmada no es muchos
  a muchos.

### Arquitectura

`ActorContext` vive en Application y no depende de CodeIgniter. Expresa:

- usuario autenticado;
- empresa o alcance global;
- roles y permisos;
- acceso a todas las sucursales de la empresa o a una lista asignada.

Los adaptadores de Infrastructure reconstruyen el contexto desde MariaDB y lo
guardan en la sesión. Los futuros casos de uso recibirán este contexto y no
leerán directamente campos enviados por HTTP.

### Seguridad

- La sesión se regenera inmediatamente después de autenticar.
- El login limita intentos por combinación de email e IP. Los valores
  `auth.maxLoginAttempts` y `auth.lockoutMinutes` son configurables y una
  respuesta bloqueada usa HTTP `429` con `Retry-After`.
- El filtro de autenticación vuelve a comprobar que el usuario siga activo y
  tenga un alcance consistente.
- Existen filtros separados para permiso empresarial y Superadministrador.
- Logout usa `POST` y CSRF; `GET /logout` no existe.
- El Administrador obtiene todas las sucursales activas de su empresa desde la
  base, no solamente las filas del pivot.
- El Superadministrador no recibe automáticamente `ordenes.cerrar`,
  `equipos.editar` ni otros permisos empresariales.
- Una empresa con usuarios comunes activos no puede ser inactivada.
- Si un usuario cambia de empresa, se revocan sus roles y sucursales anteriores;
  el Superadministrador debe asignarle explícitamente sus nuevos roles.

### Gestión global

El panel `/superadmin` permite al Superadministrador:

- listar, crear y editar empresas;
- asignar o trasladar un usuario común a una empresa;
- asignar roles empresariales;
- registrar un motivo obligatorio para cada cambio de acceso.

El filtro global relee el actor desde la base en cada solicitud. Un cambio de
estado o alcance invalida inmediatamente una sesión que ya no sea consistente.

## Seeder local

Después de migrar una base existente, crear o normalizar la cuenta global con:

```powershell
php spark migrate
php spark db:seed SuperAdminSeeder
```

Valores predeterminados solo para desarrollo:

```text
superadmin@mantenimiento.local / SuperAdmin1234
```

Pueden sustituirse para una ejecución del seeder mediante:

```text
seed.superadmin.email
seed.superadmin.password
```

Estas credenciales demo no deben usarse en producción. El encargado deberá
establecer credenciales únicas antes de ejecutar el seeder en Ferozo.

## Evidencia local

- Migraciones `110008`, `110009` y `110010`: aplicadas correctamente en MariaDB.
- `es_superadmin`: `NOT NULL`, valor por defecto `0`.
- Seeder ejecutado dos veces sin duplicar la cuenta global.
- Login Administrador: empresa `1`, alcance no global.
- Login Superadministrador: empresa nula, alcance global.
- Regeneración de ID de sesión: verificada para ambos perfiles.
- Sexto intento consecutivo con los valores predeterminados: HTTP 429 y
  `Retry-After` presente.
- `GET /logout`: 404.
- `POST /logout` con CSRF: redirige a login y destruye la sesión.
- PHPUnit: pruebas de invariantes, permisos y serialización del contexto.
- E2E aislado (`scripts/run-organization-e2e.ps1`): base temporal desde cero,
  alta y edición de empresa, traslado con revocación de accesos, reasignación de
  rol, auditoría, bloqueo de inactivación y alcance automático del Administrador.

## Próximo incremento

Completar la administración empresarial con alta, edición e inactivación de
sucursales y usuarios, siempre con scope por empresa. Después se puede comenzar
el primer circuito vertical: equipo, lectura, mantenimiento, vencimiento, orden,
cierre y recálculo del próximo servicio.

Las consultas futuras deben resolver cada recurso con `empresa_id` desde el
inicio. Nunca deben usar `find($id)` sin scope para comparar la empresa después.
