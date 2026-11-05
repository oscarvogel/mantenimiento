<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\AutomationOverviewReader;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Evidencia de automatizaciones para una empresa.
 *
 * No lee las tablas globales de ejecución y bloqueo: no distinguen empresa.
 * La evidencia se obtiene de notificaciones,
 * entregas, configuración de informes e integraciones de telemetría, todas con
 * empresa explícita. Cuando el alcance está restringido a sucursales, se
 * excluye todo lo que no se puede atribuir a una sucursal autorizada.
 */
final class CodeIgniterAutomationOverviewReader implements AutomationOverviewReader
{
    private const PREVENTIVE_TYPES = [
        'preventivo.proximo',
        'preventivo.vencido',
        'equipo.sin_lectura',
        'equipo.vencimiento_proximo',
        'equipo.vencimiento_vencido',
        'empleado.vencimiento_proximo',
        'empleado.vencimiento_vencido',
        'garantia.proxima',
    ];

    private const PREVENTIVE_WHATSAPP_TYPES = ['equipo.resumen_vencimientos'];

    private const READING_TYPES = [
        'equipo.recordatorio_lectura_semanal',
        'equipo.lectura_semanal_incumplida',
        'equipo.reclamo_manual_lectura',
    ];

    private const MANAGEMENT_EMAIL_TYPES = ['informe.gerencial.diario', 'informe.gerencial.semanal'];

    private const MANAGEMENT_WHATSAPP_TYPES = ['informe.lecturas.gerencia'];

    private const PENDING_STATES = ['PENDIENTE', 'REINTENTO', 'PENDIENTE_CONFIRMACION', 'EN_PROCESO'];
    private const FAILED_STATES = ['FALLIDA', 'FALLIDO', 'ERROR'];
    private const SENT_STATES = ['ENVIADA', 'ENVIADO', 'ENTREGADA', 'LEIDA', 'ACEPTADA', 'CONFIRMADA'];

    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function forCompany(int $companyId, ?array $branchIds): array
    {
        $limitations = [
            'La pantalla muestra la evidencia registrada en la base de la empresa; no confirma por sí sola que el programador del hosting esté activo.',
            'Esta entrega es sólo de lectura: no permite enviar, ejecutar ni reintentar procesos.',
            'Una entrega aceptada por el proveedor no equivale a una lectura confirmada por el destinatario.',
            'No se muestran teléfonos, correos, tokens ni errores crudos de los proveedores.',
        ];

        if ($branchIds === []) {
            return [
                'processes' => [],
                'deliveries' => $this->emptyDeliveries(),
                'limitations' => [...$limitations, 'No tenés sucursales autorizadas para consultar evidencia de automatizaciones.'],
            ];
        }

        $preventive = $this->notificationEvidence(
            $companyId,
            $branchIds,
            self::PREVENTIVE_TYPES,
            self::PREVENTIVE_WHATSAPP_TYPES,
        );

        $reading = $this->notificationEvidence($companyId, $branchIds, self::READING_TYPES, self::READING_TYPES);

        $management = $this->managementEvidence($companyId, $branchIds, $limitations);
        $telemetry = $this->telemetryProcess($companyId, $branchIds, $limitations);

        $processes = [
            $this->process(
                'preventive-alerts',
                'Vencimientos y avisos preventivos',
                'Planes próximos o vencidos, equipos sin lectura y vencimientos de equipos o empleados.',
                null,
                'Diario ' . $this->dailyRunTime() . ' (configuración de la aplicación)',
                $preventive,
            ),
            $this->process(
                'reading-reminders',
                'Recordatorios de lectura',
                'Recordatorios semanales, reclamos manuales y avisos de lectura incumplida por WhatsApp.',
                null,
                'Dentro del ciclo de notificaciones',
                $reading,
            ),
            $this->process(
                'management-reports',
                'Informes gerenciales y de lecturas',
                'Informes diarios, semanales y el informe de lecturas para la gerencia.',
                $management['enabled'],
                $management['schedule'],
                $management['evidence'],
            ),
            $this->process(
                'telemetry-sync',
                'Sincronización de telemetría',
                'Actualización de posiciones y consumos de las integraciones activas de la empresa.',
                $telemetry['enabled'],
                $telemetry['schedule'],
                $telemetry['evidence'],
                $telemetry['outcome'],
            ),
        ];

        return [
            'processes' => $processes,
            'deliveries' => $this->deliveries($companyId, $branchIds),
            'limitations' => $limitations,
        ];
    }

