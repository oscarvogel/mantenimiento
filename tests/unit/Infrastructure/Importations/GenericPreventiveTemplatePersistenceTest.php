<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Importations;

use App\Database\Migrations\AllowGenericPreventiveTemplates;
use App\Infrastructure\Importations\CodeIgniterPreventiveLibraryDestinationGateway;
use App\Infrastructure\Importations\CodeIgniterPreventiveLibraryReadModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class GenericPreventiveTemplatePersistenceTest extends CIUnitTestCase
{
    private BaseConnection $database;
    private Forge $forge;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de migracion requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->forge = Database::forge('tests');
        $this->dropTables();
        $this->createSchemaWithRequiredEquipmentType();
        if (! class_exists(AllowGenericPreventiveTemplates::class)) {
            require_once APPPATH . 'Database/Migrations/2026-08-12-120110_AllowGenericPreventiveTemplates.php';
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function testCorrectiveMigrationAllowsAndAdapterPersistsGenericTemplate(): void
    {
        (new AllowGenericPreventiveTemplates($this->forge))->up();

        $id = (new CodeIgniterPreventiveLibraryDestinationGateway($this->database))->apply(7, 12, [
            'entity' => 'PLANTILLA', 'scope' => 'EMPRESA', 'company_id' => 7,
            'template_code' => 'GENERICA', 'name' => 'Plantilla generica',
            'equipment_type_id' => null, 'brand' => null, 'model' => null,
            'description' => null, 'active' => 1,
        ]);

        self::assertGreaterThan(0, $id);
        $row = $this->database->table('plantillas_mantenimiento')->where('id', $id)->get()->getRowArray();
        self::assertNull($row['tipo_equipo_id']);
    }

    public function testReadModelLabelsGenericTemplateAndItsItems(): void
    {
        (new AllowGenericPreventiveTemplates($this->forge))->up();
        $this->database->table('plantillas_mantenimiento')->insert([
            'empresa_id' => 7, 'codigo' => 'GENERICA', 'nombre' => 'General', 'ambito' => 'EMPRESA',
            'tipo_equipo_id' => null, 'activo' => 1,
        ]);
        $templateId = (int) $this->database->insertID();
        $this->database->table('tipos_servicio')->insert([
            'id' => 3, 'codigo' => 'ACEITE', 'nombre' => 'Aceite', 'categoria' => null, 'activo' => 1,
        ]);
        $this->database->table('plantilla_mantenimiento_items')->insert([
            'plantilla_id' => $templateId, 'tipo_servicio_id' => 3, 'intervalo_km' => 20_000,
            'intervalo_horas' => null, 'intervalo_dias' => null, 'anticipacion_km' => 2_000,
            'anticipacion_horas' => null, 'anticipacion_dias' => null, 'prioridad' => 'MEDIA',
            'activo' => 1, 'observaciones' => null,
        ]);

        $overview = (new CodeIgniterPreventiveLibraryReadModel($this->database))->overview(7);

        self::assertSame('Genérica', $overview['templates'][0]['equipmentType']);
        self::assertSame('Genérica', $overview['items'][0]['equipmentType']);
    }

    private function createSchemaWithRequiredEquipmentType(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'empresa_id' => ['type' => 'INTEGER'], 'codigo' => ['type' => 'VARCHAR', 'constraint' => 80],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 150], 'ambito' => ['type' => 'VARCHAR', 'constraint' => 20],
            'tipo_equipo_id' => ['type' => 'INTEGER', 'null' => false], 'marca' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'modelo' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true], 'descripcion' => ['type' => 'TEXT', 'null' => true],
            'activo' => ['type' => 'INTEGER', 'default' => 1], 'created_by' => ['type' => 'INTEGER', 'null' => true],
            'updated_by' => ['type' => 'INTEGER', 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('plantillas_mantenimiento');

        $this->simpleTable('tipos_equipo', [
            'id' => ['type' => 'INTEGER'], 'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
        ]);
        $this->simpleTable('tipos_servicio', [
            'id' => ['type' => 'INTEGER'], 'codigo' => ['type' => 'VARCHAR', 'constraint' => 80],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 150], 'categoria' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'activo' => ['type' => 'INTEGER', 'default' => 1],
        ]);
        $this->simpleTable('plantilla_mantenimiento_items', [
            'id' => ['type' => 'INTEGER', 'auto_increment' => true], 'plantilla_id' => ['type' => 'INTEGER'],
            'tipo_servicio_id' => ['type' => 'INTEGER'], 'intervalo_km' => ['type' => 'INTEGER', 'null' => true],
            'intervalo_horas' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true], 'intervalo_dias' => ['type' => 'INTEGER', 'null' => true],
            'anticipacion_km' => ['type' => 'INTEGER', 'null' => true], 'anticipacion_horas' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true],
            'anticipacion_dias' => ['type' => 'INTEGER', 'null' => true], 'prioridad' => ['type' => 'VARCHAR', 'constraint' => 20],
            'activo' => ['type' => 'INTEGER', 'default' => 1], 'observaciones' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->simpleTable('tipo_servicio_tareas', [
            'tipo_servicio_id' => ['type' => 'INTEGER'], 'tarea_id' => ['type' => 'INTEGER'],
        ]);
        $this->simpleTable('tipo_servicio_materiales', [
            'id' => ['type' => 'INTEGER', 'auto_increment' => true], 'tipo_servicio_id' => ['type' => 'INTEGER'],
            'activo' => ['type' => 'INTEGER', 'default' => 1],
        ]);
    }

    /** @param array<string,array<string,mixed>> $fields */
    private function simpleTable(string $name, array $fields): void
    {
        $this->forge->addField($fields);
        if (isset($fields['id'])) {
            $this->forge->addKey('id', true);
        }
        $this->forge->createTable($name);
    }

    private function dropTables(): void
    {
        foreach (['tipo_servicio_materiales', 'tipo_servicio_tareas', 'plantilla_mantenimiento_items', 'tipos_servicio', 'tipos_equipo', 'plantillas_mantenimiento'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
