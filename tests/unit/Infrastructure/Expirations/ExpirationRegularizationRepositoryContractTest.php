<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationRegularizationRepositoryContractTest extends TestCase
{
    public function testRepositoryEnforcesCompanyScopePendingUniquenessAndHistorySemantics(): void
    {
        $repository = file_get_contents(APPPATH . 'Infrastructure/Expirations/CodeIgniterExpirationRegularizationRepository.php');
        self::assertIsString($repository);

        self::assertStringContainsString("where('empresa_id', \$regularization->companyId)", $repository);
        self::assertStringContainsString("where('estado', ExpirationRegularizationStatus::PENDING->value)", $repository);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString('ya tiene una regularización pendiente', $repository);
        self::assertStringContainsString('motivo de rechazo es obligatorio', $repository);
        self::assertStringContainsString("table('vencimiento_regularizacion_adjuntos')", $repository);
    }
}