    /** @param list<int>|null $branchIds */
    private function managementEvidence(int $companyId, ?array $branchIds, array &$limitations): array
    {
        $evidence = ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null];
        if (! $this->hasTable('empresas')) {
            $limitations[] = 'La configuración de informes gerenciales no está disponible en esta base.';

            return ['enabled' => null, 'schedule' => null, 'evidence' => $evidence];
        }

        $company = $this->db->table('empresas')
            ->select('informe_diario_habilitado, informe_diario_hora, informe_semanal_habilitado, informe_semanal_dia, informe_semanal_hora, informe_lecturas_whatsapp_habilitado, informe_lecturas_hora')
            ->where('id', $companyId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($company === null) {
            return ['enabled' => null, 'schedule' => null, 'evidence' => $evidence];
        }

        $parts = [];
        if ((int) ($company['informe_diario_habilitado'] ?? 0) === 1) {
            $parts[] = 'Diario ' . $this->time((string) ($company['informe_diario_hora'] ?? '07:00'));
        }
        if ((int) ($company['informe_semanal_habilitado'] ?? 0) === 1) {
            $parts[] = $this->weekday((int) ($company['informe_semanal_dia'] ?? 1)) . ' ' . $this->time((string) ($company['informe_semanal_hora'] ?? '07:00'));
        }
        if ((int) ($company['informe_lecturas_whatsapp_habilitado'] ?? 0) === 1) {
            $parts[] = 'Lecturas diario ' . $this->time((string) ($company['informe_lecturas_hora'] ?? '08:00'));
        }

        if ($branchIds !== null) {
            $limitations[] = 'Los informes gerenciales son a nivel empresa; con sucursales restringidas no se muestra su evidencia.';
        } else {
            $evidence = $this->addEvidence($evidence, $this->companyEmailEvidence($companyId, self::MANAGEMENT_EMAIL_TYPES));
        }

        $whatsapp = $this->notificationEvidence($companyId, $branchIds, [], self::MANAGEMENT_WHATSAPP_TYPES);
        $evidence = $this->addEvidence($evidence, $whatsapp);

        return [
            'enabled' => $parts !== [],
            'schedule' => $parts === [] ? null : implode('; ', $parts),
            'evidence' => $evidence,
        ];
    }

    /** @param list<int>|null $branchIds */
    private function telemetryProcess(int $companyId, ?array $branchIds, array &$limitations): array
    {
        if ($branchIds !== null) {
            $limitations[] = 'La configuración de telemetría es a nivel empresa; con sucursales restringidas no se muestra su evidencia.';

            return [
                'enabled' => null,
                'schedule' => null,
                'evidence' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null],
                'outcome' => null,
            ];
        }

