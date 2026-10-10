<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetryCredentialStore;
use App\Infrastructure\Telematic\CodeIgniterTelemetryEquipmentUnitLinkManager;
use App\Infrastructure\Telematic\WialonUnitCatalogClient;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTelemetryEquipmentUnitLinkManagerTest extends TestCase
{
    private BaseConnection $db;
    private FakeWialonUnitCatalogClient $wialon;
    private CodeIgniterTelemetryEquipmentUnitLinkManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ]);
        $this->db->query('CREATE TABLE integraciones_telemetria (id INTEGER PRIMARY KEY, empresa_id INTEGER, proveedor TEXT, nombre TEXT, activo INTEGER)');
        $this->db->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, patente TEXT, estado TEXT, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE equipo_telemetria (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, unidad_externa TEXT, rol TEXT, activo INTEGER, created_at TEXT NULL, created_by INTEGER NULL, UNIQUE (integracion_id, unidad_externa), UNIQUE (equipo_id, integracion_id))');
        $this->db->table('integraciones_telemetria')->insertBatch([
            ['id' => 31, 'empresa_id' => 8, 'proveedor' => 'wialon', 'nombre' => 'Obersat', 'activo' => 1],
            ['id' => 32, 'empresa_id' => 9, 'proveedor' => 'wialon', 'nombre' => 'Otra empresa', 'activo' => 1],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 12, 'empresa_id' => 8, 'codigo' => 'AB499OK', 'patente' => 'AB499OK', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 13, 'empresa_id' => 9, 'codigo' => 'AC532DD', 'patente' => 'AC532DD', 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);

        $this->wialon = new FakeWialonUnitCatalogClient();
        $this->manager = new CodeIgniterTelemetryEquipmentUnitLinkManager($this->db, new FakeTelemetryCredentialStore(), $this->wialon);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testSnapshotDevuelveSoloLaFlotaYCuentaDeLaEmpresaSolicitada(): void
    {
        $snapshot = $this->manager->snapshotFor(8, 31);

        self::assertSame('Obersat', $snapshot['integration']['name']);
        self::assertSame([12], array_column($snapshot['equipment'], 'id'));
        self::assertSame([['id' => 'unit-4', 'name' => 'Unidad motor 1']], $snapshot['units']);
    }

    public function testSnapshotSugiereLaUnidadCuandoElNombreWialonIncluyeLaPatente(): void
    {
        $this->wialon->units = [['id' => 'unit-4', 'name' => 'SCANIA 360 AB499OK']];

        $snapshot = $this->manager->snapshotFor(8, 31);

        self::assertSame([12 => 'unit-4'], $snapshot['suggestedLinks']);
    }

    public function testSnapshotNoSugiereUnaUnidadQueYaEstaVinculadaAOtroEquipo(): void
    {
        $this->wialon->units = [['id' => 'unit-4', 'name' => 'SCANIA 360 AB499OK']];
        $this->db->table('equipos')->insert([
            'id' => 14, 'empresa_id' => 8, 'codigo' => 'OTRO', 'patente' => 'OTR123', 'estado' => 'ACTIVO', 'deleted_at' => null,
        ]);
        $this->db->table('equipo_telemetria')->insert([
            'empresa_id' => 8, 'integracion_id' => 31, 'equipo_id' => 14,
            'unidad_externa' => 'unit-4', 'rol' => 'PRINCIPAL', 'activo' => 1,
        ]);

        $snapshot = $this->manager->snapshotFor(8, 31);

        self::assertSame([], $snapshot['suggestedLinks']);
    }

    public function testNoPermiteVincularUnEquipoDeOtraEmpresa(): void
    {
        try {
            $this->manager->saveFor(8, 21, 31, [13 => 'unit-4']);
            self::fail('Se esperaba DomainException.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no está activo en esta empresa', $exception->getMessage());
        }

        self::assertSame(0, $this->db->table('equipo_telemetria')->countAllResults());
    }

    public function testGuardaElVinculoDeUnEquipoDeLaEmpresa(): void
    {
        $count = $this->manager->saveFor(8, 21, 31, [12 => 'unit-4']);
        $link = $this->db->table('equipo_telemetria')->where('empresa_id', 8)->get()->getRowArray();

        self::assertSame(1, $count);
        self::assertSame('unit-4', $link['unidad_externa']);
        self::assertSame(12, (int) $link['equipo_id']);
        self::assertSame(21, (int) $link['created_by']);
    }
}

final class FakeTelemetryCredentialStore implements TelemetryCredentialStore
{
    public function credentials(int $integrationId): array
    {
        return ['endpoint' => 'https://wialon.example.test/ajax.html', 'token' => 'opaque-test-token'];
    }

    public function registerSuccess(int $integrationId, ?string $now): void
    {
    }

    public function registerFailure(int $integrationId, string $message, ?string $now): void
    {
    }
}

final class FakeWialonUnitCatalogClient extends WialonUnitCatalogClient
{
    /** @var list<array{id:string,name:string}> */
    public array $units = [['id' => 'unit-4', 'name' => 'Unidad motor 1']];

    public function listUnits(string $endpoint, string $token): array
    {
        return $this->units;
    }
}
