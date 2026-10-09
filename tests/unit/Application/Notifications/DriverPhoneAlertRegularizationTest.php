<?php

declare(strict_types=1);

use App\Application\Notifications\NotifyAdminsMissingDriverPhones;
use App\Domain\Notifications\NotificationState;
use App\Domain\Notifications\WhatsAppPhone;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FakeNotificationRepository;
use Tests\Support\Notifications\FixedNotificationClock;
use Tests\Support\Notifications\RecordingWhatsAppGateway;

/**
 * Regresión del issue #473.
 *
 * Incidente: el centro de avisos choirró a un chofer con el teléfono local
 * `5621687769` cuando la ficha tenía `56921687769`. El número no estaba mal
 * truncado: el aviso era una COPIA FIJA de un estado viejo. `empleados.telefono`
 * nunca se modifica (el teléfono correcto se preserva tal cual) y el aviso se
 * regulariza cuando la irregularidad desaparece.
 *
 * Estos tests corren contra una base SQLite real con las columnas de las
 * migraciones, porque el comportamiento depende del `INSERT IGNORE` sobre la
 * clave única, del alcance por `empresa_id` y de los estados reales.
 */
final class DriverPhoneAlertRegularizationTest extends TestCase
{
    private const NOW = '2026-10-09 09:00:00';
    private const EVENT = 'chofer.telefono_faltante';

    private BaseConnection $db;

    private FakeNotificationRepository $repository;

    private RecordingWhatsAppGateway $gateway;

    private FixedNotificationClock $clock;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de celulares requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->gateway = new RecordingWhatsAppGateway();
        $this->clock = new FixedNotificationClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
        $this->repository = new FakeNotificationRepository($this->db);

