<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MaintenanceCircuit;

use App\Infrastructure\MaintenanceCircuit\CodeIgniterCircuitOverview;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionClass;

final class CodeIgniterCircuitOverviewCompatibilityTest extends CIUnitTestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La compatibilidad requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->database->query('DROP TABLE IF EXISTS tipos_servicio');
    }

    protected function tearDown(): void
    {
        $this->database->query('DROP TABLE IF EXISTS tipos_servicio');
        parent::tearDown();
    }

    public function testReadsLegacyServiceCatalogWithoutTenantColumn(): void
    {
        $this->database->query(<<<'SQL'
            CREATE TABLE tipos_servicio (
                id INTEGER PRIMARY KEY,
                codigo TEXT NOT NULL,
                nombre TEXT NOT NULL,
                activo INTEGER NOT NULL DEFAULT 1
            )
        SQL);
        $this->database->table('tipos_servicio')->insert([
            'id' => 1,
            'codigo' => 'ACEITE',
            'nombre' => 'Servicio de aceite',
            'activo' => 1,
        ]);

        $result = $this->readServiceTypes(7);

        self::assertSame([[
            'id' => 1,
            'codigo' => 'ACEITE',
            'nombre' => 'Servicio de aceite',
        ]], $result);
    }

    /** @return list<array<string,mixed>> */
    private function readServiceTypes(int $companyId): array
    {
        $method = (new ReflectionClass(CodeIgniterCircuitOverview::class))->getMethod('serviceTypes');
        $method->setAccessible(true);

        /** @var list<array<string,mixed>> $result */
        $result = $method->invoke(new CodeIgniterCircuitOverview($this->database), $companyId);

        return $result;
    }
}
