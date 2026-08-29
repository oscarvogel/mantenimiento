<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

final class PreventivePlanMatcher
{
    /**
     * @param list<array<string,mixed>> $plans
     * @param list<array<string,mixed>> $services
     * @param list<array<string,mixed>> $works
     * @param list<array<string,mixed>> $materials
     * @return list<array<string,mixed>>
     */
    public function match(array $plans, array $services, array $works, array $materials): array
    {
        $servicesById = [];
        foreach ($services as $service) {
            $servicesById[(int) ($service['id'] ?? 0)] = $service;
        }

        $matched = [];
        foreach ($plans as $plan) {
            $service = $servicesById[(int) ($plan['tipo_servicio_id'] ?? 0)] ?? null;
            if ($service === null) {
                $matched[] = $plan + [
                    'matchScore' => 0,
                    'matchedItems' => [],
                    'catalogItems' => [],
                    'taskMatches' => [],
                    'evidencedTaskCount' => 0,
                    'requiredTaskCount' => 0,
                    'requiredTasksEvidenced' => false,
                ];
                continue;
            }

            $tasks = array_values(array_filter(
                is_array($service['tasks'] ?? null) ? $service['tasks'] : [],
                static fn (array $task): bool => (bool) ($task['active'] ?? true),
            ));
            $taskMatches = $this->matchTasks($tasks, $works, $materials);
            $catalogItems = [];
            $matchedItems = [];
            $evidencedTaskCount = 0;
            $requiredTaskCount = 0;
            $requiredTasksEvidenced = true;

            foreach ($taskMatches as $taskMatch) {
                $catalogItems[] = (string) $taskMatch['taskName'];
                if ($taskMatch['required']) {
                    $requiredTaskCount++;
                    if (! $taskMatch['evidenced']) {
                        $requiredTasksEvidenced = false;
                    }
                }
                if ($taskMatch['evidenced']) {
                    $evidencedTaskCount++;
                    $matchedItems[] = [
                        'catalog' => (string) $taskMatch['taskName'],
                        'score' => (float) $taskMatch['score'],
                        'source' => (string) ($taskMatch['matchedDescription'] ?? ''),
                    ];
                }
            }

            $score = $taskMatches === []
                ? 0
                : (int) round(($evidencedTaskCount / count($taskMatches)) * 100);

            $matched[] = $plan + [
                'matchScore' => $score,
                'matchedItems' => $matchedItems,
                'catalogItems' => $catalogItems,
                'taskMatches' => $taskMatches,
                'evidencedTaskCount' => $evidencedTaskCount,
                'requiredTaskCount' => $requiredTaskCount,
                'requiredTasksEvidenced' => $requiredTaskCount === 0 ? $evidencedTaskCount > 0 : $requiredTasksEvidenced,
            ];
        }

        usort($matched, static fn (array $a, array $b): int => ($b['matchScore'] ?? 0) <=> ($a['matchScore'] ?? 0));
        if ($matched !== [] && ($matched[0]['matchScore'] ?? 0) >= 50 && ($matched[0]['evidencedTaskCount'] ?? 0) > 0) {
            $matched[0]['suggested'] = true;
        }

        return $matched;
    }

    /**
     * Devuelve una coincidencia por tarea del plan. Sólo una tarea con evidencia
     * textual suficiente puede terminar marcada como REALIZADA al confirmar la OT.
     *
     * @param list<array<string,mixed>> $tasks
     * @param list<array<string,mixed>> $works
     * @param list<array<string,mixed>> $materials
     * @return list<array{taskId:int,taskName:string,required:bool,score:float,evidenced:bool,matchedDescription:?string}>
     */
    public function matchTasks(array $tasks, array $works, array $materials): array
    {
        $detected = [];
        foreach ($works as $work) {
            if (! ($work['included'] ?? true) || ($work['classification'] ?? '') !== 'preventivo') {
                continue;
            }
            $description = trim((string) ($work['description'] ?? ''));
            $tokens = $this->tokens($description);
            if ($tokens !== []) {
                $detected[] = ['description' => $description, 'tokens' => $tokens];
            }
        }
        foreach ($materials as $material) {
            $description = trim((string) ($material['description'] ?? ''));
            $tokens = $this->tokens($description);
            if ($tokens !== []) {
                $detected[] = ['description' => $description, 'tokens' => $tokens];
            }
        }

        $matches = [];
        foreach ($tasks as $task) {
            $taskName = trim((string) ($task['name'] ?? $task['description'] ?? ''));
            $candidateTexts = [$taskName];
            foreach (is_array($task['materials'] ?? null) ? $task['materials'] : [] as $material) {
                if ($material['active'] ?? true) {
                    $candidateTexts[] = trim((string) ($material['description'] ?? ''));
                }
            }

            $best = 0.0;
            $bestDescription = null;
            foreach ($candidateTexts as $candidateText) {
                $candidateTokens = $this->tokens($candidateText);
                if ($candidateTokens === []) {
                    continue;
                }
                foreach ($detected as $detectedItem) {
                    $score = $this->similarity($candidateTokens, $detectedItem['tokens']);
                    if ($score > $best) {
                        $best = $score;
                        $bestDescription = $detectedItem['description'];
                    }
                }
            }

            $matches[] = [
                'taskId' => (int) ($task['id'] ?? $task['catalog_task_id'] ?? 0),
                'taskName' => $taskName,
                'required' => (bool) ($task['mandatory'] ?? $task['required'] ?? false),
                'score' => round($best, 3),
                'evidenced' => $best >= 0.25,
                'matchedDescription' => $bestDescription,
            ];
        }

        return $matches;
    }

    /** @param list<string> $a @param list<string> $b */
    private function similarity(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));
        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $value = mb_strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $parts = preg_split('/[^a-z0-9]+/', $value) ?: [];
        $stop = ['de','del','la','el','los','las','un','una','y','para','por','con','en','al','cambiar','cambio','revisar','controlar','hacer','realizar'];
        return array_values(array_unique(array_filter($parts, static fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, $stop, true))));
    }
}
