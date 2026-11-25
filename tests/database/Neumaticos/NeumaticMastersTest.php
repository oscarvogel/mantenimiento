<?php

declare(strict_types=1);

namespace Tests\Database\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\NeumaticMasterCatalog;
use App\Database\Migrations\CreateNeumaticosTables;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticBranchScope;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticCatalog;
use App\Infrastructure\Neumaticos\CodeIgniterNeumaticMasterRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Neumaticos\NeumaticSchema;

/**
 * Maestros de Neumáticos: caso de uso y adaptador contra el esquema de la
 * migración real.
 */
final class NeumaticMastersTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('Los maestros de neumáticos requieren sqlite3.');
        }
        require_once APPPATH . 'Database/Migrations/2026-11-25-100000_CreateNeumaticosTables.php';

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, nombre TEXT NOT NULL, estado INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL, UNIQUE (empresa_id, id))');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');
        $this->database->table('empresas')->insertBatch([['id' => 7], ['id' => 8]]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 1, 'empresa_id' => 7, 'nombre' => 'Oberá', 'estado' => 1],
            ['id' => 2, 'empresa_id' => 7, 'nombre' => 'Posadas', 'estado' => 1],
            ['id' => 3, 'empresa_id' => 7, 'nombre' => 'Cerrada', 'estado' => 0],
            ['id' => 9, 'empresa_id' => 8, 'nombre' => 'Otra', 'estado' => 1],
        ]);
        (new CreateNeumaticosTables(Database::forge($this->database)))->up();
        NeumaticSchema::installMountings($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testCreatesEveryMasterAndListsItForTheCompany(): void
    {
        $masters = $this->masters();
        $brand = $masters->save($this->operator(), 'marcas', ['nombre' => '  Michelin  ']);
        $masters->save($this->operator(), 'modelos', ['nombre' => 'X Multi D', 'marca_id' => $brand]);
        $masters->save($this->operator(), 'tipos-cubierta', ['descripcion' => 'LISA']);
        $masters->save($this->operator(), 'depositos', ['nombre' => 'Depósito Central', 'sucursal_id' => 1, 'ubicacion' => 'Oberá']);

        $page = $masters->page($this->operator());

        self::assertSame('Michelin', $page['catalogs']['marcas']['items'][0]['name']);
        self::assertSame('Michelin', $page['catalogs']['modelos']['items'][0]['brandName']);
        self::assertSame('LISA', $page['catalogs']['tipos-cubierta']['items'][0]['name']);
        self::assertSame('Oberá', $page['catalogs']['depositos']['items'][0]['branchName']);
        self::assertTrue($page['catalogs']['depositos']['items'][0]['active']);
        self::assertSame(['Oberá', 'Posadas'], array_column($page['branches'], 'name'));
        self::assertTrue($page['canEdit']);
    }

    public function testAViewerSeesButCannotEdit(): void
    {
        $page = $this->masters()->page($this->actor(['neumaticos.ver']));
        self::assertFalse($page['canEdit']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso para modificar los maestros de neumáticos.');

        $this->masters()->save($this->actor(['neumaticos.ver']), 'marcas', ['nombre' => 'Michelin']);
    }

    public function testRejectsADuplicateNameIgnoringCase(): void
    {
        $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ya existe la marca "MICHELIN" en la empresa.');

        $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'MICHELIN']);
    }

    public function testAnotherCompanyCanUseTheSameNameAndDoesNotSeeOurs(): void
    {
        $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin']);
        $other = new ActorContext(20, 8, false, true, [], ['neumaticos.operar'], []);

        $this->masters()->save($other, 'marcas', ['nombre' => 'Michelin']);

        self::assertCount(1, $this->masters()->page($other)['catalogs']['marcas']['items']);
    }

    public function testAModelNeedsAnActiveBrandOfTheCompany(): void
    {
        $other = new ActorContext(20, 8, false, true, [], ['neumaticos.operar'], []);
        $foreignBrand = $this->masters()->save($other, 'marcas', ['nombre' => 'Xbri']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Elegí una marca activa para el modelo.');

        $this->masters()->save($this->operator(), 'modelos', ['nombre' => 'Ecoplus', 'marca_id' => $foreignBrand]);
    }

    public function testTheSameModelNameIsAllowedUnderAnotherBrand(): void
    {
        $michelin = $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin']);
        $xbri = $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Xbri']);
        $this->masters()->save($this->operator(), 'modelos', ['nombre' => 'Multi', 'marca_id' => $michelin]);

        $this->masters()->save($this->operator(), 'modelos', ['nombre' => 'Multi', 'marca_id' => $xbri]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Esa marca ya tiene un modelo llamado "multi".');

        $this->masters()->save($this->operator(), 'modelos', ['nombre' => 'multi', 'marca_id' => $michelin]);
    }

    public function testInactivatingAndReactivatingKeepsTheRecord(): void
    {
        $brand = $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin']);

        $this->masters()->setActive($this->operator(), 'marcas', $brand, false);
        self::assertFalse($this->masters()->page($this->operator())['catalogs']['marcas']['items'][0]['active']);
        self::assertFalse((new CodeIgniterNeumaticCatalog($this->database))->isActiveBrand(7, $brand));

        $this->masters()->setActive($this->operator(), 'marcas', $brand, true);
        self::assertTrue($this->masters()->page($this->operator())['catalogs']['marcas']['items'][0]['active']);
    }

    public function testUpdatesAMasterOfTheCompanyOnly(): void
    {
        $brand = $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin']);
        $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelín'], $brand);
        self::assertSame('Michelín', $this->masters()->page($this->operator())['catalogs']['marcas']['items'][0]['name']);

        $other = new ActorContext(20, 8, false, true, [], ['neumaticos.operar'], []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No encontramos la marca o no está autorizado.');

        $this->masters()->save($other, 'marcas', ['nombre' => 'Robada'], $brand);
    }

    public function testRenamingToItsOwnNameIsNotADuplicate(): void
    {
        $brand = $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin']);

        self::assertSame($brand, $this->masters()->save($this->operator(), 'marcas', ['nombre' => 'Michelin'], $brand));
    }

    public function testADepositNeedsAnActiveBranchInTheActorScope(): void
    {
        $scoped = new ActorContext(12, 7, false, false, [], ['neumaticos.operar'], [1]);

        foreach ([3 => 'sucursal inactiva', 9 => 'sucursal de otra empresa', 2 => 'sucursal fuera del scope'] as $branch => $case) {
            try {
                $this->masters()->save($scoped, 'depositos', ['nombre' => 'D ' . $branch, 'sucursal_id' => $branch]);
                self::fail('Aceptó ' . $case . '.');
            } catch (DomainException $exception) {
                self::assertSame('Elegí una sucursal activa y autorizada para el depósito.', $exception->getMessage(), $case);
            }
        }

        self::assertGreaterThan(0, $this->masters()->save($scoped, 'depositos', ['nombre' => 'D 1', 'sucursal_id' => 1]));
    }

    public function testDepositsOfAnotherBranchAreNotVisibleNorEditable(): void
    {
        $deposit = $this->masters()->save($this->operator(), 'depositos', ['nombre' => 'Posadas', 'sucursal_id' => 2]);
        $scoped = new ActorContext(12, 7, false, false, [], ['neumaticos.operar'], [1]);

        self::assertSame([], $this->masters()->page($scoped)['catalogs']['depositos']['items']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No encontramos el depósito o no está autorizado.');

        $this->masters()->setActive($scoped, 'depositos', $deposit, false);
    }

    public function testRejectsAnUnknownCatalogAndAnEmptyName(): void
    {
        try {
            $this->masters()->save($this->operator(), 'proveedores', ['nombre' => 'X']);
            self::fail('Aceptó un maestro inexistente.');
        } catch (DomainException $exception) {
            self::assertSame('El maestro elegido no existe.', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Indicá el nombre del tipo de cubierta.');

        $this->masters()->save($this->operator(), 'tipos-cubierta', ['descripcion' => '   ']);
    }

    private function masters(): NeumaticMasterCatalog
    {
        return new NeumaticMasterCatalog(
            new CodeIgniterNeumaticMasterRepository($this->database),
            new CodeIgniterNeumaticCatalog($this->database),
            new CodeIgniterNeumaticBranchScope($this->database),
        );
    }

    private function operator(): ActorContext
    {
        return $this->actor(['neumaticos.operar']);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], $permissions, []);
    }
}
