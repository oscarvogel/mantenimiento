<?php

declare(strict_types=1);

use App\Presentation\PageSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageSizeTest extends TestCase
{
    /** @return iterable<string, array{mixed, int}> */
    public static function values(): iterable
    {
        yield 'cinco' => ['5', 5];
        yield 'diez' => [10, 10];
        yield 'veinticinco' => ['25', 25];
        yield 'ausente' => [null, 10];
        yield 'fuera de lista' => ['20', 10];
        yield 'texto' => ['todos', 10];
    }

    #[DataProvider('values')]
    public function testNormalizesToSupportedPageSizes(mixed $value, int $expected): void
    {
        self::assertSame($expected, PageSize::normalize($value));
    }
}
