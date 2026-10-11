<?php

declare(strict_types=1);

use App\Database\Migrations\CreateTelemetryBuckets;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class TelemetryBucketsForgeSpy extends Forge
{
    /** @var array<string,array<string,mixed>> */
    public array $declarado = [];
    private array $campos = [];
    private array $primarias = [];
    private array $unicas = [];
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
            'attributes' => $attributes,
        ];
        $this->campos = $this->primarias = $this->unicas = $this->indices = [];
        return $this;
    }

    public function dropTable(string $tableName, bool $ifExists = false, bool $cascade = false)
    {
        unset($this->declarado[$tableName]);
        return $this;
    }
}

final class TelemetryBucketsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    public function testCreaTablasAditivasConExtremosYCursorPorUnidad(): void
    {
        $tables = $this->declaredTables();

        self::assertArrayHasKey('telematia_buckets', $tables);
        self::assertArrayHasKey('telematia_cursor', $tables);
        foreach ([
            'empresa_id', 'integracion_id', 'equipo_id', 'unidad_externa', 'proveedor',
            'bucket_inicio_utc', 'bucket_fin_utc', 'primera_observada_en', 'ultima_observada_en',
            'combustible_inicio_litros', 'combustible_fin_litros', 'kilometraje_inicio', 'kilometraje_fin',
            'horas_decimales_inicio', 'horas_decimales_fin', 'motor_inicio', 'motor_fin',
            'ralenti_inicio', 'ralenti_fin', 'segundos_ralenti', 'latitud_inicio', 'longitud_inicio',
            'velocidad_inicio_kmh', 'latitud_fin', 'longitud_fin', 'velocidad_fin_kmh',
        ] as $column) {
            self::assertArrayHasKey($column, $tables['telematia_buckets']['campos'], "Falta {$column}.");
        }
        self::assertArrayHasKey('cursor_hasta_utc', $tables['telematia_cursor']['campos']);
    }

    public function testDeclaraClavesDeIdempotenciaYConsultasConTenant(): void
    {
        $tables = $this->declaredTables();

        self::assertSame(
            ['integracion_id', 'unidad_externa', 'bucket_inicio_utc'],
            $tables['telematia_buckets']['unicas'][0]['columnas'],
        );
        self::assertSame(
            ['integracion_id', 'unidad_externa'],
            $tables['telematia_cursor']['unicas'][0]['columnas'],
        );
        $indices = array_column($tables['telematia_buckets']['indices'], 'columnas');
        self::assertContains(['empresa_id', 'equipo_id', 'bucket_inicio_utc'], $indices);
        self::assertContains(['empresa_id', 'bucket_inicio_utc'], $indices);
    }

    public function testDownEliminaSoloLasTablasNuevas(): void
    {
        $forge = new TelemetryBucketsForgeSpy($this->db);
        $forge->addField(['id' => ['type' => 'INT']])->createTable('telematia_muestras');
        $migration = $this->migration($forge);
        $migration->up();

        $migration->down();

        self::assertArrayHasKey('telematia_muestras', $forge->declarado);
        self::assertArrayNotHasKey('telematia_buckets', $forge->declarado);
        self::assertArrayNotHasKey('telematia_cursor', $forge->declarado);
    }

    /** @return array<string,array<string,mixed>> */
    private function declaredTables(): array
    {
        $forge = new TelemetryBucketsForgeSpy($this->db);
        $this->migration($forge)->up();

        return $forge->declarado;
    }

    private function migration(Forge $forge): CreateTelemetryBuckets
    {
        if (! class_exists(CreateTelemetryBuckets::class, false)) {
            require_once ROOTPATH . 'app/Database/Migrations/2026-10-10-170000_CreateTelemetryBuckets.php';
        }

        return new CreateTelemetryBuckets($forge);
    }
}
