<?php

declare(strict_types=1);

use App\Application\Audit\AuditEntry;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\AnnulCustomerCharge;
use App\Application\CustomerAccounts\FormalizeLegCharge;
use App\Application\CustomerAccounts\FormalizeLegChargeCommand;
use App\Application\CustomerAccounts\GetCommercialClosingQueue;
use App\Application\CustomerAccounts\GetCustomerStatement;
use App\Application\CustomerAccounts\ListCustomerAccounts;
use App\Application\CustomerAccounts\MarkLegNonCollectible;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\RegisterOpeningBalance;
use App\Application\CustomerAccounts\RegisterOpeningBalanceCommand;
use App\Application\CustomerAccounts\ReopenNonCollectibleLeg;
use App\Application\Identity\ActorContext;
use App\Database\Migrations\AddMultimonedaCustomerAccounts;
use App\Database\Migrations\CreateCustomerAccounts;
use App\Infrastructure\CustomerAccounts\CodeIgniterBillableLegLookup;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountReadModel;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerPaymentReadModel;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountRepository;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountsUnitOfWork;
use App\Infrastructure\CustomerAccounts\CodeIgniterExchangeRateCatalog;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Cuenta corriente de clientes (#530) de punta a punta: migración real,
 * adaptadores CI4 y casos de uso, sobre SQLite. Los tipos de las columnas de
 * Viajes y Clientes replican los de sus migraciones (AGENTS.md, DoD 9).
 */
final class CustomerAccountsIntegrationTest extends TestCase
{
    private const ALL = ['clientes.cc.ver', 'clientes.cc.formalizar'];

    private BaseConnection $db;

    /** @var list<AuditEntry> */
    private array $audited = [];

    protected function setUp(): void
    {
        // Por defecto corre en SQLite. Con CUSTOMER_ACCOUNTS_MYSQL=host:usuario:clave:base
        // corre contra MySQL/MariaDB real, con InnoDB y foreign keys activas.
        $mysql = getenv('CUSTOMER_ACCOUNTS_MYSQL');
        if (is_string($mysql) && $mysql !== '') {
            [$host, $user, $password, $database] = explode(':', $mysql);
            $this->db = Database::connect(['hostname' => $host, 'username' => $user, 'password' => $password, 'database' => $database, 'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci'], false);
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            // Las tablas de cobranza (#588) se limpian también: sus claves foráneas
            // apuntan a los cargos y bloquean el borrado si quedan filas de otra prueba.
            foreach (['cliente_cc_imputaciones', 'cliente_cc_pagos', 'cliente_cc_movimientos', 'cliente_cc_tramos_no_cobrables', 'cliente_cc_cargos', 'rol_permisos', 'roles', 'permisos', 'viajes_cotizaciones', 'viajes_monedas', 'viaje_operativo_tramos', 'viajes_operativos', 'clientes', 'sucursales', 'usuarios', 'empresas'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $table);
            }
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
            $pk = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $int = 'INT UNSIGNED';
            $engine = ' ENGINE=InnoDB';
        } else {
            if (! extension_loaded('sqlite3')) {
                $this->markTestSkipped('La prueba de integración requiere sqlite3.');
            }
            $this->db = Database::connect(['database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true], false);
            $pk = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $int = 'INTEGER';
            $engine = '';
        }
        foreach ([
            "CREATE TABLE empresas (id {$pk})",
            "CREATE TABLE usuarios (id {$pk}, nombre VARCHAR(255))",
            "CREATE TABLE sucursales (id {$pk}, empresa_id {$int}, nombre VARCHAR(255))",
            "CREATE TABLE clientes (id {$pk}, empresa_id {$int} NOT NULL, nombre VARCHAR(255), identificacion_fiscal VARCHAR(50) NULL, email VARCHAR(255) NULL, telefono VARCHAR(50) NULL, activo TINYINT DEFAULT 1, deleted_at DATETIME NULL, UNIQUE KEY uq_clientes_empresa_id (empresa_id, id))",
            "CREATE TABLE viajes_operativos (id {$pk}, empresa_id {$int}, sucursal_id {$int}, numero {$int}, estado VARCHAR(20) DEFAULT 'PLANIFICADO', fecha_regreso_real DATETIME NULL)",
            "CREATE TABLE viaje_operativo_tramos (id {$pk}, viaje_id {$int}, empresa_id {$int} NOT NULL, sucursal_id {$int}, orden {$int}, cliente_id {$int} NULL, origen VARCHAR(200), destino VARCHAR(200), moneda CHAR(3) DEFAULT 'ARS', cotizacion DECIMAL(18,6) DEFAULT 1, flete_facturacion DECIMAL(18,4) NULL, flete_facturacion_ars DECIMAL(18,4) NULL, fecha_inicio DATE NULL, fecha_fin DATE NULL, anulado_at DATETIME NULL, anulado_por {$int} NULL, motivo_anulacion VARCHAR(500) NULL, UNIQUE KEY uq_viaje_operativo_tramos_empresa_id (empresa_id, id))",
            "CREATE TABLE viajes_monedas (id {$pk}, codigo CHAR(3), activo TINYINT)",
            "CREATE TABLE viajes_cotizaciones (id {$pk}, empresa_id {$int}, moneda CHAR(3), cotizacion DECIMAL(18,6), updated_at DATETIME NULL)",
            "CREATE TABLE permisos (id {$pk}, clave VARCHAR(100) UNIQUE, descripcion VARCHAR(255), created_at DATETIME NULL, updated_at DATETIME NULL)",
            "CREATE TABLE roles (id {$pk}, nombre VARCHAR(100))",
            "CREATE TABLE rol_permisos (id {$pk}, rol_id {$int}, permiso_id {$int}, created_at DATETIME NULL)",
        ] as $sql) {
            $this->db->query(($engine === '' ? preg_replace('/, UNIQUE KEY [a-z_]+ \(/', ', UNIQUE (', $sql) : $sql) . $engine);
        }
        $this->db->table('empresas')->insertBatch([['id' => 5], ['id' => 6]]);
        $this->db->table('usuarios')->insert(['id' => 1, 'nombre' => 'Carlos']);
        $this->db->table('sucursales')->insertBatch([['id' => 2, 'empresa_id' => 5, 'nombre' => 'Argentina'], ['id' => 3, 'empresa_id' => 5, 'nombre' => 'Brasil']]);
        $this->db->table('clientes')->insertBatch([
            ['id' => 4, 'empresa_id' => 5, 'nombre' => 'Grupo Pluma'],
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Lottus Transportes'],
            ['id' => 8, 'empresa_id' => 6, 'nombre' => 'Cliente de otra empresa'],
        ]);
        $this->db->table('viajes_operativos')->insertBatch([
            ['id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'numero' => 16, 'estado' => 'FINALIZADO', 'fecha_regreso_real' => '2026-11-02 00:00:00'],
            ['id' => 17, 'empresa_id' => 5, 'sucursal_id' => 2, 'numero' => 17, 'estado' => 'EN_CURSO', 'fecha_regreso_real' => null],
            ['id' => 18, 'empresa_id' => 5, 'sucursal_id' => 3, 'numero' => 18, 'estado' => 'FINALIZADO', 'fecha_regreso_real' => '2026-11-05 00:00:00'],
        ]);
        $this->db->table('viaje_operativo_tramos')->insertBatch([
            ['id' => 9, 'viaje_id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 1, 'cliente_id' => 4, 'origen' => 'Umuarama', 'destino' => 'Santiago', 'moneda' => 'USD', 'cotizacion' => 1530, 'flete_facturacion' => 2800, 'flete_facturacion_ars' => 4_284_000],
            ['id' => 10, 'viaje_id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 2, 'cliente_id' => 7, 'origen' => 'San Juan', 'destino' => 'Curitiba', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 4_147_000, 'flete_facturacion_ars' => 4_147_000],
            ['id' => 11, 'viaje_id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 3, 'cliente_id' => null, 'origen' => 'Curitiba', 'destino' => 'Posadas', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => null, 'flete_facturacion_ars' => null],
            ['id' => 12, 'viaje_id' => 17, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 1, 'cliente_id' => 4, 'origen' => 'Rosario', 'destino' => 'Córdoba', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 1000, 'flete_facturacion_ars' => 1000],
            ['id' => 13, 'viaje_id' => 18, 'empresa_id' => 5, 'sucursal_id' => 3, 'orden' => 1, 'cliente_id' => 7, 'origen' => 'Foz', 'destino' => 'Posadas', 'moneda' => 'BRL', 'cotizacion' => 280, 'flete_facturacion' => 3000, 'flete_facturacion_ars' => 840_000],
        ]);
        $this->db->table('viajes_monedas')->insertBatch([['codigo' => 'USD', 'activo' => 1], ['codigo' => 'BRL', 'activo' => 1]]);
        $this->db->table('roles')->insertBatch([['id' => 1, 'nombre' => 'Administrador'], ['id' => 2, 'nombre' => 'Gerencial'], ['id' => 3, 'nombre' => 'Consulta']]);
        $this->db->table('permisos')->insert(['clave' => 'viajes.economico.ver', 'descripcion' => 'x']);
        $this->db->table('rol_permisos')->insert(['rol_id' => 2, 'permiso_id' => 1]);

        require_once APPPATH . 'Database/Migrations/2026-11-18-090000_CreateCustomerAccounts.php';
        (new CreateCustomerAccounts(Database::forge($this->db)))->up();
        require_once APPPATH . 'Database/Migrations/2026-11-26-100000_AddMultimonedaCustomerAccounts.php';
        (new AddMultimonedaCustomerAccounts(Database::forge($this->db)))->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testMigrationGrantsViewToEconomicRolesAndFormalizeOnlyToAdministrators(): void
    {
        // `clientes.cc.cobrar` lo agrega la etapa multimoneda (#588).
        self::assertSame(['clientes.cc.cobrar', 'clientes.cc.formalizar', 'clientes.cc.ver'], $this->permissionsOf(1));
        self::assertSame(['clientes.cc.ver', 'viajes.economico.ver'], $this->permissionsOf(2));
        self::assertSame([], $this->permissionsOf(3));

        (new CreateCustomerAccounts(Database::forge($this->db)))->up();
        self::assertSame(['clientes.cc.cobrar', 'clientes.cc.formalizar', 'clientes.cc.ver'], $this->permissionsOf(1), 'up() es idempotente');
    }

    public function testDownRefusesOnceThereAreMovementsAndCleansUpWhenEmpty(): void
    {
        $migration = new CreateCustomerAccounts(Database::forge($this->db));
        $chargeId = $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1000, 'ARS', null);
        try {
            $migration->down();
            self::fail('Con movimientos no se revierte la cuenta corriente.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ya tiene movimientos', $exception->getMessage());
        }
        self::assertTrue($this->db->tableExists('cliente_cc_cargos'));

        $this->db->table('cliente_cc_movimientos')->where('cargo_id', $chargeId)->delete();
        $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->delete();

        // La etapa multimoneda (#588) es la última migración y se revierte primero:
        // sus tablas referencian a los cargos y MySQL no permite soltar la tabla
        // padre con la hija viva.
        (new AddMultimonedaCustomerAccounts(Database::forge($this->db)))->down();
        $this->db->resetDataCache();
        self::assertFalse($this->db->tableExists('cliente_cc_pagos'));
        self::assertFalse($this->db->tableExists('cliente_cc_imputaciones'));

        $migration->down();
        $this->db->resetDataCache();
        self::assertFalse($this->db->tableExists('cliente_cc_cargos'));
        self::assertSame([], $this->permissionsOf(1));
        self::assertSame(0, $this->db->table('permisos')->like('clave', 'clientes.cc', 'after')->countAllResults());
    }

    public function testFinishingATripDoesNotCreateDebtUntilTheLegIsFormalized(): void
    {
        $queue = $this->queue()->execute($this->actor());

        self::assertSame(['PENDIENTE' => 3, 'SIN_CLIENTE' => 1, 'FORMALIZADO' => 0, 'NO_COBRABLE' => 0], $queue['counts']);
        self::assertSame([9, 10, 13], array_column($queue['rows'], 'tramo_id'), 'Solo tramos de viajes finalizados, el más viejo primero.');
        self::assertSame(0, $this->db->table('cliente_cc_movimientos')->countAllResults());
    }

    public function testAuthorizedMemoCreatesASingleArsDebitAtItsOwnRate(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', 1578, 'M-77');

        $charge = $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray();
        self::assertEquals(4_418_400, $charge['importe_ars']);
        self::assertEquals(1530, $charge['cotizacion_estimada'], 'La estimación del viaje queda guardada sin reescribirse.');
        self::assertEquals(4_284_000, $charge['importe_estimado_ars']);
        self::assertSame('MANUAL', $charge['cotizacion_fuente']);

        $statement = (new GetCustomerStatement($this->readModel(), new CodeIgniterCustomerPaymentReadModel($this->db), new CodeIgniterExchangeRateCatalog($this->db)))->execute($this->actor(), 4);
        self::assertSame(4_418_400.0, $statement['totals']['saldo_ars']);
        self::assertCount(1, $statement['movements']);
        self::assertSame('Viaje #16 · Tramo 1 · Memo autorizado M-77', $statement['movements'][0]['descripcion']);
        self::assertSame('USD', $statement['movements'][0]['moneda']);

        $this->expectExceptionMessage('ya tiene el cobro formalizado');
        $this->formalize(9, 'FACTURA_EMITIDA', 2800, 'USD', 1490, '0003-00001234', 'FACTURA_A');
    }

    public function testAnnullingKeepsHistoryAndLetsTheLegBeFormalizedAgain(): void
    {
        $first = $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', 1578, 'M-77');
        $this->annul()->execute($this->actor(), $first, 'El cliente pidió facturar en lugar del memo');

        self::assertSame(1, $this->queue()->execute($this->actor())['counts']['PENDIENTE'] - 2, 'El tramo vuelve a pendientes.');
        $this->formalize(9, 'FACTURA_EMITIDA', 2800, 'USD', 1490, '0003-00001234', 'FACTURA_A');

        $statement = (new GetCustomerStatement($this->readModel(), new CodeIgniterCustomerPaymentReadModel($this->db), new CodeIgniterExchangeRateCatalog($this->db)))->execute($this->actor(), 4);
        // Los cargos van con la fecha de su documento (10/11) y el contraasiento
        // con la del día en que se anuló (17/11).
        self::assertSame(['CARGO', 'CARGO', 'ANULACION'], array_column($statement['movements'], 'tipo'));
        self::assertSame([4_418_400.0, 8_590_400.0, 4_172_000.0], array_column($statement['movements'], 'saldo_ars'));
        self::assertSame(4_172_000.0, $statement['totals']['saldo_ars']);
        self::assertTrue($statement['movements'][0]['cargo_anulado']);
        self::assertFalse($statement['movements'][0]['cargo_vigente']);
        self::assertTrue($statement['movements'][1]['cargo_vigente']);
        self::assertSame('MEMO_AUTORIZADO', $this->db->table('cliente_cc_cargos')->where('id', $first)->get()->getRowArray()['modalidad']);
        self::assertSame('ANULADO', $this->db->table('cliente_cc_cargos')->where('id', $first)->get()->getRowArray()['estado']);
        self::assertSame(
            ['cliente_cc.cargo_registrado', 'cliente_cc.cargo_anulado', 'cliente_cc.cargo_registrado'],
            array_map(static fn (AuditEntry $entry): string => $entry->action->value, $this->audited),
        );
        self::assertSame('El cliente pidió facturar en lugar del memo', $this->audited[1]->reason);
        self::assertSame(2, $this->audited[0]->branchId, 'El cargo de un tramo se audita con la sucursal del viaje.');
        self::assertSame(4_418_400.0, $this->audited[0]->after['importe_ars']);

        $this->expectExceptionMessage('ya está anulado');
        $this->annul()->execute($this->actor(), $first, 'Segundo intento de anular');
    }

    public function testInternalClosingIsNumberedAndPesosNeedNoRate(): void
    {
        $chargeId = $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 4_147_000, 'ARS', null);

        $charge = $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray();
        self::assertSame('LI-' . str_pad((string) $chargeId, 6, '0', STR_PAD_LEFT), $charge['documento_numero']);
        self::assertEquals(1, $charge['cotizacion']);
        self::assertSame('PESOS', $charge['cotizacion_fuente']);
    }

    public function testForeignCurrencyWithoutRateUsesTheCompanyCatalogOrAsksForIt(): void
    {
        try {
            $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', null, 'M-1');
            self::fail('Sin cotización ni catálogo no se puede formalizar.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('Indicá la cotización de USD', $exception->getMessage());
        }

        $this->db->table('viajes_cotizaciones')->insert(['empresa_id' => 5, 'moneda' => 'USD', 'cotizacion' => 1600]);
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', null, 'M-1');
        $charge = $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray();
        self::assertSame('CATALOGO_EMPRESA', $charge['cotizacion_fuente']);
        self::assertEquals(4_480_000, $charge['importe_ars']);

        // Aceptar la cotización precargada sin cambiarla también es fuente catálogo;
        // cambiarla la vuelve manual.
        $this->annul()->execute($this->actor(), $chargeId, 'Prueba de fuente de cotización');
        $accepted = $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', 1600, 'M-2');
        self::assertSame('CATALOGO_EMPRESA', $this->db->table('cliente_cc_cargos')->where('id', $accepted)->get()->getRowArray()['cotizacion_fuente']);
        $this->annul()->execute($this->actor(), $accepted, 'Prueba de fuente de cotización');
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', 1600.5, 'M-3');
        self::assertSame('MANUAL', $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray()['cotizacion_fuente']);
        $this->annul()->execute($this->actor(), $chargeId, 'Prueba de fuente de cotización');
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2800, 'USD', null, 'M-4');

        // Cambiar el catálogo después no recalcula el cargo.
        $this->db->table('viajes_cotizaciones')->where('moneda', 'USD')->update(['cotizacion' => 2000]);
        self::assertEquals(4_480_000, $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray()['importe_ars']);
    }

    public function testNonCollectibleBlocksFormalizationUntilReopened(): void
    {
        $legs = new CodeIgniterBillableLegLookup($this->db);
        (new MarkLegNonCollectible($legs, $this->repository(), $this->uow(), $this->clock(), $this->audit()))
            ->execute($this->actor(), 10, 'Flete de cortesía acordado con gerencia');

        self::assertSame(1, $this->queue()->execute($this->actor())['counts']['NO_COBRABLE']);
        self::assertSame(0, $this->db->table('cliente_cc_movimientos')->countAllResults(), 'No cobrable no genera deuda.');

        try {
            $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1000, 'ARS', null);
            self::fail('Un tramo no cobrable no se formaliza.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no cobrable', $exception->getMessage());
        }

        (new ReopenNonCollectibleLeg($legs, $this->repository(), $this->uow(), $this->clock(), $this->audit()))
            ->execute($this->actor(), 10, 'Gerencia decidió cobrarlo');
        $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1000, 'ARS', null);
        self::assertSame(1, $this->queue()->execute($this->actor())['counts']['FORMALIZADO']);
        self::assertSame(
            ['cliente_cc.tramo_no_cobrable', 'cliente_cc.tramo_reabierto', 'cliente_cc.cargo_registrado'],
            array_map(static fn (AuditEntry $entry): string => $entry->action->value, $this->audited),
        );
    }

    public function testOnlyFinishedTripsOfTheCompanyAndBranchCanBeFormalized(): void
    {
        foreach ([
            [12, $this->actor(), 'viajes finalizados'],
            [13, new ActorContext(1, 5, false, false, [], self::ALL, [2]), 'no existe o no está autorizado'],
            [9, new ActorContext(1, 6, false, true, [], self::ALL, []), 'no existe o no está autorizado'],
            [9, new ActorContext(1, 5, false, true, [], ['clientes.cc.ver'], []), 'No tenés permiso'],
        ] as [$legId, $actor, $message]) {
            try {
                $this->formalize($legId, 'CIERRE_COMERCIAL_APROBADO', 1000, 'ARS', null, null, null, $actor);
                self::fail('Debía rechazarse el tramo ' . $legId);
            } catch (DomainException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        $branchQueue = $this->queue()->execute(new ActorContext(1, 5, false, false, [], self::ALL, [3]));
        self::assertSame([13], array_column($branchQueue['rows'], 'tramo_id'));
    }

    public function testCancelledLegsLeaveTheQueueAndCannotBeFormalized(): void
    {
        $this->db->table('viaje_operativo_tramos')->where('id', 13)->update([
            'anulado_at' => '2026-11-20 16:00:00', 'motivo_anulacion' => 'Cargado por error',
        ]);

        $branchQueue = $this->queue()->execute(new ActorContext(1, 5, false, false, [], self::ALL, [3]));
        self::assertSame([], array_column($branchQueue['rows'], 'tramo_id'));

        $this->expectExceptionMessage('no existe o no está autorizado');
        $this->formalize(13, 'CIERRE_COMERCIAL_APROBADO', 1000, 'ARS', null, null, null, new ActorContext(1, 5, false, false, [], self::ALL, [3]));
    }

    public function testOpeningBalanceIsUniquePerCustomerAndShowsInTheAccountList(): void
    {
        $register = new RegisterOpeningBalance($this->repository(), new CodeIgniterExchangeRateCatalog($this->db), $this->uow(), $this->clock(), $this->audit());
        $register->execute($this->actor(), new RegisterOpeningBalanceCommand(7, 'DEBE', new DateTimeImmutable('2026-11-01'), 250_000, 'ARS', null, 'Resumen ERP octubre'));
        $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 4_147_000, 'ARS', null);

        $accounts = (new ListCustomerAccounts($this->readModel(), new CodeIgniterExchangeRateCatalog($this->db)))->execute($this->actor());
        self::assertSame(['Lottus Transportes'], array_column($accounts['rows'], 'nombre'));
        self::assertSame(4_397_000.0, $accounts['totals']['saldo_ars']);

        try {
            $register->execute($this->actor(), new RegisterOpeningBalanceCommand(7, 'HABER', new DateTimeImmutable('2026-11-01'), 10, 'ARS', null, null));
            self::fail('Un cliente tiene un solo saldo inicial vigente.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('ya tiene un saldo inicial', $exception->getMessage());
        }

        $this->expectExceptionMessage('no pertenece a la empresa');
        $register->execute($this->actor(), new RegisterOpeningBalanceCommand(8, 'DEBE', new DateTimeImmutable('2026-11-01'), 10, 'ARS', null, null));
    }

    public function testLedgerKeysMakeEntriesIdempotent(): void
    {
        $chargeId = $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1000, 'ARS', null);

        $this->expectException(Throwable::class);
        $this->db->table('cliente_cc_movimientos')->insert([
            'empresa_id' => 5, 'cliente_id' => 7, 'fecha' => '2026-11-10', 'tipo' => 'CARGO', 'cargo_id' => $chargeId,
            'debe_ars' => 1000, 'haber_ars' => 0, 'descripcion' => 'duplicado', 'clave' => 'CARGO:' . $chargeId,
        ]);
    }

    private function formalize(
        int $legId,
        string $modality,
        float $amount,
        string $currency,
        ?float $rate,
        ?string $number = null,
        ?string $invoiceType = null,
        ?ActorContext $actor = null,
    ): int {
        return (new FormalizeLegCharge(
            new CodeIgniterBillableLegLookup($this->db),
            $this->repository(),
            new CodeIgniterExchangeRateCatalog($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute($actor ?? $this->actor(), new FormalizeLegChargeCommand(
            $legId, $modality, new DateTimeImmutable('2026-11-10'), $number, $invoiceType, $amount, $currency, $rate,
        ));
    }

    private function queue(): GetCommercialClosingQueue
    {
        return new GetCommercialClosingQueue($this->readModel(), new CodeIgniterExchangeRateCatalog($this->db));
    }

    private function annul(): AnnulCustomerCharge
    {
        return new AnnulCustomerCharge($this->repository(), $this->uow(), $this->clock(), $this->audit());
    }

    private function audit(): AuditTrail
    {
        $test = $this;

        return new class ($test) implements AuditTrail {
            public function __construct(private readonly CustomerAccountsIntegrationTest $test)
            {
            }

            public function record(AuditEntry $entry): void
            {
                $this->test->audited($entry);
            }
        };
    }

    public function audited(AuditEntry $entry): void
    {
        $this->audited[] = $entry;
    }

    private function repository(): CodeIgniterCustomerAccountRepository
    {
        return new CodeIgniterCustomerAccountRepository($this->db);
    }

    private function readModel(): CodeIgniterCustomerAccountReadModel
    {
        return new CodeIgniterCustomerAccountReadModel($this->db);
    }

    private function uow(): CodeIgniterCustomerAccountsUnitOfWork
    {
        return new CodeIgniterCustomerAccountsUnitOfWork($this->db);
    }

    private function clock(): CustomerAccountsClock
    {
        return new class () implements CustomerAccountsClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-17 18:00:00');
            }
        };
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, true, [], self::ALL, []);
    }

    /** @return list<string> */
    private function permissionsOf(int $roleId): array
    {
        $keys = array_column($this->db->table('rol_permisos rp')->select('p.clave')->join('permisos p', 'p.id = rp.permiso_id')
            ->where('rp.rol_id', $roleId)->get()->getResultArray(), 'clave');
        sort($keys);

        return $keys;
    }
}
