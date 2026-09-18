<?php

declare(strict_types=1);

use App\Domain\Expirations\ExpirationSubjectType;
use App\Infrastructure\Expirations\CodeIgniterExpirationActiveVersionManager;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterExpirationActiveVersionManagerTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del gestor de vigencias requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
            'foreignKeys' => true,
        ], false);

        $this->database->query('CREATE TABLE vencimientos (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, tipo_vencimiento_id INTEGER NOT NULL, sujeto_tipo TEXT NOT NULL, equipo_id INTEGER NULL, empleado_id INTEGER NULL, fecha_vencimiento TEXT NOT NULL, activo INTEGER NOT NULL DEFAULT 1, updated_by INTEGER NULL, updated_at TEXT NULL, deleted_at TEXT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testKeepsOnlyNewestActiveEquipmentExpiration(): void
    {
        $this->database->table('vencimientos')->insertBatch([
            ['empresa_id' => 5, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 10, 'fecha_vencimiento' => '2026-01-27', 'activo' => 1],
            ['empresa_id' => 5, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 10, 'fecha_vencimiento' => '2027-10-07', 'activo' => 1],
        ]);

        (new CodeIgniterExpirationActiveVersionManager($this->database))
            ->reconcile(5, 3, ExpirationSubjectType::EQUIPMENT, 10, 99);

        $rows = $this->database->table('vencimientos')->orderBy('fecha_vencimiento', 'ASC')->get()->getResultArray();
        self::assertSame(0, (int) $rows[0]['activo']);
        self::assertSame(1, (int) $rows[1]['activo']);
        self::assertSame(99, (int) $rows[0]['updated_by']);
    }

    public function testOlderNewRecordDoesNotReplaceNewerActiveExpiration(): void
    {
        $this->database->table('vencimientos')->insertBatch([
            ['empresa_id' => 5, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 10, 'fecha_vencimiento' => '2027-10-07', 'activo' => 1],
            ['empresa_id' => 5, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 10, 'fecha_vencimiento' => '2026-01-27', 'activo' => 1],
        ]);

        (new CodeIgniterExpirationActiveVersionManager($this->database))
            ->reconcile(5, 3, ExpirationSubjectType::EQUIPMENT, 10);

        $newer = $this->database->table('vencimientos')->where('fecha_vencimiento', '2027-10-07')->get()->getRowArray();
        $older = $this->database->table('vencimientos')->where('fecha_vencimiento', '2026-01-27')->get()->getRowArray();

        self::assertSame(1, (int) $newer['activo']);
        self::assertSame(0, (int) $older['activo']);
    }

    public function testDoesNotTouchAnotherCompanyOrSubject(): void
    {
        $this->database->table('vencimientos')->insertBatch([
            ['empresa_id' => 5, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 10, 'fecha_vencimiento' => '2026-01-27', 'activo' => 1],
            ['empresa_id' => 5, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 10, 'fecha_vencimiento' => '2027-10-07', 'activo' => 1],
            ['empresa_id' => 6, 'tipo_vencimiento_id' => 3, 'sujeto_tipo' => 'EQUIPO', 'equipo_id' => 20, 'fecha_vencimiento' => '2026-01-27', 'activo' => 1],
        ]);

        (new CodeIgniterExpirationActiveVersionManager($this->database))
            ->reconcile(5, 3, ExpirationSubjectType::EQUIPMENT, 10);

        self::assertSame(1, $this->database->table('vencimientos')->where('empresa_id', 6)->where('activo', 1)->countAllResults());
    }
}
