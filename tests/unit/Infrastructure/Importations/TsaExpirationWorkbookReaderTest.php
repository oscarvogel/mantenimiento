<?php

declare(strict_types=1);

use App\Infrastructure\Importations\TsaExpirationWorkbookReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class TsaExpirationWorkbookReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testReadsEquipmentAndDriverExpirationsFromTsaWorkbook(): void
    {
        if (! class_exists(Spreadsheet::class) || ! class_exists(ZipArchive::class)) {
            self::markTestSkipped('PhpSpreadsheet o zip no están disponibles.');
        }

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

        $base = tempnam(sys_get_temp_dir(), 'tsa_exp_');
        self::assertNotFalse($base);
        @unlink($base);
        $path = $base . '.xlsx';
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $data = (new TsaExpirationWorkbookReader())->read($path);

        self::assertCount(8, $data->rows);
        self::assertSame('EQUIPO', $data->rows[0]['sujeto_tipo']);
        self::assertSame('JLH877', $data->rows[0]['equipo_codigo']);
        self::assertSame('VTV', $data->rows[0]['tipo_vencimiento']);
        self::assertSame('EMPLEADO', $data->rows[5]['sujeto_tipo']);
        self::assertSame('ARIEL RODRIGUEZ', $data->rows[5]['empleado_nombre']);
        self::assertSame('LICENCIA_CHOFER', $data->rows[5]['tipo_vencimiento']);
    }
}
