<?php

declare(strict_types=1);

use App\Application\ReadingControl\ReadingControlQuery;
use PHPUnit\Framework\TestCase;

final class ReadingControlQueryTest extends TestCase
{
    public function testToleratesCompletelyEmptyRequest(): void
    {
        $query = ReadingControlQuery::fromRequest([]);

        self::assertSame('', $query->query);
        self::assertNull($query->branchId);
        self::assertNull($query->typeId);
        self::assertSame('all', $query->filter);
        self::assertSame(1, $query->page);
        self::assertSame(ReadingControlQuery::DEFAULT_PER_PAGE, $query->perPage);
        self::assertSame(ReadingControlQuery::SORT_AGE_ASC, $query->sort);
    }

    /**
     * Regresión del `Undefined array key`: ninguna clave opcional puede
     * provocar un warning de PHP cuando el operador entra sin filtros.
     */
    public function testDoesNotRaiseUndefinedArrayKeyForAnyOptionalParameter(): void
    {
        $missing = ['q', 'sucursal_id', 'tipo_id', 'filter', 'page', 'per_page', 'sort'];
        foreach ($missing as $key) {
            self::assertArrayNotHasKey($key, [], sprintf('La clave %s no debe ser obligatoria.', $key));
        }

        $query = @ReadingControlQuery::fromRequest([]);

        self::assertSame('all', $query->filter);
    }

    public function testNormalizesBlankValuesAsAbsent(): void
    {
        $query = ReadingControlQuery::fromRequest([
            'q' => '   ',
            'sucursal_id' => '',
            'tipo_id' => '',
            'filter' => '',
            'per_page' => '',
        ]);

        self::assertSame('', $query->query);
        self::assertNull($query->branchId);
        self::assertNull($query->typeId);
        self::assertSame('all', $query->filter);
        self::assertSame(ReadingControlQuery::DEFAULT_PER_PAGE, $query->perPage);
    }

    public function testReadsEverySupportedFilter(): void
    {
        $query = ReadingControlQuery::fromRequest([
            'q' => '  BEN4G47 ',
            'sucursal_id' => '7',
            'tipo_id' => '3',
            'filter' => 'gt_7',
            'page' => '4',
            'per_page' => '50',
            'sort' => 'age_desc',
        ]);

        self::assertSame('BEN4G47', $query->query);
        self::assertSame(7, $query->branchId);
        self::assertSame(3, $query->typeId);
        self::assertSame('gt_7', $query->filter);
        self::assertSame(4, $query->page);
        self::assertSame(50, $query->perPage);
        self::assertSame(ReadingControlQuery::SORT_AGE_DESC, $query->sort);
    }

    public function testClampsPageSizeAndPageToSafeValues(): void
    {
        $query = ReadingControlQuery::fromRequest(['page' => '-5', 'per_page' => '5000']);

        self::assertSame(1, $query->page);
        self::assertSame(ReadingControlQuery::MAX_PER_PAGE, $query->perPage);
    }

    public function testFallsBackToDefaultWhenSortingIsNotAllowed(): void
    {
        $query = ReadingControlQuery::fromRequest(['sort' => 'DROP TABLE equipos']);

        self::assertSame(ReadingControlQuery::SORT_AGE_ASC, $query->sort);
    }

    public function testIgnoresNonPositiveOrNonScalarIdentifiers(): void
    {
        $query = ReadingControlQuery::fromRequest([
            'sucursal_id' => '0',
            'tipo_id' => ['9'],
        ]);

        self::assertNull($query->branchId);
        self::assertNull($query->typeId);
    }
}
