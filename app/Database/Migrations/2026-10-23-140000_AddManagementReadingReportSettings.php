<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Configuracion del informe de lecturas atrasadas para la gerencia de la empresa.
 *
 * - `telefono_gerencia`: contacto de la duena/gerencia en formato internacional
 *   (solo digitos). Es el destinatario del aviso por WhatsApp. Nullable porque
 *   no toda empresa tiene un contacto de gerencia.
 * - `informe_lecturas_whatsapp_habilitado`: opt-in por empresa. Nace en 0 para
 *   no activar envios al aplicar la migracion.
 * - `informe_lecturas_hora`: hora de envio. El ciclo de notificaciones revisa
 *   en cada ejecucion que informes estan vencidos; no se crea un cron por empresa.
 * - `informe_lecturas_dias`: umbral de dias sin lectura que se informa. Lo usan
 *   el mismo informe por email y el aviso por WhatsApp, para que ambos canales
 *   hablen del mismo universo de equipos.
 *
 * No se usa `after` a proposito: las columnas se agregan al final de la tabla
 * para que la migracion no dependa del orden de otras columnas agregadas.
 */
final class AddManagementReadingReportSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('empresas', [
            'telefono_gerencia' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
            ],
            'informe_lecturas_whatsapp_habilitado' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 0,
            ],
            'informe_lecturas_hora' => [
                'type' => 'CHAR',
                'constraint' => 5,
                'default' => '08:00',
            ],
            'informe_lecturas_dias' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 7,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('empresas', [
            'telefono_gerencia',
            'informe_lecturas_whatsapp_habilitado',
            'informe_lecturas_hora',
            'informe_lecturas_dias',
        ]);
    }
}
