<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Application\Audit\AuditEntry;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\AnnulCustomerPayment;
use App\Application\CustomerAccounts\CreditCustomerPayment;
use App\Application\CustomerAccounts\FormalizeLegCharge;
use App\Application\CustomerAccounts\FormalizeLegChargeCommand;
use App\Application\CustomerAccounts\GetCustomerStatement;
use App\Application\CustomerAccounts\ListCustomerAccounts;
use App\Application\CustomerAccounts\PaymentImputationInput;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\RegisterCustomerPayment;
use App\Application\CustomerAccounts\RegisterCustomerPaymentCommand;
use App\Application\Identity\ActorContext;
use App\Database\Migrations\AddMultimonedaCustomerAccounts;
use App\Database\Migrations\CreateCustomerAccounts;
use App\Domain\CustomerAccounts\Money;
use App\Infrastructure\CustomerAccounts\CodeIgniterBillableLegLookup;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountReadModel;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountRepository;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerAccountsUnitOfWork;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerPaymentReadModel;
use App\Infrastructure\CustomerAccounts\CodeIgniterCustomerPaymentRepository;
use App\Infrastructure\CustomerAccounts\CodeIgniterExchangeRateCatalog;
use CodeIgniter\Database\ConnectionInterface;
use Config\Database;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cuenta corriente multimoneda (#588): saldos en moneda original, consolidación
 * ARS y diferencias de cambio.
 *
 * Corre contra SQLite en memoria por defecto y contra MariaDB real cuando se
 * define `CUSTOMER_ACCOUNTS_MYSQL=host:usuario:clave:base`. La prueba de
 * concurrencia (#20) solo puede correr contra MariaDB: necesita dos sesiones
 * de base de datos sobre el mismo esquema.
 */
final class CustomerAccountsMultimonedaIntegrationTest extends TestCase
{
    private const COMPANY = 5;
    private const OTHER_COMPANY = 6;
    private const ALL_PERMISSIONS = ['clientes.cc.ver', 'clientes.cc.formalizar', 'clientes.cc.cobrar', 'viajes.economico.ver'];

    private ConnectionInterface $db;
    private bool $mysql = false;

    /** Parámetros de la conexión real, para abrir sesiones adicionales sobre el mismo esquema. */
    private array $connectionParams = [];

    /** @var list<AuditEntry> */
    private array $audited = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->audited = [];
        $connection = getenv('CUSTOMER_ACCOUNTS_MYSQL');
        if (is_string($connection) && $connection !== '') {
            [$host, $user, $password, $database] = explode(':', $connection);
            $this->connectionParams = [
                'hostname' => $host,
                'username' => $user,
                'password' => $password,
                'database' => $database,
                'DBDriver' => 'MySQLi',
                'DBPrefix' => '',
                'DBDebug' => true,
                'charset' => 'utf8mb4',
                'DBCollat' => 'utf8mb4_general_ci',
            ];
            $this->db = Database::connect($this->connectionParams, false);
            $this->mysql = true;
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            // Un bloqueo esperando metadatos no debe colgar la suite.
            $this->db->query('SET SESSION lock_wait_timeout = 15');
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

        $this->db->table('empresas')->insertBatch([['id' => self::COMPANY], ['id' => self::OTHER_COMPANY]]);
        $this->db->table('usuarios')->insert(['id' => 1, 'nombre' => 'Carlos']);
        $this->db->table('sucursales')->insertBatch([['id' => 2, 'empresa_id' => self::COMPANY, 'nombre' => 'Argentina']]);
        $this->db->table('clientes')->insertBatch([
            ['id' => 4, 'empresa_id' => self::COMPANY, 'nombre' => 'Grupo Pluma'],
            ['id' => 8, 'empresa_id' => self::OTHER_COMPANY, 'nombre' => 'Cliente de otra empresa'],
        ]);
        $this->db->table('viajes_operativos')->insertBatch([
            ['id' => 16, 'empresa_id' => self::COMPANY, 'sucursal_id' => 2, 'numero' => 16, 'estado' => 'FINALIZADO', 'fecha_regreso_real' => '2026-11-02 00:00:00'],
        ]);
        $this->db->table('viaje_operativo_tramos')->insertBatch([
            ['id' => 9, 'viaje_id' => 16, 'empresa_id' => self::COMPANY, 'sucursal_id' => 2, 'orden' => 1, 'cliente_id' => 4, 'origen' => 'Umuarama', 'destino' => 'Santiago', 'moneda' => 'USD', 'cotizacion' => 1530, 'flete_facturacion' => 2800, 'flete_facturacion_ars' => 4284000],
            ['id' => 10, 'viaje_id' => 16, 'empresa_id' => self::COMPANY, 'sucursal_id' => 2, 'orden' => 2, 'cliente_id' => 4, 'origen' => 'San Juan', 'destino' => 'Curitiba', 'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 4147000, 'flete_facturacion_ars' => 4147000],
            ['id' => 11, 'viaje_id' => 16, 'empresa_id' => self::COMPANY, 'sucursal_id' => 2, 'orden' => 3, 'cliente_id' => 4, 'origen' => 'Curitiba', 'destino' => 'Posadas', 'moneda' => 'BRL', 'cotizacion' => 280, 'flete_facturacion' => 3000, 'flete_facturacion_ars' => 840000],
        ]);
        $this->db->table('viajes_monedas')->insertBatch([['codigo' => 'USD', 'activo' => 1], ['codigo' => 'BRL', 'activo' => 1]]);
        $this->db->table('roles')->insertBatch([['id' => 1, 'nombre' => 'Administrador']]);
        $this->db->table('permisos')->insert(['clave' => 'viajes.economico.ver', 'descripcion' => 'x']);
        $this->db->table('rol_permisos')->insert(['rol_id' => 1, 'permiso_id' => 1]);

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
        parent::tearDown();
    }

    // ---------------------------------------------------------------- pruebas

    /** #1 Deuda exclusivamente ARS. */
    public function testDeudaExclusivamenteEnPesos(): void
    {
        $chargeId = $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1_000_000, 'ARS', null, 'LI-TEST');

        self::assertSame('ARS', $this->charge($chargeId)['moneda']);
        $balances = $this->balances(4);
        self::assertSame(['ARS'], array_keys($balances));
        self::assertSame(1_000_000.0, $balances['ARS']['pendiente']);
        self::assertSame(0.0, $balances['ARS']['a_favor']);
    }

    /** #2 Deuda exclusivamente USD. */
    public function testDeudaExclusivamenteEnDolar(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');

        self::assertSame('USD', $this->charge($chargeId)['moneda']);
        $balances = $this->balances(4);
        self::assertSame(['USD'], array_keys($balances));
        self::assertSame(2_800.0, $balances['USD']['pendiente']);
        self::assertSame(4_200_000.0, $balances['USD']['saldo_ars']);
    }

    /** #3 Deuda exclusivamente BRL. */
    public function testDeudaExclusivamenteEnReales(): void
    {
        $chargeId = $this->formalize(11, 'MEMO_AUTORIZADO', 3_000, 'BRL', 280, 'M-BRL');

        self::assertSame('BRL', $this->charge($chargeId)['moneda']);
        $balances = $this->balances(4);
        self::assertSame(['BRL'], array_keys($balances));
        self::assertSame(3_000.0, $balances['BRL']['pendiente']);
        self::assertSame(840_000.0, $balances['BRL']['saldo_ars']);
    }

    /** #4 Cliente con deudas simultáneas en tres monedas. */
    public function testClienteConDeudasEnTresMonedas(): void
    {
        $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1_000_000, 'ARS', null, 'LI-1');
        $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->formalize(11, 'MEMO_AUTORIZADO', 3_000, 'BRL', 280, 'M-2');

        $balances = $this->balances(4);
        self::assertSame(['ARS', 'BRL', 'USD'], array_keys($balances));
        self::assertSame(1_000_000.0, $balances['ARS']['pendiente']);
        self::assertSame(2_800.0, $balances['USD']['pendiente']);
        self::assertSame(3_000.0, $balances['BRL']['pendiente']);
        // El consolidado histórico es la suma de los tres.
        self::assertSame(6_040_000.0, round($balances['ARS']['saldo_ars'] + $balances['USD']['saldo_ars'] + $balances['BRL']['saldo_ars'], 2));
    }

    /**
     * #5 Pago parcial USD con cotización diferente.
     *
     * Es el ejemplo obligatorio del issue: USD 2.800 a 1.500 y un pago de USD
     * 1.000 a 1.600 dejan USD 1.800 pendientes, valor histórico ARS 2.700.000,
     * valor actual ARS 2.880.000 y una diferencia de cambio de ARS 100.000.
     */
    public function testPagoParcialEnDolarConCotizacionDistinta(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']]);

        $balances = $this->balances(4);
        self::assertSame(1_800.0, $balances['USD']['pendiente']);
        self::assertSame(2_700_000.0, $balances['USD']['saldo_ars'], 'El valor histórico del saldo pendiente es 1.800 × 1.500.');

        $imputation = $this->imputations()[0];
        self::assertSame(100_000.0, (float) $imputation['diferencia_cambio']);
        self::assertSame(1_500_000.0, (float) $imputation['importe_ars_cargo']);
        self::assertSame(1_600_000.0, (float) $imputation['importe_ars_pago']);

        // La diferencia queda como movimiento propio del libro.
        $difference = $this->movement('DIFERENCIA_CAMBIO');
        self::assertNotNull($difference);
        self::assertEquals(100_000.0, (float) $difference['debe_ars']);
        self::assertEquals(0.0, (float) $difference['haber_ars']);
    }

    /** #6 Pago total USD con cotización distinta: el saldo original queda en cero. */
    public function testPagoTotalEnDolarConCotizacionDistinta(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->pay(4, '2026-11-12', 'USD', '2800', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '2800']]);

        $balances = $this->balances(4);
        self::assertSame(0.0, $balances['USD']['pendiente']);
        self::assertSame(0.0, $balances['USD']['a_favor']);
    }

    /** #7 Pago parcial y total BRL. */
    public function testPagoParcialYTotalEnReales(): void
    {
        $chargeId = $this->formalize(11, 'MEMO_AUTORIZADO', 3_000, 'BRL', 280, 'M-1');

        $this->pay(4, '2026-11-12', 'BRL', '1000', 300, 'EFECTIVO', [['charge' => $chargeId, 'amount' => '1000']]);
        self::assertSame(2_000.0, $this->balances(4)['BRL']['pendiente']);

        $this->pay(4, '2026-11-13', 'BRL', '2000', 300, 'EFECTIVO', [['charge' => $chargeId, 'amount' => '2000']]);
        $balances = $this->balances(4);
        self::assertSame(0.0, $balances['BRL']['pendiente']);
        self::assertSame(0.0, $balances['BRL']['saldo_ars'], 'El ledger en pesos de la moneda queda en cero.');
    }

    /** #8 Diferencias de cambio positivas y negativas. */
    public function testDiferenciasDeCambioPositivasYNegativas(): void
    {
        $up = $this->formalize(9, 'MEMO_AUTORIZADO', 1_000, 'USD', 1_500, 'M-UP');
        $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'EFECTIVO', [['charge' => $up, 'amount' => '1000']]);
        self::assertEquals(100_000.0, (float) $this->imputations()[0]['diferencia_cambio']);

        $down = $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1_000, 'USD', 1_600, 'LI-DOWN');
        $this->pay(4, '2026-11-13', 'USD', '1000', 1_500, 'EFECTIVO', [['charge' => $down, 'amount' => '1000']]);
        self::assertEquals(-100_000.0, (float) $this->imputations()[1]['diferencia_cambio']);

        $movements = $this->movements('DIFERENCIA_CAMBIO');
        self::assertCount(2, $movements);
        // La diferencia favorable va al Debe.
        self::assertEquals(100_000.0, (float) $movements[0]['debe_ars']);
        self::assertEquals(0.0, (float) $movements[0]['haber_ars']);
        // La diferencia desfavorable va al Haber.
        self::assertEquals(0.0, (float) $movements[1]['debe_ars']);
        self::assertEquals(100_000.0, (float) $movements[1]['haber_ars']);
    }

    /** #9 Saldo original cero y ledger ARS conciliado. */
    public function testSaldoOriginalCeroYLedgerConciliado(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']]);
        $this->pay(4, '2026-11-13', 'USD', '1800', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1800']]);

        $balances = $this->balances(4);
        self::assertSame(0.0, $balances['USD']['pendiente']);
        self::assertSame(0.0, $balances['USD']['saldo_ars'], 'No queda saldo residual ficticio por variación de cotización.');

        $statement = $this->statement(4);
        self::assertSame(0.0, $statement['totals']['saldo_ars']);
        // Debe − Haber del libro completo.
        self::assertSame(0.0, round($statement['totals']['debe_ars'] - $statement['totals']['haber_ars'], 2));
    }

    /** #10 Anticipos en distintas monedas. */
    public function testAnticiposEnDistintasMonedas(): void
    {
        $this->pay(4, '2026-11-12', 'USD', '500', 1_600, 'EFECTIVO', []);
        $this->pay(4, '2026-11-12', 'BRL', '300', 280, 'EFECTIVO', []);

        $balances = $this->balances(4);
        self::assertSame(500.0, $balances['USD']['a_favor']);
        self::assertSame(300.0, $balances['BRL']['a_favor']);
        self::assertSame(0.0, $balances['USD']['pendiente']);
        self::assertSame(0.0, $balances['BRL']['pendiente']);
        // Cada anticipo queda en su moneda: no se compensan entre sí.
        self::assertSame(-800_000.0, $balances['USD']['saldo_ars']);
        self::assertSame(-84_000.0, $balances['BRL']['saldo_ars']);
    }

    /** #11 Saldos a favor. */
    public function testSaldosAFavorDiscriminadosPorMoneda(): void
    {
        $this->pay(4, '2026-11-12', 'USD', '500', 1_600, 'EFECTIVO', []);

        $balances = $this->balances(4);
        self::assertSame(500.0, $balances['USD']['a_favor']);
        self::assertSame(0.0, $balances['USD']['pendiente']);
        self::assertSame(-800_000.0, $balances['USD']['saldo_ars']);
    }

    /** #12 Imputaciones múltiples: un pago a varios documentos y varios pagos a un documento. */
    public function testImputacionesMultiples(): void
    {
        $first = $this->formalize(9, 'MEMO_AUTORIZADO', 1_000, 'USD', 1_500, 'M-1');
        $second = $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 2_000, 'USD', 1_500, 'LI-1');

        // Un pago aplicado a dos documentos de la misma moneda.
        $this->pay(4, '2026-11-12', 'USD', '2500', 1_500, 'EFECTIVO', [
            ['charge' => $first, 'amount' => '1000'],
            ['charge' => $second, 'amount' => '1500'],
        ]);
        self::assertCount(2, $this->imputations());
        // Quedan 500 USD pendientes del segundo documento.
        self::assertSame(500.0, $this->balances(4)['USD']['pendiente']);

        // Otro pago sobre el mismo documento, ya saldado, no se acepta.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('supera el saldo pendiente');
        $this->pay(4, '2026-11-13', 'USD', '100', 1_500, 'EFECTIVO', [['charge' => $first, 'amount' => '100']]);
    }

    /** #13 No se compensan automáticamente monedas distintas. */
    public function testNoSeCompensanMonedasDistintas(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');

        try {
            $this->pay(4, '2026-11-12', 'ARS', '1000', null, 'EFECTIVO', [['charge' => $chargeId, 'amount' => '1000']]);
            self::fail('Un cobro en pesos no puede imputarse a una deuda en dólares.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no se compensan monedas distintas', $exception->getMessage());
        }

        // La deuda sigue impaga.
        self::assertSame(2_800.0, $this->balances(4)['USD']['pendiente']);
    }

    /** #14 Anulación de un cobro: restituye la deuda en su moneda original. */
    public function testAnulacionDeCobroRestituyeLaDeuda(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $paymentId = $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']]);

        $this->annulPayment($paymentId, 'Se equivocó de cuenta');

        $balances = $this->balances(4);
        self::assertSame(2_800.0, $balances['USD']['pendiente']);
        self::assertSame(4_200_000.0, $balances['USD']['saldo_ars'], 'El ledger vuelve al valor del documento.');
        self::assertSame('ANULADO', $this->payment($paymentId)['estado']);
    }

    /** #15 Reversión de diferencias de cambio. */
    public function testReversionDeDiferenciasDeCambio(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $paymentId = $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']]);

        $this->annulPayment($paymentId, 'Cheque rechazado');

        $reversal = $this->movement('REVERSION_DIFERENCIA_CAMBIO');
        self::assertNotNull($reversal);
        self::assertEquals(100_000.0, (float) $reversal['haber_ars']);
        self::assertEquals(0.0, (float) $reversal['debe_ars']);
    }

    /** #16 Cotización actual inexistente: la valorización no está disponible. */
    public function testCotizacionActualInexistente(): void
    {
        $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');

        $statement = $this->statement(4);
        self::assertFalse($statement['valuation']['disponible']);
        self::assertNull($statement['valuation']['ars']);
        self::assertSame(['USD'], $statement['valuation']['monedas_sin_cotizacion']);

        $accounts = $this->accounts();
        self::assertFalse($accounts['valuation']['disponible']);
        self::assertNull($accounts['valuation']['ars']);
    }

    /** #17 Cambiar la cotización no modifica los movimientos históricos. */
    public function testCambioDeCotizacionNoModificaLoHistorico(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->db->table('viajes_cotizaciones')->insert([
            'empresa_id' => self::COMPANY,
            'moneda' => 'USD',
            'cotizacion' => 2_000,
            'updated_at' => '2026-11-12 10:00:00',
        ]);

        self::assertEquals(4_200_000, $this->charge($chargeId)['importe_ars']);
        $balances = $this->balances(4);
        self::assertSame(2_800.0, $balances['USD']['pendiente']);
        self::assertSame(4_200_000.0, $balances['USD']['saldo_ars']);

        // La valorización actual sí usa la cotización vigente.
        $statement = $this->statement(4);
        self::assertTrue($statement['valuation']['disponible']);
        self::assertSame(5_600_000.0, $statement['valuation']['ars']);
    }

    /** #18 Precisión y redondeos. */
    public function testPrecisionYRedondeos(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', '100.01', 'USD', '1500.5', 'M-1');

        // 100,01 × 1.500,50 = 150.065,005 → 150.065,01 con redondeo half-up.
        self::assertEquals(150_065.01, $this->charge($chargeId)['importe_ars']);

        // Un cobro de 0,01 USD a 1.500,50 vale 15,005 pesos: redondea a 15,01.
        $this->pay(4, '2026-11-12', 'USD', '0.01', '1500.5', 'EFECTIVO', [['charge' => $chargeId, 'amount' => '0.01']]);
        $imputation = $this->imputations()[0];
        self::assertSame(15.01, round((float) $imputation['importe_ars_pago'], 2));
        self::assertSame(15.01, round((float) $imputation['importe_ars_cargo'], 2));
        self::assertSame(0.0, round((float) $imputation['diferencia_cambio'], 2));

        // El saldo pendiente conserva la precisión del documento.
        self::assertSame(100.0, $this->balances(4)['USD']['pendiente']);
    }

    /** #19 Idempotencia: la misma referencia no se cobra dos veces. */
    public function testIdempotenciaDeCobrosConReferencia(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']], 'REC-001');

        try {
            $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']], 'REC-001');
            self::fail('El mismo cobro con la misma referencia no se puede registrar dos veces.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('esa referencia', $exception->getMessage());
        }

        self::assertCount(1, $this->payments());
    }

    /** #20 Concurrencia real: dos sesiones de base de datos sobre el mismo esquema. */
    public function testConcurrenciaRealEntreDosSesiones(): void
    {
        if (! $this->mysql) {
            $this->markTestSkipped('La concurrencia real necesita MariaDB: definí CUSTOMER_ACCOUNTS_MYSQL=host:usuario:clave:base.');
        }

        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 1_000, 'USD', 1_500, 'M-1');

        // Las dos sesiones se abren sobre el mismo esquema real, no sobre la
        // configuración de pruebas (que en local es SQLite en memoria). Deben
        // ser conexiones distintas: con la instancia compartida serían la misma.
        $first = Database::connect($this->connectionParams, false);
        $second = null;

        try {
            // La primera sesión abre el documento y lo deja bloqueado.
            $first->query('SET autocommit = 0');
            $first->query(
                'SELECT id FROM cliente_cc_cargos WHERE empresa_id = ? AND id = ? FOR UPDATE',
                [self::COMPANY, $chargeId],
            );

            // La segunda sesión no puede imputar contra el documento bloqueado:
            // el bloqueo se resuelve rápido para no esperar el timeout por defecto.
            $second = Database::connect($this->connectionParams, false);
            $second->query('SET autocommit = 0');
            $second->query('SET SESSION innodb_lock_wait_timeout = 3');
            $blocked = false;
            try {
                $second->query(
                    'SELECT id FROM cliente_cc_cargos WHERE empresa_id = ? AND id = ? FOR UPDATE',
                    [self::COMPANY, $chargeId],
                );
            } catch (RuntimeException) {
                $blocked = true;
            }
            self::assertTrue($blocked, 'La segunda sesión tiene que esperar el bloqueo del documento.');
        } finally {
            $first->query('ROLLBACK');
            $first->close();
            if ($second instanceof ConnectionInterface) {
                $second->query('ROLLBACK');
                $second->close();
            }
        }
    }

    /** #21 Aislamiento multiempresa. */
    public function testAislamientoMultiempresa(): void
    {
        $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');

        $accounts = $this->accounts(self::OTHER_COMPANY);
        self::assertSame([], $accounts['rows']);

        try {
            $this->pay(8, '2026-11-12', 'USD', '100', 1_600, 'EFECTIVO', [], null, self::COMPANY);
            self::fail('No se puede cobrar a un cliente de otra empresa.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no pertenece a la empresa', $exception->getMessage());
        }

        // Tampoco se puede consultar la cuenta de un cliente de otra empresa.
        try {
            $this->statement(8);
            self::fail('No se puede consultar la cuenta de un cliente de otra empresa.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no pertenece a la empresa', $exception->getMessage());
        }
    }

    /** #22 Permisos: cobrar exige `clientes.cc.cobrar`. */
    public function testPermisos(): void
    {
        try {
            $this->pay(4, '2026-11-12', 'USD', '100', 1_600, 'EFECTIVO', [], null, self::COMPANY, []);
            self::fail('Sin `clientes.cc.cobrar` no se puede cobrar.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('No tenés permiso', $exception->getMessage());
        }

        // El permiso existe y lo tienen los administradores.
        $permissions = $this->db->table('permisos')->select('clave')->where('clave', 'clientes.cc.cobrar')->get()->getResultArray();
        self::assertCount(1, $permissions);
    }

    /** #23 Migración de datos existentes: el libro conserva moneda e importes. */
    public function testMigracionDeDatosExistentes(): void
    {
        // Un cargo y su movimiento anteriores a la etapa multimoneda.
        $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->db->table('cliente_cc_movimientos')->update([
            'moneda' => null,
            'debe_original' => null,
            'haber_original' => null,
            'cotizacion' => null,
            'cotizacion_fecha' => null,
            'cotizacion_fuente' => null,
        ]);

        require_once APPPATH . 'Database/Migrations/2026-11-26-100000_AddMultimonedaCustomerAccounts.php';
        (new AddMultimonedaCustomerAccounts(Database::forge($this->db)))->up();

        $movement = $this->movement('CARGO');
        self::assertNotNull($movement);
        self::assertSame('USD', $movement['moneda']);
        self::assertEquals(2_800.0, (float) $movement['debe_original']);
        self::assertEquals(0.0, (float) $movement['haber_original']);
        self::assertEquals(1_500.0, (float) $movement['cotizacion']);
        self::assertSame('MANUAL', $movement['cotizacion_fuente']);
    }

    /** #24 Paginación y filtros. */
    public function testPaginacionYFiltros(): void
    {
        $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $this->formalize(10, 'CIERRE_COMERCIAL_APROBADO', 1_000, 'ARS', null, 'LI-1');

        $all = $this->accounts();
        self::assertSame(1, $all['pagination']['total']);
        self::assertSame(1, $all['pagination']['pages']);
        self::assertSame(1, $all['pagination']['from']);

        $filtered = $this->accounts(self::COMPANY, 'USD');
        self::assertSame(1, $filtered['pagination']['total']);

        $none = $this->accounts(self::COMPANY, 'BRL');
        self::assertSame(0, $none['pagination']['total']);
        self::assertSame([], $none['rows']);

        $search = $this->accounts(self::COMPANY, null, 'Pluma');
        self::assertSame(1, $search['pagination']['total']);
    }

    /** #25 Regresión de #530: el circuito de formalización sigue igual. */
    public function testRegresionDelCircuitoDeFormalizacion(): void
    {
        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_578, 'M-77');
        $charge = $this->charge($chargeId);
        self::assertSame('MEMO_AUTORIZADO', $charge['modalidad']);
        self::assertSame('M-77', $charge['documento_numero']);
        self::assertSame('MANUAL', $charge['cotizacion_fuente']);
        self::assertEquals(4_418_400, $charge['importe_ars']);

        // Un tramo no se puede formalizar dos veces.
        try {
            $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_578, 'M-78');
            self::fail('El tramo ya formalizado no se puede volver a formalizar.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('ya tiene el cobro formalizado', $exception->getMessage());
        }
    }

    /** #26 Compatibilidad con el modelo de recibos del PR #587. */
    public function testCompatibilidadConElModeloDeRecibos(): void
    {
        // El PR #587 crea `cliente_recibos` y `cliente_recibo_imputaciones`, y
        // agrega `recibo_id` y `recibo_medio_id` al libro. La capa de #588 no
        // depende de esas tablas y deja el hueco listo para acoplarse.
        self::assertFalse($this->db->tableExists('cliente_recibos'));

        $chargeId = $this->formalize(9, 'MEMO_AUTORIZADO', 2_800, 'USD', 1_500, 'M-1');
        $paymentId = $this->pay(4, '2026-11-12', 'USD', '1000', 1_600, 'TRANSFERENCIA', [['charge' => $chargeId, 'amount' => '1000']]);

        // La costura: el cobro puede quedar ligado a un recibo y a su medio.
        $this->db->table('cliente_cc_pagos')->where('id', $paymentId)->update(['recibo_id' => 77, 'recibo_medio_id' => 78]);
        $payment = $this->payment($paymentId);
        self::assertEquals(77, $payment['recibo_id']);
        self::assertEquals(78, $payment['recibo_medio_id']);

        // La imputación guarda el importe en la moneda de la deuda, que es lo
        // que el modelo de #587 no tiene.
        self::assertSame('USD', $this->imputations()[0]['moneda']);
        self::assertEquals(1_000.0, (float) $this->imputations()[0]['importe_original']);
    }

    // ---------------------------------------------------------------- helpers

    private function formalize(int $legId, string $modality, float|string $amount, string $currency, float|string|null $rate, string $number): int
    {
        return (new FormalizeLegCharge(
            new CodeIgniterBillableLegLookup($this->db),
            new CodeIgniterCustomerAccountRepository($this->db),
            new CodeIgniterExchangeRateCatalog($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute($this->actor(), new FormalizeLegChargeCommand(
            $legId,
            $modality,
            new DateTimeImmutable('2026-11-10'),
            $number,
            null,
            (float) $amount,
            $currency,
            $rate === null ? null : (float) $rate,
        ));
    }

    /**
     * @param list<array{charge:int,amount:string}> $imputations
     */
    private function pay(
        int $customerId,
        string $date,
        string $currency,
        string $amount,
        float|string|null $rate,
        string $medium,
        array $imputations,
        ?string $reference = null,
        ?int $companyId = null,
        ?array $permissions = null,
    ): int {
        $inputs = [];
        foreach ($imputations as $imputation) {
            $inputs[] = new PaymentImputationInput($imputation['charge'], $imputation['amount']);
        }

        return (new RegisterCustomerPayment(
            new CodeIgniterCustomerPaymentRepository($this->db),
            new CodeIgniterExchangeRateCatalog($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute(
            $this->actor($companyId ?? self::COMPANY, $permissions),
            new RegisterCustomerPaymentCommand(
                $customerId,
                new DateTimeImmutable($date),
                $currency,
                $amount,
                $rate === null ? null : (float) $rate,
                $medium,
                $reference,
                null,
                false,
                $inputs,
            ),
        );
    }

    private function annulPayment(int $paymentId, string $reason): void
    {
        (new AnnulCustomerPayment(
            new CodeIgniterCustomerPaymentRepository($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute($this->actor(), $paymentId, $reason);
    }

    private function creditPayment(int $paymentId): void
    {
        (new CreditCustomerPayment(
            new CodeIgniterCustomerPaymentRepository($this->db),
            $this->uow(),
            $this->clock(),
            $this->audit(),
        ))->execute($this->actor(), $paymentId);
    }

    /** @return array<string,mixed> */
    private function accounts(?int $companyId = null, ?string $currency = null, ?string $search = null): array
    {
        return (new ListCustomerAccounts(
            new CodeIgniterCustomerAccountReadModel($this->db),
            new CodeIgniterExchangeRateCatalog($this->db),
        ))->execute($this->actor($companyId ?? self::COMPANY), $search, $currency, 1, 25);
    }

    /** @return array<string,mixed> */
    private function statement(int $customerId): array
    {
        return (new GetCustomerStatement(
            new CodeIgniterCustomerAccountReadModel($this->db),
            new CodeIgniterCustomerPaymentReadModel($this->db),
            new CodeIgniterExchangeRateCatalog($this->db),
        ))->execute($this->actor(), $customerId);
    }

    /** @return array<string,array<string,mixed>> */
    private function balances(int $customerId): array
    {
        $statement = $this->statement($customerId);

        return $statement['balances'];
    }

    /** @return array<string,mixed>|null */
    private function charge(int $chargeId): ?array
    {
        return $this->db->table('cliente_cc_cargos')->where('id', $chargeId)->get()->getRowArray();
    }

    /** @return array<string,mixed>|null */
    private function payment(int $paymentId): ?array
    {
        return $this->db->table('cliente_cc_pagos')->where('id', $paymentId)->get()->getRowArray();
    }

    /** @return list<array<string,mixed>> */
    private function payments(): array
    {
        return $this->db->table('cliente_cc_pagos')->orderBy('id', 'ASC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    private function imputations(): array
    {
        return $this->db->table('cliente_cc_imputaciones')->orderBy('id', 'ASC')->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    private function movement(string $type): ?array
    {
        $movements = $this->movements($type);

        return $movements[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    private function movements(string $type): array
    {
        return $this->db->table('cliente_cc_movimientos')->where('tipo', $type)->orderBy('id', 'ASC')->get()->getResultArray();
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

    private function audit(): AuditTrail
    {
        $test = $this;

        return new class ($test) implements AuditTrail {
            public function __construct(private readonly CustomerAccountsMultimonedaIntegrationTest $test)
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

    /** @param list<string>|null $permissions */
    private function actor(?int $companyId = null, ?array $permissions = null): ActorContext
    {
        return new ActorContext(1, $companyId ?? self::COMPANY, false, true, [], $permissions ?? self::ALL_PERMISSIONS, []);
    }
}
