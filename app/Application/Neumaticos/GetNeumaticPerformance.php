<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Domain\Neumaticos\NeumaticPerformance;
use DomainException;

/**
 * Rendimiento de un neumático (`ObtenerRendimientoNeumaticoQuery`): cada
 * colocación con sus km y el total confiable.
 */
final class GetNeumaticPerformance
{
    public function __construct(private readonly NeumaticRepository $neumatics)
    {
    }

    /**
     * @return array{
     *     neumatic_id: int,
     *     number: string,
     *     status: string,
     *     status_label: string,
     *     total_km: int|null,
     *     total_label: string,
     *     mountings: list<array{equipment_id: int, place: string, side: string, slot: string, button: string|null, mounted_at: string, mounted_km: int, removed_at: string|null, removed_km: int|null, km: int|null, reliable: bool|null, label: string}>
     * }
     */
    public function execute(ActorContext $actor, int $neumaticId): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::VIEW, 'No tenés permiso para ver neumáticos.');
        $neumatic = $neumaticId > 0 ? $this->neumatics->find($access->companyId, $access->branchIds, $neumaticId) : null;
        if ($neumatic === null) {
            throw new DomainException(NeumaticAccess::NOT_FOUND);
        }

        $mountings = [];
        foreach ($neumatic->mountings() as $mounting) {
            $performance = $mounting->performance();
            $mountings[] = [
                'equipment_id' => $mounting->equipmentId,
                'place' => $mounting->position->place,
                'side' => $mounting->position->side,
                'slot' => $mounting->position->slot,
                'button' => $mounting->position->button,
                'mounted_at' => $mounting->mountedAt->format('Y-m-d'),
                'mounted_km' => $mounting->mountedKm,
                'removed_at' => $mounting->removedAt?->format('Y-m-d'),
                'removed_km' => $mounting->removedKm,
                'km' => $performance?->kilometers,
                'reliable' => $performance?->reliable,
                'label' => $performance === null ? 'Colocado' : $performance->describe(),
            ];
        }
        $total = $neumatic->totalKilometers();

        return [
            'neumatic_id' => $neumaticId,
            'number' => $neumatic->number()->value,
            'status' => $neumatic->status()->value,
            'status_label' => $neumatic->status()->label(),
            'total_km' => $total,
            'total_label' => $total === null ? NeumaticPerformance::INSUFFICIENT_DATA : number_format($total, 0, ',', '.') . ' km',
            'mountings' => $mountings,
        ];
    }
}
