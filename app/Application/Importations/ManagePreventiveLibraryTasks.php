<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\PreventiveLibraryTaskCatalogGateway;
use DomainException;

final readonly class ManagePreventiveLibraryTasks
{
    public function __construct(
        private PreventiveLibraryTaskCatalogGateway $tasks,
    ) {
    }

    /** @return list<array{id:int,code:string,name:string,active:bool,alreadyLinked:bool}> */
    public function search(ActorContext $actor, string $query, ?int $serviceTypeId): array
    {
        $companyId = $this->editableCompanyId($actor);
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        if ($serviceTypeId !== null) {
            $this->assertOwnedService($companyId, $serviceTypeId);
        }

        return $this->tasks->search($companyId, $query, $serviceTypeId);
    }

    /** @return array{id:int,code:string,name:string,active:bool,order:int,mandatory:bool,observations:?string} */
    public function linkExisting(
        ActorContext $actor,
        int $serviceTypeId,
        int $taskId,
        int $order,
        bool $mandatory,
        ?string $observations,
    ): array {
        $companyId = $this->editableCompanyId($actor);
        $this->assertOwnedService($companyId, $serviceTypeId);
        $this->assertPositive($taskId, 'tarea');
        $this->assertPositive($order, 'orden');
        $observations = $this->normalizeObservations($observations);

        $task = $this->tasks->findTask($taskId);
        if ($task === null) {
            throw new DomainException('La tarea seleccionada no existe.');
        }
        if ($this->tasks->relationExists($serviceTypeId, $taskId)) {
            throw new DomainException('La tarea ya está agregada a este servicio.');
        }
        if (! $this->tasks->orderIsAvailable($serviceTypeId, $order)) {
            throw new DomainException('El orden solicitado ya está ocupado para ese servicio.');
        }

        $this->tasks->link($serviceTypeId, $taskId, [
            'order' => $order,
            'mandatory' => $mandatory,
            'observations' => $observations,
        ]);

        return $task + [
            'order' => $order,
            'mandatory' => $mandatory,
            'observations' => $observations,
        ];
    }

    /** @return array{id:int,code:string,name:string,active:bool,order:int,mandatory:bool,observations:?string} */
    public function createAndLink(
        ActorContext $actor,
        int $serviceTypeId,
        string $code,
        string $name,
        ?string $description,
        ?string $procedure,
        ?int $durationMinutes,
        bool $requiresPart,
        bool $requiresControl,
        bool $requiresPhoto,
        bool $active,
        int $order,
        bool $mandatory,
        ?string $observations,
    ): array {
        $companyId = $this->editableCompanyId($actor);
        $this->assertOwnedService($companyId, $serviceTypeId);
        $this->assertPositive($order, 'orden');

        $code = mb_strtoupper(trim($code));
        $name = trim($name);
        if ($code === '' || mb_strlen($code) > 50) {
            throw new DomainException('Indicá un código de tarea válido de hasta 50 caracteres.');
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainException('Indicá un nombre de tarea válido de hasta 150 caracteres.');
        }
        $description = $this->normalizeText($description, 'La descripción', 2000);
        $procedure = $this->normalizeText($procedure, 'El procedimiento', 2000);
        if ($durationMinutes !== null && $durationMinutes < 0) {
            throw new DomainException('La duración estimada no puede ser negativa.');
        }
        $observations = $this->normalizeObservations($observations);

        $duplicate = $this->tasks->findByNormalizedCode($code);
        if ($duplicate !== null) {
            throw new DomainException(
                'Ya existe la tarea ' . $duplicate['code'] . ' - ' . $duplicate['name'] . '. Buscala y agregala como tarea existente.',
            );
        }
        if (! $this->tasks->orderIsAvailable($serviceTypeId, $order)) {
            throw new DomainException('El orden solicitado ya está ocupado para ese servicio.');
        }

        $taskId = $this->tasks->createAndLink(
            $serviceTypeId,
            [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'procedure' => $procedure,
                'durationMinutes' => $durationMinutes,
                'requiresPart' => $requiresPart,
                'requiresControl' => $requiresControl,
                'requiresPhoto' => $requiresPhoto,
                'active' => $active,
            ],
            [
                'order' => $order,
                'mandatory' => $mandatory,
                'observations' => $observations,
            ],
        );

        return [
            'id' => $taskId,
            'code' => $code,
            'name' => $name,
            'active' => $active,
            'order' => $order,
            'mandatory' => $mandatory,
            'observations' => $observations,
        ];
    }

    private function editableCompanyId(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para modificar la biblioteca preventiva.');
        }
        return $actor->companyId();
    }

    private function assertOwnedService(int $companyId, int $serviceTypeId): void
    {
        $this->assertPositive($serviceTypeId, 'servicio');
        if (! $this->tasks->serviceBelongsToCompany($companyId, $serviceTypeId)) {
            throw new DomainException('El servicio no pertenece a la biblioteca de esta empresa.');
        }
    }

    private function assertPositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException('Indicá un ' . $label . ' válido.');
        }
    }

    private function normalizeObservations(?string $value): ?string
    {
        return $this->normalizeText($value, 'Las observaciones', 500);
    }

    private function normalizeText(?string $value, string $label, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value) > $maxLength) {
            throw new DomainException($label . ' no puede superar ' . $maxLength . ' caracteres.');
        }
        return $value === '' ? null : $value;
    }
}
