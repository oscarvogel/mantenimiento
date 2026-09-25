<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;

final class ScheduleManagementReports
{
    public function __construct(
        private readonly NotificationClock $clock,
        private readonly BaseConnection $db,
        private readonly int $staleReadingDays = 30,
    ) {
    }

    /** @return array{companies:int,queued:int,duplicates:int,skipped:int} */
    public function execute(): array
    {
        if (! $this->available()) {
            return ['companies' => 0, 'queued' => 0, 'duplicates' => 0, 'skipped' => 0];
        }

        $rows = $this->db->table('empresas')
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()->getResultArray();

        $summary = ['companies' => count($rows), 'queued' => 0, 'duplicates' => 0, 'skipped' => 0];
        foreach ($rows as $company) {
            foreach (['DAILY', 'WEEKLY'] as $type) {
                $result = $this->queueCompany((int) $company['id'], $type, false, $company);
                $summary[$result] = ($summary[$result] ?? 0) + 1;
            }
        }

        return $summary;
    }

    public function queueTest(int $companyId, string $type = 'DAILY'): string
    {
        if (! $this->available()) {
            throw new DomainException('Primero aplicá la migración de informes gerenciales.');
        }

        $company = $this->db->table('empresas')->where('id', $companyId)->where('deleted_at', null)->get()->getRowArray();
        if ($company === null) {
            throw new DomainException('La empresa no existe.');
        }

        return $this->queueCompany($companyId, strtoupper($type), true, $company);
    }

