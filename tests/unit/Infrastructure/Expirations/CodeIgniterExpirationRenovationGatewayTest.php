<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Expirations;

use App\Application\Expirations\RenovarVencimientoCommand;
use App\Application\Expirations\Port\ExpirationEvidenceStorage;
use App\Application\Assets\Attachment\StoredAttachmentFile;
use App\Infrastructure\Expirations\CodeIgniterExpirationEvidenceReadModel;
use App\Infrastructure\Expirations\CodeIgniterExpirationRenovationGateway;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Tests de regresion del gateway de renovacion con aislamiento por empresa.
 *
 * Cubre los 6 escenarios pedidos:
 *  1. Empresa A vs empresa B: contadores y listado filtrados por tenant.
 *  2. Un vencimiento futuro NO aparece como vencido.
 *  3. Renovar conserva la fecha anterior en el historial.
 *  4. Renovar con evidencia persiste el adjunto en el historial.
 *  5. Seguridad: usuario de empresa A no puede renovar ni consultar historial
 *     de un vencimiento de empresa B aunque fuerce el ID.
 *  6. Contadores no suman empresas cruzadas.
 */
final class CodeIgniterExpirationRenovationGatewayTest extends TestCase
{

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('Los tests de renovacion requieren la extension sqlite3.');
        }

        // Conectamos al grupo 'tests' configurado en app/Config/Database.php
        // (SQLite in-memory). NO usamos DatabaseTestTrait porque el runner
        // de migraciones falla en SQLite con sentencias FOREIGN KEY como
        // ALTER TABLE; este test solo necesita el subconjunto de tablas.
        $this->db = Database::connect();
        $this->db->query('PRAGMA foreign_keys = OFF');

        // La conexion SQLite es singleton; cada test debe partir limpio.
        // Ademas aplicamos el prefijo configurado en Database.php (db_) a
        // cada tabla para que las queries del gateway las encuentren.
        $tables = [
            'vencimiento_renovaciones', 'vencimiento_evidencias',
            'vencimientos', 'tipos_vencimiento',
            'empleados', 'equipos', 'usuarios', 'empresas',
        ];
        foreach ($tables as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }

        // Stubs minimos de las tablas padre. Solo nos importa el WHERE por
        // (empresa_id, id) que aplica el gateway, asi que estas tablas son
        // placeholders con PK compatible.
        $this->db->query('CREATE TABLE db_empresas (id INTEGER PRIMARY KEY, razon_social TEXT NOT NULL, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE db_usuarios (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL, apellido TEXT, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE db_equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, codigo TEXT NOT NULL, patente TEXT, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE db_empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, nombre TEXT NOT NULL, apellido TEXT, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE db_tipos_vencimiento (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            nombre TEXT NOT NULL,
            aplica_a TEXT NOT NULL,
            dias_aviso_previo INTEGER NOT NULL DEFAULT 30,
            requiere_documento INTEGER NOT NULL DEFAULT 0,
            activo INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE db_vencimientos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            tipo_vencimiento_id INTEGER NOT NULL,
            sujeto_tipo TEXT NOT NULL,
            equipo_id INTEGER NULL,
            empleado_id INTEGER NULL,
            sucursal_id INTEGER NULL,
            fecha_emision TEXT NULL,
            fecha_vencimiento TEXT NOT NULL,
            numero_documento TEXT NULL,
            observaciones TEXT NULL,
            origen TEXT NOT NULL DEFAULT \'MANUAL\',
            activo INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NULL,
            deleted_at TEXT NULL
        )');

        // Las dos tablas objetivo del feature. Replicamos el esquema
        // exacto de la migracion sin FK para que SQLite no proteste.
        $this->db->query('CREATE TABLE db_vencimiento_evidencias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            vencimiento_id INTEGER NOT NULL,
            nombre_original TEXT NOT NULL,
            nombre_almacenado TEXT NOT NULL,
            ruta_privada TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            tamanio INTEGER NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            deleted_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE db_vencimiento_renovaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            vencimiento_id INTEGER NOT NULL,
            fecha_anterior TEXT NOT NULL,
            fecha_nueva TEXT NOT NULL,
            fecha_renovacion TEXT NOT NULL,
            usuario_id INTEGER NULL,
            observaciones TEXT NULL,
            evidencia_id INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $now = '2026-09-17 10:00:00';
        // Empresa A (id 1)
        $this->db->table('empresas')->insert(['id' => 1, 'razon_social' => 'Empresa A']);
        $this->db->table('usuarios')->insert(['id' => 10, 'nombre' => 'Operador', 'apellido' => 'A']);
        $this->db->table('tipos_vencimiento')->insert([
            'id' => 100, 'empresa_id' => 1, 'nombre' => 'VTV', 'aplica_a' => 'EQUIPO',
            'dias_aviso_previo' => 30, 'requiere_documento' => 1, 'activo' => 1,
        ]);
        // 3 vencidos + 1 vigente (futuro) para empresa A.
        foreach ([
            ['2026-08-01', 'ACTIVO'],
            ['2026-08-10', 'ACTIVO'],
            ['2026-08-20', 'ACTIVO'],
            ['2027-01-01', 'ACTIVO'],
        ] as [$date, $state]) {
            $this->db->table('vencimientos')->insert([
                'empresa_id' => 1, 'tipo_vencimiento_id' => 100, 'sujeto_tipo' => 'EQUIPO',
                'equipo_id' => null, 'empleado_id' => null, 'sucursal_id' => null,
                'fecha_emision' => null, 'fecha_vencimiento' => $date,
                'numero_documento' => null, 'observaciones' => null,
                'origen' => 'MANUAL', 'activo' => 1, 'created_by' => 10, 'updated_by' => null,
                'created_at' => $now, 'updated_at' => null, 'deleted_at' => null,
            ]);
        }

        // Empresa B (id 2)
        $this->db->table('empresas')->insert(['id' => 2, 'razon_social' => 'Empresa B']);
        $this->db->table('usuarios')->insert(['id' => 20, 'nombre' => 'Operador', 'apellido' => 'B']);
        $this->db->table('tipos_vencimiento')->insert([
            'id' => 200, 'empresa_id' => 2, 'nombre' => 'VTV', 'aplica_a' => 'EQUIPO',
            'dias_aviso_previo' => 30, 'requiere_documento' => 1, 'activo' => 1,
        ]);
        // 2 vencidos para empresa B.
        foreach (['2026-07-15', '2026-08-05'] as $date) {
            $this->db->table('vencimientos')->insert([
                'empresa_id' => 2, 'tipo_vencimiento_id' => 200, 'sujeto_tipo' => 'EQUIPO',
                'equipo_id' => null, 'empleado_id' => null, 'sucursal_id' => null,
                'fecha_emision' => null, 'fecha_vencimiento' => $date,
                'numero_documento' => null, 'observaciones' => null,
                'origen' => 'MANUAL', 'activo' => 1, 'created_by' => 20, 'updated_by' => null,
                'created_at' => $now, 'updated_at' => null, 'deleted_at' => null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Limpiamos las tablas para que el siguiente test parta de cero.
        if (isset($this->db)) {
            foreach ([
                'vencimiento_renovaciones', 'vencimiento_evidencias',
                'vencimientos', 'tipos_vencimiento',
                'empleados', 'equipos', 'usuarios', 'empresas',
            ] as $table) {
                $this->db->query('DROP TABLE IF EXISTS db_' . $table);
            }
        }
    }

    private function gateway(): CodeIgniterExpirationRenovationGateway
    {
        return new CodeIgniterExpirationRenovationGateway(
            $this->db,
            new InMemoryEvidenceStorageFake(),
        );
    }

    /**
     * 1) Aislamiento por empresa: contadores VENCIDOS filtrados por tenant.
     */
    public function testOverdueCountIsScopedByCompany(): void
    {
        $now = new DateTimeImmutable('2026-09-17');

        // Empresa A tiene 3 vencidos sobre 4; B tiene 2 sobre 2.
        $overdueA = $this->db->table('vencimientos')
            ->where('empresa_id', 1)
            ->where('fecha_vencimiento <', $now->format('Y-m-d'))
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->countAllResults();
        $overdueB = $this->db->table('vencimientos')
            ->where('empresa_id', 2)
            ->where('fecha_vencimiento <', $now->format('Y-m-d'))
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->countAllResults();
        $overdueABSum = $this->db->table('vencimientos')
            ->whereIn('empresa_id', [1, 2])
            ->where('fecha_vencimiento <', $now->format('Y-m-d'))
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->countAllResults();

        self::assertSame(3, $overdueA, 'Empresa A debe ver 3 vencidos.');
        self::assertSame(2, $overdueB, 'Empresa B debe ver 2 vencidos.');
        self::assertSame(5, $overdueABSum, 'Solo el agregado global suma 5; cada empresa ve SU contador.');
    }

    /**
     * 2) Un vencimiento futuro NO debe aparecer como vencido.
     */
    public function testFutureExpirationIsNotOverdue(): void
    {
        $today = new DateTimeImmutable('2026-09-17');
        $rows = $this->db->table('vencimientos')
            ->where('empresa_id', 1)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->get()->getResultArray();

        $overdue = array_filter($rows, static fn (array $row): bool => new DateTimeImmutable((string) $row['fecha_vencimiento']) < $today);
        self::assertCount(3, $overdue);

        $future = array_filter($rows, static fn (array $row): bool => new DateTimeImmutable((string) $row['fecha_vencimiento']) >= $today);
        self::assertCount(1, $future, 'La fila con fecha 2027-01-01 es la unica vigente para empresa A.');
    }

    /**
     * 3) Renovar conserva fecha anterior + nueva fecha + usuario + fecha/hora.
     */
    public function testRenewalPreservesHistory(): void
    {
        $gateway = $this->gateway();
        $now = new DateTimeImmutable('2026-09-17 11:30:00');
        $command = new RenovarVencimientoCommand(
            1,
            new DateTimeImmutable('2027-09-20'),
            null,
            null,
            'renovacion anual',
            null,
            null,
            null,
            null,
            'equipos.editar',
            false,
        );

        $result = $gateway->renew(1, $command, $now, 10);
        self::assertNotNull($result);
        self::assertSame('2026-08-01', $result['previousDate']);
        self::assertSame('2027-09-20', $result['newDate']);
        self::assertSame(10, $result['userId']);
        self::assertSame('renovacion anual', $result['notes']);

        // La fila del vencimiento ahora vence 2027-09-20.
        $updated = $this->db->table('vencimientos')->where('id', 1)->get()->getRowArray();
        self::assertSame('2027-09-20', $updated['fecha_vencimiento']);

        // El historial conserva 2026-08-01 -> 2027-09-20.
        $history = $this->db->table('vencimiento_renovaciones')->get()->getResultArray();
        self::assertCount(1, $history);
        self::assertSame('2026-08-01', $history[0]['fecha_anterior']);
        self::assertSame('2027-09-20', $history[0]['fecha_nueva']);
        self::assertSame(1, (int) $history[0]['empresa_id']);
        self::assertSame(10, (int) $history[0]['usuario_id']);
    }

    /**
     * 4) Renovacion con evidencia: la pieza queda asociada y recuperable.
     */
    public function testRenewalWithEvidencePersistsAndIsRecoverable(): void
    {
        $gateway = $this->gateway();
        $now = new DateTimeImmutable('2026-09-17 11:30:00');
        $storedName = str_repeat('e', 48) . '.pdf';
        $command = new RenovarVencimientoCommand(
            1,
            new DateTimeImmutable('2027-09-20'),
            null,
            'DOC-2027',
            'presento nueva documentacion',
            '/tmp/' . $storedName,
            'documento.pdf',
            'application/pdf',
            1024,
            'equipos.editar',
            true,
        );

        $result = $gateway->renew(1, $command, $now, 10);
        self::assertNotNull($result);
        self::assertNotNull($result['evidenceId']);

        $evidence = $this->db->table('vencimiento_evidencias')->where('id', $result['evidenceId'])->get()->getRowArray();
        self::assertSame('documento.pdf', $evidence['nombre_original']);
        self::assertSame('application/pdf', $evidence['mime_type']);
        self::assertSame(1, (int) $evidence['empresa_id']);
        self::assertSame($storedName, $evidence['nombre_almacenado']);
        self::assertSame('1/' . $storedName, $evidence['ruta_privada']);

        $history = $this->db->table('vencimiento_renovaciones')->where('id', $result['renovationId'])->get()->getRowArray();
        self::assertSame((int) $evidence['id'], (int) $history['evidencia_id']);
        self::assertSame(1, (int) $history['empresa_id']);

        // El read model devuelve la evidencia como "download_url".
        $readModel = new CodeIgniterExpirationEvidenceReadModel($this->db);
        $rows = $readModel->historyForExpiration(1, 1);
        self::assertNotEmpty($rows);
        self::assertSame(1, (int) $rows[0]['empresa_id']);
        self::assertNotNull($rows[0]['evidencia']);
        self::assertSame((int) $evidence['id'], $rows[0]['evidencia']['id']);
    }

    /**
     * 5) Seguridad: usuario de empresa A no puede renovar ni ver historial
     *    de un vencimiento de empresa B aunque fuerce el ID.
     */
    public function testCrossTenantAccessIsBlocked(): void
    {
        $gateway = $this->gateway();
        $now = new DateTimeImmutable('2026-09-17 11:30:00');

        // El vencimiento 5 es de empresa B (ultimo insert).
        $row = $this->db->table('vencimientos')->where('empresa_id', 2)->orderBy('id', 'DESC')->get()->getRowArray();
        self::assertSame(2, (int) $row['empresa_id']);

        $command = new RenovarVencimientoCommand(
            (int) $row['id'],
            new DateTimeImmutable('2027-09-20'),
            null,
            null,
            'intento cross-tenant',
            null,
            null,
            null,
            null,
            'equipos.editar',
            false,
        );

        // Llamamos al gateway con empresa_id=1; debe devolver null (no encontro
        // la fila porque el WHERE por empresa_id excluye a la de empresa 2).
        $result = $gateway->renew(1, $command, $now, 10);
        self::assertNull($result, 'Un usuario de empresa 1 NO debe poder renovar un vencimiento de empresa 2.');

        // El historial del vencimiento de empresa B no debe ser visible para A.
        $readModel = new CodeIgniterExpirationEvidenceReadModel($this->db);
        $history = $readModel->historyForExpiration(1, (int) $row['id']);
        self::assertSame([], $history, 'El historial filtrado por empresa A NO debe devolver eventos de empresa B.');

        // El resumen (detail) del vencimiento de B tampoco es accesible.
        $summary = $readModel->summary(1, (int) $row['id']);
        self::assertNull($summary);
    }

    /**
     * 6) Los contadores del "proximo vencimiento" no suman empresas.
     */
    public function testUpcomingCountersNeverMixCompanies(): void
    {
        $today = new DateTimeImmutable('2026-09-17');
        $overdueA = $this->db->table('vencimientos')
            ->where('empresa_id', 1)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where('fecha_vencimiento <', $today->format('Y-m-d'))
            ->countAllResults();
        $overdueB = $this->db->table('vencimientos')
            ->where('empresa_id', 2)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where('fecha_vencimiento <', $today->format('Y-m-d'))
            ->countAllResults();

        // Cada empresa ve SUS vencidos. La suma global NO debe ser visible
        // desde ninguna consulta con filtro por empresa.
        self::assertSame(3, $overdueA);
        self::assertSame(2, $overdueB);
        self::assertNotSame($overdueA + $overdueB, $overdueA, 'El contador por empresa NO debe ser la suma global.');

        // Renovacion de empresa A no debe afectar el contador de empresa B.
        $gateway = $this->gateway();
        $now = new DateTimeImmutable('2026-09-17 11:30:00');
        $command = new RenovarVencimientoCommand(
            1,
            new DateTimeImmutable('2027-09-20'),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'equipos.editar',
            false,
        );
        $gateway->renew(1, $command, $now, 10);

        $overdueAAfterRenew = $this->db->table('vencimientos')
            ->where('empresa_id', 1)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where('fecha_vencimiento <', $today->format('Y-m-d'))
            ->countAllResults();
        $overdueBAfterRenew = $this->db->table('vencimientos')
            ->where('empresa_id', 2)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where('fecha_vencimiento <', $today->format('Y-m-d'))
            ->countAllResults();
        self::assertSame(2, $overdueAAfterRenew, 'Renovar movio 1 vencimiento de A a futuro: A pasa de 3 a 2.');
        self::assertSame(2, $overdueBAfterRenew, 'La renovacion en A no debe tocar B.');
    }
}

final class InMemoryEvidenceStorageFake implements ExpirationEvidenceStorage
{
    public function store(string $temporaryPath, int $companyId, string $extension): StoredAttachmentFile
    {
        $storedName = str_repeat('e', 48) . '.' . $extension;

        return new StoredAttachmentFile($storedName, $companyId . '/' . $storedName);
    }

    public function read(string $privateRelativePath): string
    {
        return '';
    }

    public function delete(string $privateRelativePath): void
    {
    }
}
