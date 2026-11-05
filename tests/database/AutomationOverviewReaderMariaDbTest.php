<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterAutomationOverviewReader;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Verifica las consultas del lector sobre MariaDB real, no sobre SQLite.
 *
 * Se ejecuta sólo cuando el grupo `tests` apunta a la base descartable creada
 * por el runner; en la suite normal queda omitido.
 */
final class AutomationOverviewReaderMariaDbTest extends CIUnitTestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if (($config['DBDriver'] ?? '') !== 'MySQLi' || ! preg_match('/^mantenimiento_test$/', (string) ($config['database'] ?? ''))) {
            self::markTestSkipped('Requiere MariaDB descartable mantenimiento_test en fasa_189.');
        }

        $this->database = db_connect($config, false);
        $this->database->transException(true);
        $this->database->transBegin();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->transRollback();
            $this->database->close();
        }
        parent::tearDown();
    }

    public function testBuildsEvidenceWithRealMariaDbSemantics(): void
    {
        $company = 9001;
        $branch = 9001;
        $otherBranch = 9002;
        $user = 9001;
        $type = 9001;
        $equipment = 9001;

        $this->database->table('empresas')->insert([
            'id' => $company,
            'razon_social' => 'QA Automatizaciones',
            'estado' => 1,
            'informe_diario_habilitado' => 1,
            'informe_diario_hora' => '07:30',
        ]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => $branch, 'empresa_id' => $company, 'codigo' => 'QA1', 'nombre' => 'QA 1', 'estado' => 1],
            ['id' => $otherBranch, 'empresa_id' => $company, 'codigo' => 'QA2', 'nombre' => 'QA 2', 'estado' => 1],
        ]);
        $this->database->table('usuarios')->insert([
            'id' => $user,
            'empresa_id' => $company,
            'nombre' => 'QA',
            'email' => uniqid('qa-automation-', true) . '@example.test',
            'password_hash' => 'unused',
            'activo' => 1,
        ]);
        $this->database->table('tipos_equipo')->insert(['id' => $type, 'nombre' => 'QA tipo']);
        $this->database->table('equipos')->insert([
            'id' => $equipment,
            'empresa_id' => $company,
            'sucursal_id' => $branch,
            'tipo_equipo_id' => $type,
            'codigo' => 'QA-UNIT',
            'fecha_alta' => '2026-01-01',
        ]);

        $this->database->table('notificaciones')->insert([
            'empresa_id' => $company,
            'sucursal_id' => $branch,
            'usuario_id' => $user,
            'tipo_evento' => 'preventivo.vencido',
            'severidad' => 'CRITICA',
            'titulo' => 'QA',
            'resumen' => 'QA',
            'entidad_tipo' => 'plan',
            'entidad_id' => '1',
            'clave_evento' => 'qa:automation:1',
            'estado' => 'PENDIENTE',
            'created_at' => '2026-11-05 06:00:00',
        ]);
        $this->database->table('notificacion_entregas')->insert([
            'notificacion_id' => $this->database->insertID(),
            'canal' => 'EMAIL',
            'clave_entrega' => 'qa:automation:email:1',
            'estado' => 'FALLIDA',
            'intentos' => 1,
            'updated_at' => '2026-11-05 06:05:00',
            'created_at' => '2026-11-05 06:00:00',
        ]);
        $this->database->table('notificacion_whatsapp_entregas')->insert([
            'empresa_id' => $company,
            'equipo_id' => $equipment,
            'tipo_evento' => 'equipo.resumen_vencimientos',
            'clave_entrega' => 'qa:automation:wa:1',
            'external_ref' => 'qa:automation:wa:1',
            'mensaje' => 'QA',
            'estado' => 'PENDIENTE',
            'created_at' => '2026-11-05 06:10:00',
        ]);
        $this->database->table('notificacion_empresa_entregas')->insert([
            'empresa_id' => $company,
            'tipo_evento' => 'informe.gerencial.diario',
            'clave_entrega' => 'qa:automation:report:1',
            'titulo' => 'QA',
            'resumen' => 'QA',
            'estado' => 'ENVIADA',
            'enviada_en' => '2026-11-05 07:30:00',
            'created_at' => '2026-11-05 07:30:00',
        ]);
        $this->database->table('integraciones_telemetria')->insert([
            'empresa_id' => $company,
            'proveedor' => 'QA',
            'nombre' => 'QA',
            'activo' => 1,
            'ultimo_ok_en' => '2026-11-05 06:45:00',
            'consecutivos_fallidos' => 0,
        ]);

        $overview = (new CodeIgniterAutomationOverviewReader($this->database))->forCompany($company, [$branch]);

        $preventive = $this->process($overview, 'preventive-alerts');
        self::assertSame(2, $preventive['pending']);
        self::assertSame(1, $preventive['failed']);
        self::assertSame('CON_FALLOS', $preventive['lastOutcome']);

        $management = $this->process($overview, 'management-reports');
        self::assertTrue($management['enabled']);
        self::assertSame('Diario 07:30', $management['schedule']);
        self::assertSame(0, $management['sent'], 'El informe de empresa no se atribuye a una sucursal restringida.');

        $telemetry = $this->process($overview, 'telemetry-sync');
        self::assertNull($telemetry['enabled']);

        self::assertSame(1, $overview['deliveries']['email']['failed']);
        self::assertSame(1, $overview['deliveries']['whatsapp']['pending']);
        self::assertNotEmpty($overview['limitations']);
    }

    /** @param array{processes:list<array<string,mixed>>} $overview */
    private function process(array $overview, string $key): array
    {
        foreach ($overview['processes'] as $process) {
            if ($process['key'] === $key) {
                return $process;
            }
        }

        self::fail('Falta el proceso ' . $key);
    }
}
