<?php

declare(strict_types=1);

namespace Tests\Database\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\ChangeNeumaticStatus;
use App\Application\Neumaticos\GetNeumaticPerformance;
use App\Application\Neumaticos\RegisterDepositMovement;
use App\Application\Neumaticos\RegisterDepositMovementCommand;
use App\Application\Neumaticos\RegisterNeumatic;
use App\Application\Neumaticos\RegisterNeumaticCommand;
use App\Application\Neumaticos\UpdateNeumatic;
use App\Application\Neumaticos\UpdateNeumaticCommand;
use App\Database\Migrations\AddNeumaticosPermissions;
use App\Database\Migrations\CreateNeumaticosTables;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Domain\Neumaticos\NeumaticStatus;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticBranchScope;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticCatalog;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticDepositRepository;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticRepository;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticsUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Neumaticos\InMemoryNeumatics;
use Throwable;

/**
 * Adaptadores de Neumáticos contra el esquema que crean las migraciones
 * reales, no contra una copia escrita a mano: los tipos de columna son los de
 * producción.
 */
final class NeumaticosPersistenceTest extends TestCase
{
    private BaseConnection $database;
    private InMemoryNeumatics $clock;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La persistencia de neumáticos requiere sqlite3.');
        }
        require_once APPPATH . 'Database/Migrations/2026-11-25-100000_CreateNeumaticosTables.php';
        require_once APPPATH . 'Database/Migrations/2026-11-25-100100_AddNeumaticosPermissions.php';

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, estado INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL, UNIQUE (empresa_id, id))');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');
        $this->database->table('empresas')->insertBatch([['id' => 7, 'nombre' => 'TSA'], ['id' => 8, 'nombre' => 'Otra']]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 1, 'empresa_id' => 7, 'estado' => 1],
            ['id' => 2, 'empresa_id' => 7, 'estado' => 0],
            ['id' => 9, 'empresa_id' => 8, 'estado' => 1],
        ]);
        $this->database->table('usuarios')->insertBatch([['id' => 12], ['id' => 13]]);

        (new CreateNeumaticosTables(Database::forge($this->database)))->up();

        $now = '2026-11-01 00:00:00';
        $this->database->table('neumatico_marcas')->insertBatch([
            ['id' => 3, 'empresa_id' => 7, 'nombre' => 'MICHELIN', 'activo' => 1, 'created_at' => $now],
            ['id' => 4, 'empresa_id' => 7, 'nombre' => 'XBRI', 'activo' => 0, 'created_at' => $now],
            ['id' => 6, 'empresa_id' => 8, 'nombre' => 'MICHELIN', 'activo' => 1, 'created_at' => $now],
        ]);
        $this->database->table('neumatico_modelos')->insert(['id' => 5, 'empresa_id' => 7, 'marca_id' => 3, 'nombre' => 'X MULTI D', 'activo' => 1]);
        $this->database->table('neumatico_tipos_cubierta')->insert(['id' => 2, 'empresa_id' => 7, 'descripcion' => 'LISA', 'activo' => 1]);
        $this->database->table('depositos')->insertBatch([
            ['id' => 4, 'empresa_id' => 7, 'sucursal_id' => 1, 'nombre' => 'Depósito Central - Oberá', 'activo' => 1],
            ['id' => 5, 'empresa_id' => 8, 'sucursal_id' => 9, 'nombre' => 'Otro', 'activo' => 1],
        ]);

        $this->clock = new InMemoryNeumatics();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testTheMigrationSeedsTheElevenStatesWithTheDomainCodes(): void
    {
        $codes = array_column($this->database->table('neumaticos_estados')->get()->getResultArray(), 'codigo');

        sort($codes);
        $expected = array_map(static fn (NeumaticStatus $status): string => $status->value, NeumaticStatus::cases());
        sort($expected);
        self::assertSame($expected, $codes);
    }

    public function testRegistersAndReloadsATireWithItsLog(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'tsa 181917', 3, 5, 2, '295/80 R22.5', 2025, registeredAt: new DateTimeImmutable('2026-11-10 08:30:00')));

        $row = $this->database->table('neumaticos')->where('id', $id)->get()->getRowArray();
        self::assertSame('TSA 181917', $row['numero']);
        self::assertSame('NUEVA', $row['estado']);
        self::assertSame(12, (int) $row['created_by']);

        $tire = $this->repository()->find(7, null, $id);
        self::assertNotNull($tire);
        self::assertSame(5, $tire->modelId());
        self::assertSame(2025, $tire->manufactureYear());
        self::assertSame('295/80 R22.5', $tire->size());
        self::assertCount(1, $tire->statusLog());
        self::assertSame('2026-11-10 08:30:00', $tire->statusLog()[0]->at->format('Y-m-d H:i:s'));
    }

    public function testTheDatabaseRejectsADuplicateNumberInTheCompany(): void
    {
        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        $this->expectException(Throwable::class);

        $this->repository()->add(Neumatic::register(7, 1, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-11-01')), 12);
    }

    public function testTheSameNumberIsAllowedInAnotherCompany(): void
    {
        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        $id = $this->repository()->add(Neumatic::register(8, 9, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-11-01')), 13);

        self::assertGreaterThan(0, $id);
    }

    public function testTheDatabaseRejectsTheBrandOfAnotherCompany(): void
    {
        $this->expectException(Throwable::class);

        $this->repository()->add(Neumatic::register(7, 1, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-11-01'), brandId: 6), 12);
    }

    public function testCatalogAndBranchAdaptersRespectCompanyAndActiveFlags(): void
    {
        $catalog = new CodeIgniterNeumaticCatalog($this->database);
        $branches = new CodeIgniterNeumaticBranchScope($this->database);

        self::assertTrue($catalog->isActiveBrand(7, 3));
        self::assertFalse($catalog->isActiveBrand(7, 4), 'marca inactiva');
        self::assertFalse($catalog->isActiveBrand(7, 6), 'marca de otra empresa');
        self::assertTrue($catalog->isActiveModelOfBrand(7, 5, 3));
        self::assertFalse($catalog->isActiveModelOfBrand(7, 5, 4));
        self::assertTrue($catalog->isActiveCoverType(7, 2));
        self::assertFalse($catalog->isActiveCoverType(8, 2));
        self::assertTrue($branches->isActiveInCompany(7, 1));
        self::assertFalse($branches->isActiveInCompany(7, 2), 'sucursal inactiva');
        self::assertFalse($branches->isActiveInCompany(7, 9), 'sucursal de otra empresa');
    }

    public function testUpdateAndStatusChangesAppendOnlyTheNewLogEntries(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', registeredAt: new DateTimeImmutable('2026-11-01')));
        $unitOfWork = new CodeIgniterNeumaticsUnitOfWork($this->database);

        (new UpdateNeumatic($this->repository(), new CodeIgniterNeumaticCatalog($this->database), $unitOfWork, $this->clock))
            ->execute($this->operator(), new UpdateNeumaticCommand($id, 3, null, null, '295/80', null));
        $change = new ChangeNeumaticStatus($this->repository(), $unitOfWork, $this->clock);
        $change->execute($this->operator(), $id, 'EN_REPARACION', 'Pinchadura', new DateTimeImmutable('2026-11-05'));
        $change->execute($this->operator(), $id, 'REPARADO', 'Parche', new DateTimeImmutable('2026-11-06'));

        $log = $this->database->table('neumatico_estado_historial')->where('neumatico_id', $id)->orderBy('id')->get()->getResultArray();
        self::assertSame([null, 'NUEVA', 'EN_REPARACION'], array_column($log, 'estado_anterior'));
        self::assertSame(['NUEVA', 'EN_REPARACION', 'REPARADO'], array_column($log, 'estado'));
        self::assertSame('Parche', $log[2]['motivo']);

        $tire = $this->repository()->find(7, null, $id);
        self::assertSame(NeumaticStatus::REPAIRED, $tire?->status());
        self::assertSame(3, $tire?->brandId());
        self::assertSame('TSA 1', $tire?->number()->value);
    }

    public function testAFailedStatusChangeLeavesNoTrace(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        try {
            (new ChangeNeumaticStatus($this->repository(), new CodeIgniterNeumaticsUnitOfWork($this->database), $this->clock))
                ->execute($this->operator(), $id, 'BAJA_DEFINITIVA', 'Rotura');
            self::fail('Aceptó NUEVA → BAJA DEFINITIVA.');
        } catch (DomainException) {
        }

        self::assertSame('NUEVA', $this->database->table('neumaticos')->where('id', $id)->get()->getRowArray()['estado']);
        self::assertSame(1, $this->database->table('neumatico_estado_historial')->where('neumatico_id', $id)->countAllResults());
    }

    public function testFindRespectsCompanyAndBranchScope(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        self::assertNotNull($this->repository()->find(7, [1], $id));
        self::assertNull($this->repository()->find(7, [2], $id));
        self::assertNull($this->repository()->find(8, null, $id));
        self::assertNull($this->repository()->find(7, [], $id));
    }

    public function testMountingsAreNotSilentlyDropped(): void
    {
        $tire = Neumatic::register(7, 1, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-11-01'));
        $tire->mount(10, new NeumaticPosition('E1', 'I', '1'), new DateTimeImmutable('2026-11-02'), 1000);

        $this->expectException(LogicException::class);

        $this->repository()->add($tire, 12);
    }

    public function testDepositMovementsKeepStockAndMovementTogether(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));
        $handler = $this->depositHandler();

        self::assertSame(1, $handler->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 4, $id, 1, 'PL-100', at: new DateTimeImmutable('2026-11-20'))));
        self::assertSame(0, $handler->execute($this->operator(), new RegisterDepositMovementCommand('SALIDA', 4, $id, 1, 'PL-101', at: new DateTimeImmutable('2026-11-21'))));

        self::assertSame(0, (int) $this->database->table('deposito_stock')->where(['deposito_id' => 4, 'neumatico_id' => $id])->get()->getRowArray()['cantidad']);
        $movements = $this->database->table('deposito_movimientos')->orderBy('id')->get()->getResultArray();
        self::assertSame(['ENTRADA', 'SALIDA'], array_column($movements, 'tipo'));
        self::assertSame(['PL-100', 'PL-101'], array_column($movements, 'numero_planilla'));
        self::assertSame(7, (int) $movements[0]['empresa_id']);
    }

    public function testARejectedExitWritesNothing(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        try {
            $this->depositHandler()->execute($this->operator(), new RegisterDepositMovementCommand('SALIDA', 4, $id, 1, 'PL-101'));
            self::fail('Aceptó una salida sin existencia.');
        } catch (DomainException) {
        }

        self::assertSame(0, $this->database->table('deposito_stock')->countAllResults());
        self::assertSame(0, $this->database->table('deposito_movimientos')->countAllResults());
    }

    public function testADepositOfAnotherCompanyIsNotActiveForTheActor(): void
    {
        $deposits = new CodeIgniterNeumaticDepositRepository($this->database);

        self::assertTrue($deposits->isActiveDeposit(7, null, 4));
        self::assertTrue($deposits->isActiveDeposit(7, [1], 4));
        self::assertFalse($deposits->isActiveDeposit(7, [2], 4));
        self::assertFalse($deposits->isActiveDeposit(7, null, 5));
    }

    public function testPerformanceReadsFromTheDatabase(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        $view = (new GetNeumaticPerformance($this->repository()))->execute($this->operator(), $id);

        self::assertSame('TSA 1', $view['number']);
        self::assertSame('sin datos suficientes', $view['total_label']);
    }

    public function testTheMigrationRollsBackCleanly(): void
    {
        (new CreateNeumaticosTables(Database::forge($this->database)))->down();

        foreach (['neumaticos', 'neumatico_marcas', 'neumaticos_estados', 'depositos', 'deposito_movimientos'] as $table) {
            self::assertFalse($this->database->tableExists($table, false), $table);
        }
    }

    public function testThePermissionMigrationIsIdempotentAndReversible(): void
    {
        $this->database->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, clave TEXT UNIQUE, descripcion TEXT, created_at DATETIME, updated_at DATETIME)');
        $this->database->query('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT)');
        $this->database->query('CREATE TABLE rol_permisos (rol_id INTEGER, permiso_id INTEGER, created_at DATETIME)');
        foreach (['Administrador', 'Responsable de mantenimiento', 'Consulta', 'Solicitante'] as $name) {
            $this->database->table('roles')->insert(['nombre' => $name]);
        }
        $migration = new AddNeumaticosPermissions(Database::forge($this->database));

        $migration->up();
        $migration->up();

        self::assertSame(2, $this->database->table('permisos')->like('clave', 'neumaticos.', 'after')->countAllResults());
        self::assertSame(5, $this->database->table('rol_permisos')->countAllResults(), 'ver: 3 roles presentes; operar: 2');
        $solicitante = $this->database->table('roles')->where('nombre', 'Solicitante')->get()->getRowArray();
        self::assertSame(0, $this->database->table('rol_permisos')->where('rol_id', (int) $solicitante['id'])->countAllResults());

        $migration->down();

        self::assertSame(0, $this->database->table('permisos')->countAllResults());
        self::assertSame(0, $this->database->table('rol_permisos')->countAllResults());
    }

    private function repository(): CodeIgniterNeumaticRepository
    {
        return new CodeIgniterNeumaticRepository($this->database);
    }

    private function register(): RegisterNeumatic
    {
        return new RegisterNeumatic(
            $this->repository(),
            new CodeIgniterNeumaticCatalog($this->database),
            new CodeIgniterNeumaticBranchScope($this->database),
            new CodeIgniterNeumaticsUnitOfWork($this->database),
            $this->clock,
        );
    }

    private function depositHandler(): RegisterDepositMovement
    {
        return new RegisterDepositMovement(
            $this->repository(),
            new CodeIgniterNeumaticDepositRepository($this->database),
            new CodeIgniterNeumaticsUnitOfWork($this->database),
            $this->clock,
        );
    }

    private function operator(): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], ['neumaticos.operar'], []);
    }
}
