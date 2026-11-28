<?php

declare(strict_types=1);

use App\Database\Migrations\AddUserNotificationEmailSnapshot;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddUserNotificationEmailSnapshotMigrationTest extends TestCase
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
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, email VARCHAR(255) NULL, nombre VARCHAR(255) NULL)');
        $this->database->query('CREATE TABLE notificacion_entregas (id INTEGER PRIMARY KEY AUTOINCREMENT, clave_entrega VARCHAR(220) NOT NULL)');
        require_once APPPATH . 'Database/Migrations/2026-11-28-100000_AddUserNotificationEmailSnapshot.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsBothNullableColumnsWithoutTouchingExistingData(): void
    {
        $this->database->table('usuarios')->insert([
            'id' => 7, 'nombre' => 'Carlos', 'email' => 'gimenezcarlosgabriel@gmail.com',
        ]);
        $this->database->table('notificacion_entregas')->insert([
            'id' => 1, 'clave_entrega' => 'evento:1:usuario:7:email',
        ]);

        $migration = new AddUserNotificationEmailSnapshot(Database::forge($this->database));
        $migration->up();
        $migration->up();

        self::assertTrue($this->database->fieldExists('email_notificaciones', 'usuarios'));
        self::assertTrue($this->database->fieldExists('destinatario', 'notificacion_entregas'));

        $user = $this->database->table('usuarios')->where('id', 7)->get()->getRowArray();
        self::assertNull($user['email_notificaciones']);
        self::assertSame('gimenezcarlosgabriel@gmail.com', $user['email']);

        $delivery = $this->database->table('notificacion_entregas')->where('id', 1)->get()->getRowArray();
        self::assertNull($delivery['destinatario']);
        self::assertSame('evento:1:usuario:7:email', $delivery['clave_entrega']);
    }

    public function testDownDropsOnlyTheNewColumnsAndIsSafeToRepeat(): void
    {
        $migration = new AddUserNotificationEmailSnapshot(Database::forge($this->database));
        $migration->up();
        $migration->down();
        $migration->down();

        self::assertFalse($this->database->fieldExists('email_notificaciones', 'usuarios'));
        self::assertFalse($this->database->fieldExists('destinatario', 'notificacion_entregas'));
        self::assertTrue($this->database->fieldExists('email', 'usuarios'));
    }
}
