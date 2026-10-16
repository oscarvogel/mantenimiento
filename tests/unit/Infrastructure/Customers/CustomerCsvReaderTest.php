<?php

declare(strict_types=1);

use App\Infrastructure\Customers\CustomerCsvReader;
use PHPUnit\Framework\TestCase;

final class CustomerCsvReaderTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    public function testReadsBomSemicolonAndQuotedMultilineAddressPreservingCode(): void
    {
        $rows = (new CustomerCsvReader())->read($this->file("\xEF\xBB\xBForigen;codigo_origen;nombre;identificacion_fiscal;identificacion_fiscal_pais;direccion;provincia;localidad;contacto;telefono;email;activo\r\nlegacy;001;Cliente;123;BR;\"Calle 1\nPiso 2\";RS;Ciudad;;;;1\r\n"));
        self::assertCount(1, $rows);
        self::assertSame('001', $rows[0]['data']['codigo_origen']);
        self::assertSame("Calle 1\nPiso 2", $rows[0]['data']['direccion']);
        self::assertSame([], $rows[0]['errors']);
        self::assertNotEmpty($rows[0]['warnings']);
    }

    public function testDuplicateCodesBlockBothRowsButDifferentCodesWithSameNameOnlyWarn(): void
    {
        $header = 'origen,codigo_origen,nombre,identificacion_fiscal,identificacion_fiscal_pais,direccion,provincia,localidad,contacto,telefono,email,activo';
        $rows = (new CustomerCsvReader())->read($this->file($header . "\nx,001,Uno,,,,,,,,,1\nx,001,Dos,,,,,,,,,1\nx,1,Uno,,,,,,,,,1\n"));
        self::assertNotEmpty($rows[0]['errors']);
        self::assertNotEmpty($rows[1]['errors']);
        self::assertSame([], $rows[2]['errors']);
        self::assertNotEmpty($rows[2]['warnings']);
    }

    public function testRejectsMissingHeaders(): void
    {
        $this->expectException(DomainException::class);
        (new CustomerCsvReader())->read($this->file("nombre\nCliente\n"));
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(DomainException::class);
        (new CustomerCsvReader())->read($this->file("nombre\n\xFF\n"));
    }

    public function testReportsInvalidEmailAndStateAndWrongColumnCount(): void
    {
        $header = 'origen,codigo_origen,nombre,identificacion_fiscal,identificacion_fiscal_pais,direccion,provincia,localidad,contacto,telefono,email,activo';
        $rows = (new CustomerCsvReader())->read($this->file($header . "\nx,1,Uno,,,,,,,,mal,2\nx,2\n"));
        self::assertCount(2, $rows[0]['errors']);
        self::assertNotEmpty($rows[1]['errors']);
    }

    public function testRejectsOversizeFile(): void
    {
        $this->expectException(DomainException::class);
        (new CustomerCsvReader())->read($this->file(str_repeat('x', 5 * 1024 * 1024 + 1)));
    }

    public function testRejectsHeaderOnlyDataset(): void
    {
        $this->expectException(DomainException::class);
        (new CustomerCsvReader())->read($this->file(implode(',', \App\Domain\Customers\CustomerImportRow::FIELDS) . "\n"));
    }

    public function testRejectsMoreThanTenThousandRows(): void
    {
        $header = implode(',', \App\Domain\Customers\CustomerImportRow::FIELDS) . "\n";
        $this->expectException(DomainException::class);
        (new CustomerCsvReader())->read($this->file($header . str_repeat("qa,1,Cliente,,,,,,,,,1\n", 10001)));
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clients-test-');
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }
}
