<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Datos mínimos repetibles para desarrollo local.
 *
 * Las relaciones se resuelven por claves naturales: las migraciones pueden
 * haber creado permisos antes de ejecutar este seeder y, por eso, ningún ID
 * autoincremental se da por conocido.
 */
final class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $companyId = $this->ensureRow('empresas', ['cuit' => '30-12345678-9'], [
            'razon_social' => 'Empresa Demo S.A.',
            'nombre_fantasia' => 'Empresa Demo',
            'cuit' => '30-12345678-9',
            'email' => 'contacto@empresa-demo.local',
            'telefono' => '+54 11 5555-0000',
            'estado' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $branchId = $this->ensureRow('sucursales', [
            'empresa_id' => $companyId,
            'codigo' => 'CENTRAL',
        ], [
            'empresa_id' => $companyId,
            'codigo' => 'CENTRAL',
            'nombre' => 'Casa Central',
            'direccion' => 'Av. Siempre Viva 742',
            'email_alertas' => 'alertas@empresa-demo.local',
            'estado' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roles = [
            'Administrador' => 'Acceso completo, configuración, usuarios, catálogos, auditoría y anulaciones.',
            'Responsable de mantenimiento' => 'Equipos, lecturas, planes, órdenes, trabajos, repuestos, garantías y reportes.',
            'Tecnico u operador' => 'Consultar trabajo asignado, cargar lecturas, iniciar, actualizar y cerrar tareas u órdenes según autorización.',
            'Solicitante' => 'Informar fallas o necesidades, adjuntar evidencia y consultar el estado de sus solicitudes.',
            'Consulta' => 'Visualizar paneles, equipos, órdenes e historial sin modificar información.',
        ];
        $roleIds = [];
        foreach ($roles as $name => $description) {
            $roleIds[$name] = $this->ensureRow('roles', ['nombre' => $name], [
                'nombre' => $name,
                'descripcion' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            'empresas.ver' => 'Ver listado y detalle de empresas',
            'empresas.editar' => 'Crear y modificar empresas',
            'sucursales.ver' => 'Ver listado y detalle de sucursales',
            'sucursales.editar' => 'Crear y modificar sucursales',
            'usuarios.ver' => 'Ver listado y detalle de usuarios',
            'usuarios.editar' => 'Crear, modificar y desactivar usuarios',
            'roles.editar' => 'Administrar roles y permisos',
            'equipos.ver' => 'Ver listado y detalle de equipos',
            'equipos.editar' => 'Crear y modificar equipos',
            'lecturas.cargar' => 'Cargar lecturas de kilómetros y horas',
            'lecturas.ver' => 'Ver historial de lecturas',
            'lecturas.corregir' => 'Corregir lecturas con motivo y trazabilidad',
            'planes.ver' => 'Ver planes preventivos',
            'planes.editar' => 'Crear y modificar planes preventivos',
            'solicitudes.crear' => 'Cargar solicitudes de mantenimiento o fallas',
            'solicitudes.revisar' => 'Revisar, aprobar, rechazar o agrupar solicitudes',
            'ordenes.ver' => 'Ver listado y detalle de órdenes de trabajo',
            'ordenes.editar' => 'Crear y modificar órdenes de trabajo',
            'ordenes.cerrar' => 'Finalizar órdenes de trabajo',
            'ordenes.mi_trabajo' => 'Ver y trabajar sobre las órdenes asignadas',
            'reportes.ver' => 'Ver reportes y exportar a Excel o CSV',
            'auditoria.ver' => 'Ver la bitácora de auditoría',
            'importaciones.ver' => 'Ver historial y vista previa de importaciones',
            'importaciones.cargar' => 'Cargar, confirmar y cancelar importaciones',
            'notificaciones.ver' => 'Ver notificaciones y configurar preferencias propias',
        ];
        $permissionIds = [];
        foreach ($permissions as $key => $description) {
            $permissionIds[$key] = $this->ensureRow('permisos', ['clave' => $key], [
                'clave' => $key,
                'descripcion' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rolePermissions = [
            'Administrador' => array_keys($permissions),
            'Responsable de mantenimiento' => [
                'sucursales.ver', 'sucursales.editar', 'equipos.ver', 'equipos.editar',
                'lecturas.cargar', 'lecturas.ver', 'lecturas.corregir', 'planes.ver',
                'planes.editar', 'solicitudes.crear', 'solicitudes.revisar',
                'ordenes.ver', 'ordenes.editar', 'ordenes.cerrar', 'reportes.ver',
                'importaciones.ver', 'importaciones.cargar',
                'notificaciones.ver',
            ],
            'Tecnico u operador' => [
                'equipos.ver', 'lecturas.cargar', 'lecturas.ver', 'solicitudes.crear',
                'ordenes.ver', 'ordenes.mi_trabajo', 'notificaciones.ver',
            ],
            'Solicitante' => ['solicitudes.crear', 'notificaciones.ver'],
            'Consulta' => ['equipos.ver', 'lecturas.ver', 'ordenes.ver', 'reportes.ver', 'importaciones.ver', 'notificaciones.ver'],
        ];
        foreach ($rolePermissions as $roleName => $keys) {
            foreach ($keys as $key) {
                $this->ensureRelation('rol_permisos', [
                    'rol_id' => $roleIds[$roleName],
                    'permiso_id' => $permissionIds[$key],
                ], $now);
            }
        }

        $adminEmail = 'admin@mantenimiento.local';
        $adminId = $this->ensureRow('usuarios', ['email' => $adminEmail], [
            'empresa_id' => $companyId,
            'nombre' => 'Administrador',
            'email' => $adminEmail,
            'password_hash' => password_hash('Admin1234', PASSWORD_BCRYPT),
            'es_superadmin' => 0,
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->ensureRelation('usuario_roles', [
            'usuario_id' => $adminId,
            'rol_id' => $roleIds['Administrador'],
        ], $now);
        $this->ensureRelation('usuario_sucursales', [
            'usuario_id' => $adminId,
            'sucursal_id' => $branchId,
        ], $now);

        $this->call(SuperAdminSeeder::class);
        $this->call(VerticalCircuitSeeder::class);
        $this->call(NotificationDefaultsSeeder::class);
    }

    /** @param array<string, mixed> $criteria @param array<string, mixed> $data */
    private function ensureRow(string $table, array $criteria, array $data): int
    {
        $row = $this->db->table($table)->select('id')->where($criteria)->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }

        $this->db->table($table)->insert($data);

        return (int) $this->db->insertID();
    }

    /** @param array<string, int> $relation */
    private function ensureRelation(string $table, array $relation, string $now): void
    {
        if ($this->db->table($table)->where($relation)->countAllResults() !== 0) {
            return;
        }

        $this->db->table($table)->insert($relation + ['created_at' => $now]);
    }
}
