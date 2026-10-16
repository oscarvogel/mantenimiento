<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Platform\PresentationProfiles;

use App\Application\Platform\PresentationProfiles\PresentationProfileAlreadyAssigned;
use App\Infrastructure\Platform\PresentationProfiles\CodeIgniterCompanyPresentationProfileStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DomainException;

final class CodeIgniterCompanyPresentationProfileStoreTest extends CIUnitTestCase
{
    private BaseConnection $database;
    private CodeIgniterCompanyPresentationProfileStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
            'foreignKeys' => true,
        ], false);
        $this->createSchema();
        $this->store = new CodeIgniterCompanyPresentationProfileStore($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        parent::tearDown();
    }

    public function testAssignStoresCompanyProfileAndAuditTogether(): void
    {
        $this->store->assign(21, 'cliente_piloto', 9, true);

        self::assertSame('cliente_piloto', $this->companyProfile(21)['frontend_profile']);
        self::assertSame('cliente_piloto', $this->companyProfile(21)['perfil_exclusivo']);
        self::assertSame(1, $this->historyCount(21));
        self::assertSame('cliente_piloto', $this->historyRows(21)[0]['perfil_nuevo']);
        self::assertSame(9, (int) $this->historyRows(21)[0]['actor_user_id']);
    }

    public function testAssigningSameProfileAgainDoesNotDuplicateHistory(): void
    {
        $this->store->assign(21, 'base', 9, false);
        $this->store->assign(21, 'base', 9, false);

        self::assertSame(1, $this->historyCount(21));
    }

    public function testExclusiveProfileConflictRollsBackConfigurationAndHistory(): void
    {
        $this->store->assign(21, 'cliente_piloto', 9, true);

        try {
            $this->store->assign(22, 'cliente_piloto', 9, true);
            self::fail('Se esperaba que el perfil exclusivo ya asignado fuera rechazado.');
        } catch (PresentationProfileAlreadyAssigned $exception) {
            self::assertSame('Ese perfil de presentación ya está asignado a otra empresa.', $exception->getMessage());
        }

        self::assertSame(0, $this->configurationCount(22));
        self::assertSame(0, $this->historyCount(22));
        self::assertSame(1, $this->historyCount(21));
    }

    public function testInactiveOrMissingCompanyCannotReceiveProfile(): void
    {
        try {
            $this->store->assign(23, 'base', 9, false);
            self::fail('Se esperaba que una empresa inactiva fuera rechazada.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('empresa', strtolower($exception->getMessage()));
        }

        self::assertSame(0, $this->configurationCount(23));
        self::assertSame(0, $this->historyCount(23));
    }

    private function createSchema(): void
    {
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, estado INTEGER NOT NULL, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE empresa_configuracion_presentacion (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL UNIQUE,
            frontend_profile VARCHAR(64) NOT NULL,
            perfil_exclusivo VARCHAR(64) NULL UNIQUE,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE empresa_presentacion_perfil_historial (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            perfil_anterior VARCHAR(64) NULL,
            perfil_nuevo VARCHAR(64) NOT NULL,
            actor_user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->database->table('empresas')->insertBatch([
            ['id' => 21, 'estado' => 1, 'deleted_at' => null],
            ['id' => 22, 'estado' => 1, 'deleted_at' => null],
            ['id' => 23, 'estado' => 0, 'deleted_at' => null],
        ]);
        $this->database->table('usuarios')->insert(['id' => 9]);
    }

    /** @return array<string, mixed> */
    private function companyProfile(int $companyId): array
    {
        return $this->database->table('empresa_configuracion_presentacion')->where('empresa_id', $companyId)->get()->getRowArray() ?? [];
    }

    private function configurationCount(int $companyId): int
    {
        return $this->database->table('empresa_configuracion_presentacion')->where('empresa_id', $companyId)->countAllResults();
    }

    private function historyCount(int $companyId): int
    {
        return $this->database->table('empresa_presentacion_perfil_historial')->where('empresa_id', $companyId)->countAllResults();
    }

    /** @return list<array<string, mixed>> */
    private function historyRows(int $companyId): array
    {
        return $this->database->table('empresa_presentacion_perfil_historial')->where('empresa_id', $companyId)->get()->getResultArray();
    }
}