    /** @param array<string,mixed>|null $company */
    private function queueCompany(int $companyId, string $type, bool $force, ?array $company = null): string
    {
        if (! in_array($type, ['DAILY', 'WEEKLY'], true)) {
            throw new DomainException('El tipo de informe no es válido.');
        }

        $company ??= $this->db->table('empresas')->where('id', $companyId)->where('deleted_at', null)->get()->getRowArray();
        if ($company === null || (int) ($company['estado'] ?? 0) !== 1) {
            return 'skipped';
        }

        $now = $this->clock->now();
        if (! $force && ! $this->isDue($company, $type, $now)) {
            return 'skipped';
        }

        $recipients = $this->recipients($company);
        if ($recipients === []) {
            return 'skipped';
        }

        $period = $type === 'DAILY' ? $now->format('Y-m-d') : $now->format('o-\WW');
        $companyName = trim((string) ($company['nombre_fantasia'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($company['razon_social'] ?? ''));
        }
        if ($companyName === '') {
            $companyName = 'Empresa #' . $companyId;
        }

        $report = $this->buildReport($companyId, $type, $now, $companyName);
        $queued = 0;
        $duplicates = 0;

        foreach ($recipients as $recipient) {
            $suffix = $force ? ':test:' . $now->format('YmdHis') : '';
            $key = 'management:' . strtolower($type) . ':company:' . $companyId . ':period:' . $period . ':to:' . substr(hash('sha256', $recipient), 0, 16) . $suffix;
            $this->db->table('notificacion_empresa_entregas')->ignore(true)->insert([
                'empresa_id' => $companyId,
                'tipo_evento' => $type === 'DAILY' ? 'informe.gerencial.diario' : 'informe.gerencial.semanal',
                'destinatario' => $recipient,
                'clave_entrega' => $key,
                'titulo' => $report['title'],
                'resumen' => $report['summary'],
                'url' => $this->reportUrl(),
                'estado' => 'PENDIENTE',
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            if ($this->db->affectedRows() > 0) {
                $queued++;
            } else {
                $duplicates++;
            }
        }

        if ($queued > 0) {
            return 'queued';
        }
        if ($duplicates > 0) {
            return 'duplicates';
        }

        return 'skipped';
    }

    /** @param array<string,mixed> $company */
    private function isDue(array $company, string $type, DateTimeImmutable $now): bool
    {
        if ($type === 'DAILY') {
            return (int) ($company['informe_diario_habilitado'] ?? 0) === 1
                && $now->format('H:i') >= $this->time((string) ($company['informe_diario_hora'] ?? '07:00'));
        }

        return (int) ($company['informe_semanal_habilitado'] ?? 0) === 1
            && (int) $now->format('N') === max(1, min(7, (int) ($company['informe_semanal_dia'] ?? 1)))
            && $now->format('H:i') >= $this->time((string) ($company['informe_semanal_hora'] ?? '07:00'));
    }

    /** @param array<string,mixed> $company @return list<string> */
    private function recipients(array $company): array
    {
        $raw = trim((string) ($company['emails_informes'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($company['email_notificaciones'] ?? ''));
        }
        if ($raw === '') {
            $raw = trim((string) ($company['email'] ?? ''));
        }

        $parts = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $valid = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[strtolower($email)] = $email;
            }
        }

        return array_values($valid);
    }

    /** @return array{title:string,summary:string} */
    private function buildReport(int $companyId, string $type, DateTimeImmutable $now, string $companyName): array
    {
        $today = $now->format('Y-m-d');
        $weekStart = $now->modify('-6 days')->format('Y-m-d 00:00:00');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d 00:00:00');

        $activeEquipment = $this->count('equipos', ['empresa_id' => $companyId, 'estado' => 'ACTIVO', 'deleted_at' => null]);
        $overdueExpirations = $this->expirationCount($companyId, '<', $today);
        $upcomingExpirations = $this->expirationCount($companyId, '>=', $today, $now->modify('+30 days')->format('Y-m-d'));
        $openOrders = $this->openOrders($companyId);
        $delayedOrders = $this->delayedOrders($companyId, $now);
        $periodStart = $type === 'DAILY' ? $now->modify('-1 day')->format('Y-m-d H:i:s') : $weekStart;
        $closedPeriod = $this->closedOrders($companyId, $periodStart, $tomorrow);
        $createdPeriod = $this->createdOrders($companyId, $periodStart, $tomorrow);
        $staleReadings = $this->staleReadings($companyId, $now);
        $staleReadingDetails = $this->staleReadingDetails($companyId, $now, 10);

        $label = $type === 'DAILY' ? 'Informe diario' : 'Informe semanal';
        $lines = [
            'Equipos activos: ' . $activeEquipment,
            'Vencimientos vencidos: ' . $overdueExpirations,
            'Vencimientos próximos (30 días): ' . $upcomingExpirations,
            'Órdenes abiertas: ' . $openOrders,
            'Órdenes demoradas: ' . $delayedOrders,
            'Órdenes creadas en el período: ' . $createdPeriod,
            'Órdenes cerradas en el período: ' . $closedPeriod,
            'Equipos sin lectura reciente: ' . $staleReadings,
        ];

        foreach ($staleReadingDetails as $detail) {
            $lines[] = '!LECTURA|' . $detail['code'] . '|' . $detail['detail'] . '|' . $detail['status'];
        }
        if ($staleReadings > count($staleReadingDetails)) {
            $lines[] = '!LECTURA_MAS|' . ($staleReadings - count($staleReadingDetails));
        }

        if ($type === 'WEEKLY') {
            $missingWeeklyKm = $this->weeklyMissingDriverKilometers($companyId, $now, 25);
            $lines[] = 'Choferes sin carga de km esta semana: ' . count($missingWeeklyKm);
            foreach ($missingWeeklyKm as $item) {
                $lines[] = '!KM_SEMANAL|' . $item['driver'] . '|' . $item['equipment'] . '|' . $item['last_reading'];
            }

            $lines[] = 'Preventivos pendientes: ' . $this->preventiveOrdersPending($companyId);
            $lines[] = 'Preventivos finalizados en la semana: ' . $this->preventiveOrdersClosed($companyId, $periodStart, $tomorrow);
            $lines[] = 'Costo registrado en OT cerradas: $ ' . number_format($this->closedOrderCost($companyId, $periodStart, $tomorrow), 2, ',', '.');
            $top = $this->topEquipmentByOrders($companyId, $periodStart, $tomorrow);
            if ($top !== []) {
                $lines[] = 'Equipos con más OT: ' . implode(', ', $top);
            }
        }

        return [
            'title' => $label . ' de mantenimiento · ' . $companyName . ' · ' . $now->format('d/m/Y'),
            'summary' => 'Empresa: ' . $companyName . "\n" . implode("\n", $lines),
        ];
    }

    /** @return list<array{driver:string,equipment:string,last_reading:string}> */
    private function weeklyMissingDriverKilometers(int $companyId, DateTimeImmutable $now, int $limit = 25): array
    {
        foreach (['employee_equipment_assignments', 'empleados', 'equipos', 'tipos_equipo', 'lecturas_equipo'] as $table) {
            if (! $this->db->tableExists($table)) {
                return [];
            }
        }

        $weekStart = $now->modify('monday this week')->setTime(0, 0)->format('Y-m-d H:i:s');
        $rows = $this->db->query(
            "SELECT
                a.equipo_id,
                TRIM(CONCAT_WS(' ', emp.nombre, emp.apellido)) chofer,
                e.codigo,
                e.patente,
                MAX(l.fecha_lectura) ultima_lectura,
                MAX(CASE WHEN l.fecha_lectura >= ? AND l.kilometraje IS NOT NULL AND l.anulada = 0 THEN 1 ELSE 0 END) cargo_semana
             FROM employee_equipment_assignments a
             INNER JOIN empleados emp
                ON emp.id = a.empleado_id
               AND emp.empresa_id = a.empresa_id
             INNER JOIN equipos e
                ON e.id = a.equipo_id
               AND e.empresa_id = a.empresa_id
             INNER JOIN tipos_equipo te
                ON te.id = e.tipo_equipo_id
             LEFT JOIN lecturas_equipo l
                ON l.empresa_id = e.empresa_id
               AND l.equipo_id = e.id
               AND l.kilometraje IS NOT NULL
               AND l.anulada = 0
             WHERE a.empresa_id = ?
               AND a.rol = 'CHOFER'
               AND a.fecha_hasta IS NULL
               AND emp.activo = 1
               AND emp.deleted_at IS NULL
               AND e.estado = 'ACTIVO'
               AND e.deleted_at IS NULL
               AND te.controla_km = 1
             GROUP BY a.equipo_id, emp.id, emp.nombre, emp.apellido, e.codigo, e.patente
             HAVING cargo_semana = 0
             ORDER BY chofer ASC, e.codigo ASC
             LIMIT " . max(1, min(100, $limit)),
            [$weekStart, $companyId],
        )->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $driver = trim((string) ($row['chofer'] ?? ''));
            if ($driver === '') {
                $driver = 'Chofer sin nombre';
            }

            $code = trim((string) ($row['codigo'] ?? ''));
            $plate = trim((string) ($row['patente'] ?? ''));
            $equipment = $code !== '' ? $code : ('Equipo #' . (int) ($row['equipo_id'] ?? 0));
            if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipment)) {
                $equipment .= ' · ' . $plate;
            }

            $lastReading = trim((string) ($row['ultima_lectura'] ?? ''));
            if ($lastReading === '') {
                $lastReadingText = 'Sin lecturas previas';
            } else {
                try {
                    $lastReadingText = (new DateTimeImmutable($lastReading))->format('d/m/Y H:i');
                } catch (\Throwable) {
                    $lastReadingText = 'Lectura anterior registrada';
                }
            }

            $result[] = [
                'driver' => $driver,
                'equipment' => $equipment,
                'last_reading' => $lastReadingText,
            ];
        }

