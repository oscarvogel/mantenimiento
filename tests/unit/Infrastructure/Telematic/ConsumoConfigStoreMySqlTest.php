<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Application\Telematic\ConfigurarConsumoPorEmpresa;
use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;
use App\Infrastructure\Telematic\CodeIgniterConsumoConfigStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Aislamiento de los umbrales por empresa.
 *
 * Estas pruebas corren contra **MySQL**, no contra el SQLite de la suite, porque
 * el comportamiento de una columna única y de un upsert no es el mismo en los
 * dos motores y el scoping es justo lo que no se puede verificar a medias.
 *
 * Sin `MUESTRAS_TEST_DSN` se saltean, así que la suite normal no depende de una
 * base externa.
 *
 * @internal
 */
final class ConsumoConfigStoreMySqlTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TABLA = 'telemetria_consumo_config';

    private \CodeIgniter\Database\BaseConnection $mysql;
    private ConfigurarConsumoPorEmpresa $caso;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('MUESTRAS_TEST_DSN');

        if (! is_string($dsn) || trim($dsn) === '') {
            $this->markTestSkipped('Requiere MUESTRAS_TEST_DSN apuntando a una base MySQL descartable.');
        }

        $partes = parse_url($dsn);

        if ($partes === false || ($partes['host'] ?? '') === '') {
            $this->markTestSkipped('MUESTRAS_TEST_DSN no es un DSN válido.');
        }

        $this->mysql = \Config\Database::connect([
            'hostname' => $partes['host'],
            'username' => $partes['user'] ?? '',
            'password' => $partes['pass'] ?? '',
            'database' => ltrim($partes['path'] ?? '', '/'),
            'port' => (int) ($partes['port'] ?? 3306),
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8mb4',
            'DBCollat' => 'utf8mb4_unicode_ci',
            'failover' => [],
        ]);

        $this->mysql->query('DROP TABLE IF EXISTS ' . self::TABLA);

        if (! class_exists(\App\Database\Migrations\CreateTelemetryConsumptionSettings::class, false)) {
            require_once ROOTPATH . 'app/Database/Migrations/2026-10-11-080000_CreateTelemetryConsumptionSettings.php';
        }

        (new \App\Database\Migrations\CreateTelemetryConsumptionSettings(
            new \CodeIgniter\Database\Forge($this->mysql),
        ))->up();

        $this->caso = new ConfigurarConsumoPorEmpresa(new CodeIgniterConsumoConfigStore($this->mysql));
    }

    protected function tearDown(): void
    {
        if (isset($this->mysql)) {
            $this->mysql->query('DROP TABLE IF EXISTS ' . self::TABLA);
            $this->mysql->close();
        }

        parent::tearDown();
    }

    public function testUnaEmpresaSinFilaRecibeLosDefaults(): void
    {
        $politica = $this->caso->politica(7);

        self::assertFalse($politica->calibrada);
        self::assertSame(2.0, $politica->atribucion->deltaMinimoLitros);
    }

    public function testGuardarDejaLaEmpresaCalibrada(): void
    {
        $this->caso->guardar(7, ['deltaMinimoLitros' => 8.0]);

        $politica = $this->caso->politica(7);

        self::assertTrue($politica->calibrada);
        self::assertSame(8.0, $politica->atribucion->deltaMinimoLitros);
    }

    public function testLosUmbralesDeUnaEmpresaNoSeAplicanAOtra(): void
    {
        // La invariante de seguridad del módulo: si el filtro por empresa
        // fallara, la empresa 9 vería un umbral que no configuró, aplicado a
        // números que se presentan como medidos.
        $this->caso->guardar(7, ['deltaMinimoLitros' => 8.0, 'umbralCargaLitros' => 60.0]);

        $otra = $this->caso->politica(9);

        self::assertFalse($otra->calibrada, 'La empresa 9 nunca configuró nada.');
        self::assertSame(2.0, $otra->atribucion->deltaMinimoLitros);
        self::assertSame(20.0, $otra->atribucion->umbralCargaLitros);
    }

    public function testGuardarDosVecesActualizaYNoDuplicaLaFila(): void
    {
        $this->caso->guardar(7, ['deltaMinimoLitros' => 8.0]);
        $this->caso->guardar(7, ['deltaMinimoLitros' => 15.0]);

        self::assertSame(
            1,
            $this->mysql->table(self::TABLA)->where('empresa_id', 7)->countAllResults(),
            'Una sola fila por empresa.',
        );
        self::assertSame(15.0, $this->caso->politica(7)->atribucion->deltaMinimoLitros);
    }

    public function testLosNulosSeConservanComoNulos(): void
    {
        $this->caso->guardar(7, ['deltaMinimoLitros' => 8.0]);

        $fila = $this->mysql->table(self::TABLA)->where('empresa_id', 7)->get()->getRowArray();

        self::assertNull($fila['umbral_carga_litros'], 'Null es sin configurar, no cero.');
        self::assertSame(8.0, (float) $fila['delta_minimo_litros']);
    }

    public function testLosNulosVuelvenComoNulosYNoComoCero(): void
    {
        // Si el store devolviera 0 en vez de null, el cálculo contaría cada
        // fluctuación del sensor como consumo de toda la empresa.
        $this->caso->guardar(7, ['deltaMinimoLitros' => 8.0]);

        $config = (new CodeIgniterConsumoConfigStore($this->mysql))->obtener(7);

        self::assertInstanceOf(ConsumoConfigEmpresa::class, $config);
        self::assertNull($config->valores()['umbralCargaLitros']);
        self::assertSame(8.0, $config->valores()['deltaMinimoLitros']);
    }
}