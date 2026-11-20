<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Autorización de la cuenta corriente de clientes. La cuenta es de la empresa:
 * el Superadministrador global no opera en ella y cada consulta se acota a la
 * empresa del actor. El control de cierre comercial respeta además las
 * sucursales asignadas, porque lista tramos de viajes.
 */
final class CustomerAccountAccess
{
    public const VIEW = 'clientes.cc.ver';
    public const FORMALIZE = 'clientes.cc.formalizar';

    public static function view(ActorContext $actor): int
    {
        return self::company($actor, self::VIEW, 'No tenés permiso para consultar la cuenta corriente de clientes.');
    }

    public static function formalize(ActorContext $actor): int
    {
        return self::company($actor, self::FORMALIZE, 'No tenés permiso para formalizar cobros de clientes.');
    }

    /** @return list<int>|null `null` = todas las sucursales de la empresa. */
    public static function branches(ActorContext $actor): ?array
    {
        if ($actor->hasAllCompanyBranches()) {
            return null;
        }
        $branchIds = $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('No tenés sucursales asignadas.');
        }

        return $branchIds;
    }

    private static function company(ActorContext $actor, string $permission, string $message): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission($permission)) {
            throw new DomainException($message);
        }

        return $companyId;
    }
}
