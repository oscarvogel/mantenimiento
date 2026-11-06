<?php

declare(strict_types=1);

namespace App\Domain\Geography;

/**
 * Decide qué localidades del catálogo oficial quedan visibles para elegir un
 * origen o destino de viaje. El catálogo completo se conserva para historial,
 * equivalencias y viajes ya cargados.
 */
final class PrincipalLocalityPolicy
{
    /** @param list<string> $brazilMunicipalityCodes */
    public function __construct(private readonly array $brazilMunicipalityCodes)
    {
    }

    public function isPrincipal(string $country, string $category, string $code): bool
    {
        $country = strtoupper(trim($country));
        $category = trim($category);
        $code = trim($code);

        return match ($country) {
            'BRASIL' => str_contains(mb_strtoupper($category, 'UTF-8'), 'CIDADE')
                && in_array($code, $this->brazilMunicipalityCodes, true),
            'CHILE' => mb_strtoupper($category, 'UTF-8') === 'CIUDAD',
            // Uruguay tiene pocas localidades censales y todas son útiles.
            'URUGUAY' => true,
            // Argentina no ofrece localidades oficiales: usa una entrada por
            // provincia, administrada por ProvinceLocalityRegistry.
            default => false,
        };
    }
}
