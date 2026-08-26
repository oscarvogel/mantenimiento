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
    ) {}

    /** @param array<string,mixed> $proposal @return array{orders:list<array{orderId:int,kind:string}>,readingRegistered:bool} */
    public function execute(ActorContext $actor, int $importId, string $action, array $proposal): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('ordenes.editar')) throw new DomainException('No tenés permiso para confirmar la importación de la OT.');
        $action = strtolower(trim($action));
        if (! in_array($action, ['corrective', 'preventive', 'both'], true)) throw new DomainException('La acción de creación no es válida.');
        if (($action === 'preventive' || $action === 'both') && ! $actor->hasPermission('ordenes.cerrar')) throw new DomainException('Para registrar un preventivo ya realizado necesitás permiso para cerrar OT.');

        $import = $this->imports->findForActor($importId, $actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds());
        if ($import === null) throw new DomainException('El documento no existe o no está autorizado.');
        $equipmentId = (int) ($proposal['selectedEquipmentId'] ?? 0);
        $equipment = $this->gateway->equipment($actor->companyId(), $equipmentId);
        if ($equipment === null) throw new DomainException('Seleccioná un equipo válido antes de crear la OT.');
        if ((int) $equipment['sucursal_id'] !== (int) $import['sucursal_id']) throw new DomainException('El equipo seleccionado no pertenece a la sucursal del documento.');
        if (! $actor->hasAllCompanyBranches() && ! in_array((int) $equipment['sucursal_id'], $actor->branchIds(), true)) throw new DomainException('El equipo queda fuera de tus sucursales autorizadas.');

        $date = $this->serviceDate($proposal['serviceDate'] ?? null);
        [$km, $hours] = $this->reading($proposal['readingType'] ?? null, $proposal['readingValue'] ?? null);
        $works = is_array($proposal['works'] ?? null) ? $proposal['works'] : [];
        $materials = is_array($proposal['materials'] ?? null) ? $proposal['materials'] : [];
        $corrective = $this->worksOf($works, 'correctivo');
        $preventive = $this->worksOf($works, 'preventivo');
        if (($action === 'corrective' || $action === 'both') && $corrective === []) throw new DomainException('No hay trabajos correctivos seleccionados.');
        if (($action === 'preventive' || $action === 'both') && $preventive === []) throw new DomainException('No hay trabajos preventivos seleccionados.');

        return $this->gateway->transaction(function () use ($actor, $importId, $action, $proposal, $equipment, $date, $km, $hours, $corrective, $preventive, $materials): array {
            $this->gateway->lockImportForConfirmation($actor->companyId(), $importId);
            $linked = $this->gateway->linkedOrders($actor->companyId(), $importId);
            if ($linked !== []) return ['orders' => $linked, 'readingRegistered' => $km !== null || $hours !== null];

            $orders = [];
            $readingRegistered = false;
            if ($action === 'corrective' || $action === 'both') {
                $number = $this->numbers->next($actor->companyId(), (int) $date->format('Y'));
                $id = $this->gateway->createCompletedCorrective(
                    $actor->companyId(), (int) $equipment['sucursal_id'], (int) $equipment['id'], $actor->userId(), $number->value(),
                    $date->format('Y-m-d'), 'MEDIA', $actor->userId(), $km, $hours,
                    $this->nullable($proposal['supplier'] ?? null), $this->nullable($proposal['concept'] ?? null),
                    $this->nullable($proposal['observations'] ?? null), $corrective, $materials,
                );
                $this->imports->linkWorkOrder($importId, $actor->companyId(), $id, 'CORRECTIVA');
                $orders[] = ['orderId' => $id, 'kind' => 'CORRECTIVA'];
            }

            if ($action === 'preventive' || $action === 'both') {
                $planId = (int) ($proposal['selectedPlanId'] ?? 0);
                $plan = $this->gateway->preventivePlan($actor->companyId(), (int) $equipment['id'], $planId);
                if ($plan === null || ($plan['tasks'] ?? []) === []) throw new DomainException('Seleccioná un plan preventivo válido con tareas antes de continuar.');
                $preventiveOrderId = $this->generatePreventive->execute($actor, new GeneratePreventiveWorkOrderCommand(
                    companyId: $actor->companyId(), branchId: (int) $equipment['sucursal_id'], equipmentId: (int) $equipment['id'],
                    planId: (int) $plan['id'], preventiveNoticeId: null, serviceTypeId: (int) $plan['tipo_servicio_id'],
                    responsibleUserId: $actor->userId(), priority: (string) ($plan['prioridad'] ?: 'MEDIA'),
                    inputKilometres: $km, inputHours: $hours, tasks: $plan['tasks'],
                ));
                $this->startWorkOrder->execute($actor, new StartWorkOrderCommand($preventiveOrderId, $km, $hours));
                $performed = implode("\n", array_map(static fn (array $row): string => '- ' . (string) $row['description'], $preventive));
                $this->closePreventive->execute($actor, $preventiveOrderId, [
                    'trabajo_realizado' => $performed, 'fecha_servicio' => $date->format('Y-m-d'), 'km_salida' => $km, 'horas_salida' => $hours,
                    'observaciones' => $this->nullable($proposal['observations'] ?? null), 'costo_mano_obra' => '0', 'costo_repuestos' => '0', 'otros_costos' => '0',
                ]);
                $this->imports->linkWorkOrder($importId, $actor->companyId(), $preventiveOrderId, 'PREVENTIVA');
                $orders[] = ['orderId' => $preventiveOrderId, 'kind' => 'PREVENTIVA'];
                $readingRegistered = $km !== null || $hours !== null;
            }

            if ($action === 'corrective' && ($km !== null || $hours !== null)) {
                $this->registerReading->execute($actor, new RegisterReadingCommand(
                    (int) $equipment['id'], $date, $km, $hours, EquipmentReading::WORK_ORDER, 'OT_DOC_IMPORT#' . $importId, null,
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
        return strtolower(trim((string) $type)) === 'horas' ? [null, number_format((float) $normalized, 1, '.', '')] : [(int) round((float) $normalized), null];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
