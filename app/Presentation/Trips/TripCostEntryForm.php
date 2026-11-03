<?php

declare(strict_types=1);

namespace App\Presentation\Trips;

final class TripCostEntryForm
{
    public const FUEL = 'fuel';
    public const EXPENSE = 'expense';
    public const CASH = 'cash';

    private const FIELDS = [
        self::FUEL => [
            'fecha', 'litros', 'importe', 'moneda', 'cotizacion', 'kilometraje',
            'estacion', 'comprobante', 'observaciones', 'tramo_id',
        ],
        self::EXPENSE => [
            'fecha', 'importe', 'categoria_id', 'moneda', 'cotizacion', 'medio_pago_id',
            'tramo_id', 'pagado_por', 'comprobante', 'observaciones',
        ],
        self::CASH => [
            'tipo', 'fecha', 'importe', 'moneda', 'cotizacion', 'observaciones',
        ],
    ];

    private const REPEATABLE = [
        self::FUEL => ['fecha', 'moneda', 'tramo_id', 'estacion'],
        self::EXPENSE => ['fecha', 'moneda', 'tramo_id', 'categoria_id', 'pagado_por', 'medio_pago_id'],
        self::CASH => ['fecha', 'moneda', 'tipo'],
    ];

    private const CURRENCY_FIELDS = ['moneda', 'cotizacion'];

    public static function normalizeType(mixed $value): ?string
    {
        $type = strtolower(trim((string) ($value ?? '')));

        return in_array($type, [self::FUEL, self::EXPENSE, self::CASH], true) ? $type : null;
    }

    /** @return list<string> */
    public static function fields(string $type): array
    {
        return self::FIELDS[$type] ?? [];
    }

    /** @return list<string> */
    public static function repeatable(string $type): array
    {
        return self::REPEATABLE[$type] ?? [];
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    public static function valuesFromPost(string $type, array $post): array
    {
        $values = [];
        foreach (self::fields($type) as $field) {
            if (array_key_exists($field, $post) && is_scalar($post[$field])) {
                $values[$field] = mb_substr(trim((string) $post[$field]), 0, 500);
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    public static function repeatQuery(string $type, array $post): array
    {
        $selected = array_map('strval', is_array($post['repetir'] ?? null) ? $post['repetir'] : []);
        $params = [];
        foreach (self::repeatable($type) as $field) {
            if (! in_array($field, $selected, true)) {
                continue;
            }
            foreach ($field === 'moneda' ? self::CURRENCY_FIELDS : [$field] as $name) {
                if (array_key_exists($name, $post) && is_scalar($post[$name])) {
                    $params['repetir_' . $name] = mb_substr(trim((string) $post[$name]), 0, 500);
                }
            }
        }

        return $params;
    }

    /**
     * @param array<string,mixed> $get
     * @return array<string,string>
     */
    public static function repeatValues(string $type, array $get): array
    {
        $values = [];
        foreach (self::repeatable($type) as $field) {
            foreach ($field === 'moneda' ? self::CURRENCY_FIELDS : [$field] as $name) {
                $key = 'repetir_' . $name;
                if (array_key_exists($key, $get) && is_scalar($get[$key])) {
                    $values[$name] = mb_substr(trim((string) $get[$key]), 0, 500);
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $oldPost
     * @param array<string,mixed> $get
     * @return array{type:string,values:array<string,string>,repeat:array<string,string>,editing_id?:int}
     */
    public static function state(string $type, array $oldPost, array $get, ?int $editingId = null): array
    {
        $state = [
            'type' => $type,
            'mode' => (string) ($get['error'] ?? '') === '1' ? 'error' : 'repeat',
            'values' => self::valuesFromPost($type, $oldPost),
            'repeat' => self::repeatValues($type, $get),
        ];
        if ($editingId !== null && $editingId > 0) {
            $state['editing_id'] = $editingId;
        }

        return $state;
    }
}
