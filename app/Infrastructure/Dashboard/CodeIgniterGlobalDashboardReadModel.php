<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\GlobalDashboardReadModel;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final readonly class CodeIgniterGlobalDashboardReadModel implements GlobalDashboardReadModel
{
    private const STALE_READING_DAYS = 7;

    public function __construct(private BaseConnection $database)
    {
    }

    public function fetch(): array
    {
        $now = new DateTimeImmutable('now');
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $thirtyDays = $today->modify('+30 days');
        $readingCutoff = $today->modify('-' . self::STALE_READING_DAYS . ' days');

        $companies = $this->activeCompanies();
        $activeCompanyIds = array_fill_keys(array_map(static fn (array $row): int => (int) $row['id'], $companies), true);
        $companyTotal = $this->countRows('empresas', static fn ($builder) => $builder->where('deleted_at', null));

        $equipment = $this->database->table('equipos e')
            ->select('e.id, e.empresa_id, te.controla_km')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->get()
            ->getResultArray();
        $equipment = array_values(array_filter(
            $equipment,
            static fn (array $row): bool => isset($activeCompanyIds[(int) $row['empresa_id']]),
        ));
        $equipmentByCompany = $this->countRowsByCompany($equipment);

        $latestReadings = [];
        if ($this->database->tableExists('lecturas_equipo')) {
            $rows = $this->database->table('lecturas_equipo')
                ->select('empresa_id, equipo_id, MAX(fecha_lectura) AS ultima_lectura', false)
                ->where('anulada', 0)
                ->where('kilometraje IS NOT NULL', null, false)
                ->groupBy(['empresa_id', 'equipo_id'])
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $latestReadings[(int) $row['empresa_id'] . ':' . (int) $row['equipo_id']] = (string) ($row['ultima_lectura'] ?? '');
            }
        }

        $pendingReadingsByCompany = [];
        foreach ($equipment as $row) {
            if ((int) ($row['controla_km'] ?? 0) !== 1) {
                continue;
            }
            $companyId = (int) $row['empresa_id'];
            $key = $companyId . ':' . (int) $row['id'];
            $lastReading = $latestReadings[$key] ?? '';
            if ($lastReading === '' || new DateTimeImmutable($lastReading) < $readingCutoff) {
                $pendingReadingsByCompany[$companyId] = ($pendingReadingsByCompany[$companyId] ?? 0) + 1;
            }
        }

        $overdueByCompany = $this->expirationCountsByCompany(
            static fn ($builder) => $builder->where('fecha_vencimiento <', $today->format('Y-m-d')),
            $activeCompanyIds,
        );
        $upcomingByCompany = $this->expirationCountsByCompany(
            static fn ($builder) => $builder
                ->where('fecha_vencimiento >=', $today->format('Y-m-d'))
                ->where('fecha_vencimiento <=', $thirtyDays->format('Y-m-d')),
            $activeCompanyIds,
        );
        $notificationAlertsByCompany = $this->notificationAlertsByCompany($activeCompanyIds);

        $companyStatus = [];
        $attention = [];
        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            $equipmentCount = $equipmentByCompany[$companyId] ?? 0;
            $overdue = $overdueByCompany[$companyId] ?? 0;
            $upcoming = $upcomingByCompany[$companyId] ?? 0;
            $pending = $pendingReadingsByCompany[$companyId] ?? 0;
            $notificationAlerts = $notificationAlertsByCompany[$companyId] ?? 0;
            $displayName = trim((string) ($company['nombre_fantasia'] ?: $company['razon_social']));

            $companyStatus[] = [
                'id' => $companyId,
                'name' => $displayName,
                'equipment' => $equipmentCount,
                'overdue' => $overdue,
                'upcoming' => $upcoming,
                'pendingReadings' => $pending,
                'notificationAlerts' => $notificationAlerts,
                'tone' => ($overdue + $notificationAlerts) > 0 ? 'danger' : (($upcoming + $pending) > 0 ? 'warning' : 'success'),
            ];

            $this->appendAttention($attention, $companyId, $displayName, 'Vencimientos vencidos', $overdue, 'danger', 0, 'expirations');
            $this->appendAttention($attention, $companyId, $displayName, 'Errores de notificación', $notificationAlerts, 'danger', 1, 'notifications');
            $this->appendAttention($attention, $companyId, $displayName, 'KM pendientes', $pending, 'warning', 2, 'employees');
            $this->appendAttention($attention, $companyId, $displayName, 'Próximos vencimientos', $upcoming, 'warning', 3, 'expirations');
        }

        usort($attention, static fn (array $left, array $right): int => [$left['priority'], -$left['count']] <=> [$right['priority'], -$right['count']]);
        usort($companyStatus, static fn (array $left, array $right): int => [
            $left['tone'] === 'danger' ? 0 : ($left['tone'] === 'warning' ? 1 : 2),
            -($left['overdue'] + $left['upcoming'] + $left['pendingReadings'] + $left['notificationAlerts']),
            $left['name'],
        ] <=> [
            $right['tone'] === 'danger' ? 0 : ($right['tone'] === 'warning' ? 1 : 2),
            -($right['overdue'] + $right['upcoming'] + $right['pendingReadings'] + $right['notificationAlerts']),
            $right['name'],
        ]);

        $activity = [
            'readingsToday' => $this->countKilometerReadingsToday($today, $tomorrow),
            'renewalsToday' => $this->countInRange('vencimientos', 'created_at', $today, $tomorrow, ['activo' => 1]),
            'evidenceToday' => $this->countInRange('equipo_adjuntos', 'created_at', $today, $tomorrow, ['retirado_at' => null]),
            'whatsappSentToday' => $this->countInRange('notificacion_whatsapp_entregas', 'enviada_en', $today, $tomorrow, ['estado' => 'ENVIADA']),
            'emailSentToday' => $this->countEmailSentToday($today, $tomorrow),
            'chatQueriesToday' => $this->countInRange('mensajes', 'created_at', $today, $tomorrow, ['role' => 'user']),
            'chatResponsesToday' => $this->countInRange('mensajes', 'created_at', $today, $tomorrow, ['role' => 'assistant']),
        ];

        return [
            'metrics' => [
                'companiesActive' => count($companies),
                'companiesTotal' => $companyTotal,
                'equipmentActive' => count($equipment),
                'equipmentTotal' => $this->countRows('equipos', static fn ($builder) => $builder->where('deleted_at', null)),
                'expirationOverdue' => array_sum($overdueByCompany),
                'expirationUpcoming30' => array_sum($upcomingByCompany),
                'pendingReadings' => array_sum($pendingReadingsByCompany),
                'notificationAlerts' => array_sum($notificationAlertsByCompany),
            ],
            'attention' => array_slice($attention, 0, 5),
            'activity' => $activity,
            'companies' => array_slice($companyStatus, 0, 8),
            'communications' => $this->communications(),
            'drivers' => [
                'active' => $this->activeDrivers(),
                'pendingReadings' => array_sum($pendingReadingsByCompany),
                'readingsToday' => $activity['readingsToday'],
            ],
            'chatbot' => [
                'queriesToday' => $activity['chatQueriesToday'],
                'responsesToday' => $activity['chatResponsesToday'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function activeCompanies(): array
    {
        if (! $this->database->tableExists('empresas')) {
            return [];
        }

        return $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre_fantasia', 'ASC')
            ->orderBy('razon_social', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,int>
     */
    private function countRowsByCompany(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $companyId = (int) ($row['empresa_id'] ?? 0);
            if ($companyId > 0) {
                $counts[$companyId] = ($counts[$companyId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param callable(object):object $scope
     * @param array<int,bool> $activeCompanyIds
     * @return array<int,int>
     */
    private function expirationCountsByCompany(callable $scope, array $activeCompanyIds): array
    {
        if (! $this->database->tableExists('vencimientos')) {
            return [];
        }

        $builder = $this->database->table('vencimientos')
            ->select('empresa_id, COUNT(*) AS total', false)
            ->where('activo', 1)
            ->where('deleted_at', null);
        $scope($builder);
        $rows = $builder->groupBy('empresa_id')->get()->getResultArray();

        return $this->groupedRows($rows, $activeCompanyIds);
    }

    /** @param array<int,bool> $activeCompanyIds @return array<int,int> */
    private function notificationAlertsByCompany(array $activeCompanyIds): array
    {
        $counts = [];
        if ($this->database->tableExists('notificacion_entregas') && $this->database->tableExists('notificaciones')) {
            $rows = $this->database->table('notificacion_entregas d')
                ->select('n.empresa_id, COUNT(*) AS total', false)
                ->join('notificaciones n', 'n.id = d.notificacion_id', 'inner')
                ->whereIn('d.estado', ['FALLIDA', 'REINTENTO'])
                ->groupBy('n.empresa_id')
                ->get()
                ->getResultArray();
            $counts = $this->groupedRows($rows, $activeCompanyIds);
        }
        if ($this->database->tableExists('notificacion_empresa_entregas')) {
            $rows = $this->database->table('notificacion_empresa_entregas')
                ->select('empresa_id, COUNT(*) AS total', false)
                ->whereIn('estado', ['FALLIDA', 'REINTENTO'])
                ->groupBy('empresa_id')
                ->get()
                ->getResultArray();
            foreach ($this->groupedRows($rows, $activeCompanyIds) as $companyId => $total) {
                $counts[$companyId] = ($counts[$companyId] ?? 0) + $total;
            }
        }
        if ($this->database->tableExists('notificacion_whatsapp_entregas')) {
            $rows = $this->database->table('notificacion_whatsapp_entregas')
                ->select('empresa_id, COUNT(*) AS total', false)
                ->whereIn('estado', ['FALLIDA', 'REINTENTO'])
                ->groupBy('empresa_id')
                ->get()
                ->getResultArray();
            foreach ($this->groupedRows($rows, $activeCompanyIds) as $companyId => $total) {
                $counts[$companyId] = ($counts[$companyId] ?? 0) + $total;
            }
        }

        return $counts;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,bool> $activeCompanyIds
     * @return array<int,int>
     */
    private function groupedRows(array $rows, array $activeCompanyIds): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $companyId = (int) ($row['empresa_id'] ?? 0);
            if ($companyId > 0 && isset($activeCompanyIds[$companyId])) {
                $counts[$companyId] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /** @param list<array<string,mixed>> $attention */
    private function appendAttention(array &$attention, int $companyId, string $companyName, string $label, int $count, string $tone, int $priority, string $actionKey): void
    {
        if ($count <= 0) {
            return;
        }

        $attention[] = [
            'companyId' => $companyId,
            'company' => $companyName,
            'label' => $label,
            'count' => $count,
            'tone' => $tone,
            'priority' => $priority,
            'actionKey' => $actionKey,
        ];
    }

    /** @param array<string,mixed> $where */
    private function countInRange(string $table, string $field, DateTimeImmutable $from, DateTimeImmutable $to, array $where = []): int
    {
        if (! $this->database->tableExists($table)) {
            return 0;
        }

        $builder = $this->database->table($table)
            ->where($field . ' >=', $from->format('Y-m-d H:i:s'))
            ->where($field . ' <', $to->format('Y-m-d H:i:s'));
        foreach ($where as $column => $value) {
            $builder->where($column, $value);
        }

        return (int) $builder->countAllResults();
    }

    private function countKilometerReadingsToday(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if (! $this->database->tableExists('lecturas_equipo')) {
            return 0;
        }

        return (int) $this->database->table('lecturas_equipo')
            ->where('anulada', 0)
            ->where('kilometraje IS NOT NULL', null, false)
            ->where('fecha_lectura >=', $from->format('Y-m-d H:i:s'))
            ->where('fecha_lectura <', $to->format('Y-m-d H:i:s'))
            ->countAllResults();
    }

    private function countEmailSentToday(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if (! $this->database->tableExists('notificacion_entregas')) {
            return 0;
        }

        $total = (int) $this->database->table('notificacion_entregas')
            ->where('canal', 'EMAIL')
            ->where('estado', 'ENVIADA')
            ->where('enviada_en >=', $from->format('Y-m-d H:i:s'))
            ->where('enviada_en <', $to->format('Y-m-d H:i:s'))
            ->countAllResults();

        if ($this->database->tableExists('notificacion_empresa_entregas')) {
            $total += (int) $this->database->table('notificacion_empresa_entregas')
                ->where('estado', 'ENVIADA')
                ->where('enviada_en >=', $from->format('Y-m-d H:i:s'))
                ->where('enviada_en <', $to->format('Y-m-d H:i:s'))
                ->countAllResults();
        }

        return $total;
    }

    private function activeDrivers(): int
    {
        if (! $this->database->tableExists('employee_equipment_assignments') || ! $this->database->tableExists('empleados')) {
            return 0;
        }

        $row = $this->database->table('employee_equipment_assignments a')
            ->select('COUNT(DISTINCT a.empleado_id) AS total', false)
            ->join('empleados e', 'e.id = a.empleado_id AND e.empresa_id = a.empresa_id', 'inner')
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('e.activo', 1)
            ->where('e.deleted_at', null)
            ->get()
            ->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function communications(): array
    {
        $smtp = false;
        $whatsapp = false;
        if ($this->database->tableExists('configuracion_canales_globales')) {
            $settings = $this->database->table('configuracion_canales_globales')->where('id', 1)->get()->getRowArray() ?? [];
            $smtp = (int) ($settings['smtp_enabled'] ?? 0) === 1
                && trim((string) ($settings['smtp_host'] ?? '')) !== ''
                && trim((string) ($settings['smtp_from_email'] ?? '')) !== '';
            $whatsapp = (int) ($settings['whatsapp_enabled'] ?? 0) === 1
                && trim((string) ($settings['whatsapp_api_url'] ?? '')) !== ''
                && trim((string) ($settings['whatsapp_api_key_encrypted'] ?? '')) !== '';
        }

        $cron = ['status' => 'Sin ejecuciones', 'tone' => 'muted', 'lastRun' => null];
        if ($this->database->tableExists('ejecuciones_programadas')) {
            $row = $this->database->table('ejecuciones_programadas')
                ->select('estado, fecha_inicio')
                ->orderBy('fecha_inicio', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
            if ($row !== null) {
                $state = strtoupper((string) ($row['estado'] ?? ''));
                $ok = in_array($state, ['OK', 'COMPLETADA', 'COMPLETADO', 'SUCCESS'], true);
                $cron = [
                    'status' => $ok ? 'Operativo' : ($state !== '' ? ucfirst(strtolower($state)) : 'Sin estado'),
                    'tone' => $ok ? 'success' : 'warning',
                    'lastRun' => $row['fecha_inicio'] ?? null,
                ];
            }
        }

        return [
            'whatsapp' => ['status' => $whatsapp ? 'Configurado' : 'Revisar configuración', 'tone' => $whatsapp ? 'success' : 'warning'],
            'email' => ['status' => $smtp ? 'Configurado' : 'Revisar configuración', 'tone' => $smtp ? 'success' : 'warning'],
            'cron' => $cron,
        ];
    }

    /** @param callable(object):object $scope */
    private function countRows(string $table, callable $scope): int
    {
        if (! $this->database->tableExists($table)) {
            return 0;
        }

        $builder = $this->database->table($table);
        $scope($builder);

        return (int) $builder->countAllResults();
    }
}
