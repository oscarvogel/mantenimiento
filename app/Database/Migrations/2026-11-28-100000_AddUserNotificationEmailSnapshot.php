<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Correo de resumen por usuario y foto del destinatario en cada entrega.
 *
 * `usuarios.email` sigue siendo la credencial de ingreso y no se modifica.
 * `notificacion_entregas.destinatario` es la dirección efectiva al encolar;
 * NULL significa "entrega antigua": se resuelve con la política vigente al
 * despachar, sin reescribir ni reenviar nada.
 */
final class AddUserNotificationEmailSnapshot extends Migration
{
    private const USERS = 'usuarios';

    private const DELIVERIES = 'notificacion_entregas';

    public function up(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists(self::USERS)
            && ! $this->db->fieldExists('email_notificaciones', self::USERS)) {
            $this->forge->addColumn(self::USERS, [
                'email_notificaciones' => [
                    'type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'email',
                ],
            ]);
            $this->db->resetDataCache();
        }

        if ($this->db->tableExists(self::DELIVERIES)
            && ! $this->db->fieldExists('destinatario', self::DELIVERIES)) {
            $this->forge->addColumn(self::DELIVERIES, [
                'destinatario' => [
                    'type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'clave_entrega',
                ],
            ]);
            $this->db->resetDataCache();
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists(self::DELIVERIES)
            && $this->db->fieldExists('destinatario', self::DELIVERIES)) {
            $this->forge->dropColumn(self::DELIVERIES, 'destinatario');
            $this->db->resetDataCache();
        }

        if ($this->db->tableExists(self::USERS)
            && $this->db->fieldExists('email_notificaciones', self::USERS)) {
            $this->forge->dropColumn(self::USERS, 'email_notificaciones');
            $this->db->resetDataCache();
        }
    }
}
