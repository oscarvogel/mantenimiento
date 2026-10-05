<?php

declare(strict_types=1);

use App\Application\WorkOrders\DocumentImport\Port\ImportedOrderPartWriter;
use App\Infrastructure\WorkOrders\DocumentImport\CodeIgniterImportedOrderPartWriter;
use App\Infrastructure\WorkOrders\DocumentImport\CodeIgniterWorkOrderDocumentCreationGateway;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Comportamiento de los repuestos detectados al crear la OT desde un documento.
 *
 * Corre contra SQLite en memoria con el esquema real de `orden_repuestos`
 * (migración 2026-09-11-120200) y de las tablas que toca la creación de la OT.
 * Las migraciones de la app no corren en SQLite porque usan DDL de MySQL, así
 * que el esquema se replica acá con Forge, sin `auto_increment` ni `unsigned`
 * (que el Forge de SQLite3 no traduce).
 *
 * @internal
 */
final class DocumentImportedWorkOrderPartsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const COMPANY = 7;
    private const OTHER_COMPANY = 9;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de persistencia requiere la extensión sqlite3.');
        }

        parent::setUp();

        $this->buildSchema();
        $this->db->table('empresas')->insert(['id' => self::COMPANY]);
        $this->db->table('empresas')->insert(['id' => self::OTHER_COMPANY]);
    }

    public function testDetectedMaterialsBecomeStructuredPartRowsOfTheCreatedOrder(): void
    {
        $gateway = $this->gateway();
        $materials = [
            ['description' => 'Filtro de aceite', 'quantity' => 4, 'unit' => 'u', 'source_text' => 'FILTRO ACEITE 4 U'],
            ['description' => 'Correa de distribución', 'quantity' => '1,5', 'unit' => null, 'source_text' => 'Correa de distribución'],
        ];

        $orderId = $this->createCorrective($gateway, $materials);

        $rows = $this->partRows();
        self::assertCount(2, $rows, 'Cada repuesto detectado debe terminar como una fila de orden_repuestos.');
        self::assertSame([self::COMPANY, self::COMPANY], array_column($rows, 'empresa_id'));
        self::assertSame([$orderId, $orderId], array_column($rows, 'orden_id'));

        self::assertSame('Filtro de aceite', $rows[0]['descripcion']);
        self::assertEqualsWithDelta(4.0, (float) $rows[0]['cantidad'], 0.0005);
        self::assertSame('2026-09-01', $rows[0]['fecha_colocacion']);
        self::assertStringContainsString('Unidad declarada en el documento: u.', (string) $rows[0]['observaciones']);

        self::assertSame('Correa de distribución', $rows[1]['descripcion']);
        self::assertEqualsWithDelta(1.5, (float) $rows[1]['cantidad'], 0.0005);
    }

    public function testMaterialWithoutUsableQuantityIsStoredAsPendingReviewInsteadOfAnInventedNumber(): void
    {
        $this->createCorrective($this->gateway(), [
            ['description' => 'Aros deANGUIA', 'quantity' => null, 'unit' => null, 'source_text' => 'Aros deANGUIA'],
        ]);

        $rows = $this->partRows();
        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(0.0, (float) $rows[0]['cantidad'], 0.0005, 'No se debe inventar una cantidad.');
        self::assertStringContainsString('PENDIENTE DE REVISIÓN HUMANA', (string) $rows[0]['observaciones']);
    }

    public function testRetryingTheSameImportDoesNotDuplicatePartLines(): void
    {
        $gateway = $this->gateway();
        $materials = [
            ['description' => 'Filtro de aceite', 'quantity' => 4],
            ['description' => 'Filtro de aceite', 'quantity' => 4],
        ];
        $orderId = $this->createCorrective($gateway, $materials);
        self::assertCount(1, $this->partRows());

        // Reintento de la misma importación sobre la misma OT.
        $writer = new CodeIgniterImportedOrderPartWriter($this->db);
        self::assertSame(0, $writer->appendToWorkOrder(self::COMPANY, $orderId, '2026-09-01', $materials));
        self::assertSame(0, $writer->appendToWorkOrder(self::COMPANY, $orderId, '2026-09-01', $materials));

        self::assertCount(1, $this->partRows(), 'Reintentar no debe duplicar los ítems.');

        // Un ítem distinto sí se agrega.
        $writer->appendToWorkOrder(self::COMPANY, $orderId, '2026-09-01', [
            ['description' => 'Filtro de aire', 'quantity' => 4],
        ]);
        self::assertCount(2, $this->partRows());
    }

    public function testFailureWhileWritingPartsLeavesNoOrderWithPartialItems(): void
    {
        $gateway = $this->gateway(new class implements ImportedOrderPartWriter {
            public function appendToWorkOrder(int $companyId, int $workOrderId, string $serviceDate, array $detected): int
            {
                throw new DomainException('Falla la escritura de repuestos.');
            }
        });

        try {
            $this->createCorrective($gateway, [['description' => 'Filtro de aceite', 'quantity' => 4]]);
            self::fail('La creación de la OT debía fallar.');
        } catch (DomainException $exception) {
            self::assertSame('Falla la escritura de repuestos.', $exception->getMessage());
        }

        self::assertSame(0, $this->db->table('ordenes_trabajo')->countAllResults(), 'La OT no debe quedar creada.');
        self::assertSame(0, $this->db->table('orden_repuestos')->countAllResults());
        self::assertSame(0, $this->db->table('orden_estado_historial')->countAllResults());
    }

    public function testPartLinesCannotBeWrittenOnAnOrderOfAnotherCompany(): void
    {
        $this->db->table('ordenes_trabajo')->insert([
            'numero' => 'OT-EXTERNA',
            'empresa_id' => self::OTHER_COMPANY,
            'sucursal_id' => 1,
            'equipo_id' => 1,
            'origen' => 'CORRECTIVO',
            'estado' => 'FINALIZADA',
            'fecha_apertura' => '2026-09-01 00:00:00',
        ]);
        $foreignOrderId = (int) $this->db->insertID();

        $writer = new CodeIgniterImportedOrderPartWriter($this->db);

        try {
            $writer->appendToWorkOrder(self::COMPANY, $foreignOrderId, '2026-09-01', [
                ['description' => 'Filtro de aceite', 'quantity' => 4],
            ]);
            self::fail('No se debería permitir escribir en una orden de otra empresa.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no pertenece a la empresa', $exception->getMessage());
        }

        self::assertSame(0, $this->db->table('orden_repuestos')->countAllResults());
    }

    public function testTheShortSummaryStaysInObservacionesSoThePrintedOrderKeepsTheParts(): void
    {
        $this->createCorrective($this->gateway(), [
            ['description' => 'Filtro de aceite', 'quantity' => 4, 'unit' => 'u'],
            ['description' => 'Aros de/Create', 'quantity' => null],
        ]);

        $notes = (string) $this->db->table('ordenes_trabajo')->select('observaciones')->get()->getRowArray()['observaciones'];

        self::assertStringContainsString('Repuestos/consumibles detectados: Filtro de aceite x 4 u, Aros de/Create (cantidad a revisar)', $notes);
    }

    private function gateway(?ImportedOrderPartWriter $parts = null): CodeIgniterWorkOrderDocumentCreationGateway
    {
        return new CodeIgniterWorkOrderDocumentCreationGateway($this->db, $parts);
    }

    /** @param list<array<string,mixed>> $materials */
    private function createCorrective(
        CodeIgniterWorkOrderDocumentCreationGateway $gateway,
        array $materials,
    ): int {
        return $gateway->transaction(fn (): int => $gateway->createCompletedCorrective(
            companyId: self::COMPANY,
            branchId: 3,
            equipmentId: 11,
            actorUserId: 5,
            number: 'OT-2026-0001',
            serviceDate: '2026-09-01',
            priority: 'MEDIA',
            responsibleUserId: 5,
            kilometres: 120000,
            hours: null,
            supplier: 'Taller Sur',
            concept: 'Service',
            observations: null,
            documentCost: '813382.00',
            currency: 'ARS',
            works: [['description' => 'Cambio de filtro de aceite']],
            materials: $materials,
        ));
    }

    /** @return list<array<string,mixed>> */
    private function partRows(): array
    {
        return $this->db->table('orden_repuestos')
            ->select('empresa_id,orden_id,descripcion,cantidad,precio_unitario,fecha_colocacion,observaciones')
            ->orderBy('id')
            ->get()
            ->getResultArray();
    }

    private function buildSchema(): void
    {
        $forge = new Forge($this->db);

        // La conexión SQLite en memoria es única para toda la clase: se arranca
        // de cero en cada prueba para que los conteos sean deterministas.
        foreach (['orden_repuestos', 'orden_estado_historial', 'ordenes_trabajo', 'empresas'] as $table) {
            if ($this->db->tableExists($table)) {
                $forge->dropTable($table, true);
            }
        }

        $forge->addField(['id' => ['type' => 'INTEGER']]);
        $forge->addPrimaryKey('id');
        $forge->createTable('empresas', true);

        // 2026-08-08-110041_CreateOrdenesTrabajoTable, recortada a las columnas que
        // usa la creación de la correctiva y con el único único (empresa_id, id).
        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'numero' => ['type' => 'VARCHAR', 'constraint' => 20],
            'empresa_id' => ['type' => 'INT'],
            'sucursal_id' => ['type' => 'INT'],
            'equipo_id' => ['type' => 'INT'],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 30],
            'plan_id' => ['type' => 'INT', 'null' => true],
            'aviso_plan_id' => ['type' => 'INT', 'null' => true],
            'tipo_servicio_id' => ['type' => 'INT', 'null' => true],
            'prioridad' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIA'],
            'responsable_usuario_id' => ['type' => 'INT', 'null' => true],
            'fecha_apertura' => ['type' => 'DATETIME'],
            'fecha_inicio' => ['type' => 'DATETIME', 'null' => true],
            'fecha_finalizacion' => ['type' => 'DATETIME', 'null' => true],
            'km_ingreso' => ['type' => 'BIGINT', 'null' => true],
            'horas_ingreso' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true],
            'km_salida' => ['type' => 'BIGINT', 'null' => true],
            'horas_salida' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true],
            'diagnostico' => ['type' => 'TEXT', 'null' => true],
            'trabajo_realizado' => ['type' => 'TEXT', 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 30],
            'costo_mano_obra' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'costo_repuestos' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'otros_costos' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'costo_total' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'null' => true],
            'updated_by' => ['type' => 'INT', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->addUniqueKey(['empresa_id', 'id'], 'uq_ot_empresa_id');
        $forge->createTable('ordenes_trabajo', true);

        // 2026-08-08-110043_CreateOrdenEstadoHistorialTable
        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT'],
            'orden_id' => ['type' => 'INT'],
            'estado_anterior' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'estado_nuevo' => ['type' => 'VARCHAR', 'constraint' => 30],
            'fecha' => ['type' => 'DATETIME'],
            'usuario_id' => ['type' => 'INT'],
            'comentario' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('orden_estado_historial', true);

        // 2026-09-11-120200_CreateProvidersAndOrderParts (proveedores no interviene).
        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT'],
            'orden_id' => ['type' => 'INT'],
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'descripcion' => ['type' => 'VARCHAR', 'constraint' => 255],
            'marca' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'numero_serie_lote' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'cantidad' => ['type' => 'DECIMAL', 'constraint' => '12,3', 'default' => 1],
            'precio_unitario' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'proveedor_id' => ['type' => 'INT', 'null' => true],
            'comprobante' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'fecha_colocacion' => ['type' => 'DATE', 'null' => true],
            'garantia_fecha' => ['type' => 'DATE', 'null' => true],
            'garantia_km' => ['type' => 'BIGINT', 'null' => true],
            'garantia_horas' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true],
            'repuesto_retirado' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->addKey(['empresa_id', 'orden_id'], false, false, 'idx_orden_repuestos_scope_orden');
        $forge->createTable('orden_repuestos', true);
    }
}
