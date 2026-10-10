<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Application\Telematic\InstantaneaRegistrada;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Infrastructure\Telematic\CodeIgniterTelemetrySnapshotStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TelemetrySnapshotAnomaliesTest extends TestCase
{
    private BaseConnection $db;
    private CodeIgniterTelemetrySnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ]);
        $this->db->query('CREATE TABLE telematia_ultima_lectura (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, unidad_externa TEXT, proveedor TEXT, observada_en TEXT, registrada_en TEXT NULL, latitud TEXT NULL, longitud TEXT NULL, velocidad_kmh TEXT NULL, rumbo INTEGER NULL, altitud_m TEXT NULL, satelites INTEGER NULL, kilometraje INTEGER NULL, horas_decimales INTEGER NULL, motor_encendido INTEGER NULL, ralenti_activo INTEGER NULL, voltaje TEXT NULL, combustible_litros TEXT NULL, sensores_adicionales TEXT NULL, anomalias_sensor TEXT NULL, ausente INTEGER, created_at TEXT NULL, updated_at TEXT NULL)');
        $this->store = new CodeIgniterTelemetrySnapshotStore($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testPersistsCurrentSensorAnomaliesAndClearsThemWhenAHealthySignalArrives(): void
    {
        $this->store->save($this->registered($this->snapshot(0.0, -1.0)));
        $first = json_decode((string) $this->db->table('telematia_ultima_lectura')->select('anomalias_sensor')->get()->getRowArray()['anomalias_sensor'], true);

        self::assertSame(['VOLTAJE', 'COMBUSTIBLE'], array_column($first, 'sensor'));
        self::assertSame('combustible:-1', $first[1]['firma']);

        $this->store->save($this->registered($this->snapshot(24.2, 80.0)));
        $row = $this->db->table('telematia_ultima_lectura')->get()->getRowArray();

        self::assertNull($row['anomalias_sensor']);
        self::assertSame('80', (string) $row['combustible_litros']);
    }

    private function registered(InstantaneaEquipo $snapshot): InstantaneaRegistrada
    {
        return new InstantaneaRegistrada(8, 41, 101, 201, 'wialon', 'u-101', $snapshot, '2026-10-10 10:00:00');
    }

    private function snapshot(float $voltage, float $fuel): InstantaneaEquipo
    {
        return new InstantaneaEquipo(new DateTimeImmutable('2026-10-10 09:59:00'), null, 100, 500, true, false, $voltage, $fuel);
    }
}
