<?php

declare(strict_types=1);

namespace Tests\Database\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\GetNeumaticDeposit;
use App\Application\Neumaticos\RegisterDepositMovement;
use App\Application\Neumaticos\RegisterDepositMovementCommand;
use App\Database\Migrations\CreateNeumaticosTables;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticDepositReadModel;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticDepositRepository;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticRepository;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticsUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Neumaticos\InMemoryNeumatics;

/**
 * Pantalla del depósito: existencias, movimientos y neumáticos elegibles,
 * contra la migración real.
 */
final class NeumaticDepositScreenTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El depósito de neumáticos requiere sqlite3.');
        }
        require_once APPPATH . 'Database/Migrations/2026-11-25-100000_CreateNeumaticosTables.php';

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, nombre TEXT NOT NULL, estado INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL, UNIQUE (empresa_id, id))');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->database->table('empresas')->insertBatch([['id' => 7], ['id' => 8]]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 1, 'empresa_id' => 7, 'nombre' => 'Oberá'],
            ['id' => 2, 'empresa_id' => 7, 'nombre' => 'Posadas'],
            ['id' => 9, 'empresa_id' => 8, 'nombre' => 'Otra'],
        ]);
        $this->database->table('usuarios')->insert(['id' => 12, 'nombre' => 'Carla Gómez']);
        (new CreateNeumaticosTables(Database::forge($this->database)))->up();
        $this->database->table('neumatico_marcas')->insert(['id' => 3, 'empresa_id' => 7, 'nombre' => 'Michelin', 'activo' => 1]);
        $this->database->table('depositos')->insertBatch([
            ['id' => 4, 'empresa_id' => 7, 'sucursal_id' => 1, 'nombre' => 'Central', 'ubicacion' => 'Ruta 14', 'activo' => 1],
            ['id' => 5, 'empresa_id' => 7, 'sucursal_id' => 2, 'nombre' => 'Posadas', 'ubicacion' => null, 'activo' => 1],
            ['id' => 6, 'empresa_id' => 7, 'sucursal_id' => 1, 'nombre' => 'Cerrado', 'ubicacion' => null, 'activo' => 0],
            ['id' => 7, 'empresa_id' => 8, 'sucursal_id' => 9, 'nombre' => 'Ajeno', 'ubicacion' => null, 'activo' => 1],
        ]);
        $this->database->table('neumaticos')->insertBatch([
            ['id' => 10, 'empresa_id' => 7, 'sucursal_id' => 1, 'numero' => 'TSA 1', 'marca_id' => 3, 'medida' => '295/80', 'estado' => 'NUEVA'],
            ['id' => 11, 'empresa_id' => 7, 'sucursal_id' => 1, 'numero' => 'TSA 2', 'marca_id' => null, 'medida' => null, 'estado' => 'VENDIDA'],
            ['id' => 12, 'empresa_id' => 7, 'sucursal_id' => 2, 'numero' => 'TSA 3', 'marca_id' => null, 'medida' => null, 'estado' => 'MEDIO_USO'],
            ['id' => 13, 'empresa_id' => 8, 'sucursal_id' => 9, 'numero' => 'AJENO', 'marca_id' => null, 'medida' => null, 'estado' => 'NUEVA'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testShowsActiveDepositsWithTheirCurrentStock(): void
    {
        $this->move('ENTRADA', 4, 10, 1, 'PL-1');
        $this->move('ENTRADA', 5, 12, 1, 'PL-2');
        $this->move('SALIDA', 5, 12, 1, 'PL-3');

        $page = $this->screen()->execute($this->operator(), null);

        self::assertSame(['Central', 'Posadas'], array_column($page['deposits'], 'name'), 'sin el inactivo ni el ajeno');
        self::assertSame([1, 0], array_column($page['deposits'], 'tires'));
        self::assertSame('Ruta 14', $page['deposits'][0]['location']);
        self::assertCount(1, $page['stock'], 'una existencia en cero no se lista');
        self::assertSame('TSA 1', $page['stock'][0]['number']);
        self::assertSame('Michelin · 295/80', $page['stock'][0]['description']);
        self::assertSame('Nueva', $page['stock'][0]['statusLabel']);
    }

    public function testListsMovementsNewestFirstWithWhoDidThem(): void
    {
        $this->move('ENTRADA', 4, 10, 1, 'PL-1', '', '2026-11-20T09:00');
        $this->move('AJUSTE', 4, 10, -1, '', 'Conteo físico', '2026-11-21T10:30');

        $movements = $this->screen()->execute($this->operator(), null)['movements'];

        self::assertSame(['AJUSTE', 'ENTRADA'], array_column($movements, 'type'));
        self::assertSame(-1, $movements[0]['quantity']);
        self::assertSame('Conteo físico', $movements[0]['notes']);
        self::assertNull($movements[0]['sheetNumber']);
        self::assertSame('PL-1', $movements[1]['sheetNumber']);
        self::assertSame('Carla Gómez', $movements[1]['userName']);
    }

    public function testADocumentedNegativeAdjustmentIsVisible(): void
    {
        $this->move('AJUSTE', 4, 10, -1, '', 'Faltante en conteo');

        $page = $this->screen()->execute($this->operator(), null);

        self::assertSame(-1, $page['stock'][0]['quantity']);
        self::assertSame(1, $page['deposits'][0]['negative']);
    }

    public function testFiltersByDepositAndIgnoresAForeignOne(): void
    {
        $this->move('ENTRADA', 4, 10, 1, 'PL-1');
        $this->move('ENTRADA', 5, 12, 1, 'PL-2');

        $filtered = $this->screen()->execute($this->operator(), 5);
        self::assertSame(5, $filtered['selectedDeposit']);
        self::assertSame(['TSA 3'], array_column($filtered['stock'], 'number'));
        self::assertSame(['TSA 3'], array_column($filtered['movements'], 'number'));

        $foreign = $this->screen()->execute($this->operator(), 7);
        self::assertNull($foreign['selectedDeposit']);
        self::assertCount(2, $foreign['stock']);
    }

    public function testABranchUserOnlySeesItsDepositsAndTires(): void
    {
        $this->move('ENTRADA', 4, 10, 1, 'PL-1');
        $this->move('ENTRADA', 5, 12, 1, 'PL-2');
        $scoped = new ActorContext(13, 7, false, false, [], ['neumaticos.operar'], [1]);

        $page = $this->screen()->execute($scoped, null);

        self::assertSame(['Central'], array_column($page['deposits'], 'name'));
        self::assertSame(['TSA 1'], array_column($page['stock'], 'number'));
        self::assertSame(['TSA 1', 'TSA 2'], array_column($page['tires'], 'number'));
    }

    public function testTiresOfferWhichOnesCanEnterADeposit(): void
    {
        $tires = $this->screen()->execute($this->operator(), null)['tires'];

        self::assertSame(['TSA 1', 'TSA 2', 'TSA 3'], array_column($tires, 'number'));
        self::assertSame([true, false, true], array_column($tires, 'canEnter'), 'una vendida no ingresa');
    }

    public function testAViewerSeesStockButNoMovementForm(): void
    {
        $this->move('ENTRADA', 4, 10, 1, 'PL-1');

        $page = $this->screen()->execute(new ActorContext(13, 7, false, true, [], ['neumaticos.ver'], []), null);

        self::assertFalse($page['canOperate']);
        self::assertSame([], $page['tires']);
        self::assertCount(1, $page['stock']);
    }

    private function move(string $type, int $deposit, int $tire, int $quantity, string $sheet, string $notes = '', ?string $at = null): void
    {
        $clock = new InMemoryNeumatics();
        (new RegisterDepositMovement(
            new CodeIgniterNeumaticRepository($this->database),
            new CodeIgniterNeumaticDepositRepository($this->database),
            new CodeIgniterNeumaticsUnitOfWork($this->database),
            $clock,
        ))->execute($this->operator(), new RegisterDepositMovementCommand(
            $type,
            $deposit,
            $tire,
            $quantity,
            $sheet,
            $notes,
            $at === null ? null : new DateTimeImmutable($at),
        ));
    }

    private function screen(): GetNeumaticDeposit
    {
        return new GetNeumaticDeposit(new CodeIgniterNeumaticDepositReadModel($this->database));
    }

    private function operator(): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], ['neumaticos.operar'], []);
    }
}
