# Refactor de multi-tenancy (pendiente)

Estado: **pendiente**, no arrancar sin confirmar.

## Contexto

Oscar (cliente) confirmo el 5 de agosto de 2026 que la app es multi-empresa:

- Cada usuario pertenece a **una empresa** (`usuarios.empresa_id`).
- Cada sucursal pertenece a **una empresa** (`sucursales.empresa_id`).
- El "Administrador" es admin de **su propia empresa** (no superadmin global).
- El admin de una empresa ve **automaticamente todas las sucursales** de su empresa.
- Los roles son **globales** (5 de la spec: Administrador, Responsable de mantenimiento, Tecnico u operador, Solicitante, Consulta).
- Toda tabla de "datos de negocio" lleva `empresa_id NOT NULL`. Las tablas de catalogo (roles, permisos, tipos_equipo, marcas, modelos, tipos_servicio, tareas_mantenimiento) son globales y NO llevan `empresa_id`.

## Que hay que hacer

### 1. Refactor de migraciones existentes

Las migraciones 110005-110007 (las tablas pivote) necesitan `empresa_id`:

- `usuario_roles`: agregar `empresa_id` (la asignacion rol-usuario es por empresa)
- `rol_permisos`: NO necesita `empresa_id` (los permisos son globales)
- `usuario_sucursales`: ya esta bien, los ids implican empresa via JOIN

Pero como Oscar pidio que sea "automaticamente todas las sucursales de su empresa" para el admin, la logica de asignacion de sucursales puede no necesitar `usuario_sucursales` para el admin. Hay que decidir.

### 2. Helper de scoping

Crear un helper en `app/Common.php` (o como clase estatica):

```php
function empresa_id(): ?int {
    return session()->get('empresa_id');
}

function scope_empresa(\CodeIgniter\Database\BaseBuilder $builder): \CodeIgniter\Database\BaseBuilder {
    $id = empresa_id();
    if ($id !== null) {
        $builder->where('empresa_id', $id);
    }
    return $builder;
}
```

Uso en controllers:

```php
$equipos = scope_empresa($this->db->table('equipos'))->get()->getResultArray();
```

### 3. Tests de aislamiento

Test: usuario de empresa A intenta ver/editar datos de empresa B. Debe ser rechazado.

Caso basico: crear dos empresas demo (ademas de la actual), un usuario en cada una, y verificar que:
- El usuario de empresa A no ve empresas, sucursales, usuarios de empresa B
- El usuario de empresa A no puede editar registros de empresa B (404 o 403)

### 4. CRUDs de admin (etapa 1)

Pendiente:
- Listado de empresas (solo admin de su empresa)
- Edicion de empresa
- Listado de sucursales (filtrado por empresa)
- Alta/baja/modificacion de sucursal
- Listado de usuarios (filtrado por empresa)
- Alta/baja/modificacion de usuario
- Asignacion de roles a usuario
- Asignacion de sucursales a usuario (o auto-asignar todas al admin)

### 5. Decisiones que faltan

- Superadmin: cuando se necesite, agregar un flag `is_superadmin` en `usuarios` o un rol extra. Por ahora NO.
- Auditoria: el sistema de auditoria de la spec lleva `empresa_id` para registrar que empresa origino cada operacion.
- Multi-rol por empresa: si un usuario puede pertenecer a dos empresas con roles distintos, hoy no se puede (FK a una sola empresa). Si Oscar lo necesita, se agrega `usuario_rol_empresa` despues.

## Pasos concretos sugeridos

1. Commit con la migracion 110008 que agrega `empresa_id` a `usuario_roles` y un indice
2. Commit con la migracion 110009 que normaliza los datos demo (no hay nada que mover, queda igual)
3. Commit con el helper `scope_empresa()` en `app/Common.php`
4. Commit con el controller `Empresas` (CRUD basico, solo admin)
5. Commit con el controller `Sucursales` (CRUD basico, filtrado)
6. Commit con el controller `Usuarios` (CRUD basico, filtrado)
7. Tests de aislamiento por empresa (PHPUnit)
8. Despues: etapa 2 (equipos), etapa 3 (planes), etc.

## Estimacion

- 1) + 2): 1-2 horas (incluye el refactor de queries existentes, son pocas)
- 3): 30 minutos
- 4) + 5) + 6): 4-6 horas (CRUDs con vistas, validacion, mensajes flash)
- 7): 1-2 horas (escritura + correr)
- Total: 6-10 horas de programacion tranquila

## Pre-requisitos antes de arrancar

- [ ] Oscar confirma si necesita superadmin en algun momento del v1
- [ ] Oscar confirma si los usuarios pueden pertenecer a varias empresas (v1 probablemente no)
- [ ] Validar visualmente que el filtro por empresa funciona antes de avanzar a etapa 2