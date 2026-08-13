# Refactor de multi-tenancy (resuelto)

Estado: **implementado y cubierto por pruebas de aislamiento**.
Un usuario común pertenece a una sola empresa; el Superadministrador es global.

Este documento reemplaza las decisiones históricas del issue #1. En particular,
no se agrega `empresa_id` a `usuario_roles` ni se utiliza un helper global basado
únicamente en la sesión: el alcance se expresa mediante `ActorContext` y se
aplica dentro de cada puerto/adaptador.

## Contexto

Decisiones vigentes, incluida la aclaración del 7 de agosto de 2026:

- Debe existir un **Superadministrador global**.
- El Superadministrador puede gestionar empresas y asignar usuarios a empresas.
- El "Administrador" es administrador de **su empresa**, no un
  superadministrador global.
- El Administrador ve **automáticamente todas las sucursales de su empresa**.
- Cada sucursal pertenece a **una empresa** (`sucursales.empresa_id`).
- Cada usuario no global pertenece a **una sola empresa**. El Superadministrador
  puede asignarlo o trasladarlo a otra empresa.
- Los roles son **globales** (5 de la spec: Administrador, Responsable de mantenimiento, Tecnico u operador, Solicitante, Consulta).
- Toda tabla de datos de negocio lleva `empresa_id NOT NULL` cuando corresponde al tenant.
- Roles, permisos, tipos de equipo, tipos de servicio y tareas son catálogos globales.
- Marcas y modelos son catálogos propios de cada empresa, como expresa el esquema ejecutable actual y la gestión tenant de activos.

> **Nota vigente (agosto 2026):** `tareas_mantenimiento` y su relación
> `tipo_servicio_tareas` son catálogos globales: una edición desde la Biblioteca
> preventiva afecta a todas las empresas que referencian esa tarea. No se
> introduce `empresa_id` en estas tablas en esta iteración; sigue pendiente el
> refactor multiempresa del catálogo. La edición se autoriza por
> `importaciones.cargar` y valida el alcance contra las plantillas de la empresa
> actual, pero el catálogo compartido es un límite conocido a documentar.

## Decisiones implementadas

### 1. Esquema incremental

El esquema confirmado es:

- `usuarios.empresa_id` identifica la única empresa de una cuenta común y es
  `NULL` exclusivamente para una cuenta global.
- `usuarios.es_superadmin` distingue de forma explícita la capacidad global.
- No se crea `usuario_empresas`: la relación usuario-empresa no es muchos a
  muchos en esta versión.
- `usuario_roles` no necesita duplicar `empresa_id`, porque los roles son
  globales y el usuario solo tiene una empresa. Un traslado conserva o reajusta
  sus roles mediante el caso de uso correspondiente y debe quedar auditado.
- `rol_permisos` no lleva `empresa_id`: los roles y permisos son catálogos
  globales.
- El Superadministrador es una capacidad global de la cuenta, separada de los
  roles de empresa. No debe simularse asignándole el rol Administrador en todas
  las empresas.
- `usuario_sucursales` aplica a usuarios restringidos. El Administrador obtiene
  automáticamente todas las sucursales activas de la empresa seleccionada.

### 2. Contexto de actor y scoping

No alcanza con un helper que lea un único `empresa_id` de sesión: el
Superadministrador tiene alcance global y su `empresa_id` es `NULL`.

Crear un `ActorContext` de aplicación con:

- identidad del usuario;
- capacidad global de Superadministrador;
- empresa del usuario, cuando no es global;
- sucursales autorizadas dentro de esa empresa;
- roles y permisos efectivos.

Los repositorios reciben ese contexto o un scope derivado y filtran desde la
consulta. Nunca se hace `find($id)` sin scope para comparar después.

### 3. Tests de aislamiento

Test: usuario o Administrador de empresa A intenta ver/editar datos de empresa B. Debe ser rechazado.

Caso basico: crear dos empresas demo (ademas de la actual), un usuario en cada una, y verificar que:
- El usuario de empresa A no ve empresas, sucursales, usuarios de empresa B
- El usuario de empresa A no puede editar registros de empresa B (404 o 403)
- El Administrador de A ve automáticamente todas las sucursales activas de A,
  pero ninguna de B.
- El Superadministrador puede listar empresas y gestionar sus asignaciones sin
  heredar permisos empresariales implícitos para operaciones de mantenimiento.

### 4. CRUDs de administración (etapa 1)

Estado del alcance:
- [x] Listado, alta y edición de empresas (Superadministrador)
- [x] Asignación de usuarios a empresas (Superadministrador)
- [x] Asignación de roles a usuario (Superadministrador)
- [x] Revocación segura y auditoría al trasladar usuarios
- [x] Listado de sucursales (filtrado por empresa)
- [x] Alta/baja/modificación de sucursal
- [x] Listado de usuarios (filtrado por empresa)
- [x] Alta/baja/modificación de usuario
- [x] Asignación de sucursales a usuario (o auto-asignar todas al admin)

### 5. Decisiones operativas posteriores

- Auditoría: el Superadministrador registra `empresa_id` cuando actúa sobre una
  empresa y `NULL` solo para operaciones verdaderamente globales.
- [x] Al trasladar un usuario se revocan sus roles y sucursales anteriores y se
  exige una nueva asignación explícita.

## Pasos concretos sugeridos

1. [x] Agregar migración correctiva incremental para `es_superadmin`; no editar las
   ocho ya aplicadas.
2. [x] Implementar `ActorContext` y autorización global/empresarial.
3. [x] Adaptar login, roles, permisos y sucursales.
4. [x] Implementar gestión de empresas y asignaciones para Superadministrador.
5. [x] Implementar administración de sucursales y usuarios con scope.
6. [x] Completar pruebas de aislamiento sobre usuarios y sucursales tenant.
7. [x] Continuar con el circuito vertical de equipos, lecturas, planes y órdenes.

## Estimacion

La relación confirmada evita un selector de empresa activa y roles por
membresía. La gestión global y las pruebas de aislamiento siguen siendo parte
obligatoria del incremento.

## Pre-requisitos antes de arrancar

- [x] Se confirma Superadministrador global en v1
- [x] Cada usuario común pertenece a una sola empresa
- [x] El Administrador ve todas las sucursales de su empresa
- [x] Completar aceptación visual manual en escritorio y móvil de la administración multiempresa.

## Cierre del issue histórico

El issue #1 quedó superado por decisiones posteriores confirmadas con el usuario:

- existe Superadministrador global;
- un usuario común pertenece a una sola empresa;
- el Administrador accede automáticamente a las sucursales activas de su empresa;
- los traslados de usuario revocan accesos anteriores y quedan auditados;
- las consultas sensibles aplican empresa y sucursal desde `ActorContext`.

Por lo tanto, sus tareas originales de agregar `empresa_id` a `usuario_roles` y
crear `scope_empresa()` no deben ejecutarse: introducirían duplicación y no
representan el modelo vigente.
