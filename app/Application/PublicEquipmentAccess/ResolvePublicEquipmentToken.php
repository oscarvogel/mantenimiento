<?php

declare(strict_types=1);

namespace App\Application\PublicEquipmentAccess;

use App\Application\PublicEquipmentAccess\Port\PublicEquipmentTokenRepository;
use DomainException;

final class ResolvePublicEquipmentToken
{
    public function __construct(private readonly PublicEquipmentTokenRepository $repository)
    {
    }

    /** @return array{id:int,empresa_id:int,equipo_id:int,codigo:string,patente:?string,estado:string} */
    public function execute(string $plainToken): array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || strlen($plainToken) < 40 || strlen($plainToken) > 80) {
            throw new DomainException('El acceso QR no es válido.');
        }

        $row = $this->repository->resolveActiveToken(hash('sha256', $plainToken));
        if ($row === null || strtoupper((string) $row['estado']) !== 'ACTIVO') {
            throw new DomainException('El acceso QR no es válido o dejó de estar vigente.');
        }

        return $row;
    }
}
