<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\ReadingRepository;
use App\Domain\Measurement\EquipmentReading;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterReadingRepository implements ReadingRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function append(EquipmentReading $reading): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('lecturas_equipo')->insert([
            'empresa_id'          => $reading->companyId(),
            'sucursal_id'         => $reading->branchId(),
            'equipo_id'           => $reading->equipmentId(),
            'fecha_lectura'       => $reading->recordedAt()->format('Y-m-d H:i:s'),
            'kilometraje'         => $reading->measurement()->kilometers(),
            'horometro'           => $reading->measurement()->hours(),
            'origen'              => $reading->origin(),
            'referencia_origen'   => $reading->originReference(),
            'usuario_id'          => $reading->userId(),
            'motivo_correccion'   => $reading->correctionReason(),
            'lectura_corregida_id'=> $reading->correctedReadingId(),
            'observaciones'       => $reading->notes(),
            'anulada'             => 0,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $readingId = (int) $this->database->insertID();
        if ($readingId <= 0) {
            throw new RuntimeException('No se pudo registrar la lectura.');
        }

        return $readingId;
    }
}
