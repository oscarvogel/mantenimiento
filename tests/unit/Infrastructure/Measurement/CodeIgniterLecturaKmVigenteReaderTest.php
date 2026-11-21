<?php

declare(strict_types=1);

use App\Infrastructure\Measurement\CodeIgniterLecturaKmVigenteReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Contrato `LecturaKmVigenteReader` sobre `lecturas_equipo` y `equipos`.
 *
 * Los tipos replican las migraciones: `kilometraje` y `km_actual` son BIGINT,
 * `anulada` TINYINT, `estado` VARCHAR y `fecha_lectura` DATETIME. Ver la regla 9
 * de AGENTS.md.
 */
final class CodeIgniterLecturaKmVigenteReaderTest extends TestCase
{
    private BaseConnection $db;
    private CodeIgniterLecturaKmVigenteReader $reader;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El contrato de lectura vigente requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE equipos (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo VARCHAR(50), estado VARCHAR(20) DEFAULT \'ACTIVO\',
            km_actual BIGINT NULL, deleted_at DATETIME NULL
        )');
        $this->db->query('CREATE TABLE lecturas_equipo (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER, fecha_lectura DATETIME,
            kilometraje BIGINT NULL, horometro DECIMAL(12,1) NULL, origen VARCHAR(30), anulada TINYINT DEFAULT 0
        )');

        $this->db->table('equipos')->insertBatch([
            ['id' => 1, 'empresa_id' => 4, 'codigo' => 'T01', 'km_actual' => 1013505],
            ['id' => 2, 'empresa_id' => 4, 'codigo' => 'T02', 'km_actual' => 500000],
            ['id' => 3, 'empresa_id' => 4, 'codigo' => 'T03', 'km_actual' => null],
            ['id' => 9, 'empresa_id' => 5, 'codigo' => 'X09', 'km_actual' => 1013505],
        ]);
        $this->db->table('lecturas_equipo')->insertBatch([
            ['id' => 1, 'empresa_id' => 4, 'sucursal_id' => 1, 'equipo_id' => 1, 'fecha_lectura' => '2026-11-09 08:00:00', 'kilometraje' => 1009000, 'origen' => 'QR_ANONIMO', 'anulada' => 0],
            ['id' => 2, 'empresa_id' => 4, 'sucursal_id' => 1, 'equipo_id' => 1, 'fecha_lectura' => '2026-11-16 12:48:00', 'kilometraje' => 1013505, 'origen' => 'QR_ANONIMO', 'anulada' => 0],
            // Lectura histórica de viaje posterior con km menor: no mueve el odómetro, no es la vigente.
            ['id' => 3, 'empresa_id' => 4, 'sucursal_id' => 1, 'equipo_id' => 1, 'fecha_lectura' => '2026-11-18 10:00:00', 'kilometraje' => 1011000, 'origen' => 'VIAJE', 'anulada' => 0],
            // Anulada con el km actual y fecha posterior: no cuenta.
            ['id' => 4, 'empresa_id' => 4, 'sucursal_id' => 1, 'equipo_id' => 1, 'fecha_lectura' => '2026-11-19 10:00:00', 'kilometraje' => 1013505, 'origen' => 'MANUAL', 'anulada' => 1],
            // El equipo 2 tiene odómetro de una importación sin lectura que lo respalde.
            ['id' => 5, 'empresa_id' => 4, 'sucursal_id' => 1, 'equipo_id' => 2, 'fecha_lectura' => '2026-11-10 10:00:00', 'kilometraje' => 499000, 'origen' => 'MANUAL', 'anulada' => 0],
            // Solo horómetro.
            ['id' => 6, 'empresa_id' => 4, 'sucursal_id' => 1, 'equipo_id' => 3, 'fecha_lectura' => '2026-11-10 10:00:00', 'kilometraje' => null, 'origen' => 'MANUAL', 'anulada' => 0],
            // Otra empresa con el mismo id de equipo cruzado: no se mezcla.
            ['id' => 7, 'empresa_id' => 5, 'sucursal_id' => 3, 'equipo_id' => 9, 'fecha_lectura' => '2026-11-20 10:00:00', 'kilometraje' => 1013505, 'origen' => 'MANUAL', 'anulada' => 0],
            ['id' => 8, 'empresa_id' => 5, 'sucursal_id' => 3, 'equipo_id' => 1, 'fecha_lectura' => '2026-11-20 11:00:00', 'kilometraje' => 1013505, 'origen' => 'MANUAL', 'anulada' => 0],
        ]);

        $this->reader = new CodeIgniterLecturaKmVigenteReader($this->db);
    }

    public function testLaVigenteEsLaUltimaNoAnuladaConElKmActual(): void
    {
        $vigentes = $this->reader->vigentes(4, [1]);

        self::assertCount(1, $vigentes);
        self::assertSame(1013505, $vigentes[1]->km);
        self::assertSame('2026-11-16 12:48', $vigentes[1]->fecha->format('Y-m-d H:i'));
        self::assertSame('America/Argentina/Buenos_Aires', $vigentes[1]->fecha->getTimezone()->getName());
    }

    public function testSinLecturaQueRespaldeElOdometroElEquipoNoAparece(): void
    {
        self::assertSame([], $this->reader->vigentes(4, [2, 3]));
    }

    public function testNoCruzaEmpresas(): void
    {
        self::assertSame([], $this->reader->vigentes(4, [9]));
        self::assertSame([9], array_keys($this->reader->vigentes(5, [9, 1])));
    }

    public function testSinEquiposNoConsulta(): void
    {
        self::assertSame([], $this->reader->vigentes(4, []));
        self::assertSame([], $this->reader->vigentes(0, [1]));
    }
}
