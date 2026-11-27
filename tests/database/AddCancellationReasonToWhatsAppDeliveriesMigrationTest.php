<?php

declare(strict_types=1);

use App\Database\Migrations\AddCancellationReasonToWhatsAppDeliveries;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddCancellationReasonToWhatsAppDeliveriesMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de la migración requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query(
            'CREATE TABLE notificacion_whatsapp_entregas ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, ultimo_error VARCHAR(1000) NULL, created_at TEXT NULL)',
        );
        require_once APPPATH . 'Database/Migrations/2026-11-27-120000_AddCancellationReasonToWhatsAppDeliveries.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsNullableColumnAndIsIdempotent(): void
    {
        $this->database->table('notificacion_whatsapp_entregas')->insert(['ultimo_error' => 'sin cancelar']);

        $migration = new AddCancellationReasonToWhatsAppDeliveries(Database::forge($this->database));
        $migration->up();
        $migration->up();

        self::assertTrue($this->database->fieldExists('motivo_cancelacion', 'notificacion_whatsapp_entregas'));

        $row = $this->database->table('notificacion_whatsapp_entregas')->get()->getRowArray();
        self::assertNull($row['motivo_cancelacion']);
        self::assertSame('sin cancelar', $row['ultimo_error']);
    }

    public function testDownDropsTheColumnAndIsSafeToRepeat(): void
    {
        $migration = new AddCancellationReasonToWhatsAppDeliveries(Database::forge($this->database));
        $migration->up();
        $migration->down();
        $migration->down();

        self::assertFalse($this->database->fieldExists('motivo_cancelacion', 'notificacion_whatsapp_entregas'));
    }
}
