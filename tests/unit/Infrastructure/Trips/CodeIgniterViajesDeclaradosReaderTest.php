<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterViajesDeclaradosReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Contrato `ViajesDeclaradosReader` sobre las tablas de Viajes.
 *
 * Los tipos replican las migraciones: `estado` y `rol` son VARCHAR, las fechas
 * DATETIME y los litros DECIMAL(14,2). Ver la regla 9 de AGENTS.md.
 */
final class CodeIgniterViajesDeclaradosReaderTest extends TestCase
{
    private BaseConnection $db;
    private CodeIgniterViajesDeclaradosReader $reader;
    private DateTimeZone $zona;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El contrato de viajes declarados requiere sqlite3.');
        }

        $this->zona = new DateTimeZone('America/Argentina/Buenos_Aires');
        $this->db = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo VARCHAR(50), patente VARCHAR(20) NULL)');
        $this->db->query('CREATE TABLE viajes_operativos (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, numero INTEGER, estado VARCHAR(20),
            fecha_salida_real DATETIME NULL, fecha_regreso_real DATETIME NULL, km_salida INTEGER NULL, km_regreso INTEGER NULL
        )');
        $this->db->query('CREATE TABLE viaje_operativo_asignaciones (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, rol VARCHAR(20), equipo_id INTEGER NULL, empleado_id INTEGER NULL
        )');
        $this->db->query('CREATE TABLE viaje_operativo_combustible (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, litros DECIMAL(14,2), deleted_at DATETIME NULL
        )');

        $this->db->table('equipos')->insertBatch([
            ['id' => 7, 'empresa_id' => 4, 'codigo' => 'T07', 'patente' => 'JLH877'],
            ['id' => 8, 'empresa_id' => 4, 'codigo' => 'T08', 'patente' => 'AB499OK'],
            ['id' => 9, 'empresa_id' => 5, 'codigo' => 'X09', 'patente' => 'OTRA001'],
        ]);
        $this->db->table('viajes_operativos')->insertBatch([
            // En curso, dentro del período, con dos cargas y una borrada.
            ['id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'numero' => 21, 'estado' => 'EN_CURSO', 'fecha_salida_real' => '2026-10-30 00:00:00', 'fecha_regreso_real' => null, 'km_salida' => 1720460, 'km_regreso' => null],
            // Finalizado, de otra sucursal.
            ['id' => 2, 'empresa_id' => 4, 'sucursal_id' => 20, 'numero' => 22, 'estado' => 'FINALIZADO', 'fecha_salida_real' => '2026-11-01 08:00:00', 'fecha_regreso_real' => '2026-11-05 18:00:00', 'km_salida' => 1000, 'km_regreso' => 3000],
            // Cancelado: no se concilia.
            ['id' => 3, 'empresa_id' => 4, 'sucursal_id' => 10, 'numero' => 23, 'estado' => 'CANCELADO', 'fecha_salida_real' => '2026-11-02 08:00:00', 'fecha_regreso_real' => null, 'km_salida' => null, 'km_regreso' => null],
            // Planificado sin salida real.
            ['id' => 4, 'empresa_id' => 4, 'sucursal_id' => 10, 'numero' => 24, 'estado' => 'PLANIFICADO', 'fecha_salida_real' => null, 'fecha_regreso_real' => null, 'km_salida' => null, 'km_regreso' => null],
            // Terminó antes del período.
            ['id' => 5, 'empresa_id' => 4, 'sucursal_id' => 10, 'numero' => 25, 'estado' => 'FINALIZADO', 'fecha_salida_real' => '2026-09-01 08:00:00', 'fecha_regreso_real' => '2026-09-05 18:00:00', 'km_salida' => 1, 'km_regreso' => 2],
            // De otra empresa.
            ['id' => 6, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 1, 'estado' => 'EN_CURSO', 'fecha_salida_real' => '2026-11-01 08:00:00', 'fecha_regreso_real' => null, 'km_salida' => null, 'km_regreso' => null],
            // Sin tractor asignado.
            ['id' => 7, 'empresa_id' => 4, 'sucursal_id' => 10, 'numero' => 26, 'estado' => 'FINALIZADO', 'fecha_salida_real' => '2026-11-03 08:00:00', 'fecha_regreso_real' => '2026-11-04 08:00:00', 'km_salida' => null, 'km_regreso' => null],
        ]);
        $this->db->table('viaje_operativo_asignaciones')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'rol' => 'TRACTOR', 'equipo_id' => 7, 'empleado_id' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'rol' => 'CHOFER', 'equipo_id' => null, 'empleado_id' => 88],
            ['id' => 3, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'rol' => 'TRACTOR', 'equipo_id' => 8, 'empleado_id' => null],
            ['id' => 4, 'viaje_id' => 2, 'empresa_id' => 4, 'sucursal_id' => 20, 'rol' => 'TRACTOR', 'equipo_id' => 8, 'empleado_id' => null],
            // Una asignación con el id del viaje 7 pero de otra empresa no le da tractor.
            ['id' => 5, 'viaje_id' => 7, 'empresa_id' => 5, 'sucursal_id' => 10, 'rol' => 'TRACTOR', 'equipo_id' => 9, 'empleado_id' => null],
        ]);
        $this->db->table('viaje_operativo_combustible')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 4, 'litros' => '200.00', 'deleted_at' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 4, 'litros' => '219.50', 'deleted_at' => null],
            ['id' => 3, 'viaje_id' => 1, 'empresa_id' => 4, 'litros' => '999.00', 'deleted_at' => '2026-11-02 10:00:00'],
            ['id' => 4, 'viaje_id' => 7, 'empresa_id' => 5, 'litros' => '500.00', 'deleted_at' => null],
        ]);

        $this->reader = new CodeIgniterViajesDeclaradosReader($this->db, $this->zona);
    }

    private function entre(?array $sucursales): array
    {
        return $this->reader->viajesEntre(
            4,
            $sucursales,
            new DateTimeImmutable('2026-10-21 00:00', $this->zona),
            new DateTimeImmutable('2026-11-20 00:00', $this->zona),
        );
    }

    public function testSoloDevuelveViajesEnCursoOFinalizadosDelPeriodoYDeLaEmpresa(): void
    {
        $viajes = $this->entre(null);

        self::assertSame([26, 22, 21], array_map(static fn ($v): int => $v->numero, $viajes));
    }

    public function testSumaSoloLasCargasVigentesYTomaElPrimerTractor(): void
    {
        $viaje = $this->porNumero(21);

        self::assertSame(419.5, $viaje->litrosDeclarados);
        self::assertSame(2, $viaje->cargasDeclaradas);
        self::assertSame(7, $viaje->equipoId);
        self::assertSame('JLH877', $viaje->patente);
        self::assertNull($viaje->regreso);
        self::assertSame('2026-10-30 00:00:00', $viaje->salida->format('Y-m-d H:i:s'));
    }

    public function testLasAsignacionesYCargasDeOtraEmpresaNoSeMezclan(): void
    {
        $viaje = $this->porNumero(26);

        self::assertNull($viaje->equipoId);
        self::assertSame(0.0, $viaje->litrosDeclarados);
        self::assertSame(0, $viaje->cargasDeclaradas);
    }

    public function testFiltraPorSucursalYUnaListaVaciaNoDevuelveNada(): void
    {
        self::assertSame([22], array_map(static fn ($v): int => $v->numero, $this->entre([20])));
        self::assertSame([], $this->entre([]));
    }

    private function porNumero(int $numero): \App\Domain\Telematic\Conciliacion\ViajeDeclarado
    {
        foreach ($this->entre(null) as $viaje) {
            if ($viaje->numero === $numero) {
                return $viaje;
            }
        }

        self::fail('No se encontró el viaje ' . $numero);
    }
}
