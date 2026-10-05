<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderDashboardReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Comportamiento observable de la búsqueda general del tablero de OT contra una base real
 * (SQLite en memoria), no contra el texto del SQL.
 *
 * Custodia tres cosas: que las tareas se alcancen por `EXISTS` y no por `JOIN` (una OT con
 * varias tareas que matchean tiene que volver una sola vez, y el `total` tiene que contar
 * OTs), que el término se escape antes de entrar al `EXISTS`, y que el scoping por empresa,
 * sucursal y responsable siga aplicando después de sumar los campos de texto.
 */
final class CodeIgniterWorkOrderDashboardReadModelTest extends TestCase
{
    private const EMPRESA = 5;
    private const OTRA_EMPRESA = 9;

    private BaseConnection $database;
    private CodeIgniterWorkOrderDashboardReadModel $readModel;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La búsqueda del tablero de OT requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->registerMySqlDateFunctions();
        $this->createSchema();
        $this->seed();

        $this->readModel = new CodeIgniterWorkOrderDashboardReadModel($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    // 1) Número, código y patente del equipo.

    public function testSearchesByOrderNumberEquipmentCodeAndPlate(): void
    {
        self::assertSame([101], $this->idsFor(['q' => 'OT-0001']));
        self::assertSame([101, 106, 102, 104], $this->idsFor(['q' => 'SCANIA-01']));
        self::assertSame([101, 106, 102, 104], $this->idsFor(['q' => 'AB123CD']));
    }

    public function testMatchesPartiallyInsteadOfOnlyFromTheStart(): void
    {
        // "corre" tiene que encontrar "correa": los usuarios buscan por fragmento.
        self::assertSame([102, 103], $this->idsFor(['q' => 'corre']));
        self::assertSame([103], $this->idsFor(['q' => 'stribución']));
    }

    // 2) Diagnóstico de la OT.

    public function testSearchesByDiagnosis(): void
    {
        self::assertSame([107], $this->idsFor(['q' => 'paragolpes']));
        self::assertSame([101, 103], $this->idsFor(['q' => 'aceite']));
    }

    // 3) Observaciones de la OT.

    public function testSearchesByOrderObservations(): void
    {
        self::assertSame([104], $this->idsFor(['q' => 'bujes']));
    }

    // 4) Tareas: descripción solicitada, trabajo realizado y observaciones.

    public function testSearchesByRequestedTaskDescription(): void
    {
        self::assertSame([106], $this->idsFor(['q' => 'pastillas']));
    }

    public function testSearchesByPerformedTaskWork(): void
    {
        self::assertSame([106], $this->idsFor(['q' => 'radiador']));
    }

    public function testSearchesByTaskObservations(): void
    {
        self::assertSame([106, 103], $this->idsFor(['q' => 'original']));
    }

    public function testSearchesByOrderLevelPerformedWork(): void
    {
        self::assertSame([104], $this->idsFor(['q' => 'refrigeración']));
    }

    // 5) Coincidencia parcial + una sola fila por OT aunque matcheen varias tareas.

    public function testDoesNotDuplicateOrdersWhenSeveralTasksMatch(): void
    {
        // 101 y 103 tienen dos tareas cada una que matchean "aceite" (904+908 y 902+903).
        $result = $this->search(['q' => 'aceite']);

        self::assertSame([101, 103], array_column($result['items'], 'id'));
        self::assertSame(2, $result['total'], 'El total debe contar OTs, no filas de tareas.');
        self::assertSame(count($result['items']), count(array_unique(array_column($result['items'], 'id'))));
    }

    public function testReturnsTheMatchedTasksOfTheFoundOrder(): void
    {
        $result = $this->search(['q' => 'aceite']);
        $order = $result['items'][1];

        self::assertSame(103, $order['id']);
        self::assertSame(
            ['Cambiar correa de distribución', 'Reponer aceite de motor', 'Cambiar filtro de aceite'],
            array_column($order['tareas'], 'descripcion_solicitada'),
        );
    }

    // 6) Combinación con los filtros existentes.

    public function testCombinesSearchWithStatusBranchAndOwnerFilters(): void
    {
        self::assertSame([103], $this->idsFor(['q' => 'corre', 'status' => 'FINALIZADA']));
        self::assertSame([102], $this->idsFor(['q' => 'corre', 'branch_id' => 7]));
        self::assertSame([103], $this->idsFor(['q' => 'corre', 'owner_id' => 33]));
        self::assertSame([103], $this->idsFor(['q' => 'aceite', 'branch_id' => 8]));
        self::assertSame([101], $this->idsFor(['q' => 'aceite', 'owner_id' => 22]));
    }

    public function testCombinesSearchWithDelayedFilter(): void
    {
        // 102 está EMITIDA y tiene más de 3 días; 103 está FINALIZADA, así que no es demorada.
        self::assertSame([102], $this->idsFor(['q' => 'corre', 'attention' => 'delayed']));
    }

    // 7) La paginación cuenta bien usando el mismo filtro.

    public function testPaginatesWithoutRepeatingOrLosingMatchedOrders(): void
    {
        $first = $this->search(['q' => 'corre'], 1, 1);
        self::assertSame([102], array_column($first['items'], 'id'));
        self::assertSame(2, $first['total']);
        self::assertSame(2, $first['totalPages']);
        self::assertSame(1, $first['page']);

        $second = $this->search(['q' => 'corre'], 2, 1);
        self::assertSame([103], array_column($second['items'], 'id'));
        self::assertSame(2, $second['total']);
        self::assertSame(2, $second['page']);

        self::assertSame(
            [],
            array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')),
        );
    }

    public function testClampsPageToTheLastAvailableOne(): void
    {
        $result = $this->search(['q' => 'corre'], 9, 25);

        self::assertSame(1, $result['page']);
        self::assertSame(1, $result['totalPages']);
        self::assertSame([102, 103], array_column($result['items'], 'id'));
    }

    public function testKeepsCountConsistentWithTheRowsReturnedWithoutSearch(): void
    {
        $result = $this->search([]);

        self::assertSame(7, $result['total'], 'Las 7 OT de la empresa 5; la 105 es de otra empresa.');
        self::assertCount(7, $result['items']);
    }

    // 8) Aislamiento por empresa y sucursales, también dentro del EXISTS de tareas.

    public function testDoesNotLeakOrdersOfAnotherCompanyThroughTaskMatches(): void
    {
        // La OT 105 (empresa 9) tiene el mismo diagnóstico y una tarea con "correa".
        self::assertSame([102, 103], $this->idsFor(['q' => 'correa']));
        self::assertSame([101, 103], $this->idsFor(['q' => 'aceite']));
        self::assertArrayNotHasKey(105, array_flip($this->idsFor(['q' => 'correa'])));
    }

    public function testScopesSearchToTheBranchesOfTheActor(): void
    {
        self::assertSame([102], $this->idsFor(['q' => 'correa'], ['ordenes.ver'], false, [7]));
        self::assertSame([103], $this->idsFor(['q' => 'correa'], ['ordenes.ver'], false, [8]));
        self::assertSame([], $this->idsFor(['q' => 'correa'], ['ordenes.ver'], false, []));
    }

    public function testScopesSearchToMyWorkWhenTheActorOnlyHasThatPermission(): void
    {
        // Solo 102: la 103 es responsabilidad del usuario 33.
        self::assertSame([102], $this->idsFor(['q' => 'correa'], ['ordenes.mi_trabajo'], true, [7, 8], 22));
    }

    // Dentro del EXISTS de tareas el término se escapa: no actúa como patrón SQL.

    public function testEscapesLikeWildcardsWhenMatchingTasks(): void
    {
        // Control positivo: la tarea "(a1b)" de la 108 existe y se encuentra por texto.
        self::assertSame([108], $this->idsFor(['q' => 'pistón']));

        // "_" es un comodín de un carácter en LIKE. Buscando "a_b" se compara contra el
        // literal, así que "(a1b)" tiene que quedar fuera. Si alguien saca el
        // escapeLikeString del EXISTS, esta aserción falla.
        self::assertNotContains(108, $this->idsFor(['q' => 'a_b']));
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return list<int>
     */
    private function idsFor(array $filters, array $permissions = ['ordenes.ver'], bool $allBranches = true, array $branches = [], int $userId = 22): array
    {
        $result = $this->search($filters, 1, 25, $permissions, $allBranches, $branches, $userId);

        return array_map(static fn (array $row): int => (int) $row['id'], $result['items']);
    }

    /** @return array<string,mixed> */
    private function search(
        array $filters,
        int $page = 1,
        int $perPage = 25,
        array $permissions = ['ordenes.ver'],
        bool $allBranches = true,
        array $branches = [],
        int $userId = 22,
    ): array {
        $actor = new ActorContext(
            $userId,
            self::EMPRESA,
            false,
            $allBranches,
            ['Responsable'],
            $permissions,
            $branches,
        );

        return $this->readModel->search($actor, $filters, $page, $perPage);
    }

    private function registerMySqlDateFunctions(): void
    {
        // El read model usa funciones de MySQL (CURDATE/DATEDIFF) en el SELECT y en el filtro
        // de demoradas. Se registran en el handle real: `initialize()` es la que fija connID
        // (el `connect()` del driver abre un handle nuevo en cada llamada y no se reutiliza).
        $this->database->initialize();
        $sqlite = $this->database->getConnection();
        if (! $sqlite instanceof \SQLite3) {
            self::markTestSkipped('La conexión de prueba no expone el handle SQLite3: ' . get_debug_type($sqlite) . '.');
        }

        $sqlite->createFunction('CURDATE', static fn (): string => date('Y-m-d'));
        $sqlite->createFunction('DATEDIFF', static function (?string $end, ?string $start): ?int {
            if ($end === null || $start === null || $end === '' || $start === '') {
                return null;
            }
            $endTs = strtotime($end . ' 00:00:00');
            $startTs = strtotime($start . ' 00:00:00');
            if ($endTs === false || $startTs === false) {
                return null;
            }

            return (int) round(($endTs - $startTs) / 86400);
        });
    }

    private function createSchema(): void
    {
        $this->database->query(
            'CREATE TABLE ordenes_trabajo (
                id INTEGER PRIMARY KEY,
                numero TEXT, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER,
                responsable_usuario_id INTEGER NULL, tipo_servicio_id INTEGER NULL,
                origen TEXT, prioridad TEXT, estado TEXT,
                fecha_apertura TEXT, fecha_inicio TEXT NULL, fecha_finalizacion TEXT NULL,
                km_ingreso TEXT NULL, horas_ingreso TEXT NULL,
                diagnostico TEXT NULL, trabajo_realizado TEXT NULL, observaciones TEXT NULL,
                costo_mano_obra TEXT, costo_repuestos TEXT, otros_costos TEXT, costo_total TEXT,
                moneda_original TEXT, importe_original TEXT, tipo_cambio_ars TEXT,
                fecha_tipo_cambio TEXT NULL, origen_tipo_cambio TEXT NULL, importe_ars TEXT
            )'
        );
        $this->database->query(
            'CREATE TABLE equipos (
                id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,
                codigo TEXT, patente TEXT, km_actual TEXT, horas_actuales TEXT
            )'
        );
        $this->database->query(
            'CREATE TABLE sucursales (
                id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT,
                estado INTEGER, deleted_at TEXT NULL
            )'
        );
        $this->database->query('CREATE TABLE tipos_servicio (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query(
            'CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER, deleted_at TEXT NULL
            )'
        );
        $this->database->query(
            'CREATE TABLE orden_tareas (
                id INTEGER PRIMARY KEY, empresa_id INTEGER, orden_id INTEGER, tarea_id INTEGER NULL,
                descripcion_solicitada TEXT, trabajo_realizado TEXT NULL, observaciones TEXT NULL,
                estado TEXT
            )'
        );
    }

    private function seed(): void
    {
        $this->database->table('sucursales')->insertBatch([
            ['id' => 7, 'empresa_id' => self::EMPRESA, 'codigo' => 'CEN', 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null],
            ['id' => 8, 'empresa_id' => self::EMPRESA, 'codigo' => 'SUR', 'nombre' => 'Sur', 'estado' => 1, 'deleted_at' => null],
            ['id' => 4, 'empresa_id' => self::OTRA_EMPRESA, 'codigo' => 'OTR', 'nombre' => 'Otra', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => self::EMPRESA, 'sucursal_id' => 7, 'codigo' => 'SCANIA-01', 'patente' => 'AB123CD', 'km_actual' => '1000', 'horas_actuales' => '500'],
            ['id' => 11, 'empresa_id' => self::EMPRESA, 'sucursal_id' => 8, 'codigo' => 'VOLVO-02', 'patente' => 'EF456GH', 'km_actual' => '2000', 'horas_actuales' => '600'],
            ['id' => 13, 'empresa_id' => self::EMPRESA, 'sucursal_id' => 7, 'codigo' => 'IVECO-03', 'patente' => 'ZZ999ZZ', 'km_actual' => '3000', 'horas_actuales' => '700'],
            ['id' => 12, 'empresa_id' => self::OTRA_EMPRESA, 'sucursal_id' => 4, 'codigo' => 'AJENO-01', 'patente' => 'OT999OT', 'km_actual' => '0', 'horas_actuales' => '0'],
        ]);
        $this->database->table('tipos_servicio')->insertBatch([
            ['id' => 1, 'nombre' => 'Service mayor'],
            ['id' => 2, 'nombre' => 'Correctivo menor'],
        ]);
        $this->database->table('usuarios')->insertBatch([
            ['id' => 22, 'empresa_id' => self::EMPRESA, 'nombre' => 'Responsable', 'activo' => 1, 'deleted_at' => null],
            ['id' => 33, 'empresa_id' => self::EMPRESA, 'nombre' => 'Técnico', 'activo' => 1, 'deleted_at' => null],
            ['id' => 44, 'empresa_id' => self::OTRA_EMPRESA, 'nombre' => 'Ajeno', 'activo' => 1, 'deleted_at' => null],
        ]);

        $this->database->table('ordenes_trabajo')->insertBatch([
            $this->order(101, 'OT-0001', self::EMPRESA, 7, 10, 22, 2, 'EN_PROCESO', 'CORRECTIVO', '2026-08-30 08:00:00', 'Fuga de aceite en el motor', null, null),
            $this->order(102, 'OT-0002', self::EMPRESA, 7, 10, 22, 1, 'EMITIDA', 'PREVENTIVO', '2026-08-30 09:00:00', 'Cambio de filtros', null, 'El cliente pidió revisar la correa del auxiliar'),
            $this->order(103, 'OT-0003', self::EMPRESA, 8, 11, 33, 1, 'FINALIZADA', 'PREVENTIVO', '2026-08-29 09:00:00', null, 'Se cambió la correa de distribución', 'Cambio de correa y ajuste de tensión'),
            $this->order(104, 'OT-0004', self::EMPRESA, 7, 10, 22, 2, 'FINALIZADA', 'CORRECTIVO', '2026-08-28 09:00:00', 'Nivel bajo de refrigerante', 'Se recargó el sistema de refrigeración', 'Revisar bujes del tren delantero'),
            $this->order(105, 'OT-0005', self::OTRA_EMPRESA, 4, 12, 44, 2, 'EN_PROCESO', 'CORRECTIVO', '2026-08-27 09:00:00', 'Fuga de aceite en el motor', null, null),
            $this->order(106, 'OT-0006', self::EMPRESA, 7, 10, 33, 2, 'EMITIDA', 'CORRECTIVO', '2026-08-27 10:00:00', 'Desgaste irregular en frenos', null, 'Cliente pidió repuesto'),
            $this->order(107, 'OT-0007', self::EMPRESA, 7, 13, 22, 2, 'FINALIZADA', 'CORRECTIVO', '2026-08-26 10:00:00', 'Golpe en paragolpes', null, 'Daño en chasis reportado por el seguro'),
            $this->order(108, 'OT-0008', self::EMPRESA, 8, 11, 22, 2, 'EN_PROCESO', 'CORRECTIVO', '2026-08-25 10:00:00', 'Ruido en suspensión', null, null),
        ]);

        $this->database->table('orden_tareas')->insertBatch([
            ['id' => 901, 'empresa_id' => self::EMPRESA, 'orden_id' => 103, 'tarea_id' => null, 'descripcion_solicitada' => 'Cambiar correa de distribución', 'trabajo_realizado' => 'Correa nueva colocada', 'observaciones' => 'Usar tensor original', 'estado' => 'FINALIZADA'],
            ['id' => 902, 'empresa_id' => self::EMPRESA, 'orden_id' => 103, 'tarea_id' => null, 'descripcion_solicitada' => 'Reponer aceite de motor', 'trabajo_realizado' => 'Nivel correcto', 'observaciones' => null, 'estado' => 'FINALIZADA'],
            ['id' => 903, 'empresa_id' => self::EMPRESA, 'orden_id' => 103, 'tarea_id' => null, 'descripcion_solicitada' => 'Cambiar filtro de aceite', 'trabajo_realizado' => null, 'observaciones' => null, 'estado' => 'FINALIZADA'],
            ['id' => 904, 'empresa_id' => self::EMPRESA, 'orden_id' => 101, 'tarea_id' => null, 'descripcion_solicitada' => 'Reponer aceite y revisar filtros de aire', 'trabajo_realizado' => null, 'observaciones' => 'Filtro de aire en buen estado', 'estado' => 'PENDIENTE'],
            ['id' => 905, 'empresa_id' => self::EMPRESA, 'orden_id' => 106, 'tarea_id' => null, 'descripcion_solicitada' => 'Cambiar pastillas', 'trabajo_realizado' => 'Se reparó el radiador auxiliar', 'observaciones' => 'Repuesto original comprado', 'estado' => 'EN_PROCESO'],
            ['id' => 906, 'empresa_id' => self::OTRA_EMPRESA, 'orden_id' => 105, 'tarea_id' => null, 'descripcion_solicitada' => 'Cambiar correa de distribución', 'trabajo_realizado' => 'Correa de otra empresa', 'observaciones' => null, 'estado' => 'EN_PROCESO'],
            ['id' => 907, 'empresa_id' => self::EMPRESA, 'orden_id' => 108, 'tarea_id' => null, 'descripcion_solicitada' => 'Ajustar pistón (a1b) según plano', 'trabajo_realizado' => null, 'observaciones' => null, 'estado' => 'PENDIENTE'],
            ['id' => 908, 'empresa_id' => self::EMPRESA, 'orden_id' => 101, 'tarea_id' => null, 'descripcion_solicitada' => 'Cambiar filtro de aceite', 'trabajo_realizado' => null, 'observaciones' => null, 'estado' => 'PENDIENTE'],
        ]);
    }

    /** @return array<string,mixed> */
    private function order(
        int $id,
        string $numero,
        int $empresaId,
        int $sucursalId,
        int $equipoId,
        int $responsableId,
        int $tipoServicioId,
        string $estado,
        string $origen,
        string $apertura,
        ?string $diagnostico,
        ?string $trabajoRealizado,
        ?string $observaciones,
    ): array {
        return [
            'id' => $id,
            'numero' => $numero,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'equipo_id' => $equipoId,
            'responsable_usuario_id' => $responsableId,
            'tipo_servicio_id' => $tipoServicioId,
            'origen' => $origen,
            'prioridad' => 'MEDIA',
            'estado' => $estado,
            'fecha_apertura' => $apertura,
            'fecha_inicio' => null,
            'fecha_finalizacion' => $estado === 'FINALIZADA' ? $apertura : null,
            'km_ingreso' => null,
            'horas_ingreso' => null,
            'diagnostico' => $diagnostico,
            'trabajo_realizado' => $trabajoRealizado,
            'observaciones' => $observaciones,
            'costo_mano_obra' => '0.00',
            'costo_repuestos' => '0.00',
            'otros_costos' => '0.00',
            'costo_total' => '0.00',
            'moneda_original' => 'ARS',
            'importe_original' => '0.00',
            'tipo_cambio_ars' => '1.00',
            'fecha_tipo_cambio' => null,
            'origen_tipo_cambio' => null,
            'importe_ars' => '0.00',
        ];
    }
}
