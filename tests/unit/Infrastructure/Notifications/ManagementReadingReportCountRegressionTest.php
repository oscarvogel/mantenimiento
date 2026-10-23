<?php

declare(strict_types=1);

use App\Application\Notifications\ScheduleManagementReports;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FixedNotificationClock;

/**
 * Regresión del conteo de "equipos sin lectura reciente" del informe por email.
 *
 * El defecto: el conteo tomaba TODOS los equipos activos de la empresa, sin
 * preguntar si controlan kilometraje. Un semirremolque no tiene lectura de km
 * que informar, asi que entraba como "sin lectura" para siempre.
 *
 * Con el corte de 30 dias era ruido menor. Al bajar el umbral a 7 dias (el
 * criterio que ya usa el tablero) el informe se llenaba de falsos positivos: la
 * gerencia recibia una lista de equipos que jamas van a subir lectura.
 *
 * El conteo se hace en SQL, asi que esta prueba corre contra un motor real.
 */
final class ManagementReadingReportCountRegressionTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del conteo requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database'    => ':memory:',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => '',
            'DBDebug'     => true,
            'foreignKeys' => true,
        ], false);

        $this->createSchema();
        $this->seedData();
    }

    private function createSchema(): void
    {
        $this->database->query(
            'CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT, email TEXT,'
            . ' email_notificaciones TEXT, emails_informes TEXT, estado INTEGER, deleted_at TEXT NULL,'
            . ' informe_diario_habilitado INTEGER, informe_diario_hora TEXT, informe_semanal_habilitado INTEGER,'
            . ' informe_semanal_dia INTEGER, informe_semanal_hora TEXT, informe_lecturas_dias INTEGER)',
        );
        $this->database->query(
            'CREATE TABLE notificacion_empresa_entregas (id INTEGER PRIMARY KEY, empresa_id INTEGER, tipo_evento TEXT,'
            . ' destinatario TEXT, clave_entrega TEXT, titulo TEXT, resumen TEXT, url TEXT, estado TEXT,'
            . ' created_at TEXT, intentos INTEGER DEFAULT 0, ultimo_error TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER, controla_horas INTEGER, activo INTEGER)',
        );
        $this->database->query(
            'CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER,'
            . ' codigo TEXT, patente TEXT NULL, chasis TEXT NULL, km_actual INTEGER NULL, estado TEXT, deleted_at TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER,'
            . ' fecha_lectura TEXT, kilometraje INTEGER NULL, horometro REAL NULL, origen TEXT, usuario_id INTEGER, anulada INTEGER)',
        );
    }

    /**
     * Con "ahora" en 2026-10-23 y un umbral de 7 dias:
     *
     *  - CAM-01: controla km, ultima lectura hace 11 dias. Cuenta.
     *  - SEM-02: NO controla km y no tiene lecturas. NO cuenta.
     *  - CAM-03: controla km, ultima lectura hace 3 dias. NO cuenta.
     */
    private function seedData(): void
    {
        $this->database->table('empresas')->insert([
            'id' => 5,
            'razon_social' => 'Transportes del Sur S.A.',
            'nombre_fantasia' => 'Transportes del Sur',
            'email' => 'admin@transportesdelsur.com',
            'email_notificaciones' => 'admin@transportesdelsur.com',
            'emails_informes' => 'gerencia@transportesdelsur.com',
            'estado' => 1,
            'deleted_at' => null,
            'informe_diario_habilitado' => 1,
            'informe_diario_hora' => '08:00',
            'informe_semanal_habilitado' => 0,
            'informe_semanal_dia' => 1,
            'informe_semanal_hora' => '08:00',
            'informe_lecturas_dias' => 7,
        ]);
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1],
            ['id' => 2, 'nombre' => 'Semirremolque', 'controla_km' => 0, 'controla_horas' => 0, 'activo' => 1],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-01', 'patente' => 'AB499OK', 'chasis' => 'CH-10', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 2, 'codigo' => 'SEM-02', 'patente' => 'SEM0200', 'chasis' => 'CH-11', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-03', 'patente' => 'AF117ZK', 'chasis' => 'CH-12', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            ['id' => 100, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-10-12 09:58:00', 'kilometraje' => 1002337, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
            ['id' => 102, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 12, 'fecha_lectura' => '2026-10-20 08:00:00', 'kilometraje' => 492320, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
        ]);
    }

    public function testTheCountExcludesEquipmentThatDoesNotControlKilometres(): void
    {
        $this->scheduler()->execute();

        $summary = $this->queuedSummary();
        self::assertStringContainsString('Equipos sin lectura reciente: 1', $summary);
    }

    public function testTheDetailNamesOnlyTheOverdueEquipmentThatControlsKilometres(): void
    {
        $this->scheduler()->execute();

        $summary = $this->queuedSummary();
        self::assertStringContainsString('!LECTURA|CAM-01|', $summary);
        self::assertStringNotContainsString('SEM-02', $summary, 'El semirremolque no controla km: no es un equipo sin lectura.');
    }

    public function testEquipmentThatReportedRecentlyIsNotCounted(): void
    {
        $this->scheduler()->execute();

        self::assertStringNotContainsString('CAM-03', $this->queuedSummary());
    }

    public function testTheCompanyThresholdDrivesTheCount(): void
    {
        // Con 3 dias, CAM-03 tambien entra en el conteo.
        $this->database->table('empresas')->where('id', 5)->update(['informe_lecturas_dias' => 3]);

        $this->scheduler()->execute();

        self::assertStringContainsString('Equipos sin lectura reciente: 2', $this->queuedSummary());
    }

    private function scheduler(): ScheduleManagementReports
    {
        $clock = new FixedNotificationClock(new DateTimeImmutable('2026-10-23 09:00:00', new DateTimeZone('UTC')));

        return new ScheduleManagementReports($clock, $this->database, 30, null);
    }

    private function queuedSummary(): string
    {
        $row = $this->database->table('notificacion_empresa_entregas')
            ->where('tipo_evento', 'informe.gerencial.diario')
            ->get()
            ->getRowArray();

        self::assertNotNull($row, 'El informe diario deberia quedar encolado.');
        self::assertSame('PENDIENTE', (string) $row['estado']);

        return (string) $row['resumen'];
    }
}
