<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\ManagementReadingReportSettingsReadModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Configuracion del informe de lecturas atrasadas, leida de `empresas`.
 *
 * Devuelve todas las empresas activas con su configuracion, incluidas las que
 * todavia no habilitaron el informe o no cargaron un telefono de gerencia: el
 * caso de uso decide que hacer con cada una, y asi el boton de prueba puede
 * explicar con precision que falta en vez de decir "no se pudo preparar".
 *
 * Si las columnas no existen todavia (migracion no aplicada) no hay nada que
 * informar: es preferible no enviar nada a enviar un informe con datos a medias.
 */
final readonly class CodeIgniterManagementReadingReportSettingsReadModel implements ManagementReadingReportSettingsReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function configuredCompanies(): array
    {
        $columns = $this->database->getFieldNames('empresas');
        if (! in_array('telefono_gerencia', $columns, true)
            || ! in_array('informe_lecturas_whatsapp_habilitado', $columns, true)
        ) {
            return [];
        }

        $rows = $this->database->table('empresas')
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $companies = [];
        foreach ($rows as $row) {
            $companyId = (int) ($row['id'] ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $companies[] = [
                'companyId' => $companyId,
                'companyName' => trim((string) ($row['nombre_fantasia'] ?? ''))
                    ?: trim((string) ($row['razon_social'] ?? ''))
                    ?: 'Empresa #' . $companyId,
                'locale' => trim((string) ($row['idioma_notificaciones'] ?? 'ES')) ?: 'ES',
                'phone' => trim((string) ($row['telefono_gerencia'] ?? '')),
                'enabled' => (int) ($row['informe_lecturas_whatsapp_habilitado'] ?? 0) === 1,
                'hour' => trim((string) ($row['informe_lecturas_hora'] ?? '08:00')) ?: '08:00',
                'thresholdDays' => max(1, (int) ($row['informe_lecturas_dias'] ?? 7)),
                'whatsappInstanceId' => trim((string) ($row['whatsapp_instance_id'] ?? '')),
            ];
        }

        return $companies;
    }
}
