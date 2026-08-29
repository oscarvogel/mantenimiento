<?php

declare(strict_types=1);

use App\Infrastructure\Importations\TsaExpirationWorkbookReader;
use App\Infrastructure\Importations\TsaUnitsWorkbookReader;
use App\Infrastructure\Importations\XlsxImportTemplateExporter;
use App\Domain\Importations\ImportType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class TsaWorkbookReadersTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testUnitsReaderTranslatesMetadataSheetsToCanonicalEquipmentRows(): void
    {
        $this->requireXlsx();
        $book = new Spreadsheet();
        $argentina = $book->getActiveSheet();
        $argentina->setTitle('TSA ARGENTINA');
        $argentina->fromArray([
            ['Listado TSA'], [], ['MARCA', 'PATENTE TRACTOR', 'CHOFER'],
            ['SCANIA G420 A 4x2', "\xC2\xA0JLH877", 'ARIEL RODRIGUEZ'],
        ]);
        $brasil = $book->createSheet();
        $brasil->setTitle('TSA BRASIL');
        $brasil->fromArray([
            ['Listado TSA'], [], ['MARCA', 'PATENTES', 'CHOFER'],
            ['VOLVO / FH 500 6X2T AÑO 2021', 'BEN4G47', 'RAMOS CRISTIAN'],
        ]);
        $path = $this->save($book, 'tsa_units_');

        $data = (new TsaUnitsWorkbookReader())->read($path);

        self::assertCount(2, $data->rows);
        self::assertSame(['sucursal_codigo', 'tipo_equipo', 'codigo', 'patente', 'marca', 'modelo', 'anio', 'chasis', 'motor', 'fecha_alta', 'observaciones'], $data->headers);
        self::assertSame('TSAARG', $data->rows[0]['sucursal_codigo']);
        self::assertSame('JLH877', $data->rows[0]['codigo']);
        self::assertSame('SCANIA', $data->rows[0]['marca']);
        self::assertSame('TSABR', $data->rows[1]['sucursal_codigo']);
        self::assertSame('2021', $data->rows[1]['anio']);
    }

    public function testExpirationReaderExpandsSideBySideGroupsAndKeepsDriverRowsExplicit(): void
    {
        $this->requireXlsx();
        $book = new Spreadsheet();
        $units = $book->getActiveSheet();
        $units->setTitle('unidades');
        $units->fromArray([
            ['ARGENTINOS', null, null, null, null, null, 'BRASILEROS'],
            ['PLACA', 'VTV', 'SENASA', 'POLIZA', null, null, 'PLACA', 'VTV', 'CRVL', 'POLIZA'],
            ['JLH877', '6/6/2027', '-----------', '22/08/2027', null, null, 'BEN4G47', '12/6/2027', '4/2/2027', '22/4/2027'],
        ]);
        $drivers = $book->createSheet();
        $drivers->setTitle('Choferes');
        $drivers->fromArray([
            ['ARGENTINOS', null, null, 'BRASILEROS'],
            ['CHOFER', 'VENCIMIENTO LICENCIA', null, 'CHOFER', 'VENCIMIENTO LICENCIA ARG', 'VENCIMIENTO LICENCIA BR'],
            ['ARIEL RODRIGUEZ', '9/5/2030', null, 'RAMOS CRISTIAN', '24/6/2030', '24/6/2030'],
        ]);
        $path = $this->save($book, 'tsa_expirations_');

        $data = (new TsaExpirationWorkbookReader())->read($path);

        self::assertCount(8, $data->rows); // 5 equipos + 3 choferes fuera de alcance, no descartados.
        self::assertSame('JLH877', $data->rows[0]['equipo_codigo']);
        self::assertSame('VTV', $data->rows[0]['tipo_vencimiento']);
        self::assertSame('2027-06-06', $data->rows[0]['fecha_vencimiento']);
        self::assertSame('LICENCIA_CHOFER', $data->rows[5]['tipo_vencimiento']);
        self::assertStringContainsString('No importado', (string) $data->rows[5]['observaciones']);
    }

    public function testTsaTemplatesAreValidXlsxAndReadableByTheirAcl(): void
    {
        $this->requireXlsx();
        $exporter = new XlsxImportTemplateExporter();
        foreach ([ImportType::UNIDADES_TRANSPORTE, ImportType::VENCIMIENTOS] as $type) {
            $file = $exporter->export($type);
            $base = tempnam(sys_get_temp_dir(), 'tsa_template_');
            self::assertNotFalse($base);
            @unlink($base);
            $path = $base . '.xlsx';
            file_put_contents($path, $file->contents);
            $this->files[] = $path;
            $reader = $type === ImportType::UNIDADES_TRANSPORTE
                ? new TsaUnitsWorkbookReader()
                : new TsaExpirationWorkbookReader();
            $data = $reader->read($path);
            self::assertSame($type->templateHeaders(), $data->headers);
            self::assertCount(1, $data->rows);
        }
    }

    private function save(Spreadsheet $book, string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        self::assertNotFalse($base);
        @unlink($base);
        $path = $base . '.xlsx';
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    private function requireXlsx(): void
    {
        if (! class_exists(Spreadsheet::class) || ! class_exists(ZipArchive::class) || ! extension_loaded('gd')) {
            self::markTestSkipped('PhpSpreadsheet o las extensiones zip/gd no estan disponibles.');
        }
    }
}
