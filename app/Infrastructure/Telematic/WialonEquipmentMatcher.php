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
        $equipmentByIdentity = [];
        $equipmentIdentities = [];
        foreach ($equipment as $row) {
            $identity = self::identity((string) (($row['patente'] ?? '') ?: ($row['codigo'] ?? $row['code'] ?? '')));
            $equipmentIdentities[(int) $row['id']] = $identity;
            if ($identity !== '') {
                $equipmentByIdentity[$identity][] = $row;
            }
        }

        $unitsByIdentity = [];
        foreach ($units as $unit) {
            foreach (array_unique(array_filter($equipmentIdentities)) as $identity) {
                if (self::nameContainsIdentity((string) $unit['name'], $identity)) {
                    $unitsByIdentity[$identity][] = (string) $unit['id'];
                }
            }
        }

        $matches = [];
        $unmatched = [];
        foreach ($equipment as $row) {
            $identity = $equipmentIdentities[(int) $row['id']];
            $code = (string) ($row['codigo'] ?? $row['code'] ?? '');
            if ($identity === ''
                || count($equipmentByIdentity[$identity] ?? []) !== 1
                || count(array_unique($unitsByIdentity[$identity] ?? [])) !== 1) {
                $unmatched[] = $code;
                continue;
            }

            $matches[] = [
                'equipmentId' => (int) $row['id'],
                'externalId' => (string) $unitsByIdentity[$identity][0],
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
