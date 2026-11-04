<?php

declare(strict_types=1);

namespace App\Application\TripMasters;

use App\Application\Identity\ActorContext;
use App\Application\TripMasters\Port\TripMasterRepository;
use DateTimeImmutable;
use DomainException;

final readonly class TripMasterCatalog
{
    public const CATALOGS = [
        'clientes' => ['label' => 'Clientes', 'table' => 'clientes'],
        'domicilios' => ['label' => 'Domicilios de clientes', 'table' => 'viajes_domicilios_clientes'],
        'tipos-carga' => ['label' => 'Tipos de carga', 'table' => 'viajes_tipos_carga'],
        'unidades-carga' => ['label' => 'Unidades de carga', 'table' => 'viajes_unidades_carga'],
        'cotizaciones' => ['label' => 'Cotizaciones', 'table' => 'viajes_cotizaciones'],
        'categorias-gasto' => ['label' => 'Categorías de gastos', 'table' => 'viajes_categorias_gasto'],
        'medios-pago' => ['label' => 'Medios de pago', 'table' => 'viajes_medios_pago'],
        'tipos-documento' => ['label' => 'Tipos de documento', 'table' => 'viajes_tipos_documento'],
        'liquidaciones' => ['label' => 'Porcentajes de liquidación', 'table' => 'viajes_porcentajes_liquidacion_chofer'],
    ];

    /** @var array<string,list<string>> */
    private const REQUIRED = [
        'clientes' => ['nombre'],
        'domicilios' => ['cliente_id', 'alias', 'calle', 'localidad', 'pais_id'],
        'tipos-carga' => ['nombre'],
        'unidades-carga' => ['nombre', 'simbolo'],
        'categorias-gasto' => ['nombre'],
        'medios-pago' => ['nombre'],
        'tipos-documento' => ['nombre'],
        'liquidaciones' => ['empleado_id', 'porcentaje', 'vigente_desde'],
    ];

    public function __construct(private TripMasterRepository $repository)
    {
    }

    /** @return array<string,mixed> */
    public function page(ActorContext $actor): array
    {
        $this->authorize($actor, false);
        $companyId = (int) $actor->companyId();
        $catalogs = [];
        foreach (self::CATALOGS as $key => $definition) {
            $catalogs[$key] = [
                'label' => $definition['label'],
                'items' => $this->repository->list($key, $companyId),
            ];
        }
        return [
            'catalogs' => $catalogs,
            'currencies' => $this->repository->list('monedas', $companyId),
            'countries' => $this->repository->list('paises', $companyId),
            'canEdit' => $actor->hasPermission('viajes.maestros.editar'),
        ];
    }

    /**
     * Guarda la cotización vigente por moneda de la empresa.
     *
     * @param array<string,mixed> $input
     */
    public function saveExchangeRates(ActorContext $actor, array $input): void
    {
        $this->authorize($actor, true);
        $companyId = (int) $actor->companyId();
        $rates = [];
        foreach ($input as $code => $value) {
            $code = strtoupper(trim((string) $code));
            if ($code === 'ARS' || $code === '' || $value === null || $value === '') {
                continue;
            }
            if (! $this->repository->sharedCurrencyExists($code)) {
                throw new DomainException('La moneda ' . $code . ' no está disponible en el catálogo.');
            }
            if (! is_numeric($value)) {
                throw new DomainException('Ingresá una cotización numérica para ' . $code . '.');
            }
            $rate = (float) $value;
            if ($rate <= 0) {
                throw new DomainException('La cotización de ' . $code . ' debe ser mayor que cero.');
            }
            $rates[$code] = $rate;
        }
        if ($rates === []) {
            return;
        }
        $this->repository->saveExchangeRates($companyId, $rates, $actor->userId());
    }

    /** @param array<string,mixed> $input */
    public function save(ActorContext $actor, string $catalog, array $input, ?int $id = null): int
    {
        $this->authorize($actor, true);
        if ($catalog === 'cotizaciones') {
            throw new DomainException('Las cotizaciones se actualizan desde su propio formulario.');
        }
        if (! isset(self::REQUIRED[$catalog])) {
            throw new DomainException('Ese maestro no se puede modificar desde esta pantalla.');
        }
        $data = $this->normalize($catalog, $input);
        foreach (self::REQUIRED[$catalog] as $required) {
            if (! isset($data[$required]) || $data[$required] === '') {
                throw new DomainException('Completá los campos obligatorios del maestro.');
            }
        }

        $companyId = (int) $actor->companyId();
        if ($catalog === 'domicilios') {
            if (! $this->repository->belongsToCompany('clientes', $companyId, (int) $data['cliente_id'])) {
                throw new DomainException('El cliente seleccionado no pertenece a tu empresa.');
            }
            if (! $this->repository->countryIsActive((int) $data['pais_id'])) {
                throw new DomainException('Seleccioná un país disponible.');
            }
        }
        if ($catalog === 'liquidaciones') {
            if (! $this->repository->employeeBelongsToCompany($companyId, (int) $data['empleado_id'])) {
                throw new DomainException('El empleado seleccionado no pertenece a tu empresa.');
            }
            $percentage = (float) $data['porcentaje'];
            if ($percentage < 0 || $percentage > 100) {
                throw new DomainException('El porcentaje debe estar entre 0 y 100.');
            }
            $from = $data['vigente_desde'];
            $to = $data['vigente_hasta'] ?? null;
            if ($to !== null && $to < $from) {
                throw new DomainException('La vigencia hasta no puede ser anterior a la vigencia desde.');
            }
            if ($this->repository->hasOverlappingLiquidationRate($companyId, (int) $data['empleado_id'], $from, $to, $id)) {
                throw new DomainException('Ese empleado ya tiene un porcentaje vigente en parte de ese período.');
            }
        }

        if ($id !== null) {
            if (! $this->repository->belongsToCompany($catalog, $companyId, $id)) {
                throw new DomainException('No encontramos ese registro dentro de tu empresa.');
            }
            $this->repository->update($catalog, $companyId, $id, $data, $actor->userId());
            return $id;
        }
        return $this->repository->add($catalog, $companyId, $data, $actor->userId());
    }

    public function setActive(ActorContext $actor, string $catalog, int $id, bool $active): void
    {
        $this->authorize($actor, true);
        if ($catalog === 'cotizaciones') {
            throw new DomainException('Las cotizaciones no se inactivan; se actualiza su valor.');
        }
        if (! isset(self::REQUIRED[$catalog])) {
            throw new DomainException('Ese catálogo es compartido y solo se puede consultar.');
        }
        $companyId = (int) $actor->companyId();
        if (! $this->repository->belongsToCompany($catalog, $companyId, $id)) {
            throw new DomainException('No encontramos ese registro dentro de tu empresa.');
        }
        $this->repository->setActive($catalog, $companyId, $id, $active, $actor->userId());
    }

    private function authorize(ActorContext $actor, bool $edit): void
    {
        $permission = $edit ? 'viajes.maestros.editar' : 'viajes.maestros.ver';
        if ($actor->companyId() === null || ! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés acceso a los maestros de Viajes.');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalize(string $catalog, array $input): array
    {
        $allowed = match ($catalog) {
            'clientes' => ['nombre', 'identificacion_fiscal', 'telefono', 'email'],
            'domicilios' => ['cliente_id', 'alias', 'calle', 'localidad', 'provincia', 'pais_id', 'codigo_postal'],
            'tipos-carga', 'categorias-gasto' => ['nombre', 'descripcion'],
            'unidades-carga' => ['nombre', 'simbolo'],
            'medios-pago' => ['nombre', 'descripcion'],
            'tipos-documento' => ['nombre', 'codigo', 'descripcion'],
            'liquidaciones' => ['empleado_id', 'porcentaje', 'vigente_desde', 'vigente_hasta'],
            default => [],
        };
        $data = [];
        foreach ($allowed as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            $data[$field] = $value !== '' ? $value : null;
        }
        foreach (['cliente_id', 'pais_id', 'empleado_id'] as $integerField) {
            if (isset($data[$integerField])) {
                $value = filter_var($data[$integerField], FILTER_VALIDATE_INT);
                if ($value === false || $value < 1) {
                    throw new DomainException('Elegí una opción válida para los campos relacionados.');
                }
                $data[$integerField] = $value;
            }
        }
        if (isset($data['porcentaje']) && ! is_numeric($data['porcentaje'])) {
            throw new DomainException('Ingresá un porcentaje numérico.');
        }
        if (isset($data['vigente_desde']) && ! $this->validDate($data['vigente_desde'])) {
            throw new DomainException('La fecha de inicio no es válida.');
        }
        if (isset($data['vigente_hasta']) && ! $this->validDate($data['vigente_hasta'])) {
            throw new DomainException('La fecha de fin no es válida.');
        }
        return $data;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
