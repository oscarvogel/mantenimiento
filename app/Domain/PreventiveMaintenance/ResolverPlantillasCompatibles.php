<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

final class ResolverPlantillasCompatibles
{
    /**
     * @param list<PlantillaPreventiva> $candidates
     * @param list<int> $assignedServiceTypeIds
     * @return list<PlantillaPreventiva>
     */
    public function resolve(
        array $candidates,
        int $equipmentTypeId,
        ?string $brand,
        ?string $model,
        array $assignedServiceTypeIds = [],
    ): array {
        $compatible = array_values(array_filter(
            $candidates,
            static fn (PlantillaPreventiva $candidate): bool => $candidate->isCompatibleWith($equipmentTypeId, $brand, $model),
        ));

        usort($compatible, static fn (PlantillaPreventiva $left, PlantillaPreventiva $right): int =>
            [$right->specificity(), $left->templateId, $left->itemId]
            <=> [$left->specificity(), $right->templateId, $right->itemId]);

        $resolved = [];
        $seen = array_fill_keys($assignedServiceTypeIds, true);
        foreach ($compatible as $candidate) {
            if (isset($seen[$candidate->serviceTypeId])) {
                continue;
            }

            $seen[$candidate->serviceTypeId] = true;
            $resolved[] = $candidate;
        }

        return $resolved;
    }
}
