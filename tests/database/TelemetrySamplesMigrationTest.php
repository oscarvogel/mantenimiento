<?php

declare(strict_types=1);

use App\Database\Migrations\CreateTelemetrySamples;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Forge que registra lo que la migración pide, sin tocar la base.
 *
 * Existe porque el Forge de SQLite3 no traduce `AUTO_INCREMENT` ni `unsigned`,
 * así que la migración real no se puede ejecutar contra el SQLite en memoria de
 * las pruebas. Replicar el esquema a mano, como hacen otras pruebas del
 * proyecto, tiene el problema inverso: el esquema replicado puede estar bien y
 * la migración estar mal, y la prueba seguir en verde.
 *
 * Con esto se verifica lo declarado —nombres de columnas, clave única,
 * índices— leyendo la migración real, y abajo se materializa **ese mismo**
 * contrato en SQLite para comprobar que se comporta como tiene que comportarse.
 */
final class ForgeEspia extends Forge
{
    /** @var array<string, array<string, mixed>> */
    public array $declarado = [];

    /** @var array<int, array<string, mixed>> */
    private array $campos = [];

    /** @var array<int, array<string, mixed>> */
    private array $primarias = [];

    /** @var array<int, array<string, mixed>> */
    private array $unicas = [];

    /** @var array<int, array<string, mixed>> */
    private array $indices = [];

    public function __construct(BaseConnection $db)
    {
        parent::__construct($db);
    }

    public function addField($fields)
    {
        $this->campos = array_merge($this->campos, $fields);

        return $this;
    }

    public function addPrimaryKey($key, string $keyName = '')
    {
        $this->primarias[] = ['columnas' => (array) $key, 'nombre' => $keyName];

        return $this;
    }

    public function addUniqueKey($key, string $keyName = '')
    {
        $this->unicas[] = ['columnas' => (array) $key, 'nombre' => $keyName];

        return $this;
    }

    public function addKey($key, bool $primary = false, bool $unique = false, string $keyName = '')
    {
        $this->indices[] = ['columnas' => (array) $key, 'nombre' => $keyName];

        return $this;
    }

    public function createTable(string $table, bool $ifNotExists = false, array $attributes = [])
    {
        $this->declarado[$table] = [
            'campos' => $this->campos,
            'primarias' => $this->primarias,
            'unicas' => $this->unicas,
            'indices' => $this->indices,
        ];

        $this->campos = [];
        $this->primarias = [];
        $this->unicas = [];
        $this->indices = [];

        return $this;
    }

    public function dropTable(string $tableName, bool $ifExists = false, bool $cascade = false)
    {
        unset($this->declarado[$tableName]);

        return $this;
    }
}

/**
 * Contrato de la tabla de muestras de telemetría.
 *
 * Esta tabla es la precondición de todo lo demás que se planeó: sin histórico
 * append-only no hay series de las que calcular el combustible por trayecto, el
 * porcentaje de ralentí ni los excesos de velocidad. Los tres son funciones de
 * una **serie**, y una serie necesita más de un punto.
 *
 * La comprobación va en dos capas porque SQLite no ejecuta estas migraciones:
 *
 * 1. Se verifica lo que la migración **declara**. Si mañana alguien saca la
 *    clave única, la primera capa falla aunque el esquema replicado siga bien.
 * 2. Se materializa ese mismo contrato declarado en SQLite y se comprueba que
 *    una captura repetida se rechaza y una posterior se guarda.
 *
 * @internal
 */
