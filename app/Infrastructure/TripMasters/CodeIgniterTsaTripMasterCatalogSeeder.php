<?php

declare(strict_types=1);

namespace App\Infrastructure\TripMasters;

use App\Application\TripMasters\Port\TsaTripMasterCatalogSeeder;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;
use Throwable;

final readonly class CodeIgniterTsaTripMasterCatalogSeeder implements TsaTripMasterCatalogSeeder
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function insertMissing(array $catalogs, int $actorUserId): array
    {
        foreach ($catalogs as $catalog) {
            if (! $this->database->tableExists($catalog['table'])) {
                throw new DomainException('Faltan las tablas de maestros de Viajes. Aplicá las migraciones antes de cargar los datos.');
            }
        }

        $this->database->transBegin();

        try {
            $companyRows = $this->database->query(
                "SELECT id FROM empresas
                 WHERE estado = 1 AND deleted_at IS NULL
                   AND (UPPER(TRIM(COALESCE(nombre_fantasia, ''))) = ?
                        OR UPPER(TRIM(COALESCE(razon_social, ''))) = ?)
                 FOR UPDATE",
                ['TSA', 'TSA'],
            )->getResultArray();

            if (count($companyRows) !== 1) {
                throw new DomainException(sprintf(
                    'La carga requiere una única empresa activa identificada como TSA; se encontraron %d.',
                    count($companyRows),
                ));
            }

            $companyId = (int) $companyRows[0]['id'];
            $counts = [];
            $now = date('Y-m-d H:i:s');
            foreach ($catalogs as $label => $catalog) {
                $inserted = 0;
                foreach ($catalog['rows'] as $row) {
                    $existing = $this->database->table($catalog['table'])
                        ->where('empresa_id', $companyId)
                        ->where('nombre', $row['nombre'])
                        ->get()->getRowArray();

                    if ($existing !== null) {
                        continue;
                    }

                    $this->database->table($catalog['table'])->insert($row + [
                        'empresa_id' => $companyId,
                        'activo' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'created_by' => $actorUserId,
                        'updated_by' => $actorUserId,
                    ]);
                    $inserted++;
                }
                $counts[$label] = $inserted;
            }

            if ($this->database->transStatus() === false || ! $this->database->transCommit()) {
                throw new RuntimeException('No se pudo confirmar la carga de catálogos de TSA.');
            }

            return $counts;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
