<?php

declare(strict_types=1);

use App\Infrastructure\Measurement\CodeIgniterLaterReadingLookup;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterLaterReadingLookupTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del adaptador requiere sqlite3.');
        }
        $this->database = Database::connect(['database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true], false);
        // Tipos como en la migración: kilometraje BIGINT nullable, anulada TINYINT 0/1.
        $this->database->query('CREATE TABLE lecturas_equipo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            equipo_id INTEGER NOT NULL,
            fecha_lectura DATETIME NOT NULL,
            kilometraje BIGINT NULL,
            anulada TINYINT NOT NULL DEFAULT 0
        )');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testFindsALaterReadingWithHigherKilometers(): void
    {
        $this->reading(5, 10, '2026-11-17 00:00:00', 1_729_288);

        self::assertTrue($this->lookup()->hasLaterReadingAtOrAbove(5, 10, new DateTimeImmutable('2026-11-10 00:00:00'), 1_727_587));
    }

    public function testIgnoresEarlierVoidedOtherCompanyAndLowerReadings(): void
    {
        $this->reading(5, 10, '2026-11-09 00:00:00', 1_729_288);           // anterior
        $this->reading(5, 10, '2026-11-17 00:00:00', 1_729_288, true);     // anulada
        $this->reading(6, 10, '2026-11-17 00:00:00', 1_729_288);           // otra empresa
        $this->reading(5, 10, '2026-11-17 00:00:00', 1_727_000);           // menor

        self::assertFalse($this->lookup()->hasLaterReadingAtOrAbove(5, 10, new DateTimeImmutable('2026-11-10 00:00:00'), 1_727_587));
    }

    private function lookup(): CodeIgniterLaterReadingLookup
    {
        return new CodeIgniterLaterReadingLookup($this->database);
    }

    private function reading(int $companyId, int $equipmentId, string $at, int $km, bool $voided = false): void
    {
        $this->database->table('lecturas_equipo')->insert([
            'empresa_id' => $companyId, 'equipo_id' => $equipmentId, 'fecha_lectura' => $at,
            'kilometraje' => $km, 'anulada' => $voided ? 1 : 0,
        ]);
    }
}
