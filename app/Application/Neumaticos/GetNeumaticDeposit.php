<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticDepositReadModel;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticStatus;

/**
 * Pantalla del depósito: existencias por depósito, movimientos y lo necesario
 * para registrar uno nuevo. Registrar lo hace `RegisterDepositMovement`.
 */
final class GetNeumaticDeposit
{
    public const MOVEMENTS_LIMIT = 50;

    public function __construct(private readonly NeumaticDepositReadModel $deposits)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ActorContext $actor, ?int $depositId): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::VIEW, 'No tenés permiso para ver los depósitos de neumáticos.');
        $deposits = $this->deposits->deposits($access->companyId, $access->branchIds);
        // Un filtro a un depósito ajeno o inactivo se ignora: se ven todos.
        if ($depositId !== null && ! in_array($depositId, array_column($deposits, 'id'), true)) {
            $depositId = null;
        }

        $label = static fn (string $status): string => NeumaticStatus::from($status)->label();
        $stock = array_map(static fn (array $row): array => $row + ['statusLabel' => $label($row['status'])], $this->deposits->stock($access->companyId, $access->branchIds, $depositId));
        $canOperate = $actor->hasPermission(NeumaticAccess::OPERATE);
        $tires = $canOperate ? array_map(static fn (array $tire): array => $tire + [
            'statusLabel' => $label($tire['status']),
            // Un neumático en estado final no ingresa a un depósito: el caso de uso lo rechaza.
            'canEnter' => ! NeumaticStatus::from($tire['status'])->isFinal(),
        ], $this->deposits->tires($access->companyId, $access->branchIds)) : [];

        return [
            'deposits' => $deposits,
            'selectedDeposit' => $depositId,
            'stock' => $stock,
            'movements' => $this->deposits->movements($access->companyId, $access->branchIds, $depositId, self::MOVEMENTS_LIMIT),
            'movementsLimit' => self::MOVEMENTS_LIMIT,
            'tires' => $tires,
            'types' => [
                ['value' => NeumaticDepositMovement::ENTRY, 'label' => 'Entrada'],
                ['value' => NeumaticDepositMovement::EXIT, 'label' => 'Salida'],
                ['value' => NeumaticDepositMovement::ADJUSTMENT, 'label' => 'Ajuste'],
            ],
            'canOperate' => $canOperate,
        ];
    }
}
