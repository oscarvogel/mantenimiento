<?php

declare(strict_types=1);

use App\Application\Geography\ImportLocalities;
use App\Application\Geography\LocalityImportPreview;
use App\Application\Geography\Port\LocalityCatalogImportStore;
use App\Application\Geography\PreviewLocalities;
use PHPUnit\Framework\TestCase;

final class LocalityCatalogImportTest extends TestCase
{
    public function testPreviewSummarizesValidRowsAndRejectsDuplicateSourceCodes(): void
    {
        $preview = (new PreviewLocalities())->execute([
            $this->row('ARGENTINA', 'GEOREF_V2', '1', 'Argentina', '100', 'Ampere'),
            $this->row('ARGENTINA', 'GEOREF_V2', '1', 'Argentina', '100', 'Ampere'),
            $this->row('ARGENTINA', 'GEOREF_V2', '1', 'Argentina', '101', 'Buenos Aires'),
        ]);

        self::assertSame(3, $preview->totalRows);
        self::assertSame(1, $preview->validRows);
        self::assertSame(2, $preview->errorRows);
        self::assertSame(1, count($preview->errors[0]['errors']));
        self::assertFalse($preview->isValid());
    }

    public function testPreviewRejectsUnsupportedCountryMalformedUtf8MissingIdentityAndBadCoordinates(): void
    {
        $row = $this->row('XX', 'UNKNOWN', '', '', '', "Localidad\xFF");
        $row['latitud'] = '95.2';
        $preview = (new PreviewLocalities())->execute([$row]);

        self::assertSame(0, $preview->validRows);
        self::assertGreaterThanOrEqual(5, count($preview->errors[0]['errors']));
    }

    public function testImportRejectsInvalidPreviewWithoutCallingStore(): void
    {
        $store = new LocalityCatalogImportStoreFake();
        $invalid = $this->row('ARGENTINA', 'GEOREF_V2', '1', 'Argentina', '100', '');
        $preview = new LocalityImportPreview($invalid, 1, 0, 1, []);

        try {
            (new ImportLocalities($store))->execute($preview, 7, str_repeat('a', 64), []);
            self::fail('No debe persistirse una vista previa inválida.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('corregí', mb_strtolower($exception->getMessage()));
        }

        self::assertSame(0, $store->calls);
    }

    public function testImportSendsOnlyValidRowsAndReturnsStoreSummary(): void
    {
        $store = new LocalityCatalogImportStoreFake();
        $row = $this->row('ARGENTINA', 'GEOREF_V2', '1', 'Argentina', '100', 'Ampere');
        $preview = (new PreviewLocalities())->execute([$row]);

        $result = (new ImportLocalities($store))->execute($preview, 7, str_repeat('b', 64), [$row]);

        self::assertSame(['nuevas' => 1, 'actualizadas' => 0, 'sin_cambios' => 0], $result);
        self::assertSame(1, $store->calls);
        self::assertSame(7, $store->actorId);
        self::assertSame([$row], $store->records);
    }

    /** @return array<string,string> */
    private function row(string $country, string $source, string $provinceCode, string $province, string $code, string $name): array
    {
        return [
            'pais_codigo' => $country,
            'fuente' => $source,
            'codigo_provincia' => $provinceCode,
            'provincia' => $province,
            'codigo_localidad' => $code,
            'localidad' => $name,
            'contexto' => '',
            'categoria' => '',
            'latitud' => '-32.08',
            'longitud' => '-58.33',
            'version' => '2026',
        ];
    }
}

final class LocalityCatalogImportStoreFake implements LocalityCatalogImportStore
{
    public int $calls = 0;
    public int $actorId = 0;
    public array $records = [];

    public function import(string $countryCode, string $source, string $version, string $sha256, int $actorId, iterable $records): array
    {
        $this->calls++;
        $this->actorId = $actorId;
        $this->records = $records;
        return ['nuevas' => count($records), 'actualizadas' => 0, 'sin_cambios' => 0];
    }
}