final class TelemetrySamplesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TABLA = 'telematia_muestras';
    private const ARCHIVO = 'app/Database/Migrations/2026-10-10-160000_CreateTelemetrySamples.php';

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de migración requiere la extensión sqlite3.');
        }

        parent::setUp();

        $forge = new Forge($this->db);
        if ($this->db->tableExists(self::TABLA)) {
            $forge->dropTable(self::TABLA, true);
        }
    }

    public function testCreaLaTablaDeMuestras(): void
    {
        self::assertArrayHasKey(
            self::TABLA,
            $this->declarar()['declarado'],
            'La migración tiene que crear la tabla de muestras: es la base del histórico.',
        );
    }

    public function testDeclaraLasColumnasCanonicasDeLaInstantanea(): void
    {
        $campos = $this->columnas();

        // El instante del proveedor y el nuestro van separados. Sin
        // `registrada_en` no se puede distinguir una muestra de cuándo la
        // consultamos de una captura atrasada del proveedor.
        foreach ([
            'empresa_id',
            'integracion_id',
            'equipo_id',
            'unidad_externa',
            'proveedor',
            'observada_en',
            'registrada_en',
            'kilometraje',
            'horas_decimales',
            'motor_encendido',
            'ralenti_activo',
            'voltaje',
            'combustible_litros',
            'latitud',
            'longitud',
            'velocidad_kmh',
            'rumbo',
            'altitud_m',
            'satelites',
        ] as $columna) {
            self::assertArrayHasKey($columna, $campos, "Falta la columna {$columna}.");
        }

        // El histórico completo de sensores y las lecturas imposibles que el
        // proveedor reportó se conservan: sin ellos, un sensor que dejó de
        // entregar valor no se puede estudiar después.
        self::assertArrayHasKey('sensores_json', $campos);
        self::assertArrayHasKey('anomalias', $campos);
    }

    public function testDeclaraLaUnicidadPorCapturaYNoPorEquipo(): void
    {
        $unicas = $this->declarar()['declarado'][self::TABLA]['unicas'];

        self::assertCount(1, $unicas, 'Sólo puede haber una clave única: la de la captura.');
        self::assertSame(
            ['integracion_id', 'unidad_externa', 'observada_en'],
            $unicas[0]['columnas'],
            'La unicidad va por integración, unidad externa y hora observada. Usar `registrada_en` '
            . 'haría que cada reintento del cron creara muestras duplicadas.',
        );
    }

    public function testDeclaraElIndiceDeLecturaPorEquipoYElDeRetencion(): void
    {
        $indices = array_column($this->declarar()['declarado'][self::TABLA]['indices'], 'columnas');

        self::assertContains(
            ['empresa_id', 'equipo_id', 'observada_en'],
            $indices,
            'Es la consulta que hacen el consumo por viaje, el ralentí y la velocidad.',
        );
        self::assertContains(
            ['empresa_id', 'observada_en'],
            $indices,
            'La purga de retención borra por fecha, no por equipo.',
        );
    }

    public function testUnaCapturaRepetidaNoSeGuardaDosVeces(): void
    {
        $this->crear();
        $this->insertarMuestra();

        try {
            $this->insertarMuestra();
            self::fail('La misma captura no puede guardarse dos veces: el cron se reintenta.');
        } catch (DatabaseException) {
            // Comportamiento esperado.
        }

        self::assertSame(
            1,
            $this->db->table(self::TABLA)->countAllResults(),
            'Debe quedar una sola fila para la misma integración, unidad y hora observada.',
        );
    }

    public function testLaCadenciaDeQuinceMinutosSeApila(): void
    {
        $this->crear();
        $this->insertarMuestra('2026-10-10 06:15:00');
        $this->insertarMuestra('2026-10-10 06:30:00');

        self::assertSame(
            2,
            $this->db->table(self::TABLA)->countAllResults(),
            'La unicidad es por captura, no por equipo: dos horarios del mismo camión se guardan.',
        );
    }

    public function testDosUnidadesDistintasGuardanLaMismaHora(): void
    {
        $this->crear();
        $this->insertarMuestra('2026-10-10 06:15:00', 101);
        $this->insertarMuestra('2026-10-10 06:15:00', 202);

        self::assertSame(
            2,
            $this->db->table(self::TABLA)->countAllResults(),
            'Dos camiones se reportan a la misma hora y no se pisan entre sí.',
        );
    }

    public function testUnaMuestraSinMetricasGuardaNulosYNoInventaValores(): void
    {
        $this->crear();

        $this->db->table(self::TABLA)->insert([
            'empresa_id' => 1,
            'integracion_id' => 10,
            'equipo_id' => 5,
            'unidad_externa' => 'WIALON-101',
            'proveedor' => 'WIALON',
            'observada_en' => '2026-10-10 06:15:00',
            'registrada_en' => '2026-10-10 06:15:04',
        ]);

        $fila = $this->db->table(self::TABLA)->get()->getRowArray();

        // Copiar el valor anterior como si fuera una lectura nueva es la forma
        // más común de mostrarle a un operador un tanque lleno que nadie midió.
        self::assertNull($fila['combustible_litros']);
        self::assertNull($fila['kilometraje']);
        self::assertNull($fila['horas_decimales']);
        self::assertNull($fila['motor_encendido']);
    }

    public function testElDownEliminaLaTabla(): void
    {
        $espia = new ForgeEspia($this->db);
        $this->construir($espia)->down();

        self::assertArrayNotHasKey(
            self::TABLA,
            $espia->declarado,
            'Sin un down() que funcione la migración no es reproducible ni reversible.',
        );
    }

    /** Ejecuta la migración real contra un Forge que sólo registra. */
    private function espia(): ForgeEspia
    {
        $espia = new ForgeEspia($this->db);
        $this->construir($espia)->up();

        return $espia;
    }

    /** @return array<string, mixed> */
    private function declarar(): array
    {
        return ['declarado' => $this->espia()->declarado];
    }

    /** @return array<string, mixed> */
    private function columnas(): array
    {
        return $this->declarar()['declarado'][self::TABLA]['campos'];
    }

    /**
     * Materializa en SQLite el contrato que la migración declaró.
     *
     * Se construye desde lo declarado y no desde una copia a mano: si la
     * migración cambia sus columnas o sus claves, esta tabla cambia con ella.
     */
    private function crear(): void
    {
        $declarado = $this->espia()->declarado[self::TABLA];

        $forge = new Forge($this->db);
        $forge->addField($this->camposCompatibles($declarado['campos'], $declarado['primarias']));

        foreach ($declarado['primarias'] as $primaria) {
            $forge->addPrimaryKey($primaria['columnas'], $primaria['nombre']);
        }
        foreach ($declarado['unicas'] as $unica) {
            $forge->addUniqueKey($unica['columnas'], $unica['nombre']);
        }
        foreach ($declarado['indices'] as $indice) {
            $forge->addKey($indice['columnas'], false, false, $indice['nombre']);
        }

        $forge->createTable(self::TABLA, true);
    }

    /**
     * El Forge de SQLite3 no traduce `AUTO_INCREMENT` ni `unsigned`. Lo que se
     * prueba acá es el comportamiento de las claves, no esos tipos.
     *
     * La clave primaria queda nullable porque en MySQL la completa el
     * AUTO_INCREMENT y en SQLite el Forge no la declara como alias del rowid.
     *
     * @param  array<string, array<string, mixed>> $campos
     * @param  array<int, array<string, mixed>>    $primarias
     * @return array<string, array<string, mixed>>
     */
    private function camposCompatibles(array $campos, array $primarias): array
    {
        $autoincrementales = [];

        foreach ($primarias as $primaria) {
            foreach ($primaria['columnas'] as $columna) {
                $autoincrementales[] = $columna;
            }
        }

        $compatibles = [];

        foreach ($campos as $nombre => $definicion) {
            unset($definicion['unsigned']);
            $definicion['auto_increment'] = false;
            $definicion['null'] = in_array($nombre, $autoincrementales, true) || ($definicion['null'] ?? false);
            $compatibles[$nombre] = $definicion;
        }

        return $compatibles;
    }

    private function construir(Forge $forge): CreateTelemetrySamples
    {
        // Las migraciones de CodeIgniter llevan el timestamp en el nombre del
        // archivo, así que PSR-4 no las resuelve: el framework las carga
        // escaneando el directorio. Por eso la prueba las requiere.
        if (! class_exists(CreateTelemetrySamples::class, false)) {
            require_once ROOTPATH . self::ARCHIVO;
        }

        return new CreateTelemetrySamples($forge);
    }

    private function insertarMuestra(
        string $observadaEn = '2026-10-10 06:15:00',
        int $unidadExterna = 101,
    ): void {
        $this->db->table(self::TABLA)->insert([
            'empresa_id' => 1,
            'integracion_id' => 10,
            'equipo_id' => 5,
            'unidad_externa' => 'WIALON-' . $unidadExterna,
            'proveedor' => 'WIALON',
            'observada_en' => $observadaEn,
            'registrada_en' => $observadaEn,
        ]);
    }
}