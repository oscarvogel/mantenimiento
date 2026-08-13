    <?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class NotificationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $events = ['preventivo.vencido', 'preventivo.proximo', 'orden.asignada', 'orden.demorada', 'solicitud.critica', 'equipo.sin_lectura', 'garantia.proxima'];
        foreach ($this->db->table('roles')->select('id, nombre')->get()->getResultArray() as $role) {
            foreach ($events as $event) {
                $defaults = $this->defaults((string) $role['nombre'], $event);
                $existing = $this->db->table('preferencias_notificacion_rol')->select('id')->where('rol_id', $role['id'])->where('tipo_evento', $event)->get()->getRowArray();
                if ($existing === null) {
                    $this->db->table('preferencias_notificacion_rol')->insert($defaults + [
                        'rol_id' => (int) $role['id'], 'tipo_evento' => $event, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
    }

    /** @return array{modo_interno:string,modo_email:string,modo_push:string} */
    private function defaults(string $role, string $event): array
    {
        $email = $role === 'Consulta' ? 'DESACTIVADO' : 'RESUMEN';
        $push = $role === 'Consulta' ? 'DESACTIVADO' : 'CRITICO';
        if ($role === 'Tecnico u operador' && $event === 'orden.asignada') {
            $email = 'DESACTIVADO';
            $push = 'INMEDIATO';
        }
        return ['modo_interno' => 'INMEDIATO', 'modo_email' => $email, 'modo_push' => $push];
    }
}
