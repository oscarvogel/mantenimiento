<?php

declare(strict_types=1);

namespace App\Application\Telematic;

final class TelemetryUnitLinkRetention
{
    /**
     * @param list<array{id:string,name:string}> $availableUnits
     * @param list<array{unidad_externa:string,activo:int|string}> $links
     * @return list<string>
     */
    public static function obsoleteUnitIds(array $availableUnits, array $links): array
    {
        $availableIds = array_fill_keys(array_column($availableUnits, 'id'), true);
        $obsolete = [];
        foreach ($links as $link) {
            $unitId = (string) $link['unidad_externa'];
            if ((int) $link['activo'] === 1 && ! isset($availableIds[$unitId])) {
                $obsolete[$unitId] = $unitId;
            }
        }

        return array_values($obsolete);
    }
}
