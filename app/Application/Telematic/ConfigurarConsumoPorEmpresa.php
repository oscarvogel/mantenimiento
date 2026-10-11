<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\ConsumoConfigStore;
use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;
use App\Domain\Telematic\Consumo\PoliticaConsumo;

/**
 * Guarda los umbrales de consumo de una empresa y resuelve la política con la
 * que se va a calcular.
 *
 * Son dos operaciones y no una porque se usan en momentos distintos: configurar
 * es una escritura administrativa, infrequenta y auditada; resolver la política es
 * una lectura que ocurre en cada cálculo.
 *
 * El caso de uso de lectura es el que evita el error grande: si el cálculo usara
 * siempre `porDefecto()`, los umbrales de la base serían decorativos y nadie se
 * enteraría hasta que un informe saliera mal tres meses después.
 */
final readonly class ConfigurarConsumoPorEmpresa
{
    public function __construct(private ConsumoConfigStore $store)
    {
    }

    /**
     * @param array<string, mixed> $valores Ver `ConsumoConfigEmpresa`.
     *
     * @return ConsumoConfigEmpresa Lo guardado, ya validado por el dominio.
     */
    public function guardar(int $empresaId, array $valores): ConsumoConfigEmpresa
    {
        // El empresa_id va del request y el resto del formulario. El dominio
        // valida que ninguno rompa una invariante antes de tocar la base.
        return $this->store->guardar(new ConsumoConfigEmpresa(...array_merge(
            ['empresaId' => $empresaId],
            $valores,
        )));
    }

    /** La política con la que hay que calcular para esa empresa, hoy. */
    public function politica(int $empresaId): PoliticaConsumo
    {
        return PoliticaConsumo::desdeConfig($this->store->obtener($empresaId));
    }
}