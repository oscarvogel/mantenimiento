<?php

declare(strict_types=1);

use App\Application\Notifications\NotificationRecipientScopePolicy;
use App\Application\Notifications\UserNotificationEmailPolicy;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationSeverity;
use App\Infrastructure\Notifications\CodeIgniterNotificationRecipientResolver;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class NotificationRecipientEmailTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del resolver requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, nombre TEXT, email VARCHAR(255) NULL, email_notificaciones VARCHAR(255) NULL, telefono VARCHAR(20) NULL, activo INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL)');
        $this->db->query('CREATE TABLE usuario_roles (usuario_id INTEGER, rol_id INTEGER)');
        $this->db->query('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre VARCHAR(255) NOT NULL)');
        $this->db->query('CREATE TABLE rol_permisos (rol_id INTEGER, permiso_id INTEGER)');
        $this->db->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY, clave VARCHAR(255) NOT NULL)');
        $this->db->query('CREATE TABLE usuario_sucursales (usuario_id INTEGER, sucursal_id INTEGER)');

        $this->db->table('roles')->insert(['id' => 3, 'nombre' => 'Técnico']);
        $this->db->table('permisos')->insertBatch([
            ['id' => 30, 'clave' => 'ordenes.ver'],
            ['id' => 31, 'clave' => 'notificaciones.ver'],
        ]);
        $this->db->table('rol_permisos')->insertBatch([
            ['rol_id' => 3, 'permiso_id' => 30],
            ['rol_id' => 3, 'permiso_id' => 31],
        ]);
        $this->db->table('usuario_roles')->insert(['usuario_id' => 7, 'rol_id' => 3]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testRecipientUsesTheNotificationEmailWhenPresent(): void
    {
        $this->seedUser('resumen@example.test');

        self::assertSame('resumen@example.test', $this->resolve()[0]->email);
    }

    public function testRecipientFallsBackToTheLoginEmailWhenTheNotificationEmailIsMissing(): void
    {
        $this->seedUser(null);

        self::assertSame('operador@example.test', $this->resolve()[0]->email);
    }

    public function testRecipientFallsBackToTheLoginEmailWhenTheNotificationEmailIsInvalid(): void
    {
        $this->seedUser('casilla-inexistente');

        self::assertSame('operador@example.test', $this->resolve()[0]->email);
    }

    private function seedUser(?string $notificationEmail): void
    {
        $this->db->table('usuarios')->insert([
            'id' => 7, 'empresa_id' => 5, 'nombre' => 'Operador',
            'email' => 'operador@example.test', 'email_notificaciones' => $notificationEmail,
            'telefono' => null, 'activo' => 1, 'deleted_at' => null,
        ]);
    }

    /** @return list<\App\Application\Notifications\NotificationRecipient> */
    private function resolve(): array
    {
        $resolver = new CodeIgniterNotificationRecipientResolver(
            new NotificationRecipientScopePolicy(),
            new UserNotificationEmailPolicy(),
            $this->db,
        );

        return $resolver->resolve(new NotifiableEvent(
            5,
            null,
            'orden.generada',
            NotificationSeverity::WARNING,
            'Orden generada',
            'Detalle',
            'orden',
            '1',
            'orden:1',
            null,
            new DateTimeImmutable('2026-11-28 07:00:00', new DateTimeZone('UTC')),
        ));
    }
}
