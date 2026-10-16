<?php

declare(strict_types=1);

use App\Application\Platform\PresentationProfiles\FrontendProfileCatalog;
use App\Presentation\AdministrationPayload;
use PHPUnit\Framework\TestCase;

final class CompanyPresentationProfileAdministrationPayloadTest extends TestCase
{
    public function testOnlyCompiledProfilesAndCurrentAssignmentsAreExposedForVisibleCompanies(): void
    {
        $payload = (new AdministrationPayload())->superadmin([
            'companies' => [
                ['id' => 7, 'razon_social' => 'Cliente Uno', 'nombre_fantasia' => 'Uno', 'estado' => 1],
                ['id' => 8, 'razon_social' => 'Cliente Dos', 'nombre_fantasia' => 'Dos', 'estado' => 1],
            ],
            'companyPresentationProfiles' => [7 => 'vogel'],
            'frontendProfiles' => (new FrontendProfileCatalog())->all(),
        ]);

        self::assertSame('vogel', $payload['companies'][0]['presentationProfile']);
        self::assertSame('base', $payload['companies'][1]['presentationProfile']);
        self::assertSame(['base', 'vogel', 'cliente_piloto'], array_column($payload['presentationProfiles'], 'key'));
        self::assertNotContains('platform', array_column($payload['presentationProfiles'], 'key'));
        self::assertFalse((new FrontendProfileCatalog())->find('cliente_piloto')['shareable']);
        self::assertSame(base_url('superadmin/empresas/7/presentacion'), $payload['companies'][0]['actions']['assignPresentationProfile']);
    }
}
