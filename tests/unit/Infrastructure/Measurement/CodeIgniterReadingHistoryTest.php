<?php

declare(strict_types=1);

use App\Infrastructure\Measurement\CodeIgniterReadingHistory;
use App\Infrastructure\Measurement\CodeIgniterReadingCorrectionRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterReadingHistoryTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del historial requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database'    => ':memory:',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => '',
            'DBDebug'     => true,
            'foreignKeys' => true,
        ], false);
        $this->database->query(
            'CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, '
            . 'km_actual INTEGER NULL, horas_actuales TEXT NULL, updated_at TEXT NULL, updated_by INTEGER NULL, deleted_at TEXT NULL)'
        );
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->database->query(
            'CREATE TABLE lecturas_equipo ('
            . 'id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER, '
            . 'fecha_lectura TEXT, kilometraje INTEGER NULL, horometro TEXT NULL, origen TEXT, referencia_origen TEXT NULL, '
            . 'usuario_id INTEGER, observaciones TEXT NULL, motivo_correccion TEXT NULL, '
            . 'lectura_corregida_id INTEGER NULL, anulada INTEGER, anulada_at TEXT NULL, '
            . 'anulada_por INTEGER NULL, motivo_anulacion TEXT NULL)'
        );
        $this->database->table('usuarios')->insertBatch([
            ['id' => 4, 'nombre' => 'Operador'],
            ['id' => 9, 'nombre' => 'Responsable'],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 9, 'km_actual' => 1200, 'horas_actuales' => null, 'updated_at' => null, 'updated_by' => null, 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 8, 'km_actual' => null, 'horas_actuales' => null, 'updated_at' => null, 'updated_by' => null, 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'sucursal_id' => 9, 'km_actual' => null, 'horas_actuales' => null, 'updated_at' => null, 'updated_by' => null, 'deleted_at' => null],
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            [
                'id' => 81, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10,
                'fecha_lectura' => '2026-07-10 08:30:00', 'kilometraje' => 1000, 'horometro' => null,
                'origen' => 'MANUAL', 'usuario_id' => 4, 'observaciones' => null,
                'motivo_correccion' => null, 'lectura_corregida_id' => null, 'anulada' => 1,
                'anulada_at' => '2026-08-08 12:00:00', 'anulada_por' => 9,
                'motivo_anulacion' => 'Error de transcripción',
            ],
            [
                'id' => 82, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10,
                'fecha_lectura' => '2026-07-10 08:30:00', 'kilometraje' => 950, 'horometro' => null,
                'origen' => 'MANUAL', 'usuario_id' => 9, 'observaciones' => null,
                'motivo_correccion' => 'Error de transcripción', 'lectura_corregida_id' => 81,
                'anulada' => 0, 'anulada_at' => null, 'anulada_por' => null, 'motivo_anulacion' => null,
            ],
            [
                'id' => 83, 'empresa_id' => 5, 'sucursal_id' => 9, 'equipo_id' => 10,
                'fecha_lectura' => '2026-08-01 10:00:00', 'kilometraje' => 1200, 'horometro' => null,
                'origen' => 'MANUAL', 'usuario_id' => 4, 'observaciones' => null,
                'motivo_correccion' => null, 'lectura_corregida_id' => null, 'anulada' => 0,
                'anulada_at' => null, 'anulada_por' => null, 'motivo_anulacion' => null,
            ],
            [
                'id' => 90, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 12,
                'fecha_lectura' => '2026-07-01 10:00:00', 'kilometraje' => 777, 'horometro' => '12.5',
                'origen' => 'MANUAL', 'usuario_id' => 4, 'observaciones' => null,
                'motivo_correccion' => null, 'lectura_corregida_id' => null, 'anulada' => 0,
                'anulada_at' => null, 'anulada_por' => null, 'motivo_anulacion' => null,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testMovedEquipmentKeepsHistoricalBranchReadingsAndPagination(): void
    {
        $history = new CodeIgniterReadingHistory($this->database);

        $first = $history->forEquipment(5, 10, [9], 1, 2);
        $second = $history->forEquipment(5, 10, [9], 2, 2);

        self::assertSame(3, $first->total);
        self::assertSame(2, $first->totalPages());
        self::assertSame([83, 82], array_map(static fn ($item): int => $item->id, $first->items));
        self::assertSame(7, $first->items[1]->branchId);
        self::assertSame(81, $first->items[1]->correctedReadingId);
        self::assertSame(82, $second->items[0]->replacementReadingId);
        self::assertTrue($second->items[0]->annulled);
    }

    public function testBranchScopeUsesCurrentEquipmentLocationNotHistoricalReadingLocation(): void
    {
        $history = new CodeIgniterReadingHistory($this->database);

        self::assertSame(0, $history->forEquipment(5, 10, [7], 1, 20)->total);
        self::assertSame(3, $history->forEquipment(5, 10, [9], 1, 20)->total);
    }

    public function testProjectionUsesLatestValidReadingAcrossHistoricalBranches(): void
    {
        $repository = new CodeIgniterReadingCorrectionRepository($this->database);

        $current = $repository->recalculateCurrentUsage(5, 9, 12, 9);
        $equipment = $this->database->table('equipos')->where('id', 12)->get()->getRowArray();

        self::assertSame(777, $current->kilometers());
        self::assertSame('12.5', $current->hours());
        self::assertSame(777, (int) $equipment['km_actual']);
        self::assertSame('12.5', (string) $equipment['horas_actuales']);
        self::assertSame(9, (int) $equipment['updated_by']);
    }
}
