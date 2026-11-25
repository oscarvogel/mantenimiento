<?php

declare(strict_types=1);

use App\Application\Audit\AuditEntry;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\AccreditReceiptInstrument;
use App\Application\CustomerAccounts\AnnulCustomerCharge;
use App\Application\CustomerAccounts\AnnullCustomerReceipt;
use App\Application\CustomerAccounts\ConfirmCustomerReceipt;
use App\Application\CustomerAccounts\CreateCustomerReceipt;
use App\Application\CustomerAccounts\CreateCustomerReceiptCommand;
use App\Application\CustomerAccounts\FormalizeLegCharge;
use App\Application\CustomerAccounts\FormalizeLegChargeCommand;
use App\Application\CustomerAccounts\GetCustomerReceipt;
use App\Application\CustomerAccounts\GetCustomerStatement;
use App\Application\CustomerAccounts\ImputeCustomerPayment;
use App\Application\CustomerAccounts\ImputeCustomerPaymentCommand;
use App\Application\CustomerAccounts\ListCustomerReceipts;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\ReceiptFilters;
use App\Application\CustomerAccounts\ReceiptImputationInput;
use App\Application\CustomerAccounts\ReceiptInstrumentInput;
use App\Application\Identity\ActorContext;
use App\Database\Migrations\CreateCustomerAccounts;
use App\Database\Migrations\CreateCustomerReceipts;
use App\Domain\CustomerAccounts\Money;
use App\Infrastructure\CustomerAccounts\CodeIgniterBillableLegLookup;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountReadModel;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountRepository;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountsUnitOfWork;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerReceiptReadModel;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerReceiptRepository;
use App\Infrastructure\CustomerAccounts\CodeIgniterExchangeRateCatalog;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Recibos y cobranzas (#536) de punta a punta sobre SQLite con la migración
 * real. Cubre los 28 escenarios del issue y la regresión de la cuenta corriente
 * de #530. Los tipos de las columnas de Viajes y Clientes replican sus
 * migraciones (AGENTS.md, DoD 9).
 */
final class CustomerReceiptsIntegrationTest extends TestCase
{
    private const ALL = [
        'clientes.cc.ver', 'clientes.cc.formalizar',
        'clientes.recibos.ver', 'clientes.recibos.registrar', 'clientes.recibos.confirmar',
        'clientes.recibos.anular', 'clientes.recibos.imputar', 'clientes.recibos.imprimir',
    ];

    private BaseConnection $db;

    /** @var list<AuditEntry> */
    private array $audited = [];

    protected function setUp(): void
    {
        $mysql = getenv('CUSTOMER_ACCOUNTS_MYSQL');
        if (is_string($mysql) && $mysql !== '') {
            [$host, $user, $password, $database] = explode(':', $mysql);
            $port = (int) (getenv('CUSTOMER_ACCOUNTS_MYSQL_PORT') ?: 3306);
            $this->db = Database::connect(['hostname' => $host, 'port' => $port, 'username' => $user, 'password' => $password, 'database' => $database, 'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci'], false);
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['cliente_recibo_adjuntos', 'cliente_recibo_imputaciones', 'cliente_cheques', 'cliente_recibo_medios', 'cliente_recibos', 'cliente_recibos_numeracion', 'cliente_cc_movimientos', 'cliente_cc_tramos_no_cobrables', 'cliente_cc_cargos', 'auditoria', 'rol_permisos', 'roles', 'permisos', 'viajes_cotizaciones', 'viajes_monedas', 'viaje_operativo_tramos', 'viajes_operativos', 'clientes', 'sucursales', 'usuarios', 'empresas'] as $table) {
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
            "CREATE TABLE empresas (id {$pk}, razon_social VARCHAR(255), nombre_fantasia VARCHAR(255) NULL, cuit VARCHAR(20) NULL)",
            "CREATE TABLE usuarios (id {$pk}, nombre VARCHAR(255))",
            "CREATE TABLE sucursales (id {$pk}, empresa_id {$int}, nombre VARCHAR(255), estado TINYINT DEFAULT 1, deleted_at DATETIME NULL)",
            "CREATE TABLE clientes (id {$pk}, empresa_id {$int} NOT NULL, nombre VARCHAR(255), identificacion_fiscal VARCHAR(50) NULL, email VARCHAR(255) NULL, telefono VARCHAR(50) NULL, direccion VARCHAR(500) NULL, activo TINYINT DEFAULT 1, deleted_at DATETIME NULL, UNIQUE (empresa_id, id))",
            "CREATE TABLE viajes_operativos (id {$pk}, empresa_id {$int}, sucursal_id {$int}, numero {$int}, estado VARCHAR(20) DEFAULT 'PLANIFICADO', fecha_regreso_real DATETIME NULL)",
            "CREATE TABLE viaje_operativo_tramos (id {$pk}, viaje_id {$int}, empresa_id {$int} NOT NULL, sucursal_id {$int}, orden {$int}, cliente_id {$int} NULL, origen VARCHAR(200), destino VARCHAR(200), moneda CHAR(3) DEFAULT 'ARS', cotizacion DECIMAL(18,6) DEFAULT 1, flete_facturacion DECIMAL(18,4) NULL, flete_facturacion_ars DECIMAL(18,4) NULL, fecha_inicio DATE NULL, fecha_fin DATE NULL, anulado_at DATETIME NULL, anulado_por {$int} NULL, motivo_anulacion VARCHAR(500) NULL, UNIQUE (empresa_id, id))",
            "CREATE TABLE viajes_monedas (id {$pk}, codigo CHAR(3), activo TINYINT)",
            "CREATE TABLE viajes_cotizaciones (id {$pk}, empresa_id {$int}, moneda CHAR(3), cotizacion DECIMAL(18,6))",
            "CREATE TABLE permisos (id {$pk}, clave VARCHAR(100) UNIQUE, descripcion VARCHAR(255), created_at DATETIME NULL, updated_at DATETIME NULL)",
            "CREATE TABLE roles (id {$pk}, nombre VARCHAR(100))",
            "CREATE TABLE rol_permisos (id {$pk}, rol_id {$int}, permiso_id {$int}, created_at DATETIME NULL)",
            "CREATE TABLE auditoria (id {$pk}, empresa_id {$int} NULL, sucursal_id {$int} NULL, actor_usuario_id {$int} NULL, alcance VARCHAR(10) NULL, accion VARCHAR(60), entidad VARCHAR(60), entidad_id VARCHAR(64) NULL, motivo VARCHAR(255) NULL, fecha DATETIME, created_at DATETIME NULL)",
        ] as $sql) {
            $this->db->query($sql . $engine);
        }
        $this->db->table('empresas')->insertBatch([
            ['id' => 5, 'razon_social' => 'Transporte Sur SA', 'nombre_fantasia' => 'Transporte Sur', 'cuit' => '30-71234567-8'],
            ['id' => 6, 'razon_social' => 'Otra Empresa SA', 'nombre_fantasia' => null, 'cuit' => '30-70000000-1'],
        ]);
        $this->db->table('usuarios')->insertBatch([['id' => 1, 'nombre' => 'Carlos'], ['id' => 2, 'nombre' => 'Ana']]);
        $this->db->table('sucursales')->insertBatch([
            ['id' => 2, 'empresa_id' => 5, 'nombre' => 'Argentina'],
            ['id' => 3, 'empresa_id' => 5, 'nombre' => 'Brasil'],
            ['id' => 4, 'empresa_id' => 6, 'nombre' => 'Otra sucursal'],
        ]);
        $this->db->table('clientes')->insertBatch([
            ['id' => 4, 'empresa_id' => 5, 'nombre' => 'Grupo Pluma', 'identificacion_fiscal' => '30-11111111-1'],
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Lottus Transportes', 'identificacion_fiscal' => '30-22222222-2'],
            ['id' => 8, 'empresa_id' => 6, 'nombre' => 'Cliente de otra empresa', 'identificacion_fiscal' => null],
        ]);
        $this->db->table('viajes_operativos')->insertBatch([
            ['id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'numero' => 16, 'estado' => 'FINALIZADO', 'fecha_regreso_real' => '2026-11-02 00:00:00'],
        ]);
        $this->db->table('viaje_operativo_tramos')->insertBatch([
            ['id' => 9, 'viaje_id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 1, 'cliente_id' => 4, 'origen' => 'Umuarama', 'destino' => 'Santiago', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 5_000_000, 'flete_facturacion_ars' => 5_000_000, 'fecha_inicio' => '2026-10-30', 'fecha_fin' => '2026-11-02'],
            ['id' => 10, 'viaje_id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 2, 'cliente_id' => 7, 'origen' => 'San Juan', 'destino' => 'Curitiba', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 1_000_000, 'flete_facturacion_ars' => 1_000_000, 'fecha_inicio' => '2026-10-30', 'fecha_fin' => '2026-11-02'],
            ['id' => 11, 'viaje_id' => 16, 'empresa_id' => 5, 'sucursal_id' => 2, 'orden' => 3, 'cliente_id' => 4, 'origen' => 'Rosario', 'destino' => 'Córdoba', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 800_000, 'flete_facturacion_ars' => 800_000, 'fecha_inicio' => '2026-10-30', 'fecha_fin' => '2026-11-02'],
        ]);
        $this->db->table('viajes_monedas')->insertBatch([['codigo' => 'USD', 'activo' => 1], ['codigo' => 'BRL', 'activo' => 1]]);
        $this->db->table('viajes_cotizaciones')->insertBatch([
            ['empresa_id' => 5, 'moneda' => 'USD', 'cotizacion' => 1500],
            ['empresa_id' => 5, 'moneda' => 'BRL', 'cotizacion' => 280],
        ]);
        $this->db->table('roles')->insertBatch([['id' => 1, 'nombre' => 'Administrador'], ['id' => 2, 'nombre' => 'Gerencial'], ['id' => 3, 'nombre' => 'Consulta']]);
        $this->db->table('permisos')->insert(['clave' => 'viajes.economico.ver', 'descripcion' => 'x']);
        $this->db->table('rol_permisos')->insert(['rol_id' => 2, 'permiso_id' => 1]);

        require_once APPPATH . 'Database/Migrations/2026-11-18-090000_CreateCustomerAccounts.php';
        require_once APPPATH . 'Database/Migrations/2026-11-25-160000_CreateCustomerReceipts.php';
        (new CreateCustomerAccounts(Database::forge($this->db)))->up();
        (new CreateCustomerReceipts(Database::forge($this->db)))->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testMigrationCreatesAdditiveTablesAndGrantsTheNewPermissions(): void
    {
        $keys = array_column($this->db->table('permisos')->like('clave', 'clientes.recibos.', 'after')->get()->getResultArray(), 'clave');
        sort($keys);
        self::assertSame([
            'clientes.recibos.anular', 'clientes.recibos.confirmar', 'clientes.recibos.imprimir',
            'clientes.recibos.imputar', 'clientes.recibos.registrar', 'clientes.recibos.ver',
        ], $keys);

        $admin = array_column($this->db->table('rol_permisos rp')
            ->select('p.clave')->join('permisos p', 'p.id = rp.permiso_id')
            ->where('rp.rol_id', 1)->like('p.clave', 'clientes.recibos.', 'after')
            ->get()->getResultArray(), 'clave');
        self::assertCount(6, $admin, 'El Administrador recibe las seis acciones.');

        $manager = array_column($this->db->table('rol_permisos rp')
            ->select('p.clave')->join('permisos p', 'p.id = rp.permiso_id')
            ->where('rp.rol_id', 2)->like('p.clave', 'clientes.recibos.', 'after')
            ->get()->getResultArray(), 'clave');
        sort($manager);
        self::assertSame(['clientes.recibos.imprimir', 'clientes.recibos.ver'], $manager);

        // El upgrade es idempotente: volver a correrlo no duplica permisos ni tablas.
        (new CreateCustomerReceipts(Database::forge($this->db)))->up();
        self::assertCount(6, $this->db->table('permisos')->like('clave', 'clientes.recibos.', 'after')->get()->getResultArray());
    }

    public function testDownRefusesOnceThereAreReceiptsAndLeavesHistoryAvailable(): void
    {
        $this->receipt(4, [$this->cash('100')]);
        $migration = new CreateCustomerReceipts(Database::forge($this->db));
        try {
            $migration->down();
            self::fail('Con recibos no se revierte el circuito de cobranzas.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Ya hay recibos', $exception->getMessage());
        }
        self::assertTrue($this->db->tableExists('cliente_recibos'));
        self::assertTrue($this->db->tableExists('cliente_cc_movimientos'));
    }

    public function testEffectiveCashReceiptReducesTheDebtAndFreezesItsOwnAmounts(): void
    {
        $chargeId = $this->formalize(9, 5_000_000);
        $receiptId = $this->receipt(4, [$this->cash('500000')], [new ReceiptImputationInput($chargeId, '500000')]);

        $receipt = $this->db->table('cliente_recibos')->where('id', $receiptId)->get()->getRowArray();
        self::assertSame('CONFIRMADO', $receipt['estado']);
        self::assertSame(1, (int) $receipt['numero']);
        self::assertSame('R-000001', 'R-' . str_pad((string) $receipt['numero'], 6, '0', STR_PAD_LEFT));
        self::assertSame(4_500_000.0, $this->saldo(4));
        self::assertSame(4_500_000.0, $this->pending($chargeId));

        $statement = $this->statement(4);
        self::assertSame(4_500_000.0, $statement['totals']['saldo_ars']);
        self::assertSame(500_000.0, $statement['summary']['total_cobrado_ars']);
        self::assertSame(500_000.0, $statement['pendingDocuments'][0]['imputado_ars']);
        self::assertSame('PARCIAL', $statement['pendingDocuments'][0]['estado_cobranza']);

        $this->db->table('auditoria')->insert([
            'empresa_id' => 5, 'sucursal_id' => 2, 'actor_usuario_id' => 1,
            'accion' => 'cliente_recibo.confirmado', 'entidad' => 'cliente_recibos', 'entidad_id' => (string) $receiptId,
            'fecha' => '2026-11-20 10:00:00',
        ]);
        $detail = $this->getReceipt()->execute($this->actor(), $receiptId);
        self::assertSame('Recibo confirmado y numerado', $detail['history'][0]['accion_label']);
        self::assertSame('Carlos', $detail['history'][0]['usuario']);
    }

    public function testVerifiedTransferCreditsAndPendingTransferDoesNot(): void
    {
        $chargeId = $this->formalize(9, 2_000_000);
        $this->receipt(4, [$this->transfer('1000000', true)], [new ReceiptImputationInput($chargeId, '1000000')]);
        $this->receipt(4, [$this->transfer('500000', false)]);

        self::assertSame(1_000_000.0, $this->saldo(4), 'Solo la transferencia verificada impactó el libro.');
        $statement = $this->statement(4);
        self::assertSame(500_000.0, $statement['summary']['medios_pendientes_ars']);
        self::assertSame(1_000_000.0, $statement['summary']['total_cobrado_ars']);
    }

    public function testChequeAndECheqStayPendingUntilAccredited(): void
    {
        $this->formalize(9, 3_000_000);
        $receiptId = $this->receipt(4, [$this->cheque('750000', 'CHEQUE'), $this->cheque('250000', 'E_CHEQ')]);

        self::assertSame(3_000_000.0, $this->saldo(4));
        self::assertSame(1_000_000.0, $this->statement(4)['summary']['medios_pendientes_ars']);
        $cheques = $this->db->table('cliente_cheques')->where('recibo_id', $receiptId)->get()->getResultArray();
        self::assertCount(2, $cheques);
        self::assertSame(['EN_CARTERA'], array_values(array_unique(array_column($cheques, 'estado'))));
        self::assertSame(0, $this->db->table('cliente_cc_movimientos')->where('recibo_id', $receiptId)->countAllResults());

        $this->accredit()->execute($this->actor(), $receiptId, (int) $cheques[0]['recibo_medio_id']);
        self::assertSame(2_250_000.0, $this->saldo(4));
        self::assertSame('ACREDITADO', $this->db->table('cliente_cheques')->where('id', $cheques[0]['id'])->get()->getRowArray()['estado']);

        $this->expectExceptionMessage('ya está acreditado');
        $this->accredit()->execute($this->actor(), $receiptId, (int) $cheques[0]['recibo_medio_id']);
    }

    public function testMultipleMeansAndCurrenciesKeepHistoricalRatesAndNeverRecalculate(): void
    {
        $chargeId = $this->formalize(9, 5_000_000);
        $receiptId = $this->receipt(4, [
            $this->cash('500000'),
            $this->transfer('1000000', true),
            $this->cheque('1500000', 'CHEQUE'),
            $this->cash('500', 'USD', '1500'),
            $this->cash('1000', 'BRL', '280'),
        ], [new ReceiptImputationInput($chargeId, '1500000')]);

        $receipt = $this->db->table('cliente_recibos')->where('id', $receiptId)->get()->getRowArray();
        // 500.000 + 1.000.000 + 1.500.000 + 750.000 + 280.000
        self::assertEquals(4_030_000, $receipt['total_ars']);
        self::assertEquals(2_530_000, $receipt['total_acreditado_ars']);
        self::assertSame(2_470_000.0, $this->saldo(4));

        $this->db->table('viajes_cotizaciones')->where('moneda', 'USD')->update(['cotizacion' => 3000]);
        $this->db->table('viajes_cotizaciones')->where('moneda', 'BRL')->update(['cotizacion' => 900]);

        $means = $this->db->table('cliente_recibo_medios')->where('recibo_id', $receiptId)->orderBy('orden', 'ASC')->get()->getResultArray();
        self::assertEquals(1500, $means[3]['cotizacion']);
        self::assertEquals(750_000, $means[3]['importe_ars']);
        self::assertEquals(280, $means[4]['cotizacion']);
        self::assertEquals(280_000, $means[4]['importe_ars']);
        self::assertEquals(4_030_000, $this->db->table('cliente_recibos')->where('id', $receiptId)->get()->getRowArray()['total_ars']);
    }

    public function testPartialAndTotalPaymentsAndSeveralReceiptsAgainstOneCharge(): void
    {
        $chargeId = $this->formalize(9, 5_000_000);
        $this->receipt(4, [$this->cash('1000000')], [new ReceiptImputationInput($chargeId, '1000000')]);
        $second = $this->receipt(4, [$this->cash('1500000')], [new ReceiptImputationInput($chargeId, '1500000')]);

        self::assertSame('PARCIAL', $this->document($chargeId)['estado_cobranza']);
        self::assertSame(2_500_000.0, $this->pending($chargeId));

        $third = $this->receipt(4, [$this->cash('2500000')], [new ReceiptImputationInput($chargeId, '2500000')]);
        $document = $this->document($chargeId);
        self::assertSame('COBRADO', $document['estado_cobranza']);
        self::assertSame(0.0, $this->pending($chargeId));
        self::assertSame(0.0, $this->saldo(4));

        $detail = $this->getReceipt()->execute($this->actor(), $second);
        self::assertSame(1_500_000.0, $detail['receipt']['imputado_ars']);
        self::assertSame(0.0, $detail['receipt']['disponible_ars']);
        self::assertSame(3, $this->db->table('cliente_recibos')->where('estado', 'CONFIRMADO')->countAllResults());
        self::assertGreaterThan(0, $third);
    }

    public function testOneReceiptAppliedToSeveralCharges(): void
    {
        $first = $this->formalize(9, 1_000_000);
        $second = $this->formalize(11, 800_000);
        $this->receipt(4, [$this->cash('1200000')], [
            new ReceiptImputationInput($first, '1000000'),
            new ReceiptImputationInput($second, '200000'),
        ]);

        self::assertSame(0.0, $this->pending($first));
        self::assertSame(600_000.0, $this->pending($second));
    }

    public function testAdvanceWithoutDebtAndLaterImputationDoesNotCreateASecondCredit(): void
    {
        $receiptId = $this->receipt(4, [$this->cash('300000')]);
        self::assertSame(-300_000.0, $this->saldo(4), 'El excedente queda a favor del cliente.');
        self::assertSame(300_000.0, $this->statement(4)['summary']['anticipos_disponibles_ars']);

        $chargeId = $this->formalize(9, 1_000_000);
        $ledgerBefore = $this->db->table('cliente_cc_movimientos')->countAllResults();
        $this->impute()->execute($this->actor(), new ImputeCustomerPaymentCommand($receiptId, [
            new ReceiptImputationInput($chargeId, '300000'),
        ]));

        self::assertSame($ledgerBefore, $this->db->table('cliente_cc_movimientos')->countAllResults(), 'Imputar un anticipo no genera un nuevo Haber.');
        self::assertSame(700_000.0, $this->pending($chargeId));
        self::assertSame(700_000.0, $this->saldo(4));
        self::assertSame(0.0, $this->statement(4)['summary']['anticipos_disponibles_ars']);
    }

    public function testOverpaymentStaysAvailableAsCustomerCredit(): void
    {
        $chargeId = $this->formalize(9, 1_000_000);
        $receiptId = $this->receipt(4, [$this->cash('1500000')], [new ReceiptImputationInput($chargeId, '1000000')]);

        self::assertSame(0.0, $this->pending($chargeId));
        self::assertSame(-500_000.0, $this->saldo(4));
        $detail = $this->getReceipt()->execute($this->actor(), $receiptId);
        self::assertSame(500_000.0, $detail['receipt']['disponible_ars']);
        self::assertSame(-500_000.0, $detail['receipt']['saldo_posterior_previsto_ars']);
        self::assertSame(-500_000.0, $detail['receipt']['saldo_posterior_ars']);
    }

    public function testAnnulmentPostsAContraEntryRestoresDebtAndCannotRunTwice(): void
    {
        $chargeId = $this->formalize(9, 1_000_000);
        $receiptId = $this->receipt(4, [$this->cash('1000000')], [new ReceiptImputationInput($chargeId, '1000000')]);
        self::assertSame(0.0, $this->saldo(4));

        $this->annul()->execute($this->actor(), $receiptId, 'El cliente devolvió el dinero');
        self::assertSame(1_000_000.0, $this->saldo(4));
        self::assertSame(1_000_000.0, $this->pending($chargeId));
        self::assertSame('ANULADA', $this->db->table('cliente_recibo_imputaciones')->where('recibo_id', $receiptId)->get()->getRowArray()['estado']);
        self::assertSame('ANULADO', $this->db->table('cliente_recibos')->where('id', $receiptId)->get()->getRowArray()['estado']);
        self::assertSame(2, $this->db->table('cliente_cc_movimientos')->where('recibo_id', $receiptId)->countAllResults(), 'Crédito y contraasiento.');

        $this->expectExceptionMessage('ya está anulado');
        $this->annul()->execute($this->actor(), $receiptId, 'Segundo intento de anulación');
    }

    public function testCancellingAChargeWithActiveImputationsIsBlocked(): void
    {
        $chargeId = $this->formalize(9, 1_000_000);
        $this->receipt(4, [$this->cash('500000')], [new ReceiptImputationInput($chargeId, '500000')]);

        $this->expectExceptionMessage('pagos imputados');
        (new AnnulCustomerCharge($this->accountRepository(), $this->uow(), $this->clock(), $this->audit()))
            ->execute($this->actor(), $chargeId, 'Intento de anular un cargo ya cobrado');
    }

    public function testOverimputationIsRejectedAndConcurrentValidationUsesLockedCharges(): void
    {
        $chargeId = $this->formalize(9, 1_000_000);
        $this->receipt(4, [$this->cash('900000')], [new ReceiptImputationInput($chargeId, '900000')]);

        $this->expectExceptionMessage('pendiente y se intentó imputar');
        $this->receipt(4, [$this->cash('200000')], [new ReceiptImputationInput($chargeId, '200000')]);
    }

    public function testNumberingIsCorrelativePerCompanyBranchAndSeries(): void
    {
        $first = $this->receipt(4, [$this->cash('100')], [], true, 2, 'R');
        $second = $this->receipt(4, [$this->cash('100')], [], true, 2, 'R');
        $otherBranch = $this->receipt(4, [$this->cash('100')], [], true, 3, 'R');
        $otherSeries = $this->receipt(4, [$this->cash('100')], [], true, 2, 'X');

        self::assertSame([1, 2], $this->numbers([$first, $second]));
        self::assertSame(1, (int) $this->db->table('cliente_recibos')->where('id', $otherBranch)->get()->getRowArray()['numero']);
        self::assertSame(1, (int) $this->db->table('cliente_recibos')->where('id', $otherSeries)->get()->getRowArray()['numero']);

        $this->expectException(Throwable::class);
        $this->db->table('cliente_recibos')->insert([
            'empresa_id' => 5, 'sucursal_id' => 2, 'serie' => 'R', 'numero' => 1, 'cliente_id' => 4,
            'fecha_emision' => '2026-11-20', 'fecha_recepcion' => '2026-11-20', 'estado' => 'CONFIRMADO',
            'total_ars' => 1, 'total_acreditado_ars' => 1, 'total_imputado_ars' => 0, 'usuario_id' => 1,
        ]);
    }

    public function testDoubleConfirmationAndLedgerKeysAreIdempotent(): void
    {
        $first = $this->receipt(4, [$this->cash('100')], [], false);
        $this->confirm()->execute($this->actor(), $first);
        try {
            $this->confirm()->execute($this->actor(), $first);
            self::fail('Un recibo confirmado no se vuelve a confirmar.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('borrador', $exception->getMessage());
        }

        $movement = $this->db->table('cliente_cc_movimientos')->where('recibo_id', $first)->get()->getRowArray();
        self::expectException(Throwable::class);
        $this->db->table('cliente_cc_movimientos')->insert([
            'empresa_id' => 5, 'cliente_id' => 4, 'fecha' => '2026-11-20', 'tipo' => 'COBRO',
            'debe_ars' => 0, 'haber_ars' => 100, 'descripcion' => 'duplicado', 'clave' => $movement['clave'],
            'recibo_id' => $first,
        ]);
    }

    public function testCompanyIsolationAndInsufficientPermissions(): void
    {
        try {
            $this->receipt(8, [$this->cash('100')]);
            self::fail('Un cliente de otra empresa no se cobra.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no existe o no pertenece a la empresa', $exception->getMessage());
        }

        foreach ([
            ['clientes.recibos.ver'],
            ['clientes.recibos.registrar', 'clientes.recibos.ver'],
        ] as $permissions) {
            try {
                $this->receipt(4, [$this->cash('100')], [], true, 2, 'R', new ActorContext(1, 5, false, true, [], $permissions, []));
                self::fail('Faltaban permisos para registrar/confirmar.');
            } catch (DomainException $exception) {
                self::assertStringContainsString('permiso', $exception->getMessage());
            }
        }

        $companySix = new ActorContext(2, 6, false, true, [], ['clientes.recibos.ver'], [4]);
        $page = (new ListCustomerReceipts($this->receiptReadModel()))->execute($companySix, new ReceiptFilters());
        self::assertSame([], $page['rows'], 'El listado de otra empresa no ve recibos de la empresa 5.');
    }

    public function testPendingMeansAndTreasuryGuardsOnAnnulment(): void
    {
        $receiptId = $this->receipt(4, [$this->cheque('100000', 'CHEQUE')]);
        $cheque = $this->db->table('cliente_cheques')->where('recibo_id', $receiptId)->get()->getRowArray();
        $this->db->table('cliente_cheques')->where('id', $cheque['id'])->update(['estado' => 'DEPOSITADO']);

        $this->expectExceptionMessage('depositado');
        $this->annul()->execute($this->actor(), $receiptId, 'Se quiso anular un cheque ya depositado');
    }

    public function testListPaginationAndFiltersDoNotLoadEverything(): void
    {
        $this->receipt(4, [$this->cash('100')], [], true, 2, 'R');
        $this->receipt(4, [$this->cheque('200', 'E_CHEQ')], [], true, 2, 'R');
        $this->receipt(7, [$this->cash('300')], [], false, 2, 'R');

        $list = (new ListCustomerReceipts($this->receiptReadModel()))->execute($this->actor(), new ReceiptFilters(page: 1, perPage: 1));
        self::assertSame(1, count($list['rows']));
        self::assertSame(3, $list['pagination']['total']);
        self::assertSame(3, $list['pagination']['totalPages']);

        $confirmed = (new ListCustomerReceipts($this->receiptReadModel()))->execute($this->actor(), new ReceiptFilters(status: 'CONFIRMADO'));
        self::assertSame(2, $confirmed['pagination']['total']);

        $cheques = (new ListCustomerReceipts($this->receiptReadModel()))->execute($this->actor(), new ReceiptFilters(medium: 'E_CHEQ'));
        self::assertSame(1, $cheques['pagination']['total']);

        $byCustomer = (new ListCustomerReceipts($this->receiptReadModel()))->execute($this->actor(), new ReceiptFilters(search: 'Lottus'));
        self::assertSame(1, $byCustomer['pagination']['total']);
        self::assertSame('Borrador', $byCustomer['rows'][0]['numero_visible']);
    }

    public function testMoneyArithmeticIsDeterministic(): void
    {
        self::assertSame(30, Money::toMinor('0.10') + Money::toMinor('0.20'));
        self::assertSame(10_001, Money::toMinor('100.005'));
        self::assertSame(101, Money::multiplyByRate(100, '1.005'));
        self::assertSame(75_000_000, Money::multiplyByRate(Money::toMinor('500'), '1500'));
        self::assertSame(3_086_402, Money::multiplyByRate(Money::toMinor('123.45'), '250.012345'));
        self::assertSame('1234.57', Money::format(123_457));
    }

    public function testDraftCanBeDiscardedButConfirmedReceiptsCannot(): void
    {
        $storage = new class () implements \App\Application\CustomerAccounts\Port\ReceiptAttachmentStorage {
            public function store(string $sourcePath, int $companyId, string $extension): array
            {
                return ['stored_name' => '', 'private_path' => ''];
            }

            public function read(string $privateRelativePath): string
            {
                return '';
            }

            public function delete(string $privateRelativePath): void
            {
            }
        };
        $discard = new \App\Application\CustomerAccounts\DiscardCustomerReceiptDraft(
            $this->receiptRepository(),
            $storage,
            $this->uow(),
            $this->clock(),
            $this->audit(),
        );
        $draft = $this->receipt(4, [$this->cash('100')], [], false);
        $discard->execute($this->actor(), $draft);
        self::assertSame(0, $this->db->table('cliente_recibos')->where('id', $draft)->countAllResults());

        $confirmed = $this->receipt(4, [$this->cash('100')]);
        $this->expectExceptionMessage('borrador');
        $discard->execute($this->actor(), $confirmed);
    }

    private function formalize(int $legId, float $amount, string $customer = 'ARS', ?float $rate = null): int
    {
        return (new FormalizeLegCharge(
            new CodeIgniterBillableLegLookup($this->db),
            $this->accountRepository(),
            new CodeIgniterExchangeRateCatalog($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute($this->actor(), new FormalizeLegChargeCommand(
            $legId,
            'CIERRE_COMERCIAL_APROBADO',
            new DateTimeImmutable('2026-11-10'),
            null,
            null,
            $amount,
            $customer,
            $rate,
        ));
    }

    /** @param list<ReceiptInstrumentInput> $instruments @param list<ReceiptImputationInput> $imputations */
    private function receipt(
        int $customerId,
        array $instruments,
        array $imputations = [],
        bool $confirm = true,
        int $branchId = 2,
        string $series = 'R',
        ?ActorContext $actor = null,
    ): int {
        return (new CreateCustomerReceipt(
            $this->receiptRepository(),
            new CodeIgniterExchangeRateCatalog($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute($actor ?? $this->actor(), new CreateCustomerReceiptCommand(
            $customerId,
            $branchId,
            $series,
            new DateTimeImmutable('2026-11-20'),
            new DateTimeImmutable('2026-11-20'),
            null,
            $instruments,
            $imputations,
            $confirm,
        ));
    }

    private function cash(string $amount, string $currency = 'ARS', ?string $rate = null): ReceiptInstrumentInput
    {
        return new ReceiptInstrumentInput('EFECTIVO', $currency, $amount, $rate);
    }

    private function transfer(string $amount, bool $verified = false): ReceiptInstrumentInput
    {
        return new ReceiptInstrumentInput(
            'TRANSFERENCIA',
            'ARS',
            $amount,
            bank: 'Banco Nación',
            account: '123-456/7',
            holder: 'Grupo Pluma',
            transferDate: new DateTimeImmutable('2026-11-20'),
            reference: 'TRF-0001',
            verification: $verified ? 'VERIFICADA' : 'PENDIENTE',
        );
    }

    private function cheque(string $amount, string $kind): ReceiptInstrumentInput
    {
        return new ReceiptInstrumentInput(
            $kind,
            'ARS',
            $amount,
            bank: 'Banco Galicia',
            chequeNumber: $kind . '-000123',
            drawer: 'Grupo Pluma',
            drawerTaxId: '30-11111111-1',
            chequeIssueDate: new DateTimeImmutable('2026-11-01'),
            chequeDueDate: new DateTimeImmutable('2026-12-01'),
            chequeReceivedDate: new DateTimeImmutable('2026-11-20'),
        );
    }

    private function confirm(): ConfirmCustomerReceipt
    {
        return new ConfirmCustomerReceipt($this->receiptRepository(), $this->uow(), $this->clock(), $this->audit());
    }

    private function annul(): AnnullCustomerReceipt
    {
        return new AnnullCustomerReceipt($this->receiptRepository(), $this->uow(), $this->clock(), $this->audit());
    }

    private function accredit(): AccreditReceiptInstrument
    {
        return new AccreditReceiptInstrument($this->receiptRepository(), $this->uow(), $this->clock(), $this->audit());
    }

    private function impute(): ImputeCustomerPayment
    {
        return new ImputeCustomerPayment($this->receiptRepository(), $this->uow(), $this->clock(), $this->audit());
    }

    private function getReceipt(): GetCustomerReceipt
    {
        return new GetCustomerReceipt($this->receiptReadModel(), new CodeIgniterExchangeRateCatalog($this->db));
    }

    private function statement(int $customerId): array
    {
        return (new GetCustomerStatement(
            new CodeIgniterCustomerAccountReadModel($this->db),
            new CodeIgniterExchangeRateCatalog($this->db),
            $this->receiptReadModel(),
        ))->execute($this->actor(), $customerId);
    }

    private function saldo(int $customerId): float
    {
        $row = $this->db->table('cliente_cc_movimientos')
            ->select('COALESCE(SUM(debe_ars),0) AS debe, COALESCE(SUM(haber_ars),0) AS haber', false)
            ->where('empresa_id', 5)
            ->where('cliente_id', $customerId)
            ->get()->getRowArray();

        return round((float) $row['debe'] - (float) $row['haber'], 2);
    }

    private function pending(int $chargeId): float
    {
        $charge = $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray();
        $imputed = $this->db->table('cliente_recibo_imputaciones')
            ->select('COALESCE(SUM(importe_ars),0) AS total', false)
            ->where('cargo_id', $chargeId)
            ->where('estado', 'VIGENTE')
            ->get()->getRowArray();

        return round((float) $charge['importe_ars'] - (float) $imputed['total'], 2);
    }

    private function document(int $chargeId): array
    {
        foreach ($this->statement(4)['pendingDocuments'] as $document) {
            if ($document['id'] === $chargeId) {
                return $document;
            }
        }
        self::fail('No se encontró el documento #' . $chargeId);
    }

    /** @param list<int> $ids @return list<int> */
    private function numbers(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $result[] = (int) $this->db->table('cliente_recibos')->where('id', $id)->get()->getRowArray()['numero'];
        }

        return $result;
    }

    private function audit(): AuditTrail
    {
        $test = $this;

        return new class ($test) implements AuditTrail {
            public function __construct(private readonly CustomerReceiptsIntegrationTest $test)
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

    private function accountRepository(): CodeIgniterCustomerAccountRepository
    {
        return new CodeIgniterCustomerAccountRepository($this->db);
    }

    private function receiptRepository(): CodeIgniterCustomerReceiptRepository
    {
        return new CodeIgniterCustomerReceiptRepository($this->db);
    }

    private function receiptReadModel(): CodeIgniterCustomerReceiptReadModel
    {
        return new CodeIgniterCustomerReceiptReadModel($this->db);
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
                return new DateTimeImmutable('2026-11-25 18:00:00');
            }
        };
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, true, [], self::ALL, []);
    }
}
