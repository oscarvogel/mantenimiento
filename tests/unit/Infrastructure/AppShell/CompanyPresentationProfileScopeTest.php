<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\AppShell;

use App\Application\Identity\ActorContext;
use App\Infrastructure\AppShell\CodeIgniterAppShellReadModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CompanyPresentationProfileScopeTest extends CIUnitTestCase
{
    private BaseConnection $database;
    private CodeIgniterAppShellReadModel $readModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);
        $this->createSchema();
        $this->readModel = new CodeIgniterAppShellReadModel($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        parent::tearDown();
    }

    public function testTenantProfileAndBranchesAreScopedToActorCompany(): void
    {
        $companyA = $this->readModel->fetch(new ActorContext(7, 11, false, false, [], [], [110]));
        $companyB = $this->readModel->fetch(new ActorContext(8, 22, false, false, [], [], [220]));

        self::assertSame('cliente_a', $companyA['presentationProfile']);
        self::assertSame([110], array_map('intval', array_column($companyA['branches'], 'id')));
        self::assertSame('cliente_b', $companyB['presentationProfile']);
        self::assertSame([220], array_map('intval', array_column($companyB['branches'], 'id')));
    }

    public function testSuperadminDoesNotReadAnyTenantPresentationConfiguration(): void
    {
        $this->database->query('DROP TABLE empresa_configuracion_presentacion');

        $context = $this->readModel->fetch(new ActorContext(9, null, true, true, [], [], []));

        self::assertNull($context['company']);
        self::assertSame([], $context['branches']);
        self::assertNull($context['presentationProfile']);
    }

    private function createSchema(): void
    {
        $this->database->query('CREATE TABLE usuarios (
            id INTEGER PRIMARY KEY,
            nombre TEXT NOT NULL,
            email TEXT NOT NULL,
            activo INTEGER NOT NULL,
            deleted_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE empresas (
            id INTEGER PRIMARY KEY,
            razon_social TEXT NOT NULL,
            nombre_fantasia TEXT NULL,
            estado INTEGER NOT NULL,
            deleted_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE sucursales (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            nombre TEXT NOT NULL,
            estado INTEGER NOT NULL,
            deleted_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE empresa_configuracion_presentacion (
            empresa_id INTEGER PRIMARY KEY,
            frontend_profile TEXT NOT NULL
        )');
        $this->database->table('usuarios')->insertBatch([
            ['id' => 7, 'nombre' => 'Usuario A', 'email' => 'a@example.test', 'activo' => 1, 'deleted_at' => null],
            ['id' => 8, 'nombre' => 'Usuario B', 'email' => 'b@example.test', 'activo' => 1, 'deleted_at' => null],
            ['id' => 9, 'nombre' => 'Superadmin', 'email' => 'global@example.test', 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('empresas')->insertBatch([
            ['id' => 11, 'razon_social' => 'Empresa A', 'nombre_fantasia' => 'A', 'estado' => 1, 'deleted_at' => null],
            ['id' => 22, 'razon_social' => 'Empresa B', 'nombre_fantasia' => 'B', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 110, 'empresa_id' => 11, 'nombre' => 'Base A', 'estado' => 1, 'deleted_at' => null],
            ['id' => 111, 'empresa_id' => 11, 'nombre' => 'Base A no autorizada', 'estado' => 1, 'deleted_at' => null],
            ['id' => 220, 'empresa_id' => 22, 'nombre' => 'Base B', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('empresa_configuracion_presentacion')->insertBatch([
            ['empresa_id' => 11, 'frontend_profile' => 'cliente_a'],
            ['empresa_id' => 22, 'frontend_profile' => 'cliente_b'],
        ]);
    }
}
