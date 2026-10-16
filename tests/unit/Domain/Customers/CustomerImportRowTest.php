<?php

declare(strict_types=1);

use App\Domain\Customers\CustomerImportRow;
use PHPUnit\Framework\TestCase;

final class CustomerImportRowTest extends TestCase
{
    public function testForeignFiscalCountryNeverBecomesDomicileAndOriginalDocumentIsPreserved(): void
    {
        $row = CustomerImportRow::validate(array_replace(array_fill_keys(CustomerImportRow::FIELDS, ''), [
            'origen' => 'legacy', 'codigo_origen' => '001', 'nombre' => 'Cliente', 'activo' => '1',
            'identificacion_fiscal' => '55.123/0001', 'identificacion_fiscal_pais' => 'BR',
        ]));
        self::assertSame([], $row['errors']);
        self::assertSame('55.123/0001', $row['data']['identificacion_fiscal']);
        self::assertSame('', $row['data']['provincia']);
        self::assertSame('', $row['data']['localidad']);
        $otherFiscalCountry = array_replace($row['data'], ['identificacion_fiscal_pais' => 'UY']);
        self::assertSame(CustomerImportRow::locationKey($row['data']), CustomerImportRow::locationKey($otherFiscalCountry));
    }

    public function testRequiredFieldsAndLengthAreBlockingErrors(): void
    {
        $row = CustomerImportRow::validate(['nombre' => str_repeat('a', 256), 'activo' => '1']);
        self::assertCount(3, $row['errors']);
    }
}
