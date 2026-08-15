<?php

declare(strict_types=1);

namespace App\Presentation;

final class PreventiveLibraryPayload
{
    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function items(array $items, string $base): array
    {
        return array_map(static function (array $item) use ($base): array {
            $serviceTypeId = (int) $item['serviceTypeId'];
            $tasks = array_map(static function (array $task) use ($base, $serviceTypeId): array {
                $taskId = (int) $task['id'];

                return array_merge($task, [
                    'updateUrl' => $base . '/biblioteca/tareas/' . $taskId,
                    'detachUrl' => $base . '/biblioteca/tareas/' . $taskId . '/desvincular',
                    'serviceTypeId' => $serviceTypeId,
                ]);
            }, $item['tasks'] ?? []);

            return array_merge($item, [
                'updateUrl' => $base . '/biblioteca/items/' . (int) $item['id'],
                'tasks' => $tasks,
            ]);
        }, $items);
    }
}
