<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Motivo estructurado de cancelación de una entrega de WhatsApp.
 *
 * El texto libre sigue en `ultimo_error`; esta columna guarda el código estable
 * que permite contar, filtrar y auditar por qué se canceló una entrega.
 */
final class AddCancellationReasonToWhatsAppDeliveries extends Migration
{
    private const TABLE = 'notificacion_whatsapp_entregas';

    private const COLUMN = 'motivo_cancelacion';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        $this->db->resetDataCache();
        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return;
        }

        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'ultimo_error',
            ],
        ]);
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        $this->db->resetDataCache();
        if (! $this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return;
        }

        $this->forge->dropColumn(self::TABLE, self::COLUMN);
        $this->db->resetDataCache();
    }
}
