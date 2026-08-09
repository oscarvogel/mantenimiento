# Contrato de integración de administración

Los componentes de esta carpeta son adaptadores de entrada Vue. No toman decisiones de autorización, empresa, rol o sucursal. El servidor debe entregar sólo datos ya acotados por el `ActorContext` y exponer los booleanos de permisos que controlan la presentación.

El integrador selecciona el componente mediante `adminPagesByType[pageType]`, lo envuelve en `ApplicationShell` y le pasa una única prop serializable:

```vue
<component :is="adminPagesByType[pageType]" :data="pageData" />
```

Todos los formularios son `POST` nativos. Las URLs y el token CSRF deben ser producidos por CodeIgniter. Ningún componente construye rutas ni envía peticiones por su cuenta.

## Campos comunes

```ts
type Csrf = { name: string; hash: string }
type Flash = { success: string; error: string }
type Role = { id: number; name: string; description: string }
type Action = { [key: string]: string }
```

Los textos opcionales se entregan como `''`, y las colecciones opcionales como `[]`. Esto evita contratos ambiguos entre PHP y Vue.

## `pageType: "superadmin"`

Componente: `SuperAdminPage`.

```ts
type SuperAdminData = {
  csrf: Csrf
  flash: Flash
  permissions: {
    companiesEdit: boolean
    assignCompanies: boolean
    assignRoles: boolean
  }
  metrics: {
    companiesTotal: number
    companiesActive: number
    usersTotal: number
  }
  actions: {
    createCompany: string // /superadmin/empresas
  }
  oldInput: {
    razon_social: string
    nombre_fantasia: string
    cuit: string
    email: string
    telefono: string
  }
  companies: Array<{
    id: number
    razonSocial: string
    nombreFantasia: string
    displayName: string
    cuit: string
    email: string
    telefono: string
    active: boolean
    actions: { update: string } // /superadmin/empresas/{id}
  }>
  assignableCompanies: Array<{
    id: number
    name: string
  }> // sólo empresas activas
  roles: Role[]
  users: Array<{
    id: number
    name: string
    email: string
    active: boolean
    isSuperAdmin: boolean
    companyId: number | ''
    companyName: string
    roles: Role[]
    assignedRoleIds: number[]
    actions: {
      assignCompany: string // /superadmin/usuarios/{id}/empresa
      assignRoles: string // /superadmin/usuarios/{id}/roles
    }
  }>
}
```

## `pageType: "branches-admin"`

Componente: `BranchesAdminPage`.

```ts
type BranchesAdminData = {
  csrf: Csrf
  flash: Flash
  company: { id: number; name: string }
  permissions: { edit: boolean }
  metrics: { total: number; active: number; inactive: number }
  actions: { create: string } // /administracion/sucursales
  oldInput: {
    codigo: string
    nombre: string
    direccion: string
    emailAlertas: string
  }
  branches: Array<{
    id: number
    code: string
    name: string
    address: string
    alertEmail: string
    active: boolean
    actions: { update: string } // /administracion/sucursales/{id}
  }>
}
```

## `pageType: "users-admin"`

Componente: `UsersAdminPage`.

```ts
type UsersAdminData = {
  csrf: Csrf
  flash: Flash
  company: { id: number; name: string }
  permissions: {
    create: boolean
    editAccounts: boolean
    editAccess: boolean
    resetPasswords: boolean
  }
  metrics: { total: number; active: number; inactive: number }
  actions: { create: string } // /administracion/usuarios
  oldInput: {
    nombre: string
    email: string
    motivo: string
    roleIds: number[]
    branchIds: number[]
  }
  roles: Role[]
  assignableBranches: Array<{
    id: number
    code: string
    name: string
  }> // sólo sucursales activas de la empresa del actor
  users: Array<{
    id: number
    name: string
    email: string
    active: boolean
    isSelf: boolean
    canDeactivate: boolean
    allCompanyBranches: boolean
    lastAccess: string
    roles: Role[]
    branches: Array<{ id: number; code: string; name: string; active: boolean }>
    assignedRoleIds: number[]
    assignedBranchIds: number[]
    actions: {
      update: string // /administracion/usuarios/{id}
      assignAccess: string // /administracion/usuarios/{id}/acceso
      resetPassword: string // /administracion/usuarios/{id}/password
    }
  }>
}
```

`isSelf`, `canDeactivate`, `allCompanyBranches`, `assignableCompanies` y `assignableBranches` son decisiones ya resueltas por el servidor. Vue sólo refleja su resultado. Los filtros y controladores continúan siendo la autoridad de seguridad para todas las operaciones.
