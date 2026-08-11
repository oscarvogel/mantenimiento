<?php

declare(strict_types=1);

use App\Presentation\AdministrationPayload;
use PHPUnit\Framework\TestCase;

final class AdministrationPayloadPaginationTest extends TestCase
{
    public function testSuperadminPagersAreIndependentAndAssignmentCatalogIsComplete(): void
    {
        $payload = (new AdministrationPayload())->superadmin([
            'companies' => [[
                'id' => 11, 'razon_social' => 'Empresa paginada', 'nombre_fantasia' => null,
                'cuit' => null, 'email' => null, 'telefono' => null, 'estado' => 1,
            ]],
            'assignableCompanies' => [
                ['id' => 11, 'razon_social' => 'Empresa paginada', 'nombre_fantasia' => null],
                ['id' => 99, 'razon_social' => 'Empresa fuera de pagina', 'nombre_fantasia' => 'Completa'],
            ],
            'companiesTotal' => 31,
            'companiesActive' => 30,
            'companiesPage' => 2,
            'companiesPerPage' => 5,
            'users' => [],
            'usersTotal' => 70,
            'usersPage' => 2,
            'usersPerPage' => 25,
            'roles' => [],
        ]);

        self::assertCount(1, $payload['companies']);
        self::assertCount(2, $payload['assignableCompanies']);
        self::assertSame(31, $payload['metrics']['companiesTotal']);
        self::assertSame('companies_per_page', $payload['companiesPagination']['perPageKey']);
        self::assertSame('users_per_page', $payload['usersPagination']['perPageKey']);

        parse_str((string) parse_url($payload['companiesPagination']['nextUrl'], PHP_URL_QUERY), $companiesQuery);
        self::assertSame('3', $companiesQuery['companies_page']);
        self::assertSame('2', $companiesQuery['users_page']);
        self::assertSame('25', $companiesQuery['users_per_page']);

        parse_str((string) parse_url($payload['usersPagination']['nextUrl'], PHP_URL_QUERY), $usersQuery);
        self::assertSame('3', $usersQuery['users_page']);
        self::assertSame('2', $usersQuery['companies_page']);
        self::assertSame('5', $usersQuery['companies_per_page']);
    }
}
