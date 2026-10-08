<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MaintenanceCircuit;

use App\Application\MaintenanceCircuit\CircuitOverviewPagination;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterCircuitOverview;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;

/**
 * "Atencion requerida / Planes a revisar": que se muestra en el campo "Proximo".
 *
 * Reproduction del bug reportado (#444):
 *
 *   equipo AD738EA, plan por 215.000 km contra km_actual 214.037 => 963 km
 *   dashboard:  "Faltan 963 km"                       (distancia restante)
 *   grilla:      "Proximo: 215.000 km"                 (objetivo absoluto)
 *
 * El numero absoluto era correcto como objetivo, pero no era comparable con el
 * dashboard y se leia como si faltara esa distancia. Estos tests fijan que la
 * grilla muestre la distancia restante por criterio.
 *
 * Ademas fijan que las columnas `proximo_*` persistidas por el modelo legacy no
 * sean mas la fuente: si el legacy quedo desactualizado, la grilla debe seguir
 * mostrando lo que dice el dominio, que es lo mismo que usa para el estado.
 */
final class ProximoAtencionRequeridaTest extends CIUnitTestCase
{
    private const EMPRESA_A = 1;
    private const SUCURSAL_A = 10;

    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El test de presentacion de "Proximo" requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->createSchema();
        $this->seedBase();
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

        $this->database->resetDataCache();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Caso 1: PROXIMO por kilometraje -> distancia restante, no objetivo
    // ------------------------------------------------------------------

    public function testProximoPlanShowsRemainingKmInsteadOfAbsoluteTarget(): void
    {
        $plan = $this->planById($this->fetchPlans(), 44);

        self::assertSame('PROXIMO', $plan['computed_state']);
        self::assertSame(963, $this->proximity($plan)['KILOMETRAJE'] ?? null);

        // El objetivo absoluto sigue disponible como dato de referencia...
        self::assertSame(215_000, $plan['proximo_km']);
        // ...pero la proximidad que se muestra es la distancia que falta.
        self::assertNotSame(
            ['KILOMETRAJE' => $plan['proximo_km']],
            $this->proximity($plan),
            'La proximidad debe ser la diferencia contra la lectura actual, no el objetivo absoluto.',
        );
    }

    // ------------------------------------------------------------------
    // Caso 2: VENCIDO -> cuanto se paso, con el mismo criterio que el dashboard
    // ------------------------------------------------------------------

    public function testOverduePlanExposesHowMuchItOvershot(): void
    {
        $plan = $this->planById($this->fetchPlans(), 45);

        self::assertSame('VENCIDO', $plan['computed_state']);
        self::assertSame(-1000, $this->proximity($plan)['KILOMETRAJE'] ?? null);
        self::assertContains('KILOMETRAJE', $plan['criterios_disparadores'] ?? []);
    }

    // ------------------------------------------------------------------
    // Caso 3: plan combinado -> cada criterio por separado, no un numero unico
    // ------------------------------------------------------------------

    public function testCombinedPlanKeepsOneDistancePerCriterion(): void
    {
        $plan = $this->planById($this->fetchPlans(), 46);

        self::assertSame(
            ['KILOMETRAJE' => 963, 'FECHA' => -3],
            $this->proximity($plan),
            'Fecha y kilometraje son objetivos distintos y deben quedar separados.',
        );
    }

    // ------------------------------------------------------------------
    // Caso 4: sin lectura -> sin distancia inventada
    // ------------------------------------------------------------------

    public function testPlanWithoutReadingHasNoDistance(): void
    {
        $this->database->table('equipos')->where('id', 24)->update(['km_actual' => null]);

        $plan = $this->planById($this->fetchPlans(), 47);

        self::assertSame('SIN_DATOS', $plan['computed_state']);

        // La clave existe siempre, aunque este vacia: el payload la consume sin
        // tener que adivinar. Y sin lectura no hay ninguna distancia, ni de
        // kilometres ni de fecha, aunque el plan tenga el criterio definido.
        self::assertArrayHasKey('proximidad', $plan);
        self::assertSame([], $this->proximity($plan));
    }

    // ------------------------------------------------------------------
    // Caso 5: el legacy persistido desactualizado no manda sobre el dominio
    // ------------------------------------------------------------------

    public function testStalePersistedNextTargetDoesNotOverrideDomainDerivedValues(): void
    {
        // El legacy quedo congelado en 999.999 km, que no corresponde a
        // base 200.000 + intervalo 15.000. Si la grilla leyera la columna
        // persistida, mostraria un proximo que el dominio no reconoce.
        $this->database->table('planes_mantenimiento')->where('id', 44)->update(['proximo_km' => 999_999]);

        $plan = $this->planById($this->fetchPlans(), 44);

        self::assertSame(215_000, $plan['proximo_km'], 'El objetivo debe venir del dominio, no de la columna legacy.');
        self::assertSame(963, $this->proximity($plan)['KILOMETRAJE'] ?? null);
        self::assertSame('PROXIMO', $plan['computed_state']);
    }

    public function testDashboardOrderStatusCountsRespectCompanyBranchAndOpenStateScope(): void
    {
        $this->database->table('sucursales')->insert([
            'id' => 11, 'empresa_id' => self::EMPRESA_A, 'codigo' => 'NORTE',
            'nombre' => 'Norte', 'estado' => 1,
        ]);
        $this->database->table('equipos')->insert([
            'id' => 25, 'empresa_id' => self::EMPRESA_A, 'sucursal_id' => 11,
            'tipo_equipo_id' => 1, 'codigo' => 'SUC-NORTE', 'patente' => null,
            'km_actual' => 10, 'horas_actuales' => null, 'estado' => 'ACTIVO',
            'fecha_alta' => '2026-01-01',
        ]);
        foreach ([
            [1, self::EMPRESA_A, self::SUCURSAL_A, 20, 'EN_PROCESO'],
            [2, self::EMPRESA_A, self::SUCURSAL_A, 20, 'EN_ESPERA_REPUESTOS'],
            [3, self::EMPRESA_A, self::SUCURSAL_A, 20, 'FINALIZADA'],
            [4, self::EMPRESA_A, 11, 25, 'EN_PROCESO'],
            [5, 2, self::SUCURSAL_A, 20, 'EN_PROCESO'],
        ] as [$id, $companyId, $branchId, $equipmentId, $status]) {
            $this->database->table('ordenes_trabajo')->insert([
                'id' => $id, 'empresa_id' => $companyId, 'numero' => 'OT-' . $id,
                'sucursal_id' => $branchId, 'equipo_id' => $equipmentId,
                'origen' => 'CORRECTIVO', 'prioridad' => 'MEDIA',
                'fecha_apertura' => '2026-10-01', 'estado' => $status,
            ]);
        }

        $result = (new CodeIgniterCircuitOverview($this->database))
            ->fetch(self::EMPRESA_A, [self::SUCURSAL_A], new CircuitOverviewPagination());

        self::assertSame([
            'EN_PROCESO' => 1,
            'EN_ESPERA_REPUESTOS' => 1,
        ], $result['openOrderStates']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function fetchPlans(): array
    {
        return (new CodeIgniterCircuitOverview($this->database))
            ->fetch(self::EMPRESA_A, null, new CircuitOverviewPagination())['plans'];
    }

    /**
     * Distancias por criterio de la grilla.
     *
     * Se lee con `?? []` a proposito: si la clave no existiera, el test debe
     * fallar por la asercion de valor, no por un error de clave inexistente. Un
     * fallo por "Undefined array key" demuestra que la clave falta, pero no
     * que el test este comprobando el comportamiento correcto.
     *
     * @param array<string,mixed> $plan
     * @return array<string,float|int>
     */
    private function proximity(array $plan): array
    {
        return $plan['proximidad'] ?? [];
    }

    /** @param list<array<string,mixed>> $plans @return array<string,mixed> */
    private function planById(array $plans, int $planId): array
    {
        foreach ($plans as $plan) {
            if ((int) ($plan['id'] ?? 0) === $planId) {
                return $plan;
            }
        }

        self::fail("El plan {$planId} no aparece en la grilla de planes.");
    }

    private function seedBase(): void
    {
        $this->database->table('empresas')->insert([
            'id' => self::EMPRESA_A, 'razon_social' => 'Transportes A',
            'nombre_fantasia' => 'Transportes A', 'estado' => 1,
        ]);
        $this->database->table('sucursales')->insert([
            'id' => self::SUCURSAL_A, 'empresa_id' => self::EMPRESA_A,
            'codigo' => 'CEN', 'nombre' => 'Central', 'estado' => 1,
        ]);
        $this->database->table('tipos_equipo')->insert([
            'id' => 1, 'nombre' => 'Tractor', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1,
        ]);
        $this->database->table('tipos_servicio')->insert([
            'id' => 1, 'empresa_id' => self::EMPRESA_A, 'codigo' => 'MOTOR', 'nombre' => 'Servicio Motor',
            'intervalo_km' => 15_000, 'intervalo_horas' => null, 'intervalo_dias' => null,
            'anticipacion_km' => 2_000, 'anticipacion_horas' => null, 'anticipacion_dias' => null,
            'prioridad' => 'MEDIA', 'activo' => 1,
        ]);
        // El servicio mixto es el que aporta el criterio por fecha: los
        // intervalos vigentes salen del tipo de servicio, no del plan.
        $this->database->table('tipos_servicio')->insert([
            'id' => 2, 'empresa_id' => self::EMPRESA_A, 'codigo' => 'MOTOR-FECHA', 'nombre' => 'Servicio Motor + Fecha',
            'intervalo_km' => 15_000, 'intervalo_horas' => null, 'intervalo_dias' => 30,
            'anticipacion_km' => 2_000, 'anticipacion_horas' => null, 'anticipacion_dias' => 15,
            'prioridad' => 'MEDIA', 'activo' => 1,
        ]);

        $this->insertEquipment(20, 'AD738EA', 214_037);
        $this->insertEquipment(21, 'RHB2H00', 216_000);
        $this->insertEquipment(22, 'IWE2I14', 214_037);
        $this->insertEquipment(23, 'BEN5D63', 214_037);
        $this->insertEquipment(24, 'AFI17ZK', null);

        $this->insertPlan(44, 20, baseKm: 200_000);
        $this->insertPlan(45, 21, baseKm: 200_000);
        // Vencen primero los kilometrias (faltan 963) y la fecha ya paso.
        // La base se ancla a "hoy" para que la distancia en dias sea estable:
        // objetivo = hoy - 3 dias => base = objetivo - 30 dias de intervalo.
        $this->insertPlan(
            46,
            22,
            baseKm: 200_000,
            serviceTypeId: 2,
            baseDate: (new DateTimeImmutable('today'))->modify('-33 days')->format('Y-m-d'),
        );
        $this->insertPlan(47, 24, baseKm: 200_000);
    }

    private function insertEquipment(int $id, string $code, ?int $km): void
    {
        $this->database->table('equipos')->insert([
            'id' => $id, 'empresa_id' => self::EMPRESA_A, 'sucursal_id' => self::SUCURSAL_A,
            'tipo_equipo_id' => 1, 'codigo' => $code, 'patente' => null,
            'km_actual' => $km, 'horas_actuales' => null, 'estado' => 'ACTIVO',
            'fecha_alta' => '2026-01-01 00:00:00',
        ]);
    }

    private function insertPlan(
        int $id,
        int $equipmentId,
        int $baseKm,
        ?string $baseDate = null,
        int $serviceTypeId = 1,
    ): void {
        $this->database->table('planes_mantenimiento')->insert([
            'id' => $id, 'empresa_id' => self::EMPRESA_A, 'equipo_id' => $equipmentId, 'tipo_servicio_id' => $serviceTypeId,
            'intervalo_km' => null, 'intervalo_horas' => null, 'intervalo_dias' => null,
            'anticipacion_km' => null, 'anticipacion_horas' => null, 'anticipacion_dias' => null,
            'base_km' => $baseKm, 'base_horas' => null, 'base_fecha' => $baseDate,
            'prioridad' => 'MEDIA', 'activo' => 1, 'observaciones' => null,
            // Columnas legacy: se persisten para otros consumidores, pero la
            // grilla no debe depender de ellas.
            'proximo_km' => $baseKm + 15_000, 'proximas_horas' => null, 'proxima_fecha' => null,
        ]);
    }

    private function createSchema(): void
    {
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT, estado INTEGER, deleted_at TEXT)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER, controla_horas INTEGER, activo INTEGER)');
        $this->database->query('CREATE TABLE tipos_servicio (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, intervalo_km INTEGER, intervalo_horas REAL, intervalo_dias INTEGER, anticipacion_km INTEGER, anticipacion_horas REAL, anticipacion_dias INTEGER, prioridad TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, codigo TEXT, patente TEXT, km_actual INTEGER, horas_actuales REAL, estado TEXT, fecha_alta TEXT, deleted_at TEXT)');
        $this->database->query('CREATE TABLE planes_mantenimiento (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, tipo_servicio_id INTEGER, intervalo_km INTEGER, intervalo_horas REAL, intervalo_dias INTEGER, anticipacion_km INTEGER, anticipacion_horas REAL, anticipacion_dias INTEGER, base_km INTEGER, base_horas REAL, base_fecha TEXT, prioridad TEXT, activo INTEGER, observaciones TEXT, proximo_km INTEGER, proximas_horas REAL, proxima_fecha TEXT, deleted_at TEXT)');
        $this->database->query('CREATE TABLE avisos_plan (id INTEGER PRIMARY KEY, empresa_id INTEGER, plan_id INTEGER, equipo_id INTEGER, clave_ciclo TEXT, estado_calculado TEXT, criterios_disparadores TEXT, fecha_deteccion TEXT, estado_gestion TEXT, fecha_resolucion TEXT, motivo_resolucion TEXT)');
        $this->database->query('CREATE TABLE ordenes_trabajo (id INTEGER PRIMARY KEY, empresa_id INTEGER, numero TEXT, sucursal_id INTEGER, equipo_id INTEGER, plan_id INTEGER, tipo_servicio_id INTEGER, aviso_plan_id INTEGER, origen TEXT, prioridad TEXT, responsable_usuario_id INTEGER, fecha_apertura TEXT, fecha_inicio TEXT, fecha_finalizacion TEXT, km_ingreso INTEGER, horas_ingreso REAL, km_salida INTEGER, horas_salida REAL, estado TEXT)');
        $this->database->query('CREATE TABLE orden_tareas (id INTEGER PRIMARY KEY, empresa_id INTEGER, orden_id INTEGER, descripcion_solicitada TEXT, trabajo_realizado TEXT, estado TEXT, obligatoria INTEGER, orden INTEGER)');
        $this->database->query('CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, sucursal_id INTEGER, fecha_lectura TEXT, kilometraje INTEGER, horometro REAL, origen TEXT, motivo_correccion TEXT, anulada INTEGER)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER, es_superadmin INTEGER, deleted_at TEXT)');
    }
}
