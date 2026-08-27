<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\ClosePreventiveOrder;
use App\Application\MaintenanceCircuit\RegisterReadingAndReevaluate;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentCreationGateway;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentImportRepository;
use App\Application\WorkOrders\GeneratePreventiveWorkOrder;
use App\Application\WorkOrders\GeneratePreventiveWorkOrderCommand;
use App\Application\WorkOrders\Port\WorkOrderNumberGenerator;
use App\Application\WorkOrders\StartWorkOrder;
use App\Application\WorkOrders\StartWorkOrderCommand;
use App\Domain\Measurement\EquipmentReading;
use DateTimeImmutable;
use DomainException;

final class ConfirmWorkOrderDocumentImport
{
    public function __construct(
        private readonly WorkOrderDocumentImportRepository $imports,
        private readonly WorkOrderDocumentCreationGateway $gateway,
        private readonly WorkOrderNumberGenerator $numbers,
        private readonly GeneratePreventiveWorkOrder $generatePreventive,
        private readonly StartWorkOrder $startWorkOrder,
        private readonly ClosePreventiveOrder $closePreventive,
        private readonly RegisterReadingAndReevaluate $registerReading,
        private readonly PreventivePlanMatcher $planMatcher = new PreventivePlanMatcher(),
    ) {}

    /** @param array<string,mixed> $proposal @return array{orders:list<array{orderId:int,kind:string}>,readingRegistered:bool} */
    public function execute(ActorContext $actor, int $importId, string $action, array $proposal): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('ordenes.editar')) {
            throw new DomainException('No tenés permiso para confirmar la importación de la OT.');
        }
        $action = strtolower(trim($action));
        if (! in_array($action, ['corrective', 'preventive', 'both'], true)) {
            throw new DomainException('La acción de creación no es válida.');
        }
        if (($action === 'preventive' || $action === 'both') && ! $actor->hasPermission('ordenes.cerrar')) {
            throw new DomainException('Para registrar un preventivo ya realizado necesitás permiso para cerrar OT.');
        }

        $import = $this->imports->findForActor($importId, $actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds());
        if ($import === null) throw new DomainException('El documento no existe o no está autorizado.');
        $storedProposal = $this->decodeProposal($import['proposal_json'] ?? null);
        $possibleDuplicates = is_array($storedProposal['possibleDuplicates'] ?? null) ? $storedProposal['possibleDuplicates'] : [];
        if ($possibleDuplicates !== [] && ! (bool) ($proposal['confirmPossibleDuplicate'] ?? false)) {
            throw new DomainException('Existe una importación anterior muy similar. Revisá la advertencia y confirmá expresamente que corresponde continuar.');
        }

        $equipmentId = (int) ($proposal['selectedEquipmentId'] ?? 0);
        $equipment = $this->gateway->equipment($actor->companyId(), $equipmentId);
        if ($equipment === null) throw new DomainException('Seleccioná un equipo válido antes de crear la OT.');
        if ((int) $equipment['sucursal_id'] !== (int) $import['sucursal_id']) {
            throw new DomainException('El equipo seleccionado no pertenece a la sucursal del documento.');
        }
        if (! $actor->hasAllCompanyBranches() && ! in_array((int) $equipment['sucursal_id'], $actor->branchIds(), true)) {
            throw new DomainException('El equipo queda fuera de tus sucursales autorizadas.');
        }

        $date = $this->serviceDate($proposal['serviceDate'] ?? null);
        [$km, $hours] = $this->reading($proposal['readingType'] ?? null, $proposal['readingValue'] ?? null);
        if (($km !== null || $hours !== null) && ! $actor->hasPermission('lecturas.cargar')) {
            throw new DomainException('No tenés permiso para registrar la lectura detectada en el documento.');
        }

        $historicalCorrectiveReading = $this->isHistoricalCorrectiveReading($action, $date, $equipment, $km, $hours);
        if (! $historicalCorrectiveReading) {
            $this->assertReadingProgression($equipment, $km, $hours, (bool) ($proposal['confirmReadingRollback'] ?? false));
        }

        [$correctiveCost, $preventiveCost] = $this->costAllocation($action, $proposal);
        $currency = strtoupper($this->nullable($proposal['currency'] ?? null) ?? 'ARS');

        $works = is_array($proposal['works'] ?? null) ? $proposal['works'] : [];
        $materials = is_array($proposal['materials'] ?? null) ? $proposal['materials'] : [];
        $corrective = $this->worksOf($works, 'correctivo');
        $preventive = $this->worksOf($works, 'preventivo');
        if (($action === 'corrective' || $action === 'both') && $corrective === []) {
            throw new DomainException('No hay trabajos correctivos seleccionados.');
        }
        if (($action === 'preventive' || $action === 'both') && $preventive === []) {
            throw new DomainException('No hay trabajos preventivos seleccionados.');
        }

        return $this->gateway->transaction(function () use ($actor, $importId, $action, $proposal, $equipment, $date, $km, $hours, $historicalCorrectiveReading, $corrective, $preventive, $materials, $correctiveCost, $preventiveCost, $currency): array {
            $lockedImport = $this->gateway->lockImport($actor->companyId(), $importId);
            if ($lockedImport === null) {
                throw new DomainException('La importación dejó de estar disponible.');
            }
            $existingOrders = $this->gateway->linkedOrders($actor->companyId(), $importId);
            if ($existingOrders !== []) {
                return [
                    'orders' => $existingOrders,
                    'readingRegistered' => ($km !== null || $hours !== null) && ! $historicalCorrectiveReading,
                ];
            }

            $orders = [];
            $readingRegistered = false;
            if ($action === 'corrective' || $action === 'both') {
                $number = $this->numbers->next($actor->companyId(), (int) $date->format('Y'));
                $id = $this->gateway->createCompletedCorrective(
                    $actor->companyId(),
                    (int) $equipment['sucursal_id'],
                    (int) $equipment['id'],
                    $actor->userId(),
                    $number->value(),
                    $date->format('Y-m-d'),
                    'MEDIA',
                    $actor->userId(),
                    $km,
                    $hours,
                    $this->nullable($proposal['supplier'] ?? null),
                    $this->nullable($proposal['concept'] ?? null),
                    $this->historicalObservation($proposal['observations'] ?? null, $historicalCorrectiveReading, $date, $equipment, $km, $hours),
                    $correctiveCost,
                    $currency,
                    $corrective,
                    $materials,
                );
                $this->imports->linkWorkOrder($importId, $actor->companyId(), $id, 'CORRECTIVA');
                $orders[] = ['orderId' => $id, 'kind' => 'CORRECTIVA'];
            }

            if ($action === 'preventive' || $action === 'both') {
                $planId = (int) ($proposal['selectedPlanId'] ?? 0);
                $plan = $this->gateway->preventivePlan($actor->companyId(), (int) $equipment['id'], $planId);
                if ($plan === null || ($plan['tasks'] ?? []) === []) {
                    throw new DomainException('Seleccioná un plan preventivo válido con tareas antes de continuar.');
                }
                $preventiveOrderId = $this->generatePreventive->execute($actor, new GeneratePreventiveWorkOrderCommand(
                    companyId: $actor->companyId(),
                    branchId: (int) $equipment['sucursal_id'],
                    equipmentId: (int) $equipment['id'],
                    planId: (int) $plan['id'],
                    preventiveNoticeId: null,
                    serviceTypeId: (int) $plan['tipo_servicio_id'],
                    responsibleUserId: $actor->userId(),
                    priority: (string) ($plan['prioridad'] ?: 'MEDIA'),
                    inputKilometres: $km,
                    inputHours: $hours,
                    tasks: $plan['tasks'],
                ));
                $this->startWorkOrder->execute($actor, new StartWorkOrderCommand($preventiveOrderId, $km, $hours));

                $persistedTasks = $this->gateway->workOrderTasks($actor->companyId(), $preventiveOrderId);
                $taskMatches = $this->planMatcher->matchTasks($persistedTasks, $preventive, $materials);
                $taskResults = [];
                $evidenced = 0;
                $missingRequired = [];
                foreach ($taskMatches as $match) {
                    if ($match['evidenced']) {
                        $evidenced++;
                        $taskResults[$match['taskId']] = [
                            'resultado' => 'REALIZADA',
                            'detalle' => $match['matchedDescription'] ?: $match['taskName'],
                        ];
                    } else {
                        if ($match['required']) {
                            $missingRequired[] = $match['taskName'];
                        }
                        $taskResults[$match['taskId']] = [
                            'resultado' => 'PENDIENTE',
                            'detalle' => 'No evidenciada en el documento importado.',
                        ];
                    }
                }
                if ($evidenced === 0) {
                    throw new DomainException('El documento no evidencia ninguna tarea del plan preventivo seleccionado.');
                }
                if ($missingRequired !== [] && ! (bool) ($proposal['confirmPartialPreventive'] ?? false)) {
                    throw new DomainException('Faltan tareas obligatorias del plan sin evidencia en el documento. Revisalas y confirmá explícitamente el registro preventivo parcial.');
                }

                $preventiveNotes = array_values(array_filter([
                    $this->nullable($proposal['observations'] ?? null),
                    $preventiveCost !== null ? 'Importe asignado desde documento: ' . $currency . ' ' . $preventiveCost : null,
                ]));
                $this->closePreventive->execute($actor, $preventiveOrderId, [
                    'trabajo_realizado' => $taskResults,
                    'fecha_servicio' => $date->format('Y-m-d'),
                    'km_salida' => $km,
                    'horas_salida' => $hours,
                    'observaciones' => $preventiveNotes === [] ? null : implode("\n", $preventiveNotes),
                    'costo_mano_obra' => '0',
                    'costo_repuestos' => '0',
                    'otros_costos' => $preventiveCost ?? '0',
                ]);
                $this->imports->linkWorkOrder($importId, $actor->companyId(), $preventiveOrderId, 'PREVENTIVA');
                $orders[] = ['orderId' => $preventiveOrderId, 'kind' => 'PREVENTIVA'];
                $readingRegistered = $km !== null || $hours !== null;
            }

            if ($action === 'corrective' && ($km !== null || $hours !== null) && ! $historicalCorrectiveReading) {
                $this->registerReading->execute($actor, new RegisterReadingCommand(
                    (int) $equipment['id'],
                    $date,
                    $km,
                    $hours,
                    EquipmentReading::WORK_ORDER,
                    'OT_DOC_IMPORT#' . $importId,
                    null,
                    'Lectura registrada desde documento de taller importado #' . $importId,
                ));
                $readingRegistered = true;
            }

            $this->gateway->markConfirmed($actor->companyId(), $importId, (int) $equipment['id'], $proposal);
            return ['orders' => $orders, 'readingRegistered' => $readingRegistered];
        });
    }

    /** @param list<array<string,mixed>> $works @return list<array<string,mixed>> */
    private function worksOf(array $works, string $classification): array
    {
        return array_values(array_filter($works, static fn (array $row): bool => ($row['included'] ?? true) && ($row['classification'] ?? '') === $classification && trim((string) ($row['description'] ?? '')) !== ''));
    }

    /** @param array<string,mixed> $equipment */
    private function isHistoricalCorrectiveReading(string $action, DateTimeImmutable $date, array $equipment, ?int $km, ?string $hours): bool
    {
        if ($action !== 'corrective' || $date >= new DateTimeImmutable('today')) {
            return false;
        }

        if ($km !== null && $equipment['km_actual'] !== null && $km < (int) $equipment['km_actual']) {
            return true;
        }

        return $hours !== null
            && $equipment['horas_actuales'] !== null
            && (float) $hours < (float) $equipment['horas_actuales'];
    }

    /** @param array<string,mixed> $equipment */
    private function assertReadingProgression(array $equipment, ?int $km, ?string $hours, bool $confirmedRollback): void
    {
        $regression = false;
        if ($km !== null && $equipment['km_actual'] !== null && $km < (int) $equipment['km_actual']) $regression = true;
        if ($hours !== null && $equipment['horas_actuales'] !== null && (float) $hours < (float) $equipment['horas_actuales']) $regression = true;
        if ($regression && ! $confirmedRollback) {
            throw new DomainException('La lectura del documento es menor que la lectura actual del equipo. Confirmá expresamente que revisaste esta diferencia antes de continuar.');
        }
    }

    /** @param array<string,mixed> $equipment */
    private function historicalObservation(mixed $observations, bool $historical, DateTimeImmutable $date, array $equipment, ?int $km, ?string $hours): ?string
    {
        $base = $this->nullable($observations);
        if (! $historical) {
            return $base;
        }

        $unit = $km !== null ? 'km' : 'h';
        $historicalValue = $km !== null ? number_format($km, 0, ',', '.') : number_format((float) $hours, 1, ',', '.');
        $currentValue = $km !== null
            ? ($equipment['km_actual'] === null ? 'sin lectura actual' : number_format((int) $equipment['km_actual'], 0, ',', '.') . ' km')
            : ($equipment['horas_actuales'] === null ? 'sin lectura actual' : number_format((float) $equipment['horas_actuales'], 1, ',', '.') . ' h');
        $note = sprintf(
            'Lectura histórica de la OT: %s %s al %s. Lectura actual del equipo al momento de la carga: %s. No se modificó la lectura actual.',
            $historicalValue,
            $unit,
            $date->format('d/m/Y'),
            $currentValue,
        );

        return $base === null ? $note : $base . "\n" . $note;
    }

    private function serviceDate(mixed $value): DateTimeImmutable
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) throw new DomainException('Revisá la fecha del trabajo antes de crear la OT.');
        return $date;
    }

    /** @return array{0:?int,1:?string} */
    private function reading(mixed $type, mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') return [null, null];
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! is_numeric($normalized) || (float) $normalized < 0) throw new DomainException('La lectura detectada no es válida.');
        return strtolower(trim((string) $type)) === 'horas'
            ? [null, number_format((float) $normalized, 1, '.', '')]
            : [(int) round((float) $normalized), null];
    }

    /** @param array<string,mixed> $proposal @return array{0:?string,1:?string} */
    private function costAllocation(string $action, array $proposal): array
    {
        $total = $this->money($proposal['totalAmount'] ?? null);
        if ($total === null) return [null, null];
        if ($action === 'corrective') return [$total, null];
        if ($action === 'preventive') return [null, $total];

        $corrective = $this->money($proposal['correctiveAmount'] ?? null);
        $preventive = $this->money($proposal['preventiveAmount'] ?? null);
        if ($corrective === null || $preventive === null) {
            throw new DomainException('Distribuí el importe total entre la OT correctiva y la preventiva antes de crear ambas.');
        }
        if (abs(((float) $corrective + (float) $preventive) - (float) $total) > 0.009) {
            throw new DomainException('La suma asignada a las dos OT debe coincidir con el importe total del documento.');
        }
        return [$corrective, $preventive];
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if (! is_numeric($normalized) || (float) $normalized < 0) throw new DomainException('El importe detectado no es válido.');
        return number_format((float) $normalized, 2, '.', '');
    }

    /** @return array<string,mixed> */
    private function decodeProposal(mixed $json): array
    {
        if (! is_string($json) || trim($json) === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