        if (! $this->hasTable('integraciones_telemetria')) {
            $limitations[] = 'La tabla de integraciones de telemetría no está disponible en esta base.';

            return [
                'enabled' => null,
                'schedule' => null,
                'evidence' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null],
                'outcome' => null,
            ];
        }

        $rows = $this->db->table('integraciones_telemetria')
            ->select('ultimo_ok_en, consecutivos_fallidos')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->get()
            ->getResultArray();

        $active = count($rows);
        $failed = 0;
        $sent = 0;
        $lastAt = null;
        foreach ($rows as $row) {
            if ((int) ($row['consecutivos_fallidos'] ?? 0) > 0) {
                $failed++;
            }
            if (! empty($row['ultimo_ok_en'])) {
                $sent++;
                $lastAt = $this->latest($lastAt, (string) $row['ultimo_ok_en']);
            }
        }

        $interval = max(5, (int) env('alerts.telemetryRefreshMinutes', 15));

        return [
            'enabled' => $active > 0,
            'schedule' => $active > 0 ? 'Dentro del ciclo de notificaciones cada ' . $interval . ' minutos' : null,
            'evidence' => ['pending' => 0, 'failed' => $failed, 'sent' => $sent, 'lastAt' => $lastAt],
            'outcome' => $active === 0 ? null : ($failed > 0 ? 'CON_FALLOS' : ($lastAt === null ? 'SIN_EJECUCION' : 'SIN_FALLAS_REGISTRADAS')),
        ];
    }

    /**
     * @param list<int>|null $branchIds
     * @param list<string> $types
     * @param list<string> $whatsAppTypes
     *
     * @return array{pending:int,failed:int,sent:int,lastAt:?string}
     */
    private function notificationEvidence(int $companyId, ?array $branchIds, array $types, array $whatsAppTypes): array
    {
        $evidence = ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null];
        if ($branchIds === []) {
            return $evidence;
        }

        if ($types !== [] && $this->hasTable('notificaciones')) {
            $builder = $this->db->table('notificaciones')
                ->select('estado, COUNT(*) c, MAX(created_at) last_at', false)
                ->where('empresa_id', $companyId)
                ->whereIn('tipo_evento', $types);
            $this->scopeNotifications($builder, $branchIds);
            $evidence = $this->addEvidence($evidence, $this->fromStateRows($builder->groupBy('estado')->get()->getResultArray()));

            if ($this->hasTable('notificacion_entregas')) {
                $deliveries = $this->db->table('notificacion_entregas e')
                    ->join('notificaciones n', 'n.id = e.notificacion_id', 'inner')
                    ->select('e.estado, COUNT(*) c, MAX(COALESCE(e.enviada_en, e.updated_at, n.created_at)) last_at', false)
                    ->where('n.empresa_id', $companyId)
                    ->whereIn('n.tipo_evento', $types);
                $this->scopeNotifications($deliveries, $branchIds, 'n');
                $evidence = $this->addEvidence($evidence, $this->fromStateRows($deliveries->groupBy('e.estado')->get()->getResultArray()));
            }
        }

        if ($whatsAppTypes !== [] && $this->hasTable('notificacion_whatsapp_entregas')) {
            $builder = $this->db->table('notificacion_whatsapp_entregas w')
                ->select('w.estado, COUNT(*) c, MAX(COALESCE(w.enviada_en, w.updated_at, w.created_at)) last_at', false)
                ->where('w.empresa_id', $companyId)
                ->whereIn('w.tipo_evento', $whatsAppTypes);
            if ($branchIds !== null) {
                $builder->join('equipos eq', 'eq.id = w.equipo_id AND eq.empresa_id = w.empresa_id', 'inner')
                    ->whereIn('eq.sucursal_id', $branchIds);
            }
            $evidence = $this->addEvidence($evidence, $this->fromStateRows($builder->groupBy('w.estado')->get()->getResultArray()));
        }

        return $evidence;
    }

    /** @param list<string> $types @return array{pending:int,failed:int,sent:int,lastAt:?string} */
    private function companyEmailEvidence(int $companyId, array $types): array
    {
        if (! $this->hasTable('notificacion_empresa_entregas')) {
            return ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null];
        }

        $rows = $this->db->table('notificacion_empresa_entregas')
            ->select('estado, COUNT(*) c, MAX(COALESCE(enviada_en, updated_at, created_at)) last_at', false)
            ->where('empresa_id', $companyId)
            ->whereIn('tipo_evento', $types)
            ->groupBy('estado')
            ->get()
            ->getResultArray();

        return $this->fromStateRows($rows);
    }

    /** @param list<int>|null $branchIds */
    private function deliveries(int $companyId, ?array $branchIds): array
    {
        return [
            'email' => $this->channelEvidence($companyId, $branchIds, 'EMAIL'),
            'webpush' => $this->channelEvidence($companyId, $branchIds, 'PUSH'),
            'whatsapp' => $this->whatsAppEvidence($companyId, $branchIds),
        ];
    }

    /** @param list<int>|null $branchIds */
    private function channelEvidence(int $companyId, ?array $branchIds, string $channel): array
    {
        $evidence = ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null];
        if ($branchIds === [] || ! $this->hasTable('notificacion_entregas') || ! $this->hasTable('notificaciones')) {
            return $evidence;
        }

        $builder = $this->db->table('notificacion_entregas e')
            ->join('notificaciones n', 'n.id = e.notificacion_id', 'inner')
            ->select('e.estado, COUNT(*) c, MAX(COALESCE(e.enviada_en, e.updated_at, n.created_at)) last_at', false)
            ->where('n.empresa_id', $companyId)
            ->where('e.canal', $channel);
        $this->scopeNotifications($builder, $branchIds, 'n');
        $rows = $builder->groupBy('e.estado')->get()->getResultArray();
        $summary = $this->fromStateRows($rows);

        if ($channel === 'EMAIL' && $branchIds === null) {
            $summary = $this->addEvidence($summary, $this->companyEmailEvidenceAll($companyId));
        }

        return [
            'pending' => $summary['pending'],
            'failed' => $summary['failed'],
            'sent' => $summary['sent'],
            'lastActivityAt' => $summary['lastAt'],
        ];
    }

    /** @param list<int>|null $branchIds */
    private function whatsAppEvidence(int $companyId, ?array $branchIds): array
    {
        $evidence = ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null];
        if ($branchIds === [] || ! $this->hasTable('notificacion_whatsapp_entregas')) {
            return $evidence;
        }

        $builder = $this->db->table('notificacion_whatsapp_entregas w')
            ->select('w.estado, COUNT(*) c, MAX(COALESCE(w.enviada_en, w.updated_at, w.created_at)) last_at', false)
            ->where('w.empresa_id', $companyId);
        if ($branchIds !== null) {
            $builder->join('equipos eq', 'eq.id = w.equipo_id AND eq.empresa_id = w.empresa_id', 'inner')
                ->whereIn('eq.sucursal_id', $branchIds);
        }
        $summary = $this->fromStateRows($builder->groupBy('w.estado')->get()->getResultArray());

        return [
            'pending' => $summary['pending'],
            'failed' => $summary['failed'],
            'sent' => $summary['sent'],
            'lastActivityAt' => $summary['lastAt'],
        ];
    }

    /** @return array{pending:int,failed:int,sent:int,lastAt:?string} */
    private function companyEmailEvidenceAll(int $companyId): array
    {
        if (! $this->hasTable('notificacion_empresa_entregas')) {
            return ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null];
        }

        $rows = $this->db->table('notificacion_empresa_entregas')
            ->select('estado, COUNT(*) c, MAX(COALESCE(enviada_en, updated_at, created_at)) last_at', false)
            ->where('empresa_id', $companyId)
            ->groupBy('estado')
            ->get()
            ->getResultArray();

        return $this->fromStateRows($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return array{pending:int,failed:int,sent:int,lastAt:?string}
     */
    private function fromStateRows(array $rows): array
    {
        $evidence = ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastAt' => null];
        foreach ($rows as $row) {
            $state = strtoupper((string) ($row['estado'] ?? ''));
            $count = (int) ($row['c'] ?? 0);
            if (in_array($state, self::PENDING_STATES, true)) {
                $evidence['pending'] += $count;
            } elseif (in_array($state, self::FAILED_STATES, true)) {
                $evidence['failed'] += $count;
            } elseif (in_array($state, self::SENT_STATES, true)) {
                $evidence['sent'] += $count;
            }
            $evidence['lastAt'] = $this->latest($evidence['lastAt'], isset($row['last_at']) ? (string) $row['last_at'] : null);
        }

        return $evidence;
    }

    /** @param array{pending:int,failed:int,sent:int,lastAt:?string} $left @param array{pending:int,failed:int,sent:int,lastAt:?string} $right */
    private function addEvidence(array $left, array $right): array
    {
        return [
            'pending' => $left['pending'] + $right['pending'],
            'failed' => $left['failed'] + $right['failed'],
            'sent' => $left['sent'] + $right['sent'],
            'lastAt' => $this->latest($left['lastAt'], $right['lastAt']),
        ];
    }

    /** @param array{pending:int,failed:int,sent:int,lastAt:?string} $evidence */
    private function process(
        string $key,
        string $label,
        string $description,
        ?bool $enabled,
        ?string $schedule,
        array $evidence,
        ?string $forcedOutcome = null,
    ): array {
        $outcome = $forcedOutcome;
        if ($outcome === null && $evidence['lastAt'] !== null) {
            $outcome = $evidence['failed'] > 0
                ? 'CON_FALLOS'
                : ($evidence['pending'] > 0 ? 'CON_PENDIENTES' : 'SIN_FALLAS_REGISTRADAS');
        }

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'enabled' => $enabled,
            'schedule' => $schedule,
            'lastRunAt' => $evidence['lastAt'],
            'lastOutcome' => $outcome,
            'nextRunAt' => null,
            'pending' => $evidence['pending'],
            'failed' => $evidence['failed'],
            'sent' => $evidence['sent'],
        ];
    }

    private function scopeNotifications(BaseBuilder $builder, ?array $branchIds, string $alias = ''): void
    {
        if ($branchIds === null) {
            return;
        }

        $column = $alias === '' ? 'sucursal_id' : $alias . '.sucursal_id';
        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);

            return;
        }
        $builder->whereIn($column, $branchIds);
    }

    private function latest(?string $left, ?string $right): ?string
    {
        if ($right === null || $right === '') {
            return $left;
        }
        if ($left === null || $left === '') {
            return $right;
        }

        return $right > $left ? $right : $left;
    }

    private function hasTable(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    private function dailyRunTime(): string
    {
        return $this->time((string) env('alerts.dailyRunTime', '07:00'));
    }

    private function time(string $value): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '07:00';
    }

    private function weekday(int $day): string
    {
        return ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][max(1, min(7, $day)) - 1];
    }

    /** @return array<string,array{pending:int,failed:int,sent:int,lastActivityAt:?string}> */
    private function emptyDeliveries(): array
    {
        return [
            'email' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null],
            'webpush' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null],
            'whatsapp' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null],
        ];
    }
}
