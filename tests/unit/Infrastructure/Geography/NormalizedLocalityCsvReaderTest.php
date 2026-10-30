<?php

declare(strict_types=1);

use App\Infrastructure\Geography\NormalizedLocalityCsvReader;
use PHPUnit\Framework\TestCase;

final class NormalizedLocalityCsvReaderTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testReadsNormalizedCsvWithBomAndCanBeIteratedAgain(): void
    {
        $path = $this->tempPath('.csv');
        file_put_contents($path, "\xEF\xBB\xBF" . $this->header() . "\nARGENTINA,GEOREF_V2,30,Entre Ríos,30008050,\"Puerto, Nuevo\",Colón,Localidad simple,-32.08,-58.33,2026-04\n");
        $records = (new NormalizedLocalityCsvReader())->records($path);

        self::assertCount(1, iterator_to_array($records));
        self::assertSame('Puerto, Nuevo', iterator_to_array($records)[0]['localidad']);
        self::assertCount(1, iterator_to_array($records));
    }

    public function testReadsGzipAndRejectsIncorrectHeader(): void
    {
        $path = $this->tempPath('.csv.gz');
        $stream = gzopen($path, 'wb9');
        gzwrite($stream, $this->header() . "\nARGENTINA,GEOREF_V2,30,Entre Ríos,30008050,Ampere,Colón,Localidad simple,-32.08,-58.33,2026-04\n");
        gzclose($stream);
        self::assertCount(1, iterator_to_array((new NormalizedLocalityCsvReader())->records($path)));

        $bad = $this->tempPath('.csv');
        file_put_contents($bad, "pais_codigo,fuente\nARGENTINA,GEOREF_V2\n");
        $this->expectException(DomainException::class);
        iterator_to_array((new NormalizedLocalityCsvReader())->records($bad));
    }

    private function header(): string
    {
        return 'pais_codigo,fuente,codigo_provincia,provincia,codigo_localidad,localidad,contexto,categoria,latitud,longitud,version';
    }

    private function tempPath(string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), 'localities');
        @unlink($base);
        $path = $base . $suffix;
        $this->paths[] = $path;
        return $path;
    }
}
