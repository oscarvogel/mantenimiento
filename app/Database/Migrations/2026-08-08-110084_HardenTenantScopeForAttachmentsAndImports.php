<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class HardenTenantScopeForAttachmentsAndImports extends Migration
{
    public function up(): void
    {
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_sucursales_empresa_id');
        $this->forge->processIndexes('sucursales');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_importaciones_empresa_id');
        $this->forge->processIndexes('importaciones');

        $this->db->query('ALTER TABLE equipo_adjuntos DROP FOREIGN KEY equipo_adjuntos_sucursal_snapshot_id_foreign');
        $this->assertNoCrossTenantAttachments();
        $this->db->query(
            'ALTER TABLE equipo_adjuntos ADD CONSTRAINT fk_equipo_adjuntos_sucursal_tenant '
            . 'FOREIGN KEY (empresa_id, sucursal_snapshot_id) REFERENCES sucursales (empresa_id, id)',
        );

        $this->forge->addColumn('importacion_filas', [
            'empresa_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'importacion_id',
            ],
        ]);
        $this->db->query(
            'UPDATE importacion_filas f INNER JOIN importaciones i ON i.id = f.importacion_id '
            . 'SET f.empresa_id = i.empresa_id WHERE f.empresa_id IS NULL',
        );
        $this->assertNoCrossTenantImportRows();
        $this->db->query('ALTER TABLE importacion_filas MODIFY empresa_id INT UNSIGNED NOT NULL');
        $this->db->query('ALTER TABLE importacion_filas DROP FOREIGN KEY importacion_filas_sucursal_id_foreign');
        $this->db->query(
            'ALTER TABLE importacion_filas ADD CONSTRAINT fk_importacion_filas_importacion_tenant '
            . 'FOREIGN KEY (empresa_id, importacion_id) REFERENCES importaciones (empresa_id, id) ON DELETE CASCADE ON UPDATE CASCADE',
        );
        $this->db->query(
            'ALTER TABLE importacion_filas ADD CONSTRAINT fk_importacion_filas_sucursal_tenant '
            . 'FOREIGN KEY (empresa_id, sucursal_id) REFERENCES sucursales (empresa_id, id)',
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE importacion_filas DROP FOREIGN KEY fk_importacion_filas_sucursal_tenant');
        $this->db->query('ALTER TABLE importacion_filas DROP FOREIGN KEY fk_importacion_filas_importacion_tenant');
        $this->db->query(
            'ALTER TABLE importacion_filas ADD CONSTRAINT importacion_filas_sucursal_id_foreign '
            . 'FOREIGN KEY (sucursal_id) REFERENCES sucursales (id) ON DELETE SET NULL',
        );
        $this->forge->dropColumn('importacion_filas', 'empresa_id');
        $this->forge->dropKey('importaciones', 'uq_importaciones_empresa_id');

        $this->db->query('ALTER TABLE equipo_adjuntos DROP FOREIGN KEY fk_equipo_adjuntos_sucursal_tenant');
        $this->db->query(
            'ALTER TABLE equipo_adjuntos ADD CONSTRAINT equipo_adjuntos_sucursal_snapshot_id_foreign '
            . 'FOREIGN KEY (sucursal_snapshot_id) REFERENCES sucursales (id)',
        );
        $this->forge->dropKey('sucursales', 'uq_sucursales_empresa_id');
    }

    private function assertNoCrossTenantAttachments(): void
    {
        $row = $this->db->query(
            'SELECT a.id FROM equipo_adjuntos a INNER JOIN sucursales s ON s.id = a.sucursal_snapshot_id '
            . 'WHERE s.empresa_id <> a.empresa_id LIMIT 1',
        )->getRowArray();
        if ($row !== null) {
            throw new RuntimeException('Existen adjuntos con una sucursal de otra empresa.');
        }
    }

    private function assertNoCrossTenantImportRows(): void
    {
        $row = $this->db->query(
            'SELECT f.id FROM importacion_filas f '
            . 'INNER JOIN importaciones i ON i.id = f.importacion_id '
            . 'LEFT JOIN sucursales s ON s.id = f.sucursal_id '
            . 'WHERE f.empresa_id <> i.empresa_id OR (f.sucursal_id IS NOT NULL AND s.empresa_id <> f.empresa_id) LIMIT 1',
        )->getRowArray();
        if ($row !== null) {
            throw new RuntimeException('Existen filas de importacion con alcance de otra empresa.');
        }
    }
}
