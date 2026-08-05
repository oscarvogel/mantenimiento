<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeder inicial: crea una empresa demo, una sucursal demo, los 5 roles
 * de la spec (seccion 4.1), un set de permisos basicos y un usuario
 * administrador.
 *
 * Credenciales del admin:
 *   email:    admin@mantenimiento.local
 *   password: Admin1234
 */
class InitialSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        // 1) Empresa demo
        $this->db->table('empresas')->insert([
            'id'            => 1,
            'razon_social'  => 'Empresa Demo S.A.',
            'nombre_fantasia' => 'Empresa Demo',
            'cuit'          => '30-12345678-9',
            'email'         => 'contacto@empresa-demo.local',
            'telefono'      => '+54 11 5555-0000',
            'estado'        => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // 2) Sucursal demo
        $this->db->table('sucursales')->insert([
            'id'            => 1,
            'empresa_id'    => 1,
            'codigo'        => 'CENTRAL',
            'nombre'        => 'Casa Central',
            'direccion'     => 'Av. Siempre Viva 742',
            'email_alertas' => 'alertas@empresa-demo.local',
            'estado'        => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // 3) Roles (spec seccion 4.1)
        $roles = [
            ['id' => 1, 'nombre' => 'Administrador',         'descripcion' => 'Acceso completo, configuracion, usuarios, catalogos, auditoria y anulaciones.'],
            ['id' => 2, 'nombre' => 'Responsable de mantenimiento', 'descripcion' => 'Equipos, lecturas, planes, ordenes, trabajos, repuestos, garantias y reportes.'],
            ['id' => 3, 'nombre' => 'Tecnico u operador',    'descripcion' => 'Consultar trabajo asignado, cargar lecturas, iniciar, actualizar y cerrar tareas u ordenes segun autorizacion.'],
            ['id' => 4, 'nombre' => 'Solicitante',           'descripcion' => 'Informar fallas o necesidades, adjuntar evidencia y consultar el estado de sus solicitudes.'],
            ['id' => 5, 'nombre' => 'Consulta',               'descripcion' => 'Visualizar paneles, equipos, ordenes e historial sin modificar informacion.'],
        ];
        foreach ($roles as $r) {
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            $this->db->table('roles')->insert($r);
        }

        // 4) Permisos basicos
        $permisos = [
            ['id' => 1,  'clave' => 'empresas.ver',          'descripcion' => 'Ver listado y detalle de empresas'],
            ['id' => 2,  'clave' => 'empresas.editar',       'descripcion' => 'Crear y modificar empresas'],
            ['id' => 3,  'clave' => 'sucursales.ver',        'descripcion' => 'Ver listado y detalle de sucursales'],
            ['id' => 4,  'clave' => 'sucursales.editar',     'descripcion' => 'Crear y modificar sucursales'],
            ['id' => 5,  'clave' => 'usuarios.ver',         'descripcion' => 'Ver listado y detalle de usuarios'],
            ['id' => 6,  'clave' => 'usuarios.editar',      'descripcion' => 'Crear, modificar y desactivar usuarios'],
            ['id' => 7,  'clave' => 'roles.editar',          'descripcion' => 'Administrar roles y permisos'],
            ['id' => 8,  'clave' => 'equipos.ver',           'descripcion' => 'Ver listado y detalle de equipos'],
            ['id' => 9,  'clave' => 'equipos.editar',        'descripcion' => 'Crear y modificar equipos'],
            ['id' => 10, 'clave' => 'lecturas.cargar',       'descripcion' => 'Cargar lecturas de kilometros y horas'],
            ['id' => 11, 'clave' => 'planes.ver',            'descripcion' => 'Ver planes preventivos'],
            ['id' => 12, 'clave' => 'planes.editar',         'descripcion' => 'Crear y modificar planes preventivos'],
            ['id' => 13, 'clave' => 'solicitudes.crear',     'descripcion' => 'Cargar solicitudes de mantenimiento o fallas'],
            ['id' => 14, 'clave' => 'solicitudes.revisar',   'descripcion' => 'Revisar, aprobar, rechazar o agrupar solicitudes'],
            ['id' => 15, 'clave' => 'ordenes.ver',           'descripcion' => 'Ver listado y detalle de ordenes de trabajo'],
            ['id' => 16, 'clave' => 'ordenes.editar',        'descripcion' => 'Crear y modificar ordenes de trabajo'],
            ['id' => 17, 'clave' => 'ordenes.cerrar',        'descripcion' => 'Finalizar ordenes de trabajo'],
            ['id' => 18, 'clave' => 'ordenes.mi_trabajo',    'descripcion' => 'Ver y trabajar sobre las ordenes asignadas (bandeja del tecnico)'],
            ['id' => 19, 'clave' => 'reportes.ver',          'descripcion' => 'Ver reportes y exportar a Excel o CSV'],
            ['id' => 20, 'clave' => 'auditoria.ver',         'descripcion' => 'Ver la bitacora de auditoria'],
        ];
        foreach ($permisos as $p) {
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
            $this->db->table('permisos')->insert($p);
        }

        // 5) Rol -> Permisos
        // Administrador: todos
        for ($i = 1; $i <= 20; $i++) {
            $this->db->table('rol_permisos')->insert(['rol_id' => 1, 'permiso_id' => $i, 'created_at' => $now]);
        }
        // Responsable de mantenimiento: 3, 4 (sucursales ver), 5, 6 (usuarios no), 8, 9, 10, 11, 12, 15, 16, 17, 19
        $r2 = [3, 4, 8, 9, 10, 11, 12, 15, 16, 17, 19];
        foreach ($r2 as $pid) { $this->db->table('rol_permisos')->insert(['rol_id' => 2, 'permiso_id' => $pid, 'created_at' => $now]); }
        // Tecnico u operador: 8, 10, 15, 18 (mi trabajo)
        $r3 = [8, 10, 15, 18];
        foreach ($r3 as $pid) { $this->db->table('rol_permisos')->insert(['rol_id' => 3, 'permiso_id' => $pid, 'created_at' => $now]); }
        // Solicitante: 13 (crear solicitudes)
        $this->db->table('rol_permisos')->insert(['rol_id' => 4, 'permiso_id' => 13, 'created_at' => $now]);
        // Consulta: 8, 15, 19 (ver equipos, ordenes y reportes)
        $r5 = [8, 15, 19];
        foreach ($r5 as $pid) { $this->db->table('rol_permisos')->insert(['rol_id' => 5, 'permiso_id' => $pid, 'created_at' => $now]); }

        // 6) Usuario administrador
        $this->db->table('usuarios')->insert([
            'id'            => 1,
            'empresa_id'    => 1,
            'nombre'        => 'Administrador',
            'email'         => 'admin@mantenimiento.local',
            'password_hash' => password_hash('Admin1234', PASSWORD_BCRYPT),
            'activo'        => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // 7) Asignar todos los roles al admin (para desarrollo)
        $this->db->table('usuario_roles')->insert(['usuario_id' => 1, 'rol_id' => 1, 'created_at' => $now]);
        $this->db->table('usuario_sucursales')->insert(['usuario_id' => 1, 'sucursal_id' => 1, 'created_at' => $now]);
    }
}