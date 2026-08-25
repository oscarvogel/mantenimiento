<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class BackfillNotificationRoleDefaults extends Migration
{
    public function up(): void
    {
        $events = ['orden.proxima_objetivo', 'orden.espera_repuestos'];
        $now = date('Y-m-d H:i:s');

        foreach ($this->db->table('roles')->select('id, nombre')->get()->getResultArray() as $role) {
            foreach ($events as $event) {
                $existing = $this->db->table('preferencias_notificacion_rol')
                    ->select('id')
                    ->where('rol_id', $role['id'])
                    ->where('tipo_evento', $event)
                    ->get()->getRowArray();
                if ($existing !== null) {
                    continue;
                }

                $roleName = (string) $role['nombre'];
                $this->db->table('preferencias_notificacion_rol')->insert([
                    'rol_id' => (int) $role['id'],
                    'tipo_evento' => $event,
                    'modo_interno' => 'INMEDIATO',
                    'modo_email' => $roleName === 'Consulta' ? 'DESACTIVADO' : 'RESUMEN',
                    'modo_push' => $roleName === 'Consulta' ? 'DESACTIVADO' : 'CRITICO',
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('preferencias_notificacion_rol')
            ->whereIn('tipo_evento', ['orden.proxima_objetivo', 'orden.espera_repuestos'])
            ->delete();
    }
}
