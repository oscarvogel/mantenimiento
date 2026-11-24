<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterFuelPanelReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Adaptador del panel de combustible sobre las tablas de Viajes.
 *
 * Los tipos replican las migraciones: `estado` y `rol` son VARCHAR, las fechas
 * DATETIME y los litros DECIMAL(14,2). Ver la regla 9 de AGENTS.md.
 */
final class CodeIgniterFuelPanelReaderTest extends TestCase
{
    private BaseConnection $db;
    private CodeIgniterFuelPanelReader $reader;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El adaptador del panel de combustible requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(120))');
        $this->db->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo VARCHAR(50), patente VARCHAR(20) NULL)');
        $this->db->query('CREATE TABLE viajes_operativos (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, numero INTEGER, estado VARCHAR(20),
            fecha_salida_real DATETIME NULL, fecha_regreso_real DATETIME NULL, km_salida INTEGER NULL, km_regreso INTEGER NULL
        )');
        $this->db->query('CREATE TABLE viaje_operativo_asignaciones (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, rol VARCHAR(20), equipo_id INTEGER NULL, empleado_id INTEGER NULL
        )');
        $this->db->query('CREATE TABLE viaje_operativo_combustible (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, tramo_id INTEGER NULL,
            fecha DATETIME, litros DECIMAL(14,2), importe DECIMAL(18,4) NULL, moneda CHAR(3), cotizacion DECIMAL(18,6),
            importe_ars DECIMAL(18,4) NULL, deleted_at DATETIME NULL
        )');

        $this->db->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 4, 'nombre' => 'Posadas'],
            ['id' => 20, 'empresa_id' => 4, 'nombre' => 'Corrientes'],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 7, 'empresa_id' => 4, 'codigo' => 'T07', 'patente' => 'ONN137'],
            ['id' => 9, 'empresa_id' => 5, 'codigo' => 'X09', 'patente' => 'OTRA001'],
        ]);
        $this->db->table('viajes_operativos')->insertBatch([
            ['id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'numero' => 21, 'estado' => 'FINALIZADO', 'fecha_salida_real' => '2026-10-30 00:00:00', 'fecha_regreso_real' => '2026-11-17 18:00:00', 'km_salida' => 1720460, 'km_regreso' => 1727587],
            ['id' => 2, 'empresa_id' => 4, 'sucursal_id' => 20, 'numero' => 22, 'estado' => 'EN_CURSO', 'fecha_salida_real' => '2026-11-10 08:00:00', 'fecha_regreso_real' => null, 'km_salida' => 1000, 'km_regreso' => null],
            // De otra empresa: no entra.
            ['id' => 3, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 23, 'estado' => 'EN_CURSO', 'fecha_salida_real' => '2026-11-10 08:00:00', 'fecha_regreso_real' => null, 'km_salida' => null, 'km_regreso' => null],
        ]);
        $this->db->table('viaje_operativo_asignaciones')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'rol' => 'TRACTOR', 'equipo_id' => 7, 'empleado_id' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'rol' => 'CHOFER', 'equipo_id' => null, 'empleado_id' => 30],
            // Asignación de otra empresa: no le da tractor.
            ['id' => 3, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'rol' => 'TRACTOR', 'equipo_id' => 9, 'empleado_id' => null],
        ]);
        $this->db->table('viaje_operativo_combustible')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'tramo_id' => null, 'fecha' => '2026-11-02 10:00:00', 'litros' => 512, 'importe' => 1196442, 'moneda' => 'ARS', 'cotizacion' => 1, 'importe_ars' => 1196442, 'deleted_at' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'tramo_id' => null, 'fecha' => '2026-11-13 10:00:00', 'litros' => 193, 'importe' => null, 'moneda' => 'ARS', 'cotizacion' => 1, 'importe_ars' => null, 'deleted_at' => null],
            // Quitada: no cuenta.
            ['id' => 3, 'viaje_id' => 1, 'empresa_id' => 4, 'sucursal_id' => 10, 'tramo_id' => null, 'fecha' => '2026-11-14 10:00:00', 'litros' => 999, 'importe' => 999999, 'moneda' => 'ARS', 'cotizacion' => 1, 'importe_ars' => 999999, 'deleted_at' => '2026-11-15 10:00:00'],
            ['id' => 4, 'viaje_id' => 2, 'empresa_id' => 4, 'sucursal_id' => 20, 'tramo_id' => null, 'fecha' => '2026-11-11 10:00:00', 'litros' => 300, 'importe' => 600000, 'moneda' => 'ARS', 'cotizacion' => 1, 'importe_ars' => 600000, 'deleted_at' => null],
        ]);

        $this->reader = new CodeIgniterFuelPanelReader($this->db);
    }

    public function testSumsTheLiveLoadsOfEachTripAndCountsTheOnesWithoutPrice(): void
    {
        $filas = $this->reader->viajes(4, null);
        $porNumero = [];
        foreach ($filas as $fila) {
            $porNumero[(int) $fila['numero']] = $fila;
        }

        self::assertArrayHasKey(21, $porNumero);
        self::assertSame(705.0, $porNumero[21]['litros']);
        self::assertSame(2, $porNumero[21]['cargas']);
        self::assertSame(1196442.0, $porNumero[21]['importe_ars']);
        self::assertSame(1, $porNumero[21]['cargas_sin_precio']);
        self::assertSame(193.0, $porNumero[21]['litros_sin_precio']);
        self::assertSame('ONN137', $porNumero[21]['patente']);
        self::assertSame('Posadas', $porNumero[21]['sucursal']);

        // El viaje sin tractor no inventa una patente.
        self::assertNull($porNumero[22]['patente']);
    }

    public function testDoesNotReadOtherCompaniesNorBranchesOutsideTheScope(): void
    {
        $filas = $this->reader->viajes(4, [10]);
        $numeros = array_map(static fn (array $fila): int => (int) $fila['numero'], $filas);

        self::assertSame([21], $numeros);
        self::assertSame([], $this->reader->viajes(4, []));

        // La otra empresa solo ve sus propios viajes.
        $otraEmpresa = array_map(
            static fn (array $fila): int => (int) $fila['numero'],
            $this->reader->viajes(5, null),
        );
        self::assertSame([23], $otraEmpresa);
    }
}
