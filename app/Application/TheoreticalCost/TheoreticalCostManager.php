<?php

declare(strict_types=1);

namespace App\Application\TheoreticalCost;

use App\Application\Identity\ActorContext;
use App\Application\TheoreticalCost\Port\TheoreticalCostScenarioStore;
use App\Domain\TheoreticalCost\TheoreticalCostCalculator;
use DomainException;

final class TheoreticalCostManager
{
    public function __construct(
        private readonly TheoreticalCostScenarioStore $store,
        private readonly TheoreticalCostCalculator $calculator,
    ) {
    }

    public function canEdit(ActorContext $actor): bool
    {
        return $actor->hasPermission('gerencial.costo_km.editar');
    }

    public function assets(ActorContext $actor): array
    {
        $this->assertAllowed($actor, false);
        return $this->store->listAssets($this->companyId($actor), $this->branchScope($actor));
    }

    /** @param array<string,mixed> $payload */
    public function calculate(ActorContext $actor, array $payload): array
    {
        $this->assertAllowed($actor, true);
        [$tractorId, $semiId] = $this->equipmentIds($actor, $payload);
        $parameters = $this->effectiveParameters(is_array($payload['parametros'] ?? null) ? $payload['parametros'] : []);
        $trip = $this->trip($payload['viaje'] ?? null);
        return $this->calculator->calculate($parameters, $trip);
    }

    /** @param array<string,mixed> $filters */
    public function list(ActorContext $actor, array $filters = []): array
    {
        $this->assertAllowed($actor, false);
        return $this->store->listScenarios($this->companyId($actor), $this->branchScope($actor), $filters);
    }

    public function version(ActorContext $actor, int $scenarioId, int $versionNumber): array
    {
        $this->assertAllowed($actor, false);
        if ($scenarioId <= 0 || $versionNumber <= 0) {
            throw new DomainException('El escenario o la versión solicitados no son válidos.');
        }
        return $this->store->findVersion($this->companyId($actor), $this->branchScope($actor), $scenarioId, $versionNumber)
            ?? throw new DomainException('No se encontró el escenario solicitado dentro de tu alcance.');
    }

    /** @param array<string,mixed> $payload */
    public function save(ActorContext $actor, array $payload): array
    {
        $this->assertAllowed($actor, true);
        [$tractorId, $semiId] = $this->equipmentIds($actor, $payload);
        $parameters = $this->effectiveParameters(is_array($payload['parametros'] ?? null) ? $payload['parametros'] : []);
        if ($parameters === []) {
            throw new DomainException('Completá los supuestos antes de guardar el escenario.');
        }
        $trip = $this->trip($payload['viaje'] ?? null);
        $result = $this->calculator->calculate($parameters, $trip);
        $scenarioId = isset($payload['escenario_id']) && $payload['escenario_id'] !== ''
            ? $this->positiveId($payload['escenario_id'], 'escenario_id')
            : null;
        $name = trim((string) ($payload['nombre'] ?? ''));
        if ($scenarioId === null && $name === '') {
            throw new DomainException('Indicá un nombre para guardar el escenario.');
        }
        if ($scenarioId !== null && $name === '') {
            $name = 'Escenario';
        }
        if (mb_strlen($name) > 120) {
            throw new DomainException('El nombre del escenario admite hasta 120 caracteres.');
        }

        return $this->store->appendVersion(
            $this->companyId($actor),
            $actor->userId(),
            $this->branchScope($actor),
            $scenarioId,
            $name,
            $tractorId,
            $semiId,
            ['tractor_id' => $tractorId, 'semi_id' => $semiId, 'parametros' => $parameters, 'viaje' => $trip],
            $result,
        );
    }

    /** @param array<string,mixed> $payload @return array{?int,?int} */
    private function equipmentIds(ActorContext $actor, array $payload): array
    {
        $tractorId = $this->optionalPositiveId($payload['tractor_id'] ?? null, 'tractor_id');
        $semiId = $this->optionalPositiveId($payload['semi_id'] ?? null, 'semi_id');
        if (($tractorId === null) !== ($semiId === null)) {
            throw new DomainException('Seleccioná tractor y semi juntos o dejá ambos sin asociar.');
        }
        if ($tractorId === null || $semiId === null) {
            return [null, null];
        }
        if ($tractorId === $semiId) {
            throw new DomainException('Seleccioná un tractor y un semi diferentes.');
        }
        $this->store->equipmentPair($this->companyId($actor), $this->branchScope($actor), $tractorId, $semiId)
            ?? throw new DomainException('El tractor o el semi no existe, está inactivo o queda fuera de tus sucursales autorizadas.');
        return [$tractorId, $semiId];
    }

    private function assertAllowed(ActorContext $actor, bool $edit): void
    {
        $this->companyId($actor);
        if (! $actor->hasPermission('gerencial.costo_km.ver') || ($edit && ! $this->canEdit($actor))) {
            throw new DomainException('Tu usuario no está autorizado para consultar o modificar este análisis gerencial.');
        }
    }

    private function companyId(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || $companyId <= 0) {
            throw new DomainException('El análisis gerencial requiere un usuario asociado a una empresa.');
        }
        return $companyId;
    }

    /** @return list<int>|null */
    private function branchScope(ActorContext $actor): ?array
    {
        if ($actor->hasAllCompanyBranches()) {
            return null;
        }
        return $actor->branchIds();
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new DomainException('El campo ' . $field . ' debe contener un identificador válido.');
        }
        return (int) $value;
    }

    private function optionalPositiveId(mixed $value, string $field): ?int
    {
        return $value === null || $value === '' ? null : $this->positiveId($value, $field);
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed> */
    private function effectiveParameters(array $parameters): array
    {
        foreach (['tractor', 'semi'] as $assetKey) {
            $asset = is_array($parameters[$assetKey] ?? null) ? $parameters[$assetKey] : [];
            $value = $asset['valor_usd'] ?? null;
            $residual = $asset['residual_usd'] ?? null;
            if (($residual === null || $residual === '') && is_numeric($value) && (float) $value >= 0) {
                $asset['residual_usd'] = round((float) $value * 0.30, 2);
            }
            $parameters[$assetKey] = $asset;
        }

        return $parameters;
    }

    private function trip(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        foreach (['km_facturables', 'km_no_facturados', 'peajes'] as $key) {
            if (isset($value[$key]) && $value[$key] !== '') {
                return $value;
            }
        }
        return null;
    }
}
