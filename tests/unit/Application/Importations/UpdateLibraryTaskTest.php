<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\PreventiveLibraryTaskGateway;
use App\Application\Importations\UpdateLibraryTask;
use App\Application\Importations\UpdateLibraryTaskCommand;
use PHPUnit\Framework\TestCase;

final class UpdateLibraryTaskTest extends TestCase
{
    public function testRejectsActorWithoutImportLoadPermission(): void
    {
        $actor = new ActorContext(9, 5, false, true, ['Consulta'], ['importaciones.ver'], []);
        $useCase = new UpdateLibraryTask(new RecordingLibraryTaskGateway());

        $this->expectException(DomainException::class);
        $useCase->execute($actor, $this->command());
    }

    public function testRejectsSuperAdminWithoutCompanyScope(): void
    {
        $actor = new ActorContext(1, null, true, false, ['Superadministrador'], ['importaciones.cargar'], []);
        $useCase = new UpdateLibraryTask(new RecordingLibraryTaskGateway());

        $this->expectException(DomainException::class);
        $useCase->execute($actor, $this->command());
    }

    public function testDelegatesResolvedFieldsToGatewayScopedByCompany(): void
    {
        $actor = new ActorContext(9, 5, false, true, ['Responsable'], ['importaciones.cargar', 'importaciones.ver'], []);
        $gateway = new RecordingLibraryTaskGateway();
        $useCase = new UpdateLibraryTask($gateway);

        $useCase->execute($actor, new UpdateLibraryTaskCommand(
            taskId: 2,
            serviceTypeId: 3,
            name: 'Cambiar aceite de motor',
            description: 'Aceite y filtros',
            procedure: 'Drenar y reemplazar',
            durationMinutes: 45,
            requiresPart: true,
            requiresControl: true,
            requiresPhoto: false,
            active: true,
            order: 1,
            mandatory: true,
            observations: null,
        ));

        self::assertSame(5, $gateway->companyId);
        self::assertSame(2, $gateway->taskId);
        self::assertSame(3, $gateway->serviceTypeId);
        self::assertSame('Cambiar aceite de motor', $gateway->fields['name']);
        self::assertSame('Drenar y reemplazar', $gateway->fields['procedure']);
        self::assertSame(45, $gateway->fields['durationMinutes']);
        self::assertTrue($gateway->fields['requiresPart']);
        self::assertTrue($gateway->fields['mandatory']);
        self::assertTrue($gateway->fields['active']);
        self::assertNull($gateway->fields['observations']);
    }

    private function command(): UpdateLibraryTaskCommand
    {
        return new UpdateLibraryTaskCommand(
            taskId: 2,
            serviceTypeId: 3,
            name: 'Cambiar aceite de motor',
            description: null,
            procedure: null,
            durationMinutes: null,
            requiresPart: false,
            requiresControl: true,
            requiresPhoto: false,
            active: true,
            order: 1,
            mandatory: true,
            observations: null,
        );
    }
}

final class RecordingLibraryTaskGateway implements PreventiveLibraryTaskGateway
{
    public ?int $companyId = null;
    public ?int $taskId = null;
    public ?int $serviceTypeId = null;
    public array $fields = [];

    public function update(int $companyId, int $taskId, int $serviceTypeId, array $fields): void
    {
        $this->companyId = $companyId;
        $this->taskId = $taskId;
        $this->serviceTypeId = $serviceTypeId;
        $this->fields = $fields;
    }
}