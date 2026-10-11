<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Application\Telematic\Port\MuestraStore;
use App\Database\Migrations\CreateTelemetrySamples;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;

/**
 * Persistencia de muestras contra MySQL de verdad.
 *
 * **Estas pruebas no corren en SQLite, y no es un descuido.** La deduplicación
 * de MySQL es `INSERT IGNORE`, que ignora el duplicado en silencio; en SQLite el
 * mismo `INSERT` revienta con excepción de clave única. Un almacén probado con
 * SQLite y portable a MySQL sin cambiar una línea funciona en las pruebas y
 * falla en producción, o al revés: si se programó para MySQL, en SQLite el
 * test falla siempre y el deducto de "es que SQLite no lo soporta" tapa el
 * defecto real.
 *
 * La deduplicación es semántica de motor, así que se prueba en el motor.
 *
 * Para correrlas hay que apuntar a una base MySQL descartable y crear la
 * tabla con la migración real:
 *
 * ```bash
 * MUESTRAS_TEST_DSN='mysql://usuario:clave@127.0.0.1:3306/scratch_muestras' \
 *   wsl php vendor/bin/phpunit tests/unit/Infrastructure/Telematic/MuestrasStoreMySqlTest.php
 * ```
 *
 * Sin esa variable la clase se saltea, así que la suite normal sigue pasando.
 *
 * @internal
 */
final class MuestrasStoreMySqlTest extends CIUnitTestCase
{
    private const TABLA = 'telematia_muestras';

    private BaseConnection $mysql;
    private MuestraStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('MUESTRAS_TEST_DSN');

        if (! is_string($dsn) || trim($dsn) === '') {
            $this->markTestSkipped('Requiere MUESTRAS_TEST_DSN apuntando a una base MySQL descartable.');
        }

        // El DSN se descompone a mano porque `Database::connect()` deriva el
        // driver del esquema y llega a `mysql` en minúscula, que no es el
        // nombre de la clase (`MySQLi`).
        $partes = parse_url($dsn);
        if ($partes === false || ($partes['host'] ?? '') === '') {
            $this->markTestSkipped('MUESTRAS_TEST_DSN no es un DSN válido.');
        }

