<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MaintenanceCircuit;

use App\Application\MaintenanceCircuit\CircuitOverviewPagination;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterCircuitOverview;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Cubre el comportamiento observable de "Atencion requerida" contra la base real.
 *
 * Reproduction del bug observado:
 *
 *   aviso persistido: estado_gestion=PENDIENTE, estado historico=VENCIDO,
 *                     criterio=KILOMETRAJE
 *   plan actual:      EvaluadorVencimiento=PROXIMO, km_restantes=963
 *   esperado:         el aviso NO se devuelve como vencido
 *
 * Y el caso contrario obligatorio: un plan realmente VENCIDO debe seguir
 * apareciendo, para no eliminar falsos negativos.
 */
final class AvisosVencimientoConsistencyTest extends CIUnitTestCase
{
    private const EMPRESA_A = 1;
    private const EMPRESA_B = 2;
    private const SUCURSAL_A = 10;

    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El test de consistencia de avisos requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'orden_tareas', 'lecturas_equipo', 'ordenes_trabajo', 'avisos_plan',
            'planes_mantenimiento', 'equipos', 'sucursales', 'tipos_equipo',
            'tipos_servicio', 'usuarios', 'empresas',
        ] as $table) {
            $this->database->query("DROP TABLE IF EXISTS {$table}");
        }

        // Esta clase crea tipos_servicio con empresa_id y otra la crea sin esa
        // columna. CodeIgniter cachea la lista de campos por conexion, asi que
        // hay que limpiar la cache para que el otro test no vea un esquema
        // que ya no existe.
        $this->database->resetDataCache();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Caso observado: aviso PENDIENTE de un plan que ya no esta vencido
    // ------------------------------------------------------------------

    public function testStalePendingNoticeOfProximoPlanIsNotReturnedAsOverdue(): void
    {
        $this->seedProximoPlanWithStaleNotice();

        $result = $this->fetch(self::EMPRESA_A);

        // El plan sigue siendo PROXIMO para el circuito operativo.
        self::assertSame('PROXIMO', $this->stateOfPlan($result, 44));

        // El aviso obsoleto no aparece, aunque siga PENDIENTE en la base.
        $noticeIds = array_column($result['notices'], 'id');
        self::assertNotContains(55, $noticeIds, 'Un aviso de un plan PROXIMO no debe listarse como vencido.');

        // Y el conteo de la grilla coincide con lo realmente devuelto.
        self::assertSame(0, $result['pagination']['notices']['total']);
    }

    // ------------------------------------------------------------------
    // Caso contrario obligatorio: un vencimiento real debe seguir visible
    // ------------------------------------------------------------------

    public function testPendingNoticeOfCurrentlyOverduePlanIsStillReturned(): void
    {
        $this->seedOverduePlanWithNotice();

        $result = $this->fetch(self::EMPRESA_A);

        self::assertSame('VENCIDO', $this->stateOfPlan($result, 45));
        self::assertSame([56], array_column($result['notices'], 'id'));
        self::assertSame('VENCIDO', $result['notices'][0]['estado_calculado']);
        self::assertSame('KILOMETRAJE', $result['notices'][0]['criterios_disparadores']);
        self::assertSame(1, $result['pagination']['notices']['total']);
    }

    /**
     * Los dos casos juntos: no se pierde el vencimiento real y no se muestra
     * el falso. Es la garantia que evita "arreglar" el bug ocultando avisos.
     */
    public function testOnlyTheGenuinelyOverdueNoticeIsReturnedWhenBothCasesCoexist(): void
    {
        $this->seedProximoPlanWithStaleNotice();
        $this->seedOverduePlanWithNotice();

        $result = $this->fetch(self::EMPRESA_A);

        self::assertSame([56], array_column($result['notices'], 'id'));
        self::assertSame('PROXIMO', $this->stateOfPlan($result, 44));
        self::assertSame('VENCIDO', $this->stateOfPlan($result, 45));
    }

    // ------------------------------------------------------------------
    // Multiempresa y alcance
    // ------------------------------------------------------------------

    /**
     * Un aviso de otra empresa jamás debe entrar, aunque su plan_id apunte a
     * un plan de la empresa activa que SI esta vencido.
     */
    public function testNoticeFromAnotherCompanyNeverLeaksEvenWhenPlanIdMatches(): void
    {
        $this->seedOverduePlanWithNotice();

        // Empresa B tiene un plan vencido propio y un aviso PENDIENTE cuyo
        // plan_id referencia directamente el plan 45 de la empresa A.
        $this->insertCompanyB();
        $this->database->table('avisos_plan')->insert([
            'id' => 77,
            'empresa_id' => self::EMPRESA_B,
            'plan_id' => 45, // plan de la empresa A: el id compartido es el escenario de riesgo
            'equipo_id' => 93,
            'clave_ciclo' => 'plan:45|empresa-b',
            'estado_calculado' => 'VENCIDO',
            'criterios_disparadores' => 'KILOMETRAJE',
            'fecha_deteccion' => '2026-09-20 00:00:00',
            'estado_gestion' => 'PENDIENTE',
        ]);

        $result = $this->fetch(self::EMPRESA_A);

        self::assertSame([56], array_column($result['notices'], 'id'));
        self::assertNotContains(77, array_column($result['notices'], 'id'));
    }

    public function testCompanySeesOnlyItsOwnNotices(): void
    {
        $this->seedOverduePlanWithNotice();
        $this->insertCompanyB();
        $this->database->table('avisos_plan')->insert([
            'id' => 78,
            'empresa_id' => self::EMPRESA_B,
            'plan_id' => 90,
            'equipo_id' => 93,
            'clave_ciclo' => 'plan:90|empresa-b',
            'estado_calculado' => 'VENCIDO',
            'criterios_disparadores' => 'KILOMETRAJE',
            'fecha_deteccion' => '2026-09-21 00:00:00',
            'estado_gestion' => 'PENDIENTE',
        ]);

        $resultB = $this->fetch(self::EMPRESA_B);

        self::assertSame([78], array_column($resultB['notices'], 'id'));
    }

    public function testBranchScopeRestrictsNoticesToVisibleBranches(): void
    {
        $this->seedOverduePlanWithNotice();

        // El usuario solo tiene acceso a una sucursal que no contiene el equipo.
        $result = $this->fetch(self::EMPRESA_A, [999]);

        self::assertSame([], $result['notices']);
        self::assertSame(0, $result['pagination']['notices']['total']);
    }

    public function testEmptyBranchScopeReturnsNoNotices(): void
    {
        $this->seedOverduePlanWithNotice();

        $result = $this->fetch(self::EMPRESA_A, []);

        self::assertSame([], $result['notices']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Plan AD738EA: proximo_km 215.000 contra km_actual 214.037 => 963 km => PROXIMO. */
    private function seedProximoPlanWithStaleNotice(): void
    {
        $this->seedCompanyA();
        $this->insertServiceType(1);
        $this->insertBranch(self::SUCURSAL_A, self::EMPRESA_A);
        $this->insertEquipment(20, self::EMPRESA_A, self::SUCURSAL_A, 'AD738EA', 214_037);
        $this->insertPlan(44, self::EMPRESA_A, 20, baseKm: 200_000);

        // El aviso del ciclo anterior sigue PENDIENTE con el estado congelado.
        $this->database->table('avisos_plan')->insert([
            'id' => 55,
            'empresa_id' => self::EMPRESA_A,
            'plan_id' => 44,
            'equipo_id' => 20,
            'clave_ciclo' => 'plan:44|ciclo-anterior',
            'estado_calculado' => 'VENCIDO',
            'criterios_disparadores' => 'KILOMETRAJE',
            'fecha_deteccion' => '2026-09-20 00:00:00',
            'estado_gestion' => 'PENDIENTE',
        ]);
    }

    /** Plan RHB2H00: proximo_km 215.000 contra km_actual 216.000 => VENCIDO real. */
    private function seedOverduePlanWithNotice(): void
    {
        $this->seedCompanyA();
        $this->insertServiceType(1);
        $this->insertBranch(self::SUCURSAL_A, self::EMPRESA_A);
        $this->insertEquipment(21, self::EMPRESA_A, self::SUCURSAL_A, 'RHB2H00', 216_000);
        $this->insertPlan(45, self::EMPRESA_A, 21, baseKm: 200_000);

        $this->database->table('avisos_plan')->insert([
            'id' => 56,
            'empresa_id' => self::EMPRESA_A,
            'plan_id' => 45,
            'equipo_id' => 21,
            'clave_ciclo' => 'plan:45|ciclo-vigente',
            'estado_calculado' => 'VENCIDO',
            'criterios_disparadores' => 'KILOMETRAJE',
            'fecha_deteccion' => '2026-09-22 00:00:00',
            'estado_gestion' => 'PENDIENTE',
        ]);
    }

    private function seedCompanyA(): void
    {
        if ($this->database->table('empresas')->where('id', self::EMPRESA_A)->countAllResults() > 0) {
            return;
        }

        $this->database->table('empresas')->insert([
            'id' => self::EMPRESA_A,
            'razon_social' => 'Transportes A',
            'nombre_fantasia' => 'Transportes A',
            'estado' => 1,
        ]);
    }

    private function insertCompanyB(): void
    {
        $this->database->table('empresas')->insert([
            'id' => self::EMPRESA_B,
            'razon_social' => 'Transportes B',
            'nombre_fantasia' => 'Transportes B',
            'estado' => 1,
        ]);
        $this->insertServiceType(2);
        $this->insertBranch(92, self::EMPRESA_B);
        $this->insertEquipment(93, self::EMPRESA_B, 92, 'ZZ999XX', 216_000);
        $this->insertPlan(90, self::EMPRESA_B, 93, baseKm: 200_000);
    }

    private function insertServiceType(int $companyId): void
    {
        $id = $companyId === self::EMPRESA_A ? 1 : 2;
        if ($this->database->table('tipos_servicio')->where('id', $id)->countAllResults() > 0) {
            return;
        }

        $this->database->table('tipos_servicio')->insert([
            'id' => $id,
            'empresa_id' => $companyId,
            'codigo' => 'MOTOR',
            'nombre' => 'Servicio Motor',
            'intervalo_km' => 15_000,
            'intervalo_horas' => null,
            'intervalo_dias' => null,
            'anticipacion_km' => 2_000,
            'anticipacion_horas' => null,
            'anticipacion_dias' => null,
            'prioridad' => 'MEDIA',
            'activo' => 1,
        ]);
    }

    private function insertBranch(int $branchId, int $companyId): void
    {
        if ($this->database->table('sucursales')->where('id', $branchId)->countAllResults() > 0) {
            return;
        }

        $this->database->table('sucursales')->insert([
            'id' => $branchId,
            'empresa_id' => $companyId,
            'codigo' => 'CEN',
            'nombre' => 'Central',
            'estado' => 1,
        ]);
    }

    private function insertEquipment(int $id, int $companyId, int $branchId, string $code, int $km): void
    {
        if ($this->database->table('tipos_equipo')->where('id', 1)->countAllResults() === 0) {
            $this->database->table('tipos_equipo')->insert([
                'id' => 1,
                'nombre' => 'Tractor',
                'controla_km' => 1,
                'controla_horas' => 0,
                'activo' => 1,
            ]);
        }

        $this->database->table('equipos')->insert([
            'id' => $id,
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'tipo_equipo_id' => 1,
            'codigo' => $code,
            'patente' => null,
            'km_actual' => $km,
            'horas_actuales' => null,
            'estado' => 'ACTIVO',
            'fecha_alta' => '2026-01-01 00:00:00',
        ]);
    }

    private function insertPlan(int $id, int $companyId, int $equipmentId, int $baseKm): void
    {
        $this->database->table('planes_mantenimiento')->insert([
            'id' => $id,
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'tipo_servicio_id' => $companyId === self::EMPRESA_A ? 1 : 2,
            'intervalo_km' => 15_000,
            'intervalo_horas' => null,
            'intervalo_dias' => null,
            'anticipacion_km' => 2_000,
            'anticipacion_horas' => null,
            'anticipacion_dias' => null,
            'base_km' => $baseKm,
            'base_horas' => null,
            'base_fecha' => null,
            'prioridad' => 'MEDIA',
            'activo' => 1,
            'observaciones' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param list<int>|null $branchIds */
    private function fetch(int $companyId, ?array $branchIds = null): array
    {
        return (new CodeIgniterCircuitOverview($this->database))
            ->fetch($companyId, $branchIds, new CircuitOverviewPagination());
    }

    private function stateOfPlan(array $result, int $planId): ?string
    {
        foreach ($result['plans'] as $plan) {
            if ((int) ($plan['id'] ?? 0) === $planId) {
                return (string) ($plan['computed_state'] ?? 'AUSENTE');
            }
        }

        return null;
    }

    private function createSchema(): void
    {
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT, estado INTEGER, deleted_at TEXT)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER, controla_horas INTEGER, activo INTEGER)');
        $this->database->query('CREATE TABLE tipos_servicio (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, intervalo_km INTEGER, intervalo_horas REAL, intervalo_dias INTEGER, anticipacion_km INTEGER, anticipacion_horas REAL, anticipacion_dias INTEGER, prioridad TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, codigo TEXT, patente TEXT, km_actual INTEGER, horas_actuales REAL, estado TEXT, fecha_alta TEXT, deleted_at TEXT)');
        $this->database->query('CREATE TABLE planes_mantenimiento (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, tipo_servicio_id INTEGER, intervalo_km INTEGER, intervalo_horas REAL, intervalo_dias INTEGER, anticipacion_km INTEGER, anticipacion_horas REAL, anticipacion_dias INTEGER, base_km INTEGER, base_horas REAL, base_fecha TEXT, prioridad TEXT, activo INTEGER, observaciones TEXT, deleted_at TEXT)');
        $this->database->query('CREATE TABLE avisos_plan (id INTEGER PRIMARY KEY, empresa_id INTEGER, plan_id INTEGER, equipo_id INTEGER, clave_ciclo TEXT, estado_calculado TEXT, criterios_disparadores TEXT, fecha_deteccion TEXT, estado_gestion TEXT, fecha_resolucion TEXT, motivo_resolucion TEXT)');
        $this->database->query('CREATE TABLE ordenes_trabajo (id INTEGER PRIMARY KEY, empresa_id INTEGER, numero TEXT, sucursal_id INTEGER, equipo_id INTEGER, plan_id INTEGER, tipo_servicio_id INTEGER, aviso_plan_id INTEGER, origen TEXT, prioridad TEXT, responsable_usuario_id INTEGER, fecha_apertura TEXT, fecha_inicio TEXT, fecha_finalizacion TEXT, km_ingreso INTEGER, horas_ingreso REAL, km_salida INTEGER, horas_salida REAL, estado TEXT)');
        $this->database->query('CREATE TABLE orden_tareas (id INTEGER PRIMARY KEY, empresa_id INTEGER, orden_id INTEGER, descripcion_solicitada TEXT, trabajo_realizado TEXT, estado TEXT, obligatoria INTEGER, orden INTEGER)');
        $this->database->query('CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, sucursal_id INTEGER, fecha_lectura TEXT, kilometraje INTEGER, horometro REAL, origen TEXT, motivo_correccion TEXT, anulada INTEGER)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER, es_superadmin INTEGER, deleted_at TEXT)');
    }
}