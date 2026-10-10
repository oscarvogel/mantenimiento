<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

/** Translates Wialon's descriptive unit names into equipment patent matches. */
final class WialonEquipmentMatcher
{
    /**
     * @param list<array<string,mixed>> $equipment
     * @param list<array{id:string,name:string}> $units
     * @return array{
     *   matches:list<array{equipmentId:int,externalId:string,equipmentCode:string}>,
     *   unmatched:list<string>
     * }
     */
    public static function match(array $equipment, array $units): array
    {
        $equipmentByPlate = [];
        $equipmentByCode = [];
        $equipmentIdentities = [];
        foreach ($equipment as $row) {
            $equipmentId = (int) $row['id'];
            $plate = self::identity((string) (($row['patente'] ?? '') ?: ($row['plate'] ?? '')));
            $code = self::identity((string) ($row['codigo'] ?? $row['code'] ?? ''));
            $equipmentIdentities[$equipmentId] = [
                'displayCode' => (string) ($row['codigo'] ?? $row['code'] ?? ''),
                'plate' => $plate,
                'code' => $code,
            ];
            if ($plate !== '') {
                $equipmentByPlate[$plate][] = $equipmentId;
            }
            if ($code !== '') {
                $equipmentByCode[$code][] = $equipmentId;
            }
        }

        $equipmentUnits = [];
        $unitEquipment = [];
        foreach ($units as $unit) {
            $unitId = (string) $unit['id'];
            $matchesByPlate = [];
            foreach ($equipmentByPlate as $plate => $equipmentIds) {
                if (self::nameContainsIdentity((string) $unit['name'], $plate)) {
                    foreach ($equipmentIds as $equipmentId) {
                        $matchesByPlate[$equipmentId] = true;
                    }
                }
            }

            // Descriptive matching is only safe for actual plates. Internal codes
            // may be model numbers (for example, "360" in "SCANIA 360 AB499OK").
            $candidateIds = array_keys($matchesByPlate);
            if ($candidateIds === []) {
                $exactCode = self::identity((string) $unit['name']);
                $candidateIds = count($equipmentByCode[$exactCode] ?? []) === 1
                    ? $equipmentByCode[$exactCode]
                    : [];
            }

            // A Wialon name containing more than one fleet plate is ambiguous;
            // do not let the equipment iteration order choose an arbitrary link.
            if (count($candidateIds) !== 1) {
                continue;
            }
            $equipmentId = (int) $candidateIds[0];
            $equipmentUnits[$equipmentId][] = $unitId;
            $unitEquipment[$unitId][] = $equipmentId;
        }

        $matches = [];
        $unmatched = [];
        foreach ($equipment as $row) {
            $equipmentId = (int) $row['id'];
            $identity = $equipmentIdentities[$equipmentId];
            $code = $identity['displayCode'];
            $plateIsUnique = $identity['plate'] === '' || count($equipmentByPlate[$identity['plate']] ?? []) === 1;
            if ((! $plateIsUnique)
                || ($identity['plate'] === '' && $identity['code'] === '')
                || count(array_unique($equipmentUnits[$equipmentId] ?? [])) !== 1) {
                $unmatched[] = $code;
                continue;
            }

            $unitId = $equipmentUnits[$equipmentId][0];
            if (count(array_unique($unitEquipment[$unitId] ?? [])) !== 1) {
                $unmatched[] = $code;
                continue;
            }

            $matches[] = [
                'equipmentId' => $equipmentId,
                'externalId' => (string) $unitId,
                'equipmentCode' => $code,
            ];
        }

        return ['matches' => $matches, 'unmatched' => $unmatched];
    }

    private static function identity(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', self::asciiUpper($value)) ?? '';
    }

    private static function nameContainsIdentity(string $name, string $identity): bool
    {
        $tokens = preg_split('/[^A-Z0-9]+/', self::asciiUpper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $start => $_token) {
            $candidate = '';
            for ($index = $start, $count = count($tokens); $index < $count; $index++) {
                $candidate .= $tokens[$index];
                if ($candidate === $identity) {
                    return true;
                }
                if (strlen($candidate) >= strlen($identity)) {
                    break;
                }
            }
        }

        return false;
    }

    private static function asciiUpper(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtoupper($transliterated === false ? $value : $transliterated);
    }
}
