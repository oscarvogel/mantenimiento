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

    public function fetch(?int $companyId = null): array
    {
        $now = new DateTimeImmutable('now');
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $thirtyDays = $today->modify('+30 days');
        $readingCutoff = $today->modify('-' . self::STALE_READING_DAYS . ' days');

        $allCompanies = $this->activeCompanies();
        $selectedCompany = $this->selectedCompany($allCompanies, $companyId);
        $scopeCompanyId = $selectedCompany === null ? null : (int) $selectedCompany['id'];
        $companies = $selectedCompany === null ? $allCompanies : [$selectedCompany];

        $activeCompanyIds = array_fill_keys(
            array_map(static fn (array $row): int => (int) $row['id'], $companies),
            true,
        );

        $companyTotal = $scopeCompanyId === null
            ? $this->countRows('empresas', static fn ($builder) => $builder->where('deleted_at', null))
            : 1;

        $equipmentBuilder = $this->database->table('equipos e')
            ->select('e.id, e.empresa_id, te.controla_km')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null);
        if ($scopeCompanyId !== null) {
            $equipmentBuilder->where('e.empresa_id', $scopeCompanyId);
        }
        $equipment = $equipmentBuilder->get()->getResultArray();
        $equipmentByCompany = $this->countRowsByCompany($equipment);

        $latestReadings = [];
        if ($this->database->tableExists('lecturas_equipo')) {
            $readingBuilder = $this->database->table('lecturas_equipo')
                ->select('empresa_id, equipo_id, MAX(fecha_lectura) AS ultima_lectura', false)
                ->where('anulada', 0)
                ->where('kilometraje IS NOT NULL', null, false);
            if ($scopeCompanyId !== null) {
                $readingBuilder->where('empresa_id', $scopeCompanyId);
            }
            $rows = $readingBuilder
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

            $equipmentCompanyId = (int) $row['empresa_id'];
            $key = $equipmentCompanyId . ':' . (int) $row['id'];
            $lastReading = $latestReadings[$key] ?? '';
            if ($lastReading === '' || new DateTimeImmutable($lastReading) < $readingCutoff) {
                $pendingReadingsByCompany[$equipmentCompanyId] = ($pendingReadingsByCompany[$equipmentCompanyId] ?? 0) + 1;
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
            $currentCompanyId = (int) $company['id'];
            $equipmentCount = $equipmentByCompany[$currentCompanyId] ?? 0;
            $overdue = $overdueByCompany[$currentCompanyId] ?? 0;
            $upcoming = $upcomingByCompany[$currentCompanyId] ?? 0;
            $pending = $pendingReadingsByCompany[$currentCompanyId] ?? 0;
            $notificationAlerts = $notificationAlertsByCompany[$currentCompanyId] ?? 0;
            $displayName = $this->companyName($company);

            $companyStatus[] = [
                'id' => $currentCompanyId,
                'name' => $displayName,
                'equipment' => $equipmentCount,
                'overdue' => $overdue,
                'upcoming' => $upcoming,
                'pendingReadings' => $pending,
                'notificationAlerts' => $notificationAlerts,
                'tone' => ($overdue + $notificationAlerts) > 0 ? 'danger' : (($upcoming + $pending) > 0 ? 'warning' : 'success'),
            ];

            $this->appendAttention($attention, $currentCompanyId, $displayName, 'Vencimientos vencidos', $overdue, 'danger', 0, 'expirations');
            $this->appendAttention($attention, $currentCompanyId, $displayName, 'Errores de notificación', $notificationAlerts, 'danger', 1, 'notifications');
            $this->appendAttention($attention, $currentCompanyId, $displayName, 'KM pendientes', $pending, 'warning', 2, 'employees');
            $this->appendAttention($attention, $currentCompanyId, $displayName, 'Próximos vencimientos', $upcoming, 'warning', 3, 'expirations');
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
            'readingsToday' => $this->countKilometerReadingsToday($today, $tomorrow, $scopeCompanyId),
            'renewalsToday' => $this->countInRange('vencimientos', 'created_at', $today, $tomorrow, ['activo' => 1], $scopeCompanyId),
            'evidenceToday' => $this->countInRange('equipo_adjuntos', 'created_at', $today, $tomorrow, ['retirado_at' => null], $scopeCompanyId),
            'whatsappSentToday' => $this->countInRange('notificacion_whatsapp_entregas', 'enviada_en', $today, $tomorrow, ['estado' => 'ENVIADA'], $scopeCompanyId),
            'emailSentToday' => $this->countEmailSentToday($today, $tomorrow, $scopeCompanyId),
            'chatQueriesToday' => $this->countChatMessagesToday('user', $today, $tomorrow, $scopeCompanyId),
            'chatResponsesToday' => $this->countChatMessagesToday('assistant', $today, $tomorrow, $scopeCompanyId),
        ];

        return [
            'filters' => [
                'selectedCompanyId' => $scopeCompanyId,
                'selectedCompanyName' => $selectedCompany === null ? 'Todas las empresas' : $this->companyName($selectedCompany),
                'companies' => array_map(fn (array $company): array => [
                    'id' => (int) $company['id'],
                    'name' => $this->companyName($company),
                ], $allCompanies),
            ],
            'metrics' => [
                'companiesActive' => count($companies),
                'companiesTotal' => $companyTotal,
                'equipmentActive' => count($equipment),
                'equipmentTotal' => $this->equipmentTotal($scopeCompanyId),
                'expirationOverdue' => array_sum($overdueByCompany),
                'expirationUpcoming30' => array_sum($upcomingByCompany),
                'pendingReadings' => array_sum($pendingReadingsByCompany),
                'notificationAlerts' => array_sum($notificationAlertsByCompany),
            ],
            'attention' => array_slice($attention, 0, 5),
            'activity' => $activity,
            'companies' => array_slice($companyStatus, 0, 8),
            'communications' => $this->communications($scopeCompanyId),
            'drivers' => [
                'active' => $this->activeDrivers($scopeCompanyId),
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
     * @param list<array<string,mixed>> $companies
     * @return array<string,mixed>|null
     */
    private function selectedCompany(array $companies, ?int $companyId): ?array
    {
        if ($companyId === null || $companyId <= 0) {
            return null;
        }

        foreach ($companies as $company) {
            if ((int) ($company['id'] ?? 0) === $companyId) {
                return $company;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $company */
    private function companyName(array $company): string
    {
        $fantasyName = trim((string) ($company['nombre_fantasia'] ?? ''));

        return $fantasyName !== '' ? $fantasyName : trim((string) ($company['razon_social'] ?? 'Empresa'));
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
        if (! $this->database->tableExists('vencimientos') || $activeCompanyIds === []) {
            return [];
        }

        $builder = $this->database->table('vencimientos')
            ->select('empresa_id, COUNT(*) AS total', false)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->whereIn('empresa_id', array_keys($activeCompanyIds));
        $scope($builder);
        $rows = $builder->groupBy('empresa_id')->get()->getResultArray();

        return $this->groupedRows($rows, $activeCompanyIds);
    }

    /** @param array<int,bool> $activeCompanyIds @return array<int,int> */
    private function notificationAlertsByCompany(array $activeCompanyIds): array
    {
        if ($activeCompanyIds === []) {
            return [];
        }

        $companyIds = array_keys($activeCompanyIds);
        $counts = [];
        if ($this->database->tableExists('notificacion_entregas') && $this->database->tableExists('notificaciones')) {
            $rows = $this->database->table('notificacion_entregas d')
                ->select('n.empresa_id, COUNT(*) AS total', false)
                ->join('notificaciones n', 'n.id = d.notificacion_id', 'inner')
                ->whereIn('n.empresa_id', $companyIds)
                ->whereIn('d.estado', ['FALLIDA', 'REINTENTO'])
                ->groupBy('n.empresa_id')
                ->get()
                ->getResultArray();
            $counts = $this->groupedRows($rows, $activeCompanyIds);
        }
        if ($this->database->tableExists('notificacion_empresa_entregas')) {
            $rows = $this->database->table('notificacion_empresa_entregas')
                ->select('empresa_id, COUNT(*) AS total', false)
                ->whereIn('empresa_id', $companyIds)
                ->whereIn('estado', ['FALLIDA', 'REINTENTO'])
                ->groupBy('empresa_id')
                ->get()
                ->getResultArray();
            foreach ($this->groupedRows($rows, $activeCompanyIds) as $currentCompanyId => $total) {
                $counts[$currentCompanyId] = ($counts[$currentCompanyId] ?? 0) + $total;
            }
        }
        if ($this->database->tableExists('notificacion_whatsapp_entregas')) {
            $rows = $this->database->table('notificacion_whatsapp_entregas')
                ->select('empresa_id, COUNT(*) AS total', false)
                ->whereIn('empresa_id', $companyIds)
                ->whereIn('estado', ['FALLIDA', 'REINTENTO'])
                ->groupBy('empresa_id')
                ->get()
                ->getResultArray();
            foreach ($this->groupedRows($rows, $activeCompanyIds) as $currentCompanyId => $total) {
                $counts[$currentCompanyId] = ($counts[$currentCompanyId] ?? 0) + $total;
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
    private function countInRange(string $table, string $field, DateTimeImmutable $from, DateTimeImmutable $to, array $where = [], ?int $companyId = null): int
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
        if ($companyId !== null) {
            $builder->where('empresa_id', $companyId);
        }

        return (int) $builder->countAllResults();
    }

    private function countKilometerReadingsToday(DateTimeImmutable $from, DateTimeImmutable $to, ?int $companyId): int
    {
        if (! $this->database->tableExists('lecturas_equipo')) {
            return 0;
        }

        $builder = $this->database->table('lecturas_equipo')
            ->where('anulada', 0)
            ->where('kilometraje IS NOT NULL', null, false)
            ->where('fecha_lectura >=', $from->format('Y-m-d H:i:s'))
            ->where('fecha_lectura <', $to->format('Y-m-d H:i:s'));
        if ($companyId !== null) {
            $builder->where('empresa_id', $companyId);
        }

        return (int) $builder->countAllResults();
    }

    private function countEmailSentToday(DateTimeImmutable $from, DateTimeImmutable $to, ?int $companyId): int
    {
        $total = 0;

        if ($this->database->tableExists('notificacion_entregas') && $this->database->tableExists('notificaciones')) {
            $builder = $this->database->table('notificacion_entregas d')
                ->join('notificaciones n', 'n.id = d.notificacion_id', 'inner')
                ->where('d.canal', 'EMAIL')
                ->where('d.estado', 'ENVIADA')
                ->where('d.enviada_en >=', $from->format('Y-m-d H:i:s'))
                ->where('d.enviada_en <', $to->format('Y-m-d H:i:s'));
            if ($companyId !== null) {
                $builder->where('n.empresa_id', $companyId);
            }
            $total += (int) $builder->countAllResults();
        }

        if ($this->database->tableExists('notificacion_empresa_entregas')) {
            $builder = $this->database->table('notificacion_empresa_entregas')
                ->where('estado', 'ENVIADA')
                ->where('enviada_en >=', $from->format('Y-m-d H:i:s'))
                ->where('enviada_en <', $to->format('Y-m-d H:i:s'));
            if ($companyId !== null) {
                $builder->where('empresa_id', $companyId);
            }
            $total += (int) $builder->countAllResults();
        }

        return $total;
    }

    private function countChatMessagesToday(string $role, DateTimeImmutable $from, DateTimeImmutable $to, ?int $companyId): int
    {
        if (! $this->database->tableExists('mensajes') || ! $this->database->tableExists('conversaciones')) {
            return 0;
        }

        $builder = $this->database->table('mensajes m')
            ->join('conversaciones c', 'c.id = m.conversacion_id', 'inner')
            ->where('m.role', $role)
            ->where('m.created_at >=', $from->format('Y-m-d H:i:s'))
            ->where('m.created_at <', $to->format('Y-m-d H:i:s'));
        if ($companyId !== null) {
            $builder->where('c.empresa_id', $companyId);
        }

        return (int) $builder->countAllResults();
    }

    private function activeDrivers(?int $companyId): int
    {
        if (! $this->database->tableExists('employee_equipment_assignments') || ! $this->database->tableExists('empleados')) {
            return 0;
        }

        $builder = $this->database->table('employee_equipment_assignments a')
            ->select('COUNT(DISTINCT a.empleado_id) AS total', false)
            ->join('empleados e', 'e.id = a.empleado_id AND e.empresa_id = a.empresa_id', 'inner')
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('e.activo', 1)
            ->where('e.deleted_at', null);
        if ($companyId !== null) {
            $builder->where('a.empresa_id', $companyId);
        }
        $row = $builder->get()->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function communications(?int $companyId): array
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

        if ($companyId !== null && $this->database->tableExists('empresas')) {
            $company = $this->database->table('empresas')
                ->select('email, email_notificaciones, notificaciones_email_habilitadas, notificaciones_whatsapp_habilitadas')
                ->where('id', $companyId)
                ->get()
                ->getRowArray() ?? [];

            $companyEmail = trim((string) ($company['email_notificaciones'] ?? ''));
            if ($companyEmail === '') {
                $companyEmail = trim((string) ($company['email'] ?? ''));
            }
            $smtp = $smtp
                && (int) ($company['notificaciones_email_habilitadas'] ?? 0) === 1
                && $companyEmail !== '';
            $whatsapp = $whatsapp
                && (int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) === 1;
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

    private function equipmentTotal(?int $companyId): int
    {
        if (! $this->database->tableExists('equipos')) {
            return 0;
        }

        $builder = $this->database->table('equipos')->where('deleted_at', null);
        if ($companyId !== null) {
            $builder->where('empresa_id', $companyId);
        }

        return (int) $builder->countAllResults();
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
