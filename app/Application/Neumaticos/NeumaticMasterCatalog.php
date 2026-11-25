<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticBranchScope;
use App\Application\Neumaticos\Port\NeumaticCatalog;
use App\Application\Neumaticos\Port\NeumaticMasterRepository;
use DomainException;

/**
 * Maestros de Neumáticos: marcas, modelos, tipos de cubierta y depósitos.
 *
 * Ver exige `neumaticos.ver`; crear, editar, activar e inactivar exige
 * `neumaticos.operar`. Un maestro no se borra: se inactiva, y lo que ya lo usa
 * conserva la referencia.
 */
final class NeumaticMasterCatalog
{
    public const BRANDS = 'marcas';
    public const MODELS = 'modelos';
    public const COVER_TYPES = 'tipos-cubierta';
    public const DEPOSITS = 'depositos';

    /** @var array<string, array{label: string, singular: string, of: string, length: int}> */
    public const CATALOGS = [
        self::BRANDS => ['label' => 'Marcas', 'singular' => 'la marca', 'of' => 'de la marca', 'length' => 60],
        self::MODELS => ['label' => 'Modelos', 'singular' => 'el modelo', 'of' => 'del modelo', 'length' => 60],
        self::COVER_TYPES => ['label' => 'Tipos de cubierta', 'singular' => 'el tipo de cubierta', 'of' => 'del tipo de cubierta', 'length' => 40],
        self::DEPOSITS => ['label' => 'Depósitos', 'singular' => 'el depósito', 'of' => 'del depósito', 'length' => 80],
    ];

    public function __construct(
        private readonly NeumaticMasterRepository $masters,
        private readonly NeumaticCatalog $catalog,
        private readonly NeumaticBranchScope $branches,
    ) {
    }

    /**
     * @return array{catalogs: array<string, array{label: string, items: list<array<string, mixed>>}>, branches: list<array{id: int, name: string}>, canEdit: bool}
     */
    public function page(ActorContext $actor): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::VIEW, 'No tenés permiso para ver los maestros de neumáticos.');
        $catalogs = [];
        foreach (self::CATALOGS as $key => $definition) {
            $catalogs[$key] = [
                'label' => $definition['label'],
                'items' => $this->masters->list($key, $access->companyId, $access->branchIds),
            ];
        }

        return [
            'catalogs' => $catalogs,
            'branches' => $this->branches->activeBranches($access->companyId, $access->branchIds),
            'canEdit' => $actor->hasPermission(NeumaticAccess::OPERATE),
        ];
    }

    /**
     * Crea (`$id` null) o actualiza un maestro.
     *
     * @param array<string, mixed> $input
     */
    public function save(ActorContext $actor, string $catalog, array $input, ?int $id = null): int
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para modificar los maestros de neumáticos.');
        $definition = self::definition($catalog);
        $values = $this->values($access, $catalog, $definition, $input);
        $name = (string) ($values['nombre'] ?? $values['descripcion']);
        $brandId = isset($values['marca_id']) ? (int) $values['marca_id'] : null;

        if ($this->masters->nameTaken($catalog, $access->companyId, $name, $brandId, $id)) {
            throw new DomainException(match ($catalog) {
                self::MODELS => sprintf('Esa marca ya tiene un modelo llamado "%s".', $name),
                default => sprintf('Ya existe %s "%s" en la empresa.', $definition['singular'], $name),
            });
        }

        if ($id === null) {
            return $this->masters->create($catalog, $access->companyId, $values);
        }
        if ($id <= 0 || ! $this->masters->update($catalog, $access->companyId, $access->branchIds, $id, $values)) {
            throw new DomainException(sprintf('No encontramos %s o no está autorizado.', $definition['singular']));
        }

        return $id;
    }

    public function setActive(ActorContext $actor, string $catalog, int $id, bool $active): void
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para modificar los maestros de neumáticos.');
        $definition = self::definition($catalog);
        if ($id <= 0 || ! $this->masters->setActive($catalog, $access->companyId, $access->branchIds, $id, $active)) {
            throw new DomainException(sprintf('No encontramos %s o no está autorizado.', $definition['singular']));
        }
    }

    /**
     * @return array{label: string, singular: string, of: string, length: int}
     */
    private static function definition(string $catalog): array
    {
        return self::CATALOGS[$catalog] ?? throw new DomainException('El maestro elegido no existe.');
    }

    /**
     * @param array{label: string, singular: string, of: string, length: int} $definition
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function values(NeumaticAccess $access, string $catalog, array $definition, array $input): array
    {
        $nameField = $catalog === self::COVER_TYPES ? 'descripcion' : 'nombre';
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$nameField] ?? '')));
        if ($name === '') {
            throw new DomainException(sprintf('Indicá el nombre %s.', $definition['of']));
        }
        if (mb_strlen($name) > $definition['length']) {
            throw new DomainException(sprintf('El nombre no puede superar los %d caracteres.', $definition['length']));
        }
        $values = [$nameField => $name];

        if ($catalog === self::MODELS) {
            $brandId = (int) ($input['marca_id'] ?? 0);
            if ($brandId <= 0 || ! $this->catalog->isActiveBrand($access->companyId, $brandId)) {
                throw new DomainException('Elegí una marca activa para el modelo.');
            }
            $values['marca_id'] = $brandId;
        }

        if ($catalog === self::DEPOSITS) {
            $branchId = (int) ($input['sucursal_id'] ?? 0);
            if ($branchId <= 0 || ! $access->coversBranch($branchId) || ! $this->branches->isActiveInCompany($access->companyId, $branchId)) {
                throw new DomainException('Elegí una sucursal activa y autorizada para el depósito.');
            }
            $location = trim((string) ($input['ubicacion'] ?? ''));
            if (mb_strlen($location) > 200) {
                throw new DomainException('La ubicación no puede superar los 200 caracteres.');
            }
            $values['sucursal_id'] = $branchId;
            $values['ubicacion'] = $location === '' ? null : $location;
        }

        return $values;
    }
}
