<?php

declare(strict_types=1);

use App\Infrastructure\TripImports\LegacyTripPackageReader;
use PHPUnit\Framework\TestCase;

final class LegacyTripPackageReaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testOpensAndReadsAPackage(): void
    {
        $path = $this->buildPackage();

        $package = (new LegacyTripPackageReader())->open($path);
        try {
            self::assertSame('test.backup', $package->origenArchivo());
            self::assertSame(str_repeat('a', 64), $package->origenSha256());
            self::assertSame('2026-10-17T00:00:00+00:00', $package->generadoEn());
            self::assertTrue($package->hasTable('t05_viajes'));
            self::assertFalse($package->hasTable('t99_inexistente'));
            self::assertSame(1, $package->declaredRows('t05_viajes'));
            self::assertContains('t12_cuenta_corriente_origenes_destinos_por_viaje', $package->tables());

            $rows = iterator_to_array($package->rows('t05_viajes'));
            self::assertCount(1, $rows);
            self::assertSame('23-28059786-4', $rows[0]['cuit_empresa']);
            self::assertSame('AC 532 DD', $rows[0]['dominio']);
        } finally {
            $package->close();
        }
    }

    public function testRejectsPackageWithoutAggregateTable(): void
    {
        $path = $this->buildPackage(['t12_cuenta_corriente_origenes_destinos_por_viaje']);

        $this->expectException(DomainException::class);
        (new LegacyTripPackageReader())->open($path);
    }

    /** @param list<string> $omit @return string */
    private function buildPackage(array $omit = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'trip_package_') ?: self::fail('No temp file');
        $this->temporaryFiles[] = $path;

        $tables = [
            't05_viajes' => ['cuit_empresa', 'dominio', 'numero_viaje', 'fecha_salida'],
            't06_origenes_destinos_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
            't07_cargas_combustible_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
            't08_gastos_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
            't11_cuenta_corriente_x_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
            't12_cuenta_corriente_origenes_destinos_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item', 'sub_item'],
            't13_otros_haberes_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
        ];

        $manifestTables = [];
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($tables as $table => $columns) {
            if (in_array($table, $omit, true)) {
                continue;
            }
            $rows = $table === 't05_viajes'
                ? ["23-28059786-4,AC 532 DD,1,2022-01-13"]
                : [];
            $csv = implode(',', $columns) . "\n" . ($rows === [] ? '' : implode("\n", $rows) . "\n");
            $manifestTables[] = [
                'tabla' => $table,
                'rol' => 'viaje',
                'columnas' => $columns,
                'primary_key' => $columns,
                'filas' => count($rows),
                'csv' => 'data/' . $table . '.csv',
            ];
            self::assertTrue($zip->addFromString('data/' . $table . '.csv', $csv));
        }
        $manifest = [
            'formato' => 'legacy-transporte-package',
            'version' => '1.0.0',
            'alcance' => 'viajes',
            'origen_archivo' => 'test.backup',
            'origen_sha256' => str_repeat('a', 64),
            'generado_en' => '2026-10-17T00:00:00+00:00',
            'tablas' => $manifestTables,
        ];
        self::assertTrue($zip->addFromString('manifest.json', (string) json_encode($manifest)));
        self::assertTrue($zip->close());

        return $path;
    }
}
