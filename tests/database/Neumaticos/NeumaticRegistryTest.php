<?php

declare(strict_types=1);

namespace Tests\Database\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\ChangeNeumaticStatus;
use App\Application\Neumaticos\GetNeumaticDetail;
use App\Application\Neumaticos\GetNeumaticPerformance;
use App\Application\Neumaticos\GetNeumaticRegistry;
use App\Application\Neumaticos\NeumaticRegistryFilters;
use App\Application\Neumaticos\RegisterNeumatic;
use App\Application\Neumaticos\RegisterNeumaticCommand;
use App\Application\Neumaticos\UpdateNeumatic;
use App\Application\Neumaticos\UpdateNeumaticCommand;
use App\Database\Migrations\CreateNeumaticosTables;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Infrastructure\Assets\CodeIgniterEquipmentFleetLookup;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticBranchScope;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticCatalog;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticRegistryReadModel;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticRepository;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticsUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Neumaticos\NeumaticSchema;
use Tests\Support\Neumaticos\InMemoryNeumatics;

/**
 * Padrón y ficha: casos de uso de lectura y read model contra la migración
 * real.
 */
final class NeumaticRegistryTest extends TestCase
{
    private BaseConnection $database;
    private InMemoryNeumatics $clock;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El padrón de neumáticos requiere sqlite3.');
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
        $this->database->table('usuarios')->insertBatch([['id' => 12, 'nombre' => 'Carla Gómez'], ['id' => 20, 'nombre' => 'Otro']]);
        (new CreateNeumaticosTables(Database::forge($this->database)))->up();
        NeumaticSchema::installMountings($this->database);
        $this->database->table('neumatico_marcas')->insertBatch([
            ['id' => 3, 'empresa_id' => 7, 'nombre' => 'Michelin', 'activo' => 1],
            ['id' => 4, 'empresa_id' => 7, 'nombre' => 'Xbri', 'activo' => 0],
        ]);
        $this->database->table('neumatico_modelos')->insertBatch([
            ['id' => 5, 'empresa_id' => 7, 'marca_id' => 3, 'nombre' => 'X Multi D', 'activo' => 1],
            ['id' => 6, 'empresa_id' => 7, 'marca_id' => 4, 'nombre' => 'Ecoplus', 'activo' => 1],
        ]);
        $this->database->table('neumatico_tipos_cubierta')->insert(['id' => 2, 'empresa_id' => 7, 'descripcion' => 'Lisa', 'activo' => 1]);

        $this->clock = new InMemoryNeumatics();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testListsTheRegistryWithCatalogNamesAndStatusLabels(): void
    {
        $this->register(1, 'TSA 2', 3, 5, 2, '295/80 R22.5');
        $this->register(2, 'TSA 1');

        $page = $this->registry()->execute($this->operator(), NeumaticRegistryFilters::fromQuery([]));

        self::assertSame(2, $page['total']);
        self::assertSame(['TSA 1', 'TSA 2'], array_column($page['items'], 'number'));
        self::assertSame('Michelin', $page['items'][1]['brandName']);
        self::assertSame('X Multi D', $page['items'][1]['modelName']);
        self::assertSame('Lisa', $page['items'][1]['coverTypeName']);
        self::assertSame('Oberá', $page['items'][1]['branchName']);
        self::assertSame('Nueva', $page['items'][1]['statusLabel']);
        self::assertNull($page['items'][0]['brandName']);
        self::assertTrue($page['canOperate']);
    }

    public function testFiltersByTextStatusBranchAndBrand(): void
    {
        $first = $this->register(1, 'TSA 100', 3, 5);
        $this->register(2, 'TSA 200');
        $this->changeStatus($first, 'EN_REPARACION');

        self::assertSame(['TSA 100'], $this->numbers(['q' => 'multi']));
        self::assertSame(['TSA 200'], $this->numbers(['q' => '200']));
        self::assertSame(['TSA 100'], $this->numbers(['estado' => 'en_reparacion']));
        self::assertSame(['TSA 200'], $this->numbers(['sucursal' => '2']));
        self::assertSame(['TSA 100'], $this->numbers(['marca' => '3']));
        self::assertSame(['TSA 100', 'TSA 200'], $this->numbers(['estado' => 'GASTADA', 'marca' => 'abc']), 'filtros inválidos se ignoran');
    }

