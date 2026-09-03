<?php

declare(strict_types=1);

namespace App\Application\DriverPortal;

use App\Application\DriverPortal\Port\DriverPortalReadModel;
use App\Application\Identity\ActorContext;
use DateInterval;
use DateTimeImmutable;
use DomainException;

final class GetDriverPortal
{
    public function __construct(private readonly DriverPortalReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $equipmentId, DateTimeImmutable $now): array
    {
        if ($equipmentId <= 0 || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El equipo indicado no es válido para este acceso.');
        }
        if (! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar equipos.');
        }

        $row = $this->readModel->findScoped(
            $actor->companyId(),
            $equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
        if ($row === null) {
            throw new DomainException('El equipo no existe o no está autorizado para el usuario.');
        }

        $lastReadingAt = null;
        if (($row['ultima_lectura_fecha'] ?? null) !== null) {
            $lastReadingAt = new DateTimeImmutable((string) $row['ultima_lectura_fecha']);
        }
        $readingPending = $lastReadingAt === null
            || $lastReadingAt <= $now->sub(new DateInterval('P7D'));

        return [
            'equipment' => [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'plate' => $row['patente'] === null ? null : (string) $row['patente'],
                'typeName' => (string) $row['tipo_nombre'],
                'branchName' => (string) $row['sucursal_nombre'],
                'brandName' => $row['marca_nombre'] === null ? null : (string) $row['marca_nombre'],
                'modelName' => $row['modelo_nombre'] === null ? null : (string) $row['modelo_nombre'],
                'status' => (string) $row['estado'],
                'controlsKm' => (int) $row['controla_km'] === 1,
                'controlsHours' => (int) $row['controla_horas'] === 1,
                'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
                'currentHours' => $row['horas_actuales'] === null ? null : (string) $row['horas_actuales'],
            ],
            'lastReading' => $lastReadingAt === null ? null : [
                'at' => $lastReadingAt->format(DATE_ATOM),
                'kilometers' => $row['ultima_lectura_km'] === null ? null : (int) $row['ultima_lectura_km'],
                'hours' => $row['ultima_lectura_horas'] === null ? null : (string) $row['ultima_lectura_horas'],
                'userName' => $row['ultima_lectura_usuario'] === null ? null : (string) $row['ultima_lectura_usuario'],
            ],
            'readingPending' => $readingPending,
            'can' => [
                'registerReading' => $actor->hasPermission('lecturas.cargar'),
                'reportIncident' => $actor->hasPermission('solicitudes.crear'),
                'viewOrders' => $actor->hasPermission('ordenes.ver'),
                'editOrders' => $actor->hasPermission('ordenes.editar'),
                'viewPlans' => $actor->hasPermission('planes.ver'),
            ],
        ];
    }
}
