<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Autorización de recibos y cobranzas (#536). Cada acción tiene su permiso y
 * toda consulta o comando se acota a la empresa del actor y, cuando el usuario
 * no ve todas las sucursales, a sus sucursales asignadas.
 */
final class CustomerReceiptAccess
{
    public const VIEW = 'clientes.recibos.ver';
    public const REGISTER = 'clientes.recibos.registrar';
    public const CONFIRM = 'clientes.recibos.confirmar';
    public const CANCEL = 'clientes.recibos.anular';
    public const IMPUTE = 'clientes.recibos.imputar';
    public const PRINT = 'clientes.recibos.imprimir';

    public static function view(ActorContext $actor): int
    {
        return self::company($actor, self::VIEW, 'No tenés permiso para consultar los recibos de cobranza.');
    }

    public static function register(ActorContext $actor): int
    {
        return self::company($actor, self::REGISTER, 'No tenés permiso para registrar recibos de cobranza.');
    }

    public static function confirm(ActorContext $actor): int
    {
        return self::company($actor, self::CONFIRM, 'No tenés permiso para confirmar recibos de cobranza.');
    }

    public static function cancel(ActorContext $actor): int
    {
        return self::company($actor, self::CANCEL, 'No tenés permiso para anular recibos de cobranza.');
    }

    public static function impute(ActorContext $actor): int
    {
        return self::company($actor, self::IMPUTE, 'No tenés permiso para imputar pagos a documentos.');
    }

    public static function print(ActorContext $actor): int
    {
        return self::company($actor, self::PRINT, 'No tenés permiso para imprimir recibos de cobranza.');
    }

    /** @return list<int>|null `null` = todas las sucursales de la empresa. */
    public static function branches(ActorContext $actor): ?array
    {
        return CustomerAccountAccess::branches($actor);
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
