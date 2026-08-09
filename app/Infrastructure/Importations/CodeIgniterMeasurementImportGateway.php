<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\MeasurementImportData;
use App\Application\Importations\Port\MeasurementImportGateway;
use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;
use RuntimeException;

final class CodeIgniterMeasurementImportGateway implements MeasurementImportGateway
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isDuplicate(MeasurementImportData $data): bool
    {
        $builder = $this->database->table('lecturas_equipo')->where('empresa_id', $data->companyId)
            ->where('equipo_id', $data->equipmentId)->where('fecha_lectura', $data->recordedAt)
            ->where('origen', $data->origin)->where('anulada', 0);
        $data->kilometers === null ? $builder->where('kilometraje', null) : $builder->where('kilometraje', $data->kilometers);
        $data->hours === null ? $builder->where('horometro', null) : $builder->where('horometro', $data->hours);
        return $builder->countAllResults() > 0;
    }

    public function import(MeasurementImportData $data): int
    {
        if ($data->origin !== 'IMPORTACION') {
            throw new DomainException('El gateway solo admite lecturas traducidas al origen IMPORTACION.');
        }
        $measurement = UsageMeasurement::from($data->kilometers, $data->hours);
        $originReference = mb_substr('IMPORTACION:' . $data->importId . ':FUENTE:' . $data->sourceOrigin, 0, 100);
        $reading = EquipmentReading::record(
            $data->companyId,
            $data->branchId,
            $data->equipmentId,
            new DateTimeImmutable($data->recordedAt),
            $measurement,
            EquipmentReading::IMPORT,
            $originReference,
            $data->actorUserId,
            false,
            null,
            $data->notes,
        );
        $equipment = $this->database->query(
            'SELECT e.id, e.sucursal_id, e.km_actual, e.horas_actuales, e.estado, t.controla_km, t.controla_horas '
            . 'FROM equipos e INNER JOIN tipos_equipo t ON t.id = e.tipo_equipo_id '
            . 'WHERE e.id = ? AND e.empresa_id = ? AND e.deleted_at IS NULL FOR UPDATE',
            [$data->equipmentId, $data->companyId],
        )->getRowArray();
        if ($equipment === null || (int) $equipment['sucursal_id'] !== $data->branchId || $equipment['estado'] !== 'ACTIVO') {
            throw new DomainException('El equipo dejo de estar activo o cambio de sucursal.');
        }
        if ($measurement->hasKilometers() && ! (bool) $equipment['controla_km']) {
            throw new DomainException('El tipo de equipo no controla kilometraje.');
        }
        if ($measurement->hasHours() && ! (bool) $equipment['controla_horas']) {
            throw new DomainException('El tipo de equipo no controla horometro.');
        }
        if ($measurement->kilometers() !== null && $equipment['km_actual'] !== null && $measurement->kilometers() < (int) $equipment['km_actual']) {
            throw new DomainException('La lectura importada retrocede el kilometraje actual.');
        }
        if ($measurement->hours() !== null && $equipment['horas_actuales'] !== null && (float) $measurement->hours() < (float) $equipment['horas_actuales']) {
            throw new DomainException('La lectura importada retrocede el horometro actual.');
        }
        if ($this->isDuplicate($data)) {
            throw new DomainException('La lectura ya existe.');
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table('lecturas_equipo')->insert([
            'empresa_id' => $reading->companyId(), 'sucursal_id' => $reading->branchId(),
            'equipo_id' => $reading->equipmentId(), 'fecha_lectura' => $reading->recordedAt()->format('Y-m-d H:i:s'),
            'kilometraje' => $reading->measurement()->kilometers(), 'horometro' => $reading->measurement()->hours(),
            'origen' => $reading->origin(),
            'referencia_origen' => $reading->originReference(),
            'referencia_importacion_id' => $data->importId, 'usuario_id' => $data->actorUserId,
            'observaciones' => $reading->notes(), 'anulada' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo persistir la lectura importada.');
        }

        $latest = $this->database->table('lecturas_equipo')->selectMax('fecha_lectura', 'fecha')
            ->where('empresa_id', $data->companyId)->where('equipo_id', $data->equipmentId)
            ->where('anulada', 0)->get()->getRowArray();
        if ($latest !== null && (string) $latest['fecha'] === $data->recordedAt) {
            $update = ['updated_at' => $now, 'updated_by' => $data->actorUserId];
            if ($data->kilometers !== null) {
                $update['km_actual'] = $data->kilometers;
            }
            if ($data->hours !== null) {
                $update['horas_actuales'] = $data->hours;
            }
            $this->database->table('equipos')->where('id', $data->equipmentId)
                ->where('empresa_id', $data->companyId)->where('sucursal_id', $data->branchId)->update($update);
        }
        return $id;
    }
}
