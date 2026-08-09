<?php

declare(strict_types=1);

namespace App\Infrastructure\AppShell;

use App\Application\AppShell\Port\AppShellReadModel;
use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final readonly class CodeIgniterAppShellReadModel implements AppShellReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function fetch(ActorContext $actor): array
    {
        $user = $this->database->table('usuarios')
            ->select('id, nombre, email')
            ->where('id', $actor->userId())
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($user === null) {
            throw new DomainException('El usuario autenticado ya no está disponible.');
        }

        if ($actor->isSuperAdmin()) {
            return ['user' => $user, 'company' => null, 'branches' => []];
        }

        $companyId = (int) $actor->companyId();
        $company = $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        $branches = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre');

        if (! $actor->hasAllCompanyBranches()) {
            $branchIds = $actor->branchIds();
            if ($branchIds === []) {
                $branches->where('1 = 0', null, false);
            } else {
                $branches->whereIn('id', $branchIds);
            }
        }

        return [
            'user' => $user,
            'company' => $company,
            'branches' => $branches->get()->getResultArray(),
        ];
    }
}
