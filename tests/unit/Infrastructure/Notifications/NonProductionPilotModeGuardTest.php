<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterGlobalNotificationSettingsStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * El modo piloto no se puede apagar fuera de produccion.
 *
 * Motivo (23/10/2026): staging estaba con la instancia real de WhatsApp (`oscar_claro_2`) y el
 * piloto apagado, asi que a las 17:33 le mando a los telefonos reales de dos usuarios de TSA un
 * resumen con datos viejos y enlaces a un entorno de prueba. El aviso a las 07:00 del dia
 * siguiente volvio a salir.
 *
 * La regla: en un ambiente de prueba el unico destino de WhatsApp es el telefono piloto. Se
 * respeta lo que haya guardado un superadmin (por ejemplo, apuntar el piloto al celular de un
 * cliente para una prueba concreta), pero no se puede desactivar la proteccion.
 *
 * Y al reves: si `CI_ENVIRONMENT` no esta definido no se toca nada, para que un despliegue de
 * produccion mal configurado no quede mudo.
 */
final class NonProductionPilotModeGuardTest extends TestCase
{
    private BaseConnection $database;

    /** @var array<string,mixed> */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La configuracion de canales requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database'    => ':memory:',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => '',
            'DBDebug'     => true,
            'foreignKeys' => true,
        ], false);

        $this->database->query(
            'CREATE TABLE configuracion_canales_globales ('
            . 'id INTEGER PRIMARY KEY, smtp_enabled INTEGER, smtp_protocol TEXT, smtp_host TEXT NULL,'
            . ' smtp_port INTEGER, smtp_user TEXT NULL, smtp_pass_encrypted TEXT NULL, smtp_crypto TEXT NULL,'
            . ' smtp_from_email TEXT NULL, smtp_from_name TEXT NULL, smtp_timeout INTEGER,'
            . ' webpush_enabled INTEGER, webpush_subject TEXT NULL, webpush_public_key TEXT NULL,'
            . ' webpush_private_key_encrypted TEXT NULL, whatsapp_enabled INTEGER, whatsapp_api_url TEXT NULL,'
            . ' whatsapp_api_key_encrypted TEXT NULL, whatsapp_instance_id TEXT NULL,'
            . ' whatsapp_pilot_enabled INTEGER, whatsapp_pilot_phone TEXT NULL, updated_at TEXT NULL)',
        );

        $this->environmentBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environmentBackup;
        $_SERVER['CI_ENVIRONMENT'] = 'testing';
        putenv('CI_ENVIRONMENT=testing');
    }

    public function testPilotModeStaysOnInAStagingEnvironment(): void
    {
        $this->storePilotFlag(0);
        $this->setEnvironment('development');

        $settings = (new CodeIgniterGlobalNotificationSettingsStore($this->database))->get();

        self::assertTrue($settings['whatsapp_pilot_enabled']);
    }

    public function testPilotModeStaysOnInATestingEnvironment(): void
    {
        $this->storePilotFlag(0);
        $this->setEnvironment('testing');

        $settings = (new CodeIgniterGlobalNotificationSettingsStore($this->database))->get();

        self::assertTrue($settings['whatsapp_pilot_enabled']);
    }

    public function testProductionRespectsTheStoredPilotFlag(): void
    {
        $this->storePilotFlag(0);
        $this->setEnvironment('production');

        $settings = (new CodeIgniterGlobalNotificationSettingsStore($this->database))->get();

        self::assertFalse($settings['whatsapp_pilot_enabled']);
    }

    public function testAnUndeclaredEnvironmentDoesNotForcePilotMode(): void
    {
        $this->storePilotFlag(0);
        $this->setEnvironment(null);

        $settings = (new CodeIgniterGlobalNotificationSettingsStore($this->database))->get();

        self::assertFalse($settings['whatsapp_pilot_enabled']);
    }

    public function testAnEnabledPilotFlagIsRespectedEverywhere(): void
    {
        $this->storePilotFlag(1);
        $this->setEnvironment('development');

        $settings = (new CodeIgniterGlobalNotificationSettingsStore($this->database))->get();

        self::assertTrue($settings['whatsapp_pilot_enabled']);
    }

    private function storePilotFlag(int $enabled): void
    {
        $this->database->table('configuracion_canales_globales')->replace([
            'id'                     => 1,
            'smtp_enabled'           => 0,
            'smtp_protocol'          => 'smtp',
            'smtp_port'              => 587,
            'smtp_timeout'           => 10,
            'webpush_enabled'        => 0,
            'whatsapp_enabled'       => 1,
            'whatsapp_instance_id'   => 'oscar_claro_2',
            'whatsapp_pilot_enabled' => $enabled,
            'whatsapp_pilot_phone'   => '3743667526',
        ]);
    }

    private function setEnvironment(?string $environment): void
    {
        if ($environment === null) {
            unset($_ENV['CI_ENVIRONMENT'], $_SERVER['CI_ENVIRONMENT']);
            putenv('CI_ENVIRONMENT');

            return;
        }

        $_ENV['CI_ENVIRONMENT'] = $environment;
        $_SERVER['CI_ENVIRONMENT'] = $environment;
        putenv('CI_ENVIRONMENT=' . $environment);
    }
}