        $this->mysql = Database::connect([
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

        // La migración real, no un esquema copiado: lo que se prueba es el DDL
        // que va a correr en el servidor. Se requiere a mano porque el nombre
        // del archivo lleva timestamp y PSR-4 no la resuelve.
        if (! class_exists(CreateTelemetrySamples::class, false)) {
            require_once ROOTPATH . 'app/Database/Migrations/2026-10-10-160000_CreateTelemetrySamples.php';
        }

        (new CreateTelemetrySamples(new Forge($this->mysql)))->up();

        $this->store = new \App\Infrastructure\Telematic\CodeIgniterMuestraStore($this->mysql);
    }

    protected function tearDown(): void
    {
        if (isset($this->mysql)) {
            $this->mysql->query('DROP TABLE IF EXISTS ' . self::TABLA);
            $this->mysql->close();
        }

        parent::tearDown();
    }

    public function testGuardarUnaCapturaNuevaDevuelveUno(): void
    {
        self::assertSame(1, $this->store->guardar($this->muestra('2026-10-10 06:15:00')));
    }

    public function testGuardarLaMismaCapturaDosVecesDevuelveCeroYNoDuplica(): void
    {
        $this->store->guardar($this->muestra('2026-10-10 06:15:00'));

        // Un insert normal revienta acá con ERROR 1062. INSERT IGNORE no.
        self::assertSame(
            0,
            $this->store->guardar($this->muestra('2026-10-10 06:15:00')),
            'La captura repetida se ignora en silencio y hay que informarlo con el conteo.',
        );

        self::assertSame(1, $this->conteo());
    }

    public function testLaClaveUsaLaHoraDelProveedorYNoLaDelRegistro(): void
    {
        $primera = $this->muestra('2026-10-10 06:15:00', registradaEn: '2026-10-10 06:15:04');
        $segunda = $this->muestra('2026-10-10 06:15:00', registradaEn: '2026-10-10 06:45:00');

        self::assertSame(1, $this->store->guardar($primera));
        self::assertSame(0, $this->store->guardar($segunda), 'Cambiar `registrada_en` no la convierte en captura nueva.');
        self::assertSame(1, $this->conteo());
    }

    public function testLaCadenciaDeQuinceMinutosSeApila(): void
    {
        self::assertSame(1, $this->store->guardar($this->muestra('2026-10-10 06:15:00')));
        self::assertSame(1, $this->store->guardar($this->muestra('2026-10-10 06:30:00')));
        self::assertSame(1, $this->store->guardar($this->muestra('2026-10-10 06:45:00')));

        self::assertSame(3, $this->conteo());
    }

    public function testDosUnidadesDistintasNoSePisanALaMismaHora(): void
    {
        self::assertSame(1, $this->store->guardar($this->muestra('2026-10-10 06:15:00', equipoId: 42)));
        self::assertSame(1, $this->store->guardar($this->muestra('2026-10-10 06:15:00', equipoId: 43)));

        self::assertSame(2, $this->conteo());
    }

    public function testLasMetricasAusentesQuedanNulasYNoCopianElValorAnterior(): void
    {
        $this->store->guardar($this->muestra('2026-10-10 06:15:00'));
        $this->store->guardar($this->muestra('2026-10-10 06:30:00', litros: null, km: null, voltaje: null));

        $filas = $this->filas();
        $segunda = $filas[1];

        // Este es el motivo de que la tabla sea append-only y de que la
        // lectura se escriba siempre completa: si el proveedor no trae el
        // sensor, el hueco se ve. Si se completara con el valor anterior, la
        // serie mostraría un tanque lleno que nadie midió y el consumo por
        // viaje saldría con un delta de cero sin que nadie lo note.
        self::assertNull($segunda['combustible_litros']);
        self::assertNull($segunda['kilometraje']);
        self::assertNull($segunda['voltaje']);

        self::assertEqualsWithDelta(660.48, (float) $filas[0]['combustible_litros'], 0.0005);
    }

    public function testLosSensoresYLasAnomaliasSeConservanEnJson(): void
    {
        $this->store->guardar($this->muestra('2026-10-10 06:15:00', litros: -348.201, sensor: true));

        $fila = $this->filas()[0];

        $sensores = json_decode((string) $fila['sensores_json'], true);
        self::assertIsArray($sensores);
        self::assertSame('Presión de neumático', $sensores[0]['etiqueta']);
        self::assertSame('kPa', $sensores[0]['unidad']);

        $anomalias = json_decode((string) $fila['anomalias'], true);
        self::assertIsArray($anomalias);
        self::assertSame('COMBUSTIBLE', $anomalias[0]['sensor']);
        self::assertSame('combustible:-348.2', $anomalias[0]['firma']);
    }

    public function testLaPosicionYLosSensoresFaltantesQuedanNulos(): void
    {
        $this->store->guardar($this->muestra('2026-10-10 06:15:00', sensor: false));

        $fila = $this->filas()[0];

        self::assertNull($fila['sensores_json']);
        self::assertNull($fila['anomalias']);
        self::assertEqualsWithDelta(-33.0944, (float) $fila['latitud'], 0.0000001);
        self::assertNotNull($fila['observada_en']);
    }

    private function conteo(): int
    {
        return (int) $this->mysql->table(self::TABLA)->countAllResults();
    }

    /** @return list<array<string,mixed>> */
    private function filas(): array
    {
        return $this->mysql->table(self::TABLA)->orderBy('id')->get()->getResultArray();
    }

    private function muestra(
        string $observada,
        string $registradaEn = '2026-10-10 06:20:00',
        int $equipoId = 42,
        ?float $litros = 660.48,
        ?int $km = 487500,
        ?float $voltaje = 24.51,
        bool $sensor = false,
    ): MuestraEquipo {
        $instante = new DateTimeImmutable($observada);

        $posicion = new Posicion(-33.0944, -68.88, 42.0, 216, 955.4, 25, $instante);

        $instantanea = new InstantaneaEquipo(
            $instante,
            $posicion,
            $km,
            40625,
            false,
            false,
            $voltaje,
            $litros,
            $sensor ? [new \App\Domain\Telematic\MedidaAdicional('Presión de neumático', 98.0, 'kPa', 'custom')] : [],
        );

        return new MuestraEquipo(
            7,
            10,
            $equipoId,
            'WIALON-' . $equipoId,
            'WIALON',
            $instantanea,
            new DateTimeImmutable($registradaEn),
        );
    }
}