        $this->createSchema();
        $this->seed();
    }

    // -------------------------------------------------------------------
    // 1-4. Validación del teléfono (sin base: es la regla de dominio)
    // -------------------------------------------------------------------

    public function testChileanMobilePhoneIsValid(): void
    {
        self::assertSame('56921687769', WhatsAppPhone::normalize('56921687769'));
    }

    public function testChileanLocalPhoneWithoutCountryMobileNineIsInvalid(): void
    {
        self::assertNull(WhatsAppPhone::normalize('5621687769'));
    }

    public function testArgentineMobilePhonesKeepWorking(): void
    {
        self::assertSame('5493764123456', WhatsAppPhone::normalize('5493764123456'));
        self::assertSame('5493514449999', WhatsAppPhone::normalize('5493514449999'));
    }

    public function testBrazilianPhonesKeepWorking(): void
    {
        self::assertSame('5511987654321', WhatsAppPhone::normalize('5511987654321'));
        self::assertSame('551133334444', WhatsAppPhone::normalize('551133334444'));
    }

    // -------------------------------------------------------------------
    // 5. Aviso previo con teléfono incorrecto que después se corrige
    // -------------------------------------------------------------------

    public function testCorrectedPhoneStopsBeingReportedAndRegularizesTheExistingAlert(): void
    {
        $first = $this->audit()->execute(true);
        self::assertSame(2, $first['notifications']);
        self::assertSame(2, $first['drivers']);

        $alert = $this->alertForUser(7);
        self::assertSame(NotificationState::PENDING->value, $alert['estado']);
        self::assertStringContainsString('5621687769', $alert['resumen']);

        // El chofer carga el número correcto. No se toca empleados.telefono más
        // allá de lo que el usuario escribió.
        $this->setPhone(55, '56921687769');

        $second = $this->audit()->execute(true);
        self::assertSame(0, $second['notifications'], 'No debe generarse un aviso nuevo.');
        self::assertSame(1, $second['drivers'], 'Esa empresa ya no tiene irregularidades.');
        self::assertSame(1, $second['regularized'], 'El aviso anterior se regulariza.');

        $regularized = $this->alertForUser(7);
        self::assertSame(NotificationState::REGULARIZED->value, $regularized['estado']);
        self::assertNotNull($regularized['leida_en'], 'Sale de la bandeja de pendientes.');
        self::assertSame(
            '56921687769',
            $this->phoneOf(55),
            'La corrección del usuario no se revierte ni se reformatea.',
        );
    }

    public function testCorrectedPhoneIsNeverReportedAgain(): void
    {
        $this->audit()->execute(true);
        $this->setPhone(55, '56921687769');
        $this->audit()->execute(true);

        // Dos semanas más tarde, con el teléfono ya válido.
        $this->clock->advance('+14 days');
        $this->audit()->execute(true);

        foreach ($this->repository->all() as $row) {
            if ($row['estado'] !== NotificationState::PENDING->value) {
                // La trazabilidad conserva el texto original: eso es historial,
                // no una advertencia viva.
                continue;
            }
            self::assertStringNotContainsString(
                'tel. 5621687769',
                (string) $row['resumen'],
                'Ningún aviso pendiente puede informar el teléfono obsoleto.',
            );
        }
    }

    public function testRegularizedAlertKeepsItsOriginalTextAsAuditTrail(): void
    {
        $this->audit()->execute(true);
        $this->setPhone(55, '56921687769');
        $this->audit()->execute(true);

        $regularized = $this->alertForUser(7);
        self::assertStringContainsString(
            'EMANUEL FERREYRA',
            $regularized['resumen'],
            'La regularización no borra el histórico de lo que se avisó.',
        );
        self::assertNotNull($regularized['created_at']);
        self::assertNotNull($regularized['updated_at']);
    }

    // -------------------------------------------------------------------
    // 6. Varios choferes, sólo se regulariza cuando no queda ninguno
    // -------------------------------------------------------------------

    public function testPartiallyCorrectedAlertKeepsOnlyRemainingDrivers(): void
    {
        $this->seedSecondIrregularDriver();
        $this->audit()->execute(true);

        $alert = $this->alertForUser(7);
        self::assertStringContainsString('Se detectaron 2 chofer(es)', $alert['resumen']);
        self::assertStringContainsString('MARIA LOPEZ', $alert['resumen']);

        $this->setPhone(55, '56921687769');
        $result = $this->audit()->execute(true);

        $alert = $this->alertForUser(7);
        self::assertSame(NotificationState::PENDING->value, $alert['estado'], 'Queda un chofer irregular: el aviso sigue vigente.');
        self::assertStringContainsString('Se detectaron 1 chofer(es)', $alert['resumen']);
        self::assertStringContainsString('MARIA LOPEZ', $alert['resumen']);
        self::assertStringNotContainsString('EMANUEL FERREYRA', $alert['resumen'], 'El chofer corregido desaparece del detalle.');
        self::assertStringNotContainsString('5621687769', $alert['resumen']);
        self::assertSame(1, $result['updated'], 'El contenido se regenera con las irregularidades vigentes.');
        self::assertSame(0, $result['regularized'], 'El aviso vigente no se regulariza mientras quede alguien irregular.');
    }

    public function testFullCorrectionRegularizesTheWholeAlert(): void
    {
        $this->seedSecondIrregularDriver();
        $this->audit()->execute(true);

        $this->setPhone(55, '56921687769');
        $this->setPhone(57, '5493764123456');
        $result = $this->audit()->execute(true);

        self::assertSame(1, $result['regularized']);
        self::assertSame(NotificationState::REGULARIZED->value, $this->alertForUser(7)['estado']);
    }

    // -------------------------------------------------------------------
    // 7. Avisos de semanas anteriores, generados antes de este fix
    // -------------------------------------------------------------------

    public function testLegacyWeeklyAlertsAreRegularizedWithoutDeletingThem(): void
    {
        $this->seedLegacyWeeklyAlert(self::EVENT . ':empresa:5:semana:2026-W40');
        $this->seedLegacyWeeklyAlert(self::EVENT . ':empresa:5:semana:2026-W41');
        $before = (int) $this->db->table('notificaciones')->countAllResults();

        $this->setPhone(55, '56921687769');
        $result = $this->audit()->execute(true);

        self::assertSame(2, $result['regularized'], 'Los avisos semanales heredados se cierran.');
        $this->assertLegacyAlertRegularized(self::EVENT . ':empresa:5:semana:2026-W40');
        $this->assertLegacyAlertRegularized(self::EVENT . ':empresa:5:semana:2026-W41');
        self::assertSame(
            $before + 1,
            (int) $this->db->table('notificaciones')->countAllResults(),
            'Ningún aviso se borra: sólo queda el de la otra empresa, que sigue irregular.',
        );
    }

    // -------------------------------------------------------------------
    // 8. Idempotencia
    // -------------------------------------------------------------------

    public function testReRunningTheCronDoesNotDuplicateOrResurrectAlerts(): void
    {
        $first = $this->audit()->execute(true);
        $second = $this->audit()->execute(true);
        $third = $this->audit()->execute(true);

        self::assertSame(2, $first['notifications'], 'Un aviso por empresa auditada.');
        self::assertSame(0, $second['notifications']);
        self::assertSame(0, $third['notifications']);
        self::assertSame(0, $second['updated'], 'Contenido idéntico: no se reescribe.');
        self::assertSame(2, $second['duplicates'], 'Los avisos ya vigentes no se tocan.');
        self::assertSame(0, $second['regularized']);
        self::assertSame(2, $this->repository->count(), 'Nunca se duplican avisos.');
    }

    public function testReadAlertIsNotReopenedWhenContentDidNotChange(): void
    {
        $this->audit()->execute(true);

        // Un humano lee el aviso.
        $this->db->table('notificaciones')->update([
            'estado' => NotificationState::READ->value,
            'leida_en' => '2026-10-09 10:00:00',
        ]);

        $result = $this->audit()->execute(true);

        $after = $this->db->table('notificaciones')->where('empresa_id', 5)->get()->getRowArray();
        self::assertSame(NotificationState::READ->value, (string) $after['estado'], 'El aviso leído no se reabre.');
        self::assertSame('2026-10-09 10:00:00', (string) $after['leida_en']);
        self::assertSame(0, $result['updated']);
        self::assertSame(2, $result['duplicates'], 'Sin información nueva no hay por qué reabrir.');
    }

    public function testRepeatedExecutionKeepsSummaryCountsStable(): void
    {
        $runs = [$this->audit()->execute(true), $this->audit()->execute(true)];

        self::assertSame(
            ['companies', 'drivers', 'notifications', 'updated', 'regularized', 'duplicates'],
            array_keys($runs[0]),
        );
        self::assertSame($runs[0]['drivers'], $runs[1]['drivers']);
        self::assertSame(0, $runs[1]['notifications']);
    }

    // -------------------------------------------------------------------
    // 9. Auditoría manual forzada desde SuperAdmin
    // -------------------------------------------------------------------

    public function testForcedAuditRunsWithoutWeeklyScheduleGate(): void
    {
        // Lunes antes de la hora de recordatorio: la corrida programada no correría.
        $this->clock->advance('-4 days -2 hours');

        $scheduled = $this->audit()->execute(false);
        self::assertSame(0, $scheduled['notifications'], 'Fuera de la ventana semanal no se crea nada.');

        $forced = $this->audit()->execute(true);
        self::assertSame(2, $forced['notifications'], 'La auditoría manual forzada sí audita.');
        self::assertSame([], $this->gateway->sent, 'La auditoría manual no envía WhatsApp a choferes.');
    }

    public function testAuditNeverSendsWhatsAppToDrivers(): void
    {
        $this->seedSecondIrregularDriver();
        $this->audit()->execute(true);
        $this->setPhone(55, '56921687769');
        $this->audit()->execute(true);

        self::assertSame([], $this->gateway->sent);
    }

    // -------------------------------------------------------------------
    // 10. Aislamiento por empresa y destinatario
    // -------------------------------------------------------------------

    public function testAlertsAreScopedPerCompanyAndRecipient(): void
    {
        $this->audit()->execute(true);

        $rows = $this->repository->all();
        self::assertCount(2, $rows, 'Un aviso por Responsable de mantenimiento, sólo de su empresa.');

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['usuario_id']] = (int) $row['empresa_id'];
        }
        ksort($byUser);
        self::assertSame([7 => 5, 70 => 99], $byUser, 'Cada aviso va al responsable de su propia empresa.');

        self::assertNull(
            $this->repository->rowFor(7, self::EVENT . ':empresa:99'),
            'No puede existir aviso cruzado de empresa.',
        );
        self::assertNull(
            $this->repository->rowFor(71, self::EVENT . ':empresa:5'),
            'Un técnico no es destinatario: sólo el Responsable de mantenimiento.',
        );
    }

    public function testCorrectingOneCompanyDoesNotTouchAnother(): void
    {
        $this->audit()->execute(true);
        $this->setPhone(55, '56921687769');

        $result = $this->audit()->execute(true);

        self::assertSame(1, $result['regularized']);
        self::assertSame(
            NotificationState::PENDING->value,
            $this->alertForUser(70)['estado'],
            'La empresa que sigue irregular mantiene su aviso.',
        );
    }

    // -------------------------------------------------------------------
    // 11. Los envíos de WhatsApp usan sólo el teléfono válido actual
    // -------------------------------------------------------------------

    public function testOnlyCurrentValidPhoneIsEverReportedAsDeliverable(): void
    {
        $this->audit()->execute(true);
        $this->setPhone(55, '56921687769');
        $this->audit()->execute(true);

        $stored = $this->phoneOf(55);
        self::assertSame('56921687769', $stored, 'La ficha conserva el teléfono que cargó el usuario.');
        self::assertSame(
            '56921687769',
            $this->gateway->normalizePhone($stored),
            'Lo que la cola de WhatsApp enviaría es el teléfono vigente, no el del aviso.',
        );
        self::assertNull(
            $this->gateway->normalizePhone('5621687769'),
            'El teléfono obsoleto jamás es un destino válido.',
        );
        self::assertSame([], $this->gateway->sent, 'La auditoría no envía mensajes.');
    }

    // -------------------------------------------------------------------
    // Extras: contenido, alcance y ciclo de vida del aviso
    // -------------------------------------------------------------------

    public function testSummaryStaysInsideTheColumnWidthWithManyDrivers(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $employeeId = 200 + $i;
            $this->db->table('empleados')->insert([
                'id' => $employeeId, 'empresa_id' => 5, 'nombre' => 'Chofer' . $i,
                'apellido' => 'ApellidoLarguisimo' . $i,
                'telefono' => '562168776' . ($i % 10), 'activo' => 1, 'deleted_at' => null,
            ]);
            $this->db->table('equipos')->insert([
                'id' => 200 + $i, 'empresa_id' => 5, 'codigo' => 'CAM-' . $i, 'patente' => 'AA' . $i . 'BB',
                'estado' => 'ACTIVO', 'deleted_at' => null,
            ]);
            $this->db->table('employee_equipment_assignments')->insert([
                'id' => 200 + $i, 'empresa_id' => 5, 'empleado_id' => $employeeId, 'equipo_id' => 200 + $i,
                'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null,
            ]);
        }

        $result = $this->audit()->execute(true);
        self::assertGreaterThan(12, $result['drivers']);

        $alert = $this->alertForUser(7);
        self::assertLessThanOrEqual(500, mb_strlen($alert['resumen']), 'resumen es VARCHAR(500).');
        self::assertStringContainsString('más', $alert['resumen']);
    }

    public function testCompaniesWithoutWhatsAppEnabledStopReceivingAlerts(): void
    {
        $this->audit()->execute(true);
        self::assertNotNull($this->repository->rowFor(7, self::EVENT . ':empresa:5'));

        // La empresa desactiva WhatsApp: ya no hay recordatorios que dar.
        $this->db->table('empresas')->where('id', 5)->update(['notificaciones_whatsapp_habilitadas' => 0]);
        $result = $this->audit()->execute(true);

        self::assertSame(1, $result['regularized'], 'El aviso de la empresa deshabilitada se cierra.');
        self::assertSame(
            NotificationState::REGULARIZED->value,
            (string) $this->db->table('notificaciones')->where('empresa_id', 5)->get()->getRowArray()['estado'],
        );
        self::assertSame(NotificationState::PENDING->value, $this->alertForUser(70)['estado']);
    }

    public function testAlertIsRegularizedWhenTheAssignmentIsClosed(): void
    {
        $this->audit()->execute(true);
        self::assertNotNull($this->repository->rowFor(7, self::EVENT . ':empresa:5'));

        // El chofer deja de estar asignado: el aviso ya no corresponde.
        $this->db->table('employee_equipment_assignments')->where('empleado_id', 55)->update(['fecha_hasta' => '2026-09-01']);
        $result = $this->audit()->execute(true);

        self::assertSame(1, $result['regularized']);
        self::assertSame(
            NotificationState::REGULARIZED->value,
            (string) $this->db->table('notificaciones')->where('empresa_id', 5)->get()->getRowArray()['estado'],
        );
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function audit(): NotifyAdminsMissingDriverPhones
    {
        return new NotifyAdminsMissingDriverPhones(
            $this->repository,
            $this->clock,
            $this->gateway,
            $this->db,
        );
    }

    private function setPhone(int $employeeId, string $phone): void
    {
        $this->db->table('empleados')->where('id', $employeeId)->update(['telefono' => $phone]);
    }

    private function phoneOf(int $employeeId): string
    {
        return (string) $this->db->table('empleados')->where('id', $employeeId)->get()->getRowArray()['telefono'];
    }

    /** @return array<string,mixed> */
    private function alertForUser(int $userId): array
    {
        $companyId = $userId === 7 ? 5 : 99;
        $row = $this->repository->rowFor($userId, self::EVENT . ':empresa:' . $companyId);
        self::assertNotNull($row, 'El aviso del usuario ' . $userId . ' debería existir.');

        return $row;
    }

    private function assertLegacyAlertRegularized(string $eventKey): void
    {
        $row = $this->db->table('notificaciones')->where('clave_evento', $eventKey)->get()->getRowArray();
        self::assertNotNull($row, 'El aviso heredado debe conservarse.');
        self::assertSame(NotificationState::REGULARIZED->value, (string) $row['estado']);
    }

    private function seedSecondIrregularDriver(): void
    {
        $this->db->table('empleados')->insert([
            'id' => 57, 'empresa_id' => 5, 'nombre' => 'MARIA', 'apellido' => 'LOPEZ',
            'telefono' => '549351444999', 'activo' => 1, 'deleted_at' => null,
        ]);
        $this->db->table('equipos')->insert([
            'id' => 12, 'empresa_id' => 5, 'codigo' => 'CAM-02', 'patente' => 'CC222DD',
            'estado' => 'ACTIVO', 'deleted_at' => null,
        ]);
        $this->db->table('employee_equipment_assignments')->insert([
            'id' => 12, 'empresa_id' => 5, 'empleado_id' => 57, 'equipo_id' => 12,
            'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null,
        ]);
    }

    private function seedLegacyWeeklyAlert(string $eventKey): void
    {
        $this->db->table('notificaciones')->insert([
            'id' => 900 + (int) $this->db->table('notificaciones')->countAllResults(),
            'empresa_id' => 5,
            'sucursal_id' => null,
            'usuario_id' => 7,
            'tipo_evento' => self::EVENT,
            'severidad' => 'ADVERTENCIA',
            'titulo' => 'Revisión de celulares para WhatsApp',
            'resumen' => 'Se detectaron 1 chofer(es) que requieren corrección de teléfono. '
                . 'Resumen: 1 número local sin código internacional. Detalle: EMANUEL FERREYRA '
                . '(RHB2H00, tel. 5621687769, número local sin código internacional).',
            'entidad_tipo' => 'empresa',
            'entidad_id' => '5',
            'url' => '/mantenimiento/empleados',
            'clave_evento' => $eventKey,
            'estado' => 'PENDIENTE',
            'leida_en' => null,
            'created_at' => '2026-09-28 08:00:00',
        ]);
    }

    private function createSchema(): void
    {
        $statements = <<<'SQL'
            CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT,
                estado INTEGER, deleted_at TEXT NULL, notificaciones_whatsapp_habilitadas INTEGER,
                whatsapp_instance_id TEXT, idioma_notificaciones TEXT);
            CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, estado INTEGER,
                deleted_at TEXT NULL, idioma_notificaciones TEXT);
            CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,
                codigo TEXT, patente TEXT NULL, estado TEXT, deleted_at TEXT NULL);
            CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT,
                telefono TEXT NULL, activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                empleado_id INTEGER, equipo_id INTEGER, rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL);
            CREATE TABLE usuarios (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER,
                deleted_at TEXT NULL);
            CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT);
            CREATE TABLE usuario_roles (usuario_id INTEGER, rol_id INTEGER);
            CREATE TABLE notificaciones (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER NULL,
                usuario_id INTEGER, tipo_evento TEXT, severidad TEXT, titulo TEXT, resumen TEXT,
                entidad_tipo TEXT, entidad_id TEXT, url TEXT NULL, clave_evento TEXT, estado TEXT,
                leida_en TEXT NULL, created_at TEXT, updated_at TEXT NULL);
            CREATE UNIQUE INDEX ux_notificaciones_clave ON notificaciones (usuario_id, clave_evento);
            SQL;

        foreach (array_filter(array_map('trim', explode(';', $statements))) as $statement) {
            $this->db->query($statement);
        }
    }

    private function seed(): void
    {
        $this->db->table('empresas')->insertBatch([
            ['id' => 5, 'razon_social' => 'Transporte Austral', 'nombre_fantasia' => 'Austral', 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1, 'whatsapp_instance_id' => 'inst-5', 'idioma_notificaciones' => 'ES'],
            ['id' => 99, 'razon_social' => 'Otra SA', 'nombre_fantasia' => 'Otra', 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1, 'whatsapp_instance_id' => 'inst-99', 'idioma_notificaciones' => 'ES'],
        ]);
        $this->db->table('sucursales')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null, 'idioma_notificaciones' => ''],
            ['id' => 9, 'empresa_id' => 99, 'nombre' => 'Otra', 'estado' => 1, 'deleted_at' => null, 'idioma_notificaciones' => ''],
        ]);
        $this->db->table('roles')->insertBatch([
            ['id' => 3, 'nombre' => 'Responsable de mantenimiento'],
            ['id' => 4, 'nombre' => 'Administrador'],
        ]);
        $this->db->table('usuarios')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Resp. Austral', 'activo' => 1, 'deleted_at' => null],
            ['id' => 70, 'empresa_id' => 99, 'nombre' => 'Resp. Otra', 'activo' => 1, 'deleted_at' => null],
            ['id' => 71, 'empresa_id' => 5, 'nombre' => 'Tecnico Austral', 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->db->table('usuario_roles')->insertBatch([
            ['usuario_id' => 7, 'rol_id' => 3],
            ['usuario_id' => 70, 'rol_id' => 3],
            // Tecnico: tiene rol pero NO es Responsable de mantenimiento.
            ['usuario_id' => 71, 'rol_id' => 4],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'codigo' => 'RHB2H00', 'patente' => 'RHB2H00', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 90, 'empresa_id' => 99, 'sucursal_id' => 9, 'codigo' => 'OTRO-01', 'patente' => 'OTRO01', 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->db->table('empleados')->insertBatch([
            ['id' => 55, 'empresa_id' => 5, 'nombre' => 'EMANUEL', 'apellido' => 'FERREYRA', 'telefono' => '5621687769', 'activo' => 1, 'deleted_at' => null],
            // Otra empresa, tambien con un numero local sin codigo internacional.
            ['id' => 95, 'empresa_id' => 99, 'nombre' => 'OTRO', 'apellido' => 'CHOFER', 'telefono' => '549351444999', 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->db->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 95, 'empresa_id' => 99, 'empleado_id' => 95, 'equipo_id' => 90, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
        ]);
    }
}