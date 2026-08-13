<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface PreventiveLibraryTaskGateway
{
    /**
     * Actualiza una tarea global y su relación con el tipo de servicio indicado.
     * Valida que la relación exista dentro del scope de la empresa.
     *
     * @param array{
     *     name: string,
     *     description: string|null,
     *     procedure: string|null,
     *     durationMinutes: int|null,
     *     requiresPart: bool,
     *     requiresControl: bool,
     *     requiresPhoto: bool,
     *     active: bool,
     *     order: int,
     *     mandatory: bool,
     *     observations: string|null,
     * } $fields
     */
    public function update(int $companyId, int $taskId, int $serviceTypeId, array $fields): void;
}