    public function testTheRegistryOnlyShowsTheActorScope(): void
    {
        $this->register(1, 'TSA 1');
        $this->register(2, 'TSA 2');
        $this->database->table('neumaticos')->insert(['empresa_id' => 8, 'sucursal_id' => 9, 'numero' => 'AJENO', 'estado' => 'NUEVA']);
        $scoped = new ActorContext(13, 7, false, false, [], ['neumaticos.ver'], [1]);

        $page = $this->registry()->execute($scoped, NeumaticRegistryFilters::fromQuery([]));

        self::assertSame(['TSA 1'], array_column($page['items'], 'number'));
        self::assertSame(['Oberá'], array_column($page['options']['branches'], 'name'));
        self::assertFalse($page['canOperate']);
    }

    public function testFormOptionsOnlyOfferActiveCatalogs(): void
    {
        $options = $this->registry()->execute($this->operator(), NeumaticRegistryFilters::fromQuery([]))['options'];

        self::assertSame(['Michelin'], array_column($options['brands'], 'name'));
        self::assertSame(['X Multi D'], array_column($options['models'], 'name'), 'el modelo de una marca inactiva no se ofrece');
        self::assertSame(['Lisa'], array_column($options['coverTypes'], 'name'));
        self::assertNotContains('VENDIDA', array_column($options['initialStatuses'], 'value'));
        self::assertCount(11, $options['statuses']);
    }

    public function testTheDetailShowsDataLogAndTheTransitionsTheDomainAllows(): void
    {
        $id = $this->register(1, 'TSA 1', 3, 5, 2, '295/80');
        $this->changeStatus($id, 'EN_REPARACION', 'Pinchadura');

        $detail = $this->detail()->execute($this->operator(), $id);

        self::assertSame('TSA 1', $detail['neumatic']['number']);
        self::assertSame('En reparación', $detail['neumatic']['statusLabel']);
        self::assertSame(3, $detail['neumatic']['brandId']);
        self::assertSame(['REPARADO', 'BAJA_DEFINITIVA'], array_column($detail['nextStatuses'], 'value'));
        self::assertSame(['EN_REPARACION', 'NUEVA'], array_column($detail['log'], 'to'), 'la bitácora va de la más reciente a la más antigua');
        self::assertSame('Pinchadura', $detail['log'][0]['reason']);
        self::assertSame('Carla Gómez', $detail['log'][0]['userName']);
        self::assertSame('Nueva', $detail['log'][0]['fromLabel']);
        self::assertSame('sin datos suficientes', $detail['performance']['total_label']);
    }

    public function testAViewerSeesTheDetailWithoutTransitions(): void
    {
        $id = $this->register(1, 'TSA 1');

        $detail = $this->detail()->execute(new ActorContext(13, 7, false, true, [], ['neumaticos.ver'], []), $id);

        self::assertSame([], $detail['nextStatuses']);
        self::assertFalse($detail['canOperate']);
    }

    public function testAFinalStateOffersNoTransitions(): void
    {
        $id = $this->register(1, 'TSA 1');
        $this->changeStatus($id, 'PARA_VENTA');
        $this->changeStatus($id, 'VENDIDA');

        $detail = $this->detail()->execute($this->operator(), $id);

        self::assertTrue($detail['neumatic']['isFinal']);
        self::assertSame([], $detail['nextStatuses']);
    }

    public function testCorrectingATireKeepsAnInactiveBrandItAlreadyHad(): void
    {
        $id = $this->register(1, 'TSA 1', 3, 5);
        $this->database->table('neumatico_marcas')->where('id', 3)->update(['activo' => 0]);
        $update = new UpdateNeumatic(
            new CodeIgniterNeumaticRepository($this->database),
            new CodeIgniterNeumaticCatalog($this->database),
            new CodeIgniterNeumaticsUnitOfWork($this->database),
            $this->clock,
        );

        $update->execute($this->operator(), new UpdateNeumaticCommand($id, 3, 5, null, '295/80', null));

        $row = $this->database->table('neumaticos')->where('id', $id)->get()->getRowArray();
        self::assertSame(3, (int) $row['marca_id']);
        self::assertSame('295/80', $row['medida']);

        $other = $this->register(1, 'TSA 2');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La marca elegida no existe o está inactiva.');

        $update->execute($this->operator(), new UpdateNeumaticCommand($other, 3, null, null, '', null));
    }

