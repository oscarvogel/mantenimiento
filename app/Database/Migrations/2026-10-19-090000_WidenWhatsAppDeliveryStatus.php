<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use DomainException;

/**
 * `PENDIENTE_CONFIRMACION` (21 caracteres) no entraba en `VARCHAR(20)` y MySQL
 * lo truncaba a `PENDIENTE_CONFIRMACI`. Eso rompía la conciliación de entregas
 * y la guarda anti-duplicado del recordatorio semanal.
 */
final class WidenWhatsAppDeliveryStatus extends Migration
{
    private const TABLE = 'notificacion_whatsapp_entregas';

    private const TRUNCATED = 'PENDIENTE_CONFIRMACI';

    private const FULL = 'PENDIENTE_CONFIRMACION';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->db->table(self::TABLE)
            ->where('estado', self::TRUNCATED)
            ->update(['estado' => self::FULL]);

        $this->forge->modifyColumn(self::TABLE, [
            'estado' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'PENDIENTE'],
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $tooLong = $this->db->table(self::TABLE)
            ->where('LENGTH(estado) >', 20, false)
            ->countAllResults();
        if ($tooLong > 0) {
            throw new DomainException('No se puede revertir: existen estados de entrega WhatsApp de más de 20 caracteres.');
        }

        $this->forge->modifyColumn(self::TABLE, [
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
        ]);
    }
}
