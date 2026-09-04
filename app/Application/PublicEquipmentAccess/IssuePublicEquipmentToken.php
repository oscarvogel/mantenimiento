<?php

declare(strict_types=1);

namespace App\Application\PublicEquipmentAccess;

use App\Application\Identity\ActorContext;
use App\Application\PublicEquipmentAccess\Port\PublicEquipmentTokenRepository;
use DateTimeImmutable;
use DomainException;

final class IssuePublicEquipmentToken
{
    public function __construct(private readonly PublicEquipmentTokenRepository $repository)
    {
    }

    public function execute(ActorContext $actor, int $equipmentId, DateTimeImmutable $now): PublicEquipmentToken
    {
        if ($equipmentId <= 0 || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('No se puede emitir acceso QR para este equipo.');
        }
        if (! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para regenerar el acceso QR.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);

        if (! $this->repository->replaceActiveToken(
            $actor->companyId(),
            $equipmentId,
            $hash,
            $token,
            $actor->userId(),
            $now->format('Y-m-d H:i:s'),
        )) {
            throw new DomainException('El equipo no existe, está dado de baja o no pertenece a tu empresa.');
        }

        return new PublicEquipmentToken($equipmentId, $token);
    }
}
