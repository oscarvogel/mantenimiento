<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface PreventiveLibraryTaskCatalogGateway
{
    /** @return list<array{id:int,code:string,name:string,active:bool,alreadyLinked:bool}> */
    public function search(int $companyId, string $query, ?int $serviceTypeId, int $limit = 20): array;

    public function serviceBelongsToCompany(int $companyId, int $serviceTypeId): bool;

    /** @return array{id:int,code:string,name:string,active:bool}|null */
    public function findTask(int $taskId): ?array;

    public function relationExists(int $serviceTypeId, int $taskId): bool;

    public function orderIsAvailable(int $serviceTypeId, int $order): bool;

    /** @param array{order:int,mandatory:bool,observations:?string} $relation */
    public function link(int $serviceTypeId, int $taskId, array $relation): void;

    /** @return array{id:int,code:string,name:string}|null */
    public function findByNormalizedCode(string $normalizedCode): ?array;

    /**
     * @param array{code:string,name:string,description:?string,procedure:?string,durationMinutes:?int,requiresPart:bool,requiresControl:bool,requiresPhoto:bool,active:bool} $task
     * @param array{order:int,mandatory:bool,observations:?string} $relation
     * @return int ID de la tarea creada
     */
    public function createAndLink(int $serviceTypeId, array $task, array $relation): int;
}
