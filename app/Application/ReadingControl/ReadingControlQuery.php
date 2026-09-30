<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

/**
 * Criterio de entrada de la pantalla de control de lecturas.
 *
 * Es solo lectura: no transporta ninguna acción mutante, permiso de reclamo
 * ni referencia a canales de notificación.
 *
 * Todos los parámetros son opcionales. `fromRequest()` debe tolerar que no
 * exista ninguna clave en el query string, sin emitir warnings de PHP.
 */
final class ReadingControlQuery
{
    public const SORT_AGE_ASC = 'age_asc';

    public const SORT_AGE_DESC = 'age_desc';

    public const SORT_CODE = 'code';

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public readonly string $query = '',
        public readonly ?int $branchId = null,
        public readonly ?int $typeId = null,
        public readonly string $filter = 'all',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
        public readonly string $sort = self::SORT_AGE_ASC,
    ) {
    }

    /**
     * Construye el criterio desde un query string potencialmente vacío.
     *
     * No se usa `$_GET` directamente: la presentación entrega el array ya
     * isolated y este DTO solo normaliza valores.
     *
     * @param array<string, mixed> $get
     */
    public static function fromRequest(array $get): self
    {
        $query = self::text($get, 'q');
        $filter = self::text($get, 'filter', 'all');
        $sort = self::text($get, 'sort', self::SORT_AGE_ASC);

        return new self(
            query: $query,
            branchId: self::positiveInt($get, 'sucursal_id'),
            typeId: self::positiveInt($get, 'tipo_id'),
            filter: $filter === '' ? 'all' : $filter,
            page: max(1, self::intValue($get['page'] ?? null, 1)),
            perPage: max(1, min(self::MAX_PER_PAGE, self::intValue($get['per_page'] ?? null, self::DEFAULT_PER_PAGE))),
            sort: in_array($sort, [self::SORT_AGE_ASC, self::SORT_AGE_DESC, self::SORT_CODE], true)
                ? $sort
                : self::SORT_AGE_ASC,
        );
    }

    /**
     * @param array<string, mixed> $get
     */
    private static function text(array $get, string $key, string $default = ''): string
    {
        $value = $get[$key] ?? null;

        if (! is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param array<string, mixed> $get
     */
    private static function positiveInt(array $get, string $key): ?int
    {
        $value = $get[$key] ?? null;

        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function intValue(mixed $value, int $default): int
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $default;
        }

        $int = (int) $value;

        return $int === 0 ? $default : $int;
    }
}
