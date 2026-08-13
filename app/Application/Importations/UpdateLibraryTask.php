<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\PreventiveLibraryTaskGateway;
use DomainException;

final readonly class UpdateLibraryTask
{
    public function __construct(
        private PreventiveLibraryTaskGateway $tasks,
    ) {
    }

    public function execute(
        ActorContext $actor,
        UpdateLibraryTaskCommand $command,
    ): void {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para modificar la biblioteca preventiva.');
        }

        $this->tasks->update(
            $actor->companyId(),
            $command->taskId,
            $command->serviceTypeId,
            [
                'name' => $command->name,
                'description' => $command->description,
                'procedure' => $command->procedure,
                'durationMinutes' => $command->durationMinutes,
                'requiresPart' => $command->requiresPart,
                'requiresControl' => $command->requiresControl,
                'requiresPhoto' => $command->requiresPhoto,
                'active' => $command->active,
                'order' => $command->order,
                'mandatory' => $command->mandatory,
                'observations' => $command->observations,
            ],
        );
    }
}