    public function testATireOutsideTheScopeLooksLikeItDoesNotExist(): void
    {
        $id = $this->register(2, 'TSA 1');
        $scoped = new ActorContext(13, 7, false, false, [], ['neumaticos.ver'], [1]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El neumático solicitado no existe o no está autorizado.');

        $this->detail()->execute($scoped, $id);
    }

    /** @param array<string, string> $query @return list<string> */
    private function numbers(array $query): array
    {
        return array_column($this->registry()->execute($this->operator(), NeumaticRegistryFilters::fromQuery($query))['items'], 'number');
    }

    private function register(int $branch, string $number, ?int $brand = null, ?int $model = null, ?int $cover = null, string $size = ''): int
    {
        return (new RegisterNeumatic(
            new CodeIgniterNeumaticRepository($this->database),
            new CodeIgniterNeumaticCatalog($this->database),
            new CodeIgniterNeumaticBranchScope($this->database),
            new CodeIgniterNeumaticsUnitOfWork($this->database),
            $this->clock,
        ))->execute($this->operator(), new RegisterNeumaticCommand($branch, $number, $brand, $model, $cover, $size, registeredAt: new DateTimeImmutable('2026-11-01 08:00')));
    }

    private function changeStatus(int $id, string $status, string $reason = 'Control'): void
    {
        (new ChangeNeumaticStatus(new CodeIgniterNeumaticRepository($this->database), new CodeIgniterNeumaticsUnitOfWork($this->database), $this->clock))
            ->execute($this->operator(), $id, $status, $reason);
    }

    private function registry(): GetNeumaticRegistry
    {
        return new GetNeumaticRegistry(new CodeIgniterNeumaticRegistryReadModel($this->database), new CodeIgniterNeumaticBranchScope($this->database));
    }

    public function testTheRegistryAndTheDetailSayWhereTheTireIsMounted(): void
    {
        $this->database->table('tipos_equipo')->insert(['id' => 1, 'nombre' => 'Tractor']);
        $this->database->table('equipos')->insertBatch([
            ['id' => 40, 'empresa_id' => 7, 'sucursal_id' => 1, 'tipo_equipo_id' => 1, 'codigo' => 'M40', 'patente' => 'AB123CD', 'estado' => 'ACTIVO'],
            ['id' => 41, 'empresa_id' => 7, 'sucursal_id' => 1, 'tipo_equipo_id' => 1, 'codigo' => 'M41', 'patente' => 'ZZ999ZZ', 'estado' => 'BAJA'],
        ]);
        $mounted = $this->register(1, 'TSA 1');
        $free = $this->register(1, 'TSA 2');
        $repository = new CodeIgniterNeumaticRepository($this->database);
        $tire = $repository->findForUpdate(7, null, $mounted);
        $tire?->mount(40, new NeumaticPosition(2, 'I', 'E'), new DateTimeImmutable('2026-11-02 08:00'), 100_000);
        $repository->save($tire, 12);

        $items = $this->registry()->execute($this->operator(), NeumaticRegistryFilters::fromQuery([]))['items'];
        self::assertSame('AB123CD · M40 · Eje 2 · izquierdo · exterior', $items[0]['mountedOn']);
        self::assertNull($items[1]['mountedOn']);

        $detail = $this->detail()->execute($this->operator(), $mounted);
        self::assertSame('AB123CD · M40', $detail['location']['equipmentLabel']);
        self::assertSame('Eje 2 · izquierdo · exterior', $detail['location']['position']);
        self::assertSame(100_000, $detail['location']['mountedKm']);
        self::assertTrue($detail['mounting']['canRemove']);
        self::assertFalse($detail['mounting']['canMount']);
        self::assertSame('AB123CD · M40', $detail['performance']['mountings'][0]['equipment_label']);

        $freeDetail = $this->detail()->execute($this->operator(), $free);
        self::assertNull($freeDetail['location']);
        self::assertTrue($freeDetail['mounting']['canMount']);
        self::assertSame(['AB123CD · M40'], array_column($freeDetail['mounting']['equipment'], 'label'), 'solo equipos activos');
        self::assertSame(['Izquierdo', 'Derecho'], array_column($freeDetail['mounting']['sides'], 'label'));
        self::assertSame(10, $freeDetail['mounting']['maxAxle']);
    }

    public function testATireThatCannotRollExplainsWhyItCannotBeMounted(): void
    {
        $id = $this->register(1, 'TSA 1');
        $this->changeStatus($id, 'EN_REPARACION', 'Pinchadura');

        $detail = $this->detail()->execute($this->operator(), $id);

        self::assertFalse($detail['mounting']['canMount']);
        self::assertSame('Un neumático en estado "En reparación" no se puede colocar en un equipo.', $detail['mounting']['blockedReason']);
        self::assertSame([], $detail['mounting']['equipment']);
    }

    private function detail(): GetNeumaticDetail
    {
        $fleet = new CodeIgniterEquipmentFleetLookup($this->database);

        return new GetNeumaticDetail(
            new CodeIgniterNeumaticRegistryReadModel($this->database),
            new GetNeumaticPerformance(new CodeIgniterNeumaticRepository($this->database)),
            $fleet,
            $fleet,
        );
    }

    private function operator(): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], ['neumaticos.operar'], []);
    }
}
