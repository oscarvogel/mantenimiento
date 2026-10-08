<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #454: filtro por tipo de documentación en Próximos vencimientos.
 *
 * Protege el contrato entre controlador, read model y frontend:
 * - el parámetro HTTP es independiente del filtro de sujeto;
 * - el read model aplica el tipo dentro del scope de empresa existente;
 * - la UI expone ambos filtros con nombres distintos.
 */
final class ExpirationDocumentationFilterContractTest extends TestCase
{
    public function testControllerAcceptsAndPersistsDocumentationTypeFilter(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/Expirations.php');

        self::assertStringContainsString("getGet('tipo_vencimiento_id')", $controller);
        self::assertStringContainsString("'expirationTypeId' =>", $controller);
        self::assertStringContainsString("'expirationTypes' =>", $controller);
        self::assertStringContainsString('$readModel->catalog($companyId)', $controller);
    }

    public function testReadModelFiltersByExpirationTypeWithoutDroppingCompanyScope(): void
    {
        $readModel = (string) file_get_contents(APPPATH . 'Infrastructure/Expirations/CodeIgniterExpirationReadModel.php');

        self::assertStringContainsString("->where('v.empresa_id', \$companyId)", $readModel);
        self::assertStringContainsString("->where('v.tipo_vencimiento_id', \$expirationTypeId)", $readModel);
        self::assertStringContainsString("->where('t.activo', 1)", $readModel);
        self::assertStringContainsString("->where('t.deleted_at', null)", $readModel);
    }

    public function testFrontendSeparatesSubjectAndDocumentationFilters(): void
    {
        $page = (string) file_get_contents(ROOTPATH . 'frontend/src/pages/operations/ExpirationsIndexPage.vue');

        self::assertStringContainsString('label="Sujeto"', $page);
        self::assertStringContainsString('name="tipo"', $page);
        self::assertStringContainsString('label="Documentación"', $page);
        self::assertStringContainsString('name="tipo_vencimiento_id"', $page);
        self::assertStringContainsString('Todos los documentos', $page);
    }
}
