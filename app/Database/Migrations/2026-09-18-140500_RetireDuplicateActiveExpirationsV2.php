<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class RetireDuplicateActiveExpirationsV2 extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('vencimientos')) {
            return;
        }

        $rows = $this->db->table('vencimientos')
            ->select('id, empresa_id, tipo_vencimiento_id, sujeto_tipo, equipo_id, empleado_id, fecha_vencimiento')
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('empresa_id', 'ASC')
            ->orderBy('tipo_vencimiento_id', 'ASC')
            ->orderBy('sujeto_tipo', 'ASC')
            ->orderBy('equipo_id', 'ASC')
            ->orderBy('empleado_id', 'ASC')
            ->orderBy('fecha_vencimiento', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $seen = [];
        $retireIds = [];

        foreach ($rows as $row) {
            $subjectId = (string) $row['sujeto_tipo'] === 'EQUIPO'
                ? (int) $row['equipo_id']
                : (int) $row['empleado_id'];

            $key = implode(':', [
                (int) $row['empresa_id'],
                (int) $row['tipo_vencimiento_id'],
                (string) $row['sujeto_tipo'],
                $subjectId,
            ]);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                continue;
            }

            $retireIds[] = (int) $row['id'];
        }

        if ($retireIds === []) {
            return;
        }

        $this->db->table('vencimientos')
            ->whereIn('id', $retireIds)
            ->update([
                'activo' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function down(): void
    {
        // No reactivar historial automáticamente.
    }
}
