<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\Identity\ActorContext;

final class AdministrationPayload
{
    /** @param array<string,mixed> $source */
    public function superadmin(array $source): array
    {
        $companies = $source['companies'] ?? [];
        $companiesTotal = (int) ($source['companiesTotal'] ?? count($companies));
        $usersTotal = (int) ($source['usersTotal'] ?? count($source['users'] ?? []));
        $base = base_url('superadmin');
        $sharedQuery = [
            'companies_page' => (int) ($source['companiesPage'] ?? 1),
            'companies_per_page' => (int) ($source['companiesPerPage'] ?? 10),
            'users_page' => (int) ($source['usersPage'] ?? 1),
            'users_per_page' => (int) ($source['usersPerPage'] ?? 10),
        ];

        return [
            'permissions' => [
                'companiesEdit' => true,
                'createCompanyAdministrators' => true,
                'assignCompanies' => true,
                'assignRoles' => true,
            ],
            'metrics' => [
                'companiesTotal' => $companiesTotal,
                'companiesActive' => (int) ($source['companiesActive'] ?? count(array_filter($companies, fn (array $row): bool => (int) $row['estado'] === 1))),
                'usersTotal' => $usersTotal,
            ],
            'actions' => [
                'createCompany' => base_url('superadmin/empresas'),
                'createCompanyAdministrator' => base_url('superadmin/administradores'),
            ],
            'oldInput' => $this->old([
                'razon_social', 'nombre_fantasia', 'cuit', 'email', 'telefono',
                'admin_empresa_id', 'admin_nombre', 'admin_email', 'admin_motivo',
            ]),
            'companies' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'razonSocial' => $row['razon_social'],
                'nombreFantasia' => $row['nombre_fantasia'] ?? '',
                'displayName' => $row['nombre_fantasia'] ?: $row['razon_social'],
                'cuit' => $row['cuit'] ?? '', 'email' => $row['email'] ?? '', 'telefono' => $row['telefono'] ?? '',
                'active' => (int) $row['estado'] === 1,
                'actions' => ['update' => base_url('superadmin/empresas/' . $row['id'])],
            ], $companies),
            'companiesPagination' => $this->pagination(
                $base, (int) ($source['companiesPage'] ?? 1), (int) ($source['companiesPerPage'] ?? 10),
                $companiesTotal, 'companies_page', 'companies_per_page', $sharedQuery,
            ),
            'assignableCompanies' => array_values(array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => $row['nombre_fantasia'] ?: $row['razon_social'],
            ], $source['assignableCompanies'] ?? array_filter($companies, fn (array $row): bool => (int) $row['estado'] === 1))),
            'roles' => $this->roles($source['roles'] ?? []),
            'users' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => $row['nombre'], 'email' => $row['email'],
                'active' => (int) $row['activo'] === 1, 'isSuperAdmin' => (int) $row['es_superadmin'] === 1,
                'companyId' => $row['empresa_id'] === null ? '' : (int) $row['empresa_id'],
                'companyName' => $row['empresa_nombre'] ?? 'Sin empresa', 'roles' => $this->roles($row['roles'] ?? []),
                'assignedRoleIds' => array_map('intval', array_column($row['roles'] ?? [], 'id')),
                'actions' => [
                    'assignCompany' => base_url('superadmin/usuarios/' . $row['id'] . '/empresa'),
                    'assignRoles' => base_url('superadmin/usuarios/' . $row['id'] . '/roles'),
                ],
            ], $source['users'] ?? []),
            'usersPagination' => $this->pagination(
                $base, (int) ($source['usersPage'] ?? 1), (int) ($source['usersPerPage'] ?? 10),
                $usersTotal, 'users_page', 'users_per_page', $sharedQuery,
            ),
        ];
    }

    /** @param array<string,mixed> $source */
    public function branches(array $source, ActorContext $actor): array
    {
        $branches = $source['branches'] ?? [];

        return [
            'company' => ['id' => (int) $source['company']['id'], 'name' => $source['company']['nombre_fantasia'] ?: $source['company']['razon_social']],
            'permissions' => ['edit' => $actor->hasPermission('sucursales.editar')],
            'metrics' => [
                'total' => (int) ($source['branchesTotal'] ?? count($branches)),
                'active' => (int) ($source['branchesActive'] ?? count(array_filter($branches, fn (array $row): bool => (int) $row['estado'] === 1))),
                'inactive' => (int) ($source['branchesTotal'] ?? count($branches)) - (int) ($source['branchesActive'] ?? count(array_filter($branches, fn (array $row): bool => (int) $row['estado'] === 1))),
            ],
            'actions' => ['create' => base_url('administracion/sucursales')],
            'oldInput' => [
                'codigo' => old('codigo') ?? '', 'nombre' => old('nombre') ?? '', 'direccion' => old('direccion') ?? '',
                'emailAlertas' => old('email_alertas') ?? '',
            ],
            'branches' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'code' => $row['codigo'], 'name' => $row['nombre'],
                'address' => $row['direccion'] ?? '', 'alertEmail' => $row['email_alertas'] ?? '',
                'active' => (int) $row['estado'] === 1,
                'actions' => ['update' => base_url('administracion/sucursales/' . $row['id'])],
            ], $branches),
            'pagination' => $this->pagination(
                base_url('administracion/sucursales'), (int) ($source['branchesPage'] ?? 1),
                (int) ($source['branchesPerPage'] ?? 10), (int) ($source['branchesTotal'] ?? count($branches)),
                'page', 'per_page', [],
            ),
        ];
    }

    /** @param array<string,mixed> $source */
    public function users(array $source, ActorContext $actor): array
    {
        $users = $source['users'] ?? [];
        $canEditAccounts = $actor->hasPermission('usuarios.editar');
        $canEditAccess = $canEditAccounts && $actor->hasPermission('roles.editar');

        return [
            'company' => ['id' => (int) $source['company']['id'], 'name' => $source['company']['nombre_fantasia'] ?: $source['company']['razon_social']],
            'permissions' => [
                'create' => $canEditAccess, 'editAccounts' => $canEditAccounts,
                'editAccess' => $canEditAccess, 'resetPasswords' => $canEditAccounts,
            ],
            'metrics' => [
                'total' => (int) ($source['usersTotal'] ?? count($users)),
                'active' => (int) ($source['usersActive'] ?? count(array_filter($users, fn (array $row): bool => (int) $row['activo'] === 1))),
                'inactive' => (int) ($source['usersTotal'] ?? count($users)) - (int) ($source['usersActive'] ?? count(array_filter($users, fn (array $row): bool => (int) $row['activo'] === 1))),
            ],
            'actions' => ['create' => base_url('administracion/usuarios')],
            'oldInput' => [
                'nombre' => old('nombre') ?? '', 'email' => old('email') ?? '', 'motivo' => old('motivo') ?? '',
                'roleIds' => array_map('intval', (array) old('roles', [])),
                'branchIds' => array_map('intval', (array) old('sucursales', [])),
            ],
            'roles' => $this->roles($source['roles'] ?? []),
            'assignableBranches' => array_values(array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'code' => $row['codigo'], 'name' => $row['nombre'],
            ], array_filter($source['branches'] ?? [], fn (array $row): bool => (int) $row['estado'] === 1))),
            'users' => array_map(function (array $row) use ($actor): array {
                $isSelf = (int) $row['id'] === $actor->userId();

                return [
                    'id' => (int) $row['id'], 'name' => $row['nombre'], 'email' => $row['email'],
                    'active' => (int) $row['activo'] === 1, 'isSelf' => $isSelf,
                    'canDeactivate' => ! $isSelf, 'allCompanyBranches' => (bool) $row['all_company_branches'],
                    'lastAccess' => $row['ultimo_acceso'] ?? '', 'roles' => $this->roles($row['roles'] ?? []),
                    'branches' => array_map(fn (array $branch): array => [
                        'id' => (int) $branch['id'], 'code' => $branch['codigo'], 'name' => $branch['nombre'],
                        'active' => (int) $branch['estado'] === 1,
                    ], $row['branches'] ?? []),
                    'assignedRoleIds' => array_map('intval', array_column($row['roles'] ?? [], 'id')),
                    'assignedBranchIds' => array_map('intval', array_column($row['branches'] ?? [], 'id')),
                    'actions' => [
                        'update' => base_url('administracion/usuarios/' . $row['id']),
                        'assignAccess' => base_url('administracion/usuarios/' . $row['id'] . '/acceso'),
                        'resetPassword' => base_url('administracion/usuarios/' . $row['id'] . '/password'),
                    ],
                ];
            }, $users),
            'pagination' => $this->pagination(
                base_url('administracion/usuarios'), (int) ($source['usersPage'] ?? 1),
                (int) ($source['usersPerPage'] ?? 10), (int) ($source['usersTotal'] ?? count($users)),
                'page', 'per_page', [],
            ),
        ];
    }

    /** @param array<string,int> $query */
    private function pagination(string $base, int $page, int $perPage, int $total, string $pageKey, string $perPageKey, array $query): array
    {
        $totalPages = max(1, (int) ceil($total / $perPage));
        $url = static function (int $target) use ($base, $query, $pageKey, $perPageKey, $perPage): string {
            $parameters = $query;
            $parameters[$pageKey] = $target;
            $parameters[$perPageKey] = $perPage;

            return $base . '?' . http_build_query($parameters);
        };

        return [
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
            'perPage' => $perPage, 'pageKey' => $pageKey, 'perPageKey' => $perPageKey,
            'previousUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $totalPages ? $url($page + 1) : null,
        ];
    }

    /** @param list<array<string,mixed>> $roles */
    private function roles(array $roles): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => $row['nombre'], 'description' => $row['descripcion'] ?? '',
        ], $roles);
    }

    /** @param list<string> $fields */
    private function old(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = old($field) ?? '';
        }

        return $result;
    }
}
