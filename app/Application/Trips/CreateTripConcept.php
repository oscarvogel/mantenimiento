<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Application\Trips\Port\TripConceptCatalog;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Domain\Trips\TripConcept;
use DomainException;

/**
 * Alta de un concepto del catálogo desde el propio viaje.
 *
 * El operador que carga un gasto o un haber necesita un concepto que no
 * existe todavía. Antes tenía que dejar el viaje, ir a Maestros de Viajes y
 * volver: el trabajo se partía en dos y se perdía el contexto. Con este caso
 * de uso el concepto se da de alta en el lugar y queda disponible de inmediato.
 *
 * El concepto es un dato maestro de la empresa: por eso la validación es
 * exactamente la del maestro (misma regla de dominio) y por eso queda
 * registrado quién lo creó. El alta sólo existe en el contexto de un viaje
 * real y dentro del alcance del operador: el viaje se valida antes de tocar
 * el catálogo.
 */
final readonly class CreateTripConcept
{
    public function __construct(
        private TripConceptCatalog $catalog,
        private OperationalTripReadModel $trips,
        private TripDirectoryLookup $directory,
    ) {
    }

    /**
     * Quién puede dar de alta un concepto desde el viaje.
     *
     * Alcanza con operar viajes: quien carga gastos y haberes necesita el
     * concepto para no dejar el viaje a mitad de trabajo. El permiso del
     * maestro sigue sirviendo para administrar el catálogo completo.
     */
    public static function canCreateConcept(ActorContext $actor): bool
    {
        return $actor->hasPermission('viajes.operar') || $actor->hasPermission('viajes.maestros.editar');
    }

    /**
     * @return array{id:int,label:string,categoria:string,unit:string,amount:float,currency:string,editable:bool}
     */
    public function execute(ActorContext $actor, int $tripId, CreateTripConceptCommand $command): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null) {
            throw new DomainException('Los conceptos de viaje requieren una cuenta de empresa.');
        }
        if (! self::canCreateConcept($actor)) {
            throw new DomainException('No tenés permiso para crear conceptos de viaje.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === [] || $this->trips->find($companyId, $branchIds, $tripId) === null) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $concept = new TripConcept(
            $command->name(),
            $command->category(),
            $command->unit(),
            $command->unitAmount(),
            $command->currency(),
            $command->amountEditable(),
        );

        if ($this->catalog->existsByName($companyId, $concept->name())) {
            throw new DomainException('Tu empresa ya tiene un concepto con ese nombre. Buscalo en la lista o usá otro nombre.');
        }

        if (! $this->directory->currencyExists($concept->currency())) {
            throw new DomainException('La moneda del concepto no está disponible.');
        }

        $id = $this->catalog->create($companyId, $concept, $actor->userId());

        return [
            'id' => $id,
            'label' => $concept->name(),
            'categoria' => $concept->category(),
            'unit' => $concept->unit(),
            'amount' => $concept->unitAmount(),
            'currency' => $concept->currency(),
            'editable' => $concept->amountEditable(),
        ];
    }
}
