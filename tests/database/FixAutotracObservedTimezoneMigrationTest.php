<?php

declare(strict_types=1);

use App\Database\Migrations\FixAutotracObservedTimezone;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * La corrección de la hora de Autotrac sobre datos guardados. Tipos como en las
 * migraciones: `observada_en` y `registrada_en` DATETIME, `proveedor` VARCHAR,
 * clave única (integracion_id, unidad_externa, observada_en). Regla 9.
 */
final class FixAutotracObservedTimezoneMigrationTest extends TestCase
{
    private BaseConnection $db;
    private string $zonaOriginal;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La migración se prueba con sqlite3.');
        }

        require_once APPPATH . 'Database/Migrations/2026-11-22-090000_FixAutotracObservedTimezone.php';
        $this->zonaOriginal = date_default_timezone_get();
        date_default_timezone_set('America/Argentina/Buenos_Aires');
        $this->db = Database::connect(['database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true], false);
        $this->db->query('CREATE TABLE telematia_muestras (id INTEGER PRIMARY KEY, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, unidad_externa VARCHAR(100), proveedor VARCHAR(32), observada_en DATETIME, registrada_en DATETIME NULL, UNIQUE (integracion_id, unidad_externa, observada_en))');
        $this->db->query('CREATE TABLE telematia_ultima_lectura (id INTEGER PRIMARY KEY, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, unidad_externa VARCHAR(100), proveedor VARCHAR(32), observada_en DATETIME, registrada_en DATETIME NULL, UNIQUE (integracion_id, unidad_externa))');

        $this->db->table('telematia_muestras')->insertBatch([
            // Autotrac corrida 3 h: se corrige.
            ['id' => 1, 'empresa_id' => 4, 'integracion_id' => 2, 'equipo_id' => 50, 'unidad_externa' => 'A1', 'proveedor' => 'autotrac', 'observada_en' => '2026-11-22 10:11:41', 'registrada_en' => '2026-11-22 07:15:00'],
            // Autotrac ya bien (entró después del arreglo): no se toca.
            ['id' => 2, 'empresa_id' => 4, 'integracion_id' => 2, 'equipo_id' => 50, 'unidad_externa' => 'A1', 'proveedor' => 'autotrac', 'observada_en' => '2026-11-22 08:00:00', 'registrada_en' => '2026-11-22 08:05:00'],
            // La misma captura leída antes (corrida) y después del arreglo: la vieja se borra.
            ['id' => 3, 'empresa_id' => 4, 'integracion_id' => 2, 'equipo_id' => 50, 'unidad_externa' => 'A1', 'proveedor' => 'autotrac', 'observada_en' => '2026-11-22 11:00:00', 'registrada_en' => '2026-11-22 08:01:00'],
            // Dos capturas reales separadas 3 h, las dos corridas: no son duplicadas, se mueven las dos.
            ['id' => 5, 'empresa_id' => 4, 'integracion_id' => 2, 'equipo_id' => 51, 'unidad_externa' => 'A2', 'proveedor' => 'autotrac', 'observada_en' => '2026-11-21 12:00:00', 'registrada_en' => '2026-11-21 09:05:00'],
            ['id' => 6, 'empresa_id' => 4, 'integracion_id' => 2, 'equipo_id' => 51, 'unidad_externa' => 'A2', 'proveedor' => 'autotrac', 'observada_en' => '2026-11-21 15:00:00', 'registrada_en' => '2026-11-21 12:05:00'],
            // Wialon con el reloj del equipo adelantado unos minutos: no es Autotrac, no se toca.
            ['id' => 4, 'empresa_id' => 4, 'integracion_id' => 1, 'equipo_id' => 7, 'unidad_externa' => 'W7', 'proveedor' => 'wialon', 'observada_en' => '2026-11-22 10:11:41', 'registrada_en' => '2026-11-22 07:15:00'],
        ]);
        $this->db->table('telematia_ultima_lectura')->insertBatch([
            ['id' => 1, 'empresa_id' => 4, 'integracion_id' => 2, 'equipo_id' => 50, 'unidad_externa' => 'A1', 'proveedor' => 'autotrac', 'observada_en' => '2026-11-22 10:11:41', 'registrada_en' => '2026-11-22 07:15:00'],
        ]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zonaOriginal);
    }

    private function migrar(): void
    {
        $migracion = new FixAutotracObservedTimezone(Database::forge($this->db));
        $migracion->up();
    }

    /** @return array<int,string> */
    private function horas(string $tabla): array
    {
        return array_column($this->db->table($tabla)->select('id, observada_en')->orderBy('id')->get()->getResultArray(), 'observada_en', 'id');
    }

    public function testCorrigeSoloLasMuestrasDeAutotracImposiblesYBorraElDuplicado(): void
    {
        $this->migrar();

        self::assertSame([
            1 => '2026-11-22 07:11:41',
            2 => '2026-11-22 08:00:00',
            4 => '2026-11-22 10:11:41',
            5 => '2026-11-21 09:00:00',
            6 => '2026-11-21 12:00:00',
        ], $this->horas('telematia_muestras'));
        self::assertSame([1 => '2026-11-22 07:11:41'], $this->horas('telematia_ultima_lectura'));
    }

    public function testCorrerlaDosVecesNoCorreNadaDosVeces(): void
    {
        $this->migrar();
        $despuesDeUna = $this->horas('telematia_muestras');
        $this->migrar();

        self::assertSame($despuesDeUna, $this->horas('telematia_muestras'));
    }
}
