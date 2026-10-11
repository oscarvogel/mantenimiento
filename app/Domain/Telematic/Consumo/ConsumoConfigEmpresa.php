<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Los umbrales que una empresa configuró para su propio cálculo de consumo.
 *
 * Todos los campos son anulables y **anular no es cero**: null significa "no
 * configurado", y cada valor falta lo cubre `PoliticaConsumo` con su default.
 *
 * La distinción importa. Un `deltaMinimoLitros = 0` desactiva el descarte por
 * ruido y hace que cada fluctuación del sensor cuente como consumo; un null
 * deja el default y mantiene el reporte marcado como no calibrado. Son dos
 * decisiones opuestas y por eso no se pueden representar con el mismo número.
 */
final readonly class ConsumoConfigEmpresa
{
    public function __construct(
        private int $empresaId,
        private ?float $deltaMinimoLitros = null,
        private ?float $umbralCargaLitros = null,
        private ?int $huecoMaximoAtribucionMinutos = null,
        private ?int $kmMinimosViaje = null,
        private ?int $minutosMinimosViaje = null,
        private ?int $intervalosParadoParaCerrar = null,
        private ?float $coberturaMinima = null,
        private ?int $huecoMaximoCoberturaMinutos = null,
        private ?float $velocidadMinimaPlausibleKmh = null,
        private ?float $velocidadMaximaPlausibleKmh = null,
    ) {
        if ($this->empresaId <= 0) {
            throw new \InvalidArgumentException('La configuracion necesita una empresa valida.');
        }

        // La invariante de la atribución: sin umbral de carga no se puede
        // separar una carga de un consumo, y decidir por omisión sería asumir
        // que todo descenso es consumo. Se valida acá y no en la política, para
        // que el error salte al guardar y no tres semanas después, cuando un
        // camión haya cargado a mitad de camino y contaminado el informe.
        if ($this->umbralCargaLitros !== null && $this->umbralCargaLitros <= 0) {
            throw new \InvalidArgumentException('El umbral de carga tiene que ser positivo.');
        }

        if ($this->deltaMinimoLitros !== null && $this->umbralCargaLitros !== null
            && $this->umbralCargaLitros <= $this->deltaMinimoLitros) {
            throw new \InvalidArgumentException('El umbral de carga tiene que ser mayor que el delta minimo.');
        }

        if ($this->coberturaMinima !== null && ($this->coberturaMinima <= 0.0 || $this->coberturaMinima > 1.0)) {
            throw new \InvalidArgumentException('La cobertura minima tiene que ser una proporcion entre 0 y 1.');
        }

        if ($this->velocidadMaximaPlausibleKmh !== null
            && $this->velocidadMinimaPlausibleKmh !== null
            && $this->velocidadMaximaPlausibleKmh <= $this->velocidadMinimaPlausibleKmh) {
            throw new \InvalidArgumentException('El tope de velocidad plausible tiene que estar por encima del piso.');
        }

        if ($this->intervalosParadoParaCerrar !== null && $this->intervalosParadoParaCerrar <= 0) {
            throw new \InvalidArgumentException('Hace falta al menos un intervalo para cerrar un viaje.');
        }
    }

    public function empresaId(): int
    {
        return $this->empresaId;
    }

    /** Campos configurados: si viene vacío, la empresa no tocó nada. */
    public function configurado(): bool
    {
        foreach ($this->valores() as $valor) {
            if ($valor !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, float|int|null> */
    public function valores(): array
    {
        return [
            'deltaMinimoLitros' => $this->deltaMinimoLitros,
            'umbralCargaLitros' => $this->umbralCargaLitros,
            'huecoMaximoAtribucion' => $this->huecoMaximoAtribucionMinutos,
            'kmMinimosViaje' => $this->kmMinimosViaje,
            'minutosMinimosViaje' => $this->minutosMinimosViaje,
            'intervalosParadoParaCerrar' => $this->intervalosParadoParaCerrar,
            'coberturaMinima' => $this->coberturaMinima,
            'huecoMaximoCobertura' => $this->huecoMaximoCoberturaMinutos,
            'velocidadMinimaPlausibleKmh' => $this->velocidadMinimaPlausibleKmh,
            'velocidadMaximaPlausibleKmh' => $this->velocidadMaximaPlausibleKmh,
        ];
    }

    /** Sólo los campos que la empresa fijó, para mostrar en la pantalla qué es de ella. */
    public function valoresPropios(): array
    {
        return array_filter($this->valores(), static fn (mixed $v): bool => $v !== null);
    }
}