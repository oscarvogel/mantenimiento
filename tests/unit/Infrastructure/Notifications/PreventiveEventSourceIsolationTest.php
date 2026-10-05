<?php

declare(strict_types=1);

use App\Application\Notifications\Port\NotificationClock;
use App\Infrastructure\Notifications\CodeIgniterOperationalNotificationEventSource;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Regresion de #167: un plan preventivo incoherente no puede silenciar el resto
 * del lote de eventos.
 *
 * Este test es de comportamiento: arma la base real que consulta el recolector y
 * verifica que el evento del plan sano sigue emitiendo. Si alguien saca el
 * aislamiento o mueve la evaluacion de vencimiento fuera del try, el test falla
 * porque el evento sano desaparece, no porque un string deje de estar en el
 * fuente. Reemplaza al anterior, que solo hacia file_get_contents + assert sobre
 * el codigo y por lo tanto pasaba aunque el continue estuviese en otro metodo.
 */
final class PreventiveEventSourceIsolationTest extends TestCase
{
    private const NOW = '2026-10-02 08:00:00';

    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresion de aislamiento de planes requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug'  => true,
        ], false);

        $this->createSchema();
    }

    public function testAnIncoherentPlanDoesNotSilenceTheHealthyPlansOfTheSameBatch(): void
    {
        // El plan incoherente va PRIMERO: si el aislamiento falla, la corrida muere
        // antes de llegar al plan sano y no se emite ningun evento.
        $this->insertPlan(id: 1, equipoId: 1, tipoServicioId: 1, intervalKm: null);
        $this->insertPlan(id: 2, equipoId: 1, tipoServicioId: 1, intervalKm: 10000);

        $events = $this->source()->collect();

        $preventive = array_values(array_filter(
            $events,
            static fn ($event): bool => str_starts_with((string) $event->type(), 'preventivo.'),
        ));

        self::assertCount(1, $preventive, 'Solo el plan incoherente debe omitirse; el sano debe emitirse.');
        self::assertSame('preventivo.vencido', $preventive[0]->type());
        self::assertSame('2', (string) $preventive[0]->entityId());

        $omitidos = array_values(array_filter(
            $events,
            static fn ($event): bool => $event->entityId() === '1',
        ));
        self::assertSame([], $omitidos, 'El plan incoherente no debe generar ningún evento.');
    }

    public function testEveryHealthyPlanOfTheBatchIsEmittedEvenWhenAnotherRowIsIncoherent(): void
    {
        $this->insertPlan(id: 10, equipoId: 1, tipoServicioId: 1, intervalKm: null);
        $this->insertPlan(id: 11, equipoId: 1, tipoServicioId: 1, intervalKm: 10000);
        $this->insertPlan(id: 12, equipoId: 2, tipoServicioId: 1, intervalKm: 20000);

        $ids = array_map(
            static fn ($event): string => (string) $event->entityId(),
            array_filter(
                $this->source()->collect(),
                static fn ($event): bool => str_starts_with((string) $event->type(), 'preventivo.'),
            ),
        );

        sort($ids);
        self::assertSame(['11', '12'], $ids);
    }

    public function testAPlanWithoutAnyCriterionIsLoggedAndTheBatchContinues(): void
    {
        $this->insertPlan(id: 20, equipoId: 1, tipoServicioId: 1, intervalKm: null);

        $events = $this->source()->collect();

        $preventive = array_filter(
            $events,
            static fn ($event): bool => str_starts_with((string) $event->type(), 'preventivo.'),
        );

        self::assertSame([], $preventive);
    }

    private function source(): CodeIgniterOperationalNotificationEventSource
    {
        return new CodeIgniterOperationalNotificationEventSource(
            new FixedNotificationClock(new DateTimeImmutable(self::NOW)),
            30,
            5,
            2,
            $this->db,
        );
    }

    private function insertPlan(int $id, int $equipoId, int $tipoServicioId, ?int $intervalKm): void
    {
        $this->db->table('planes_mantenimiento')->insert([
            'id' => $id,
            'empresa_id' => 1,
            'equipo_id' => $equipoId,
            'tipo_servicio_id' => $tipoServicioId,
            'intervalo_km' => $intervalKm,
            'intervalo_horas' => null,
            'intervalo_dias' => null,
            'anticipacion_km' => $intervalKm === null ? null : 1000,
            'anticipacion_horas' => null,
            'anticipacion_dias' => null,
            'base_km' => $intervalKm === null ? null : 0,
            'base_horas' => null,
            'base_fecha' => null,
            'proximo_km' => $intervalKm,
            'proximas_horas' => null,
            'proxima_fecha' => null,
            'prioridad' => 'ALTA',
            'activo' => 1,
            'observaciones' => null,
            'deleted_at' => null,
        ]);
    }

    private function createSchema(): void
    {
        // Solo las tablas que el recolector consulta sin guarda de tableExists.
        $this->db->query('CREATE TABLE equipos (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            sucursal_id INTEGER NOT NULL,
            codigo TEXT NOT NULL,
            km_actual INTEGER NULL,
            horas_actuales DECIMAL NULL,
            estado TEXT NOT NULL,
            deleted_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE tipos_servicio (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->db->query('CREATE TABLE planes_mantenimiento (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            equipo_id INTEGER NOT NULL,
            tipo_servicio_id INTEGER NOT NULL,
            intervalo_km INTEGER NULL,
            intervalo_horas DECIMAL NULL,
            intervalo_dias INTEGER NULL,
            anticipacion_km INTEGER NULL,
            anticipacion_horas DECIMAL NULL,
            anticipacion_dias INTEGER NULL,
            base_km INTEGER NULL,
            base_horas DECIMAL NULL,
            base_fecha TEXT NULL,
            proximo_km INTEGER NULL,
            proximas_horas DECIMAL NULL,
            proxima_fecha TEXT NULL,
            prioridad TEXT NOT NULL,
            activo INTEGER NOT NULL,
            observaciones TEXT NULL,
            deleted_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE lecturas_equipo (
            id INTEGER PRIMARY KEY,
            equipo_id INTEGER NOT NULL,
            empresa_id INTEGER NOT NULL,
            fecha_lectura TEXT NOT NULL,
            anulada INTEGER NOT NULL
        )');
        $this->db->query('CREATE TABLE ordenes_trabajo (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            sucursal_id INTEGER NOT NULL,
            equipo_id INTEGER NOT NULL,
            numero TEXT NOT NULL,
            estado TEXT NOT NULL,
            responsable_usuario_id INTEGER NULL,
            fecha_objetivo TEXT NULL,
            fecha_apertura TEXT NULL
        )');

        $this->db->query("INSERT INTO equipos (id, empresa_id, sucursal_id, codigo, km_actual, horas_actuales, estado, deleted_at)
            VALUES (1, 1, 1, 'CAM-001', 10500, NULL, 'ACTIVO', NULL)");
        $this->db->query("INSERT INTO equipos (id, empresa_id, sucursal_id, codigo, km_actual, horas_actuales, estado, deleted_at)
            VALUES (2, 1, 1, 'CAM-002', 25000, NULL, 'ACTIVO', NULL)");
        $this->db->query("INSERT INTO tipos_servicio (id, nombre) VALUES (1, 'Service motor')");

        // Lectura reciente: evita que staleReadingEvents ensucie el lote.
        $this->db->query("INSERT INTO lecturas_equipo (id, equipo_id, empresa_id, fecha_lectura, anulada)
            VALUES (1, 1, 1, '2026-10-01 18:00:00', 0)");
        $this->db->query("INSERT INTO lecturas_equipo (id, equipo_id, empresa_id, fecha_lectura, anulada)
            VALUES (2, 2, 1, '2026-10-01 18:00:00', 0)");
    }
}

final readonly class FixedNotificationClock implements NotificationClock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
