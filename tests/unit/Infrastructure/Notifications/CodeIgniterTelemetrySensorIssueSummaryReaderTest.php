<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications;

use App\Infrastructure\Notifications\CodeIgniterTelemetrySensorIssueSummaryReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTelemetrySensorIssueSummaryReaderTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El lector de anomalías telemáticas requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE equipos (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            codigo VARCHAR(50) NOT NULL,
            patente VARCHAR(20) NULL,
            estado VARCHAR(20) NOT NULL,
            deleted_at DATETIME NULL
        )');
        $this->db->query('CREATE TABLE telematia_ultima_lectura (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            equipo_id INTEGER NOT NULL,
            anomalias_sensor TEXT NULL
        )');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testReturnsCurrentActiveIssuesWithinCompanyAndLimitsUnits(): void
    {
        $this->insertEquipment(1, 8, 'EQ-1', 'AB4990K');
        $this->insertSnapshot(1, 8, 1, [['sensor' => 'Voltaje', 'valor' => 0, 'motivo' => 'requiere revisar conexión', 'firma' => 'voltaje:0']]);
        // Dos fuentes del mismo activo no inflan el contador de unidades.
        $this->insertSnapshot(2, 8, 1, [['sensor' => 'Combustible', 'valor' => -2.5, 'motivo' => 'señal fuera de rango', 'firma' => 'combustible:-2.5']]);
        for ($id = 2; $id <= 11; $id++) {
            $this->insertEquipment($id, 8, 'EQ-' . $id, null);
            $this->insertSnapshot($id + 10, 8, $id, [['sensor' => 'Voltaje', 'valor' => 0, 'motivo' => 'requiere revisión', 'firma' => 'voltaje:0']]);
        }
        $this->insertEquipment(20, 8, 'BAJA', null, 'INACTIVO');
        $this->insertSnapshot(30, 8, 20, [['sensor' => 'Voltaje', 'valor' => 0, 'motivo' => 'inactivo', 'firma' => 'voltaje:0']]);
        $this->insertEquipment(21, 9, 'OTRA-EMPRESA', null);
        $this->insertSnapshot(31, 9, 21, [['sensor' => 'Voltaje', 'valor' => 0, 'motivo' => 'otra empresa', 'firma' => 'voltaje:0']]);

        $result = (new CodeIgniterTelemetrySensorIssueSummaryReader($this->db))->forCompany(8, 50);

        self::assertSame(11, $result['count']);
        self::assertCount(10, $result['issues'], 'El lector nunca debe devolver más de diez unidades en el resumen.');
        self::assertSame('AB4990K', $result['issues'][0]['equipmentCode']);
        self::assertStringContainsString('Voltaje (0,00): requiere revisar conexión', $result['issues'][0]['summary']);
        self::assertStringContainsString('Combustible (-2,50): señal fuera de rango', $result['issues'][0]['summary']);
    }

    public function testReturnsEmptyWhenAnomalyColumnIsNotInstalledYet(): void
    {
        $this->db->query('ALTER TABLE telematia_ultima_lectura RENAME TO telematia_sin_anomalias');
        $this->db->query('CREATE TABLE telematia_ultima_lectura (
            id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, equipo_id INTEGER NOT NULL
        )');

        self::assertSame(
            ['count' => 0, 'issues' => []],
            (new CodeIgniterTelemetrySensorIssueSummaryReader($this->db))->forCompany(8),
        );
    }

    private function insertEquipment(int $id, int $companyId, string $code, ?string $plate, string $state = 'ACTIVO'): void
    {
        $this->db->table('equipos')->insert([
            'id' => $id,
            'empresa_id' => $companyId,
            'codigo' => $code,
            'patente' => $plate,
            'estado' => $state,
            'deleted_at' => null,
        ]);
    }

    /** @param list<array{sensor:string,valor:?float,motivo:string,firma:string}> $anomalies */
    private function insertSnapshot(int $id, int $companyId, int $equipmentId, array $anomalies): void
    {
        $this->db->table('telematia_ultima_lectura')->insert([
            'id' => $id,
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'anomalias_sensor' => json_encode($anomalies, JSON_THROW_ON_ERROR),
        ]);
    }
}
