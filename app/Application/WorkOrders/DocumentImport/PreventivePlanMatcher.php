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
        $detected = [];
        foreach ($works as $work) {
            if (($work['included'] ?? true) && ($work['classification'] ?? '') === 'preventivo') {
                $detected[] = $this->tokens((string) ($work['description'] ?? ''));
            }
        }
        foreach ($materials as $material) {
            $detected[] = $this->tokens((string) ($material['description'] ?? ''));
        }
        $detected = array_values(array_filter($detected));

        $matched = [];
        foreach ($plans as $plan) {
            $service = $servicesById[(int) ($plan['tipo_servicio_id'] ?? 0)] ?? null;
            if ($service === null) {
                $matched[] = $plan + ['matchScore' => 0, 'matchedItems' => [], 'catalogItems' => []];
                continue;
            }

            $catalogItems = [];
            foreach ($service['tasks'] ?? [] as $task) {
                if (! ($task['active'] ?? true)) continue;
                $catalogItems[] = (string) ($task['name'] ?? '');
                foreach ($task['materials'] ?? [] as $material) {
                    if ($material['active'] ?? true) $catalogItems[] = (string) ($material['description'] ?? '');
                }
            }

            $hits = [];
            foreach ($catalogItems as $catalogItem) {
                $catalogTokens = $this->tokens($catalogItem);
                if ($catalogTokens === []) continue;
                $best = 0.0;
                foreach ($detected as $detectedTokens) {
                    $intersection = count(array_intersect($catalogTokens, $detectedTokens));
                    $union = count(array_unique([...$catalogTokens, ...$detectedTokens]));
                    $best = max($best, $union === 0 ? 0.0 : $intersection / $union);
                }
                if ($best >= 0.25) $hits[] = ['catalog' => $catalogItem, 'score' => round($best, 3)];
            }
            $score = $catalogItems === [] ? 0 : (int) round(min(1, count($hits) / max(1, min(count($catalogItems), count($detected)))) * 100);
            $matched[] = $plan + ['matchScore' => $score, 'matchedItems' => $hits, 'catalogItems' => $catalogItems];
        }

        usort($matched, static fn (array $a, array $b): int => ($b['matchScore'] ?? 0) <=> ($a['matchScore'] ?? 0));
        if ($matched !== [] && ($matched[0]['matchScore'] ?? 0) >= 50) {
            $matched[0]['suggested'] = true;
        }
        return $matched;
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
