<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Permisos del contexto y resolución del scope del actor.
 *
 * Neumáticos se opera dentro de una empresa: el Superadministrador global no
 * tiene empresa y no opera neumáticos. El mensaje de "no existe" y el de "no
 * autorizado" son el mismo, para no revelar recursos de otra empresa.
 */
final class NeumaticAccess
{
    public const VIEW = 'neumaticos.ver';
    public const OPERATE = 'neumaticos.operar';

    public const NOT_FOUND = 'El neumático solicitado no existe o no está autorizado.';

    private function __construct(
        public readonly int $companyId,
        /** @var list<int>|null null = todas las sucursales de la empresa */
        public readonly ?array $branchIds,
    ) {
    }

    public static function require(ActorContext $actor, string $permission, string $deniedMessage): self
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! self::allows($actor, $permission)) {
            throw new DomainException($deniedMessage);
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException($deniedMessage);
        }

        return new self($companyId, $branchIds);
    }

    public function coversBranch(int $branchId): bool
    {
        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    /**
     * Quien opera también puede ver.
     */
    private static function allows(ActorContext $actor, string $permission): bool
    {
        if ($actor->hasPermission($permission)) {
            return true;
        }

        return $permission === self::VIEW && $actor->hasPermission(self::OPERATE);
    }
}
