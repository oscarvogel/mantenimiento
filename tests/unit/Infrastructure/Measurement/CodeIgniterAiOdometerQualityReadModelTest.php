<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Measurement;

use App\Infrastructure\Measurement\CodeIgniterAiOdometerQualityReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CodeIgniterAiOdometerQualityReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La integración de calidad IA requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
            'foreignKeys' => true,
        ], false);

        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo TEXT)');
        $this->database->query('CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, sucursal_id INTEGER)');
        $this->database->query('CREATE TABLE lecturas_equipo_evidencias (id INTEGER PRIMARY KEY, empresa_id INTEGER, lectura_id INTEGER, km_detectado_ia INTEGER NULL, km_confirmado INTEGER NULL, confianza_ia REAL NULL, created_at TEXT)');

        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'codigo' => 'CAM-01'],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 8, 'codigo' => 'CAM-02'],
            ['id' => 12, 'empresa_id' => 99, 'sucursal_id' => 9, 'codigo' => 'OTRO'],
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'equipo_id' => 10, 'sucursal_id' => 7],
            ['id' => 2, 'empresa_id' => 5, 'equipo_id' => 10, 'sucursal_id' => 7],
            ['id' => 3, 'empresa_id' => 5, 'equipo_id' => 10, 'sucursal_id' => 7],
            ['id' => 4, 'empresa_id' => 5, 'equipo_id' => 11, 'sucursal_id' => 8],
            ['id' => 5, 'empresa_id' => 99, 'equipo_id' => 12, 'sucursal_id' => 9],
        ]);
        $this->database->table('lecturas_equipo_evidencias')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'lectura_id' => 1, 'km_detectado_ia' => 17, 'km_confirmado' => 507738, 'confianza_ia' => 0.92, 'created_at' => '2026-11-10 08:00:00'],
            ['id' => 2, 'empresa_id' => 5, 'lectura_id' => 2, 'km_detectado_ia' => 507738, 'km_confirmado' => 507738, 'confianza_ia' => 0.99, 'created_at' => '2026-11-11 08:00:00'],
            ['id' => 3, 'empresa_id' => 5, 'lectura_id' => 3, 'km_detectado_ia' => 1, 'km_confirmado' => 500, 'confianza_ia' => 0.50, 'created_at' => '2026-10-01 08:00:00'],
            ['id' => 4, 'empresa_id' => 5, 'lectura_id' => 4, 'km_detectado_ia' => 10, 'km_confirmado' => 900, 'confianza_ia' => 0.90, 'created_at' => '2026-11-12 08:00:00'],
            ['id' => 5, 'empresa_id' => 99, 'lectura_id' => 5, 'km_detectado_ia' => 1, 'km_confirmado' => 2, 'confianza_ia' => 0.99, 'created_at' => '2026-11-12 08:00:00'],
        ]);
    }

    public function testItAggregatesDiscrepanciesByCompanyAndEquipment(): void
    {
        $quality = (new CodeIgniterAiOdometerQualityReadModel($this->database))
            ->summary(5, null, new DateTimeImmutable('2026-11-16 12:00:00'));

        self::assertSame(4, $quality->total);
        self::assertSame(2, $quality->discrepancies);
        self::assertSame(50.0, $quality->rate);
        self::assertCount(2, $quality->byEquipment);
        $cam01 = array_values(array_filter(
            $quality->byEquipment,
            static fn (array $row): bool => $row['equipmentCode'] === 'CAM-01',
        ))[0];
        self::assertSame(3, $cam01['total']);
        self::assertSame(1, $cam01['discrepancies']);

        $november = array_values(array_filter($quality->byMonth, static fn (array $row): bool => $row['month'] === '2026-11'));
        self::assertSame(3, $november[0]['total']);
        self::assertSame(2, $november[0]['discrepancies']);
    }

    public function testItRespectsTheBranchScope(): void
    {
        $quality = (new CodeIgniterAiOdometerQualityReadModel($this->database))
            ->summary(5, [7], new DateTimeImmutable('2026-11-16 12:00:00'));

        self::assertSame(3, $quality->total);
        self::assertSame(1, $quality->discrepancies);
    }
}
