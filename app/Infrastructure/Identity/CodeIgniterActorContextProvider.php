<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\ActorContext;
use App\Models\UsuarioModel;
use Throwable;

final class CodeIgniterActorContextProvider
{
    public function __construct(private readonly ?UsuarioModel $users = null)
    {
    }

    public function load(int $userId): ?ActorContext
    {
        $users = $this->users ?? new UsuarioModel();
        $user  = $users->findActiveById($userId);

        if ($user === null) {
            return null;
        }

        $superAdmin = (bool) $user['es_superadmin'];
        $companyId  = $user['empresa_id'] === null ? null : (int) $user['empresa_id'];

        if ($superAdmin) {
            if ($companyId !== null) {
                return null;
            }

            return new ActorContext((int) $user['id'], null, true, false, [], [], []);
        }

        if ($companyId === null || ! $users->companyIsActive($companyId)) {
            return null;
        }

        $roles       = $users->roles((int) $user['id']);
        $roleNames   = array_values(array_map(static fn (array $role): string => $role['nombre'], $roles));
        $permissions = $users->permisos((int) $user['id']);
        $branches    = $users->sucursales((int) $user['id'], in_array('Administrador', $roleNames, true));
        $branchIds   = array_values(array_map(static fn (array $branch): int => (int) $branch['id'], $branches));

        try {
            return new ActorContext(
                (int) $user['id'],
                $companyId,
                false,
                in_array('Administrador', $roleNames, true),
                $roleNames,
                $permissions,
                $branchIds,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
