<?php

declare(strict_types=1);

use App\Infrastructure\Importations\NativeCsvSpreadsheetReader;
use App\Infrastructure\Importations\PhpSpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class NativeCsvSpreadsheetReaderTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testReadsSemicolonCsvAndNormalizesHeaders(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'csv_import_') ?: self::fail('No temp file');
        file_put_contents($this->path, "\xEF\xBB\xBFEquipo Codigo;Fecha Lectura;Kilometraje;Horometro;Origen\nCAM-1;2026-08-08 10:00;1200;;MANUAL\n");

        $data = (new NativeCsvSpreadsheetReader())->read($this->path);

        self::assertSame(['equipo_codigo', 'fecha_lectura', 'kilometraje', 'horometro', 'origen'], $data->headers);
        self::assertSame('CAM-1', $data->rows[0]['equipo_codigo']);
        self::assertSame('', $data->rows[0]['horometro']);
    }

    public function testRejectsMoreRowsThanConfigured(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'csv_import_') ?: self::fail('No temp file');
        file_put_contents($this->path, "codigo,fecha_alta\nA,2026-01-01\nB,2026-01-02\n");
        $this->expectException(DomainException::class);
        (new NativeCsvSpreadsheetReader())->read($this->path, 1);
    }

    public function testReadsRealXlsxWhenPhpSpreadsheetIsAvailable(): void
    {
        if (! class_exists(Spreadsheet::class) || ! class_exists(ZipArchive::class) || ! extension_loaded('gd')) {
            $this->markTestSkipped('PhpSpreadsheet o las extensiones zip/gd no estan disponibles.');
        }
        $base = tempnam(sys_get_temp_dir(), 'xlsx_import_') ?: self::fail('No temp file');
        unlink($base);
        $this->path = $base . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['equipo_codigo', 'fecha_lectura', 'kilometraje', 'horometro', 'origen'],
            ['CAM-1', '2026-08-08 10:00', 1200, null, 'MANUAL'],
        ]);
        (new Xlsx($book))->save($this->path);
        $book->disconnectWorksheets();

        $data = (new PhpSpreadsheetReader(new NativeCsvSpreadsheetReader()))->read($this->path);

        self::assertSame('CAM-1', $data->rows[0]['equipo_codigo']);
        self::assertSame('1200', $data->rows[0]['kilometraje']);
    }
}
