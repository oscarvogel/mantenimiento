<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Infrastructure\Geography\PrincipalLocalityMarker;
use CodeIgniter\Database\Migration;

/**
 * Agrega la marca `principal` a las localidades y deja el catálogo principal
 * definido.
 *
 * Corrección 17/11/2026 (#146): la regla de Brasil se aplicaba sobre las
 * provincias del país sin comprobar que la lista no estuviera vacía. En una
 * instalación desde cero todavía no hay provincias cargadas, la consulta
 * `provincia_id IN ()` es SQL inválido en MySQL/MariaDB y la instalación se
 * cortaba en esta migración.
 *
 * El cambio no altera el resultado en las instalaciones donde esta migración
 * ya se aplicó: allí la lista de provincias estaba poblada y la marca se
 * calculó igual. Lo que cambia es que, si la lista está vacía, no se ejecuta
 * una consulta que abortaría la instalación. La implementación de la regla vive
 * ahora en `PrincipalLocalityMarker`, que comparten esta migración y la
 * migración correctiva `2026-11-17-100000_ReapplyPrincipalLocalityFlags`.
 */
final class AddPrincipalFlagToLocalities extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('principal', 'localidades')) {
            $this->forge->addColumn('localidades', [
                'principal' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            ]);
            $this->db->resetDataCache();
        }

        (new PrincipalLocalityMarker($this->db))->applyFromScratch();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('localidades') && $this->db->fieldExists('fuente', 'localidades')) {
            $rows = $this->db->table('localidades')
                ->select('id')
                ->where('fuente', 'CATALOGO_PRINCIPAL')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ($this->isReferenced($id)) {
                    $this->db->table('localidades')->where('id', $id)->update(['activo' => 0]);
                    continue;
                }
                $this->db->table('localidades')->where('id', $id)->delete();
            }
        }

        if ($this->db->fieldExists('principal', 'localidades')) {
            $this->forge->dropColumn('localidades', 'principal');
            $this->db->resetDataCache();
        }
    }

    private function isReferenced(int $localityId): bool
    {
        if ($this->db->tableExists('viaje_operativo_tramos')) {
            $builder = $this->db->table('viaje_operativo_tramos');
            $builder->groupStart()->where('localidad_origen_id', $localityId);
            if ($this->db->fieldExists('localidad_destino_id', 'viaje_operativo_tramos')) {
                $builder->orWhere('localidad_destino_id', $localityId);
            }
            $builder->groupEnd();
            if ($builder->countAllResults() > 0) {
                return true;
            }
        }

        if ($this->db->tableExists('geografia_equivalencias')
            && $this->db->table('geografia_equivalencias')->where('localidad_id', $localityId)->countAllResults() > 0) {
            return true;
        }

        return false;
    }
}