        return $result;
    }

    private function expirationCount(int $companyId, string $operator, string $date, ?string $upper = null): int
    {
        if (! $this->db->tableExists('vencimientos')) {
            return 0;
        }
        $builder = $this->db->table('vencimientos')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where('fecha_vencimiento ' . $operator, $date);
        if ($upper !== null) {
            $builder->where('fecha_vencimiento <=', $upper);
        }
        return $builder->countAllResults();
    }

    private function openOrders(int $companyId): int
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }
        return $this->db->table('ordenes_trabajo')->where('empresa_id', $companyId)
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])->countAllResults();
    }

    private function delayedOrders(int $companyId, DateTimeImmutable $now): int
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }
        return $this->db->table('ordenes_trabajo')->where('empresa_id', $companyId)
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
            ->where('fecha_objetivo IS NOT NULL', null, false)
            ->where('fecha_objetivo <', $now->format('Y-m-d H:i:s'))->countAllResults();
    }

    private function closedOrders(int $companyId, string $from, string $to): int
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }
        return $this->db->table('ordenes_trabajo')->where('empresa_id', $companyId)
            ->where('estado', 'FINALIZADA')->where('fecha_finalizacion >=', $from)->where('fecha_finalizacion <', $to)->countAllResults();
    }

    private function createdOrders(int $companyId, string $from, string $to): int
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }
        return $this->db->table('ordenes_trabajo')->where('empresa_id', $companyId)
            ->where('created_at >=', $from)->where('created_at <', $to)->countAllResults();
    }

    private function preventiveOrdersPending(int $companyId): int
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }

        return $this->db->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('plan_id IS NOT NULL', null, false)
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
            ->countAllResults();
    }

    private function preventiveOrdersClosed(int $companyId, string $from, string $to): int
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }

        return $this->db->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('plan_id IS NOT NULL', null, false)
            ->where('estado', 'FINALIZADA')
            ->where('fecha_finalizacion >=', $from)
            ->where('fecha_finalizacion <', $to)
            ->countAllResults();
    }

    private function closedOrderCost(int $companyId, string $from, string $to): float
    {
        if (! $this->db->tableExists('ordenes_trabajo')) {
            return 0.0;
        }

        $row = $this->db->table('ordenes_trabajo')
            ->selectSum('costo_total', 'total')
            ->where('empresa_id', $companyId)
            ->where('estado', 'FINALIZADA')
            ->where('fecha_finalizacion >=', $from)
            ->where('fecha_finalizacion <', $to)
            ->get()->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    /** @return list<string> */
    private function topEquipmentByOrders(int $companyId, string $from, string $to): array
    {
        if (! $this->db->tableExists('ordenes_trabajo') || ! $this->db->tableExists('equipos')) {
            return [];
        }

        $rows = $this->db->table('ordenes_trabajo o')
            ->select('e.codigo, COUNT(o.id) cantidad')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->where('o.empresa_id', $companyId)
            ->where('o.created_at >=', $from)
            ->where('o.created_at <', $to)
            ->groupBy(['e.id', 'e.codigo'])
            ->orderBy('cantidad', 'DESC')
            ->orderBy('e.codigo', 'ASC')
            ->limit(3)
            ->get()->getResultArray();

        return array_map(
            static fn (array $row): string => (string) $row['codigo'] . ' (' . (int) $row['cantidad'] . ')',
            $rows,
        );
    }

    private function staleReadings(int $companyId, DateTimeImmutable $now): int
    {
        if (! $this->db->tableExists('equipos') || ! $this->db->tableExists('lecturas_equipo')) {
            return 0;
        }
        $cutoff = $now->modify('-' . max(1, $this->staleReadingDays) . ' days')->format('Y-m-d H:i:s');
        $row = $this->db->query(
            "SELECT COUNT(*) total FROM equipos e
             LEFT JOIN (SELECT equipo_id, MAX(fecha_lectura) ultima FROM lecturas_equipo WHERE empresa_id = ? AND anulada = 0 GROUP BY equipo_id) l ON l.equipo_id = e.id
             WHERE e.empresa_id = ? AND e.estado = 'ACTIVO' AND e.deleted_at IS NULL AND (l.ultima IS NULL OR l.ultima < ?)",
            [$companyId, $companyId, $cutoff],
        )->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    /** @return list<array{code:string,detail:string,status:string}> */
    private function staleReadingDetails(int $companyId, DateTimeImmutable $now, int $limit = 10): array
    {
        if (! $this->db->tableExists('equipos') || ! $this->db->tableExists('lecturas_equipo')) {
            return [];
        }

        $cutoff = $now->modify('-' . max(1, $this->staleReadingDays) . ' days')->format('Y-m-d H:i:s');
        $rows = $this->db->query(
            "SELECT e.codigo, l.ultima
             FROM equipos e
             LEFT JOIN (
                 SELECT equipo_id, MAX(fecha_lectura) ultima
                 FROM lecturas_equipo
                 WHERE empresa_id = ? AND anulada = 0
                 GROUP BY equipo_id
             ) l ON l.equipo_id = e.id
             WHERE e.empresa_id = ?
               AND e.estado = 'ACTIVO'
               AND e.deleted_at IS NULL
               AND (l.ultima IS NULL OR l.ultima < ?)
             ORDER BY CASE WHEN l.ultima IS NULL THEN 0 ELSE 1 END ASC, l.ultima ASC, e.codigo ASC
             LIMIT " . max(1, min(50, $limit)),
            [$companyId, $companyId, $cutoff],
        )->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['codigo'] ?? ''));
            if ($code === '') {
                $code = 'Equipo sin código';
            }

            $lastReading = trim((string) ($row['ultima'] ?? ''));
            if ($lastReading === '') {
                $result[] = [
                    'code' => $code,
                    'detail' => 'Nunca registró km/horas',
                    'status' => 'Sin lectura',
                ];
                continue;
            }

            try {
                $last = new DateTimeImmutable($lastReading);
                $days = max(0, (int) $last->diff($now)->format('%a'));
                $result[] = [
                    'code' => $code,
                    'detail' => 'Hace ' . $days . ' días que no registra km/horas',
                    'status' => 'Lectura antigua',
                ];
            } catch (\Throwable) {
                $result[] = [
                    'code' => $code,
                    'detail' => 'La última lectura registrada es demasiado antigua',
                    'status' => 'Lectura antigua',
                ];
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $where */
    private function count(string $table, array $where): int
    {
        if (! $this->db->tableExists($table)) {
            return 0;
        }
        $builder = $this->db->table($table);
        foreach ($where as $field => $value) {
            $builder->where($field, $value);
        }
        return $builder->countAllResults();
    }

    private function available(): bool
    {
        return $this->db->tableExists('notificacion_empresa_entregas')
            && $this->db->fieldExists('informe_diario_habilitado', 'empresas')
            && $this->db->fieldExists('informe_semanal_habilitado', 'empresas');
    }

    private function time(string $value): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '07:00';
    }

    private function reportUrl(): string
    {
        return (string) parse_url(base_url('dashboard'), PHP_URL_PATH);
    }
}
