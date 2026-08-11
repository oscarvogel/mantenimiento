<?php

declare(strict_types=1);

use App\Domain\PreventiveMaintenance\PlantillaPreventiva;
use App\Domain\PreventiveMaintenance\ResolverPlantillasCompatibles;
use PHPUnit\Framework\TestCase;

final class ResolverPlantillasCompatiblesTest extends TestCase
{
    public function testSpecificTemplateWinsAndServiceIsSuggestedOnlyOnce(): void
    {
        $resolver = new ResolverPlantillasCompatibles();
        $resolved = $resolver->resolve([
            $this->template(1, 1, 9, null, null, null, 10_000),
            $this->template(2, 2, 9, 3, 'IVECO', null, 15_000),
            $this->template(3, 3, 9, 3, 'IVECO', 'TECTOR', 20_000),
        ], 3, 'Iveco', 'Tector');

        self::assertCount(1, $resolved);
        self::assertSame(3, $resolved[0]->itemId);
        self::assertSame(20_000, $resolved[0]->intervalKm);
    }

    public function testAlreadyAssignedServicesAreNotSuggested(): void
    {
        $resolved = (new ResolverPlantillasCompatibles())->resolve([
            $this->template(1, 1, 9, 3, null, null, 10_000),
            $this->template(2, 1, 10, 3, null, null, 20_000),
        ], 3, null, null, [9]);

        self::assertSame([10], array_map(static fn (PlantillaPreventiva $item): int => $item->serviceTypeId, $resolved));
    }

    private function template(int $itemId, int $templateId, int $serviceId, ?int $typeId, ?string $brand, ?string $model, int $intervalKm): PlantillaPreventiva
    {
        return new PlantillaPreventiva(
            $itemId, $templateId, 'Plantilla', $serviceId, 'Servicio', $typeId, $brand, $model,
            $intervalKm, null, null, 1_000, null, null, 'MEDIA', null,
        );
    }
}
