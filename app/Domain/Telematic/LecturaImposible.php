<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use InvalidArgumentException;

/**
 * Lectura que un proveedorreported y el dominio no puede aceptar como dato.
 *
 * La diferencia con "no hay dato" es la clave: -348.201 l de combustible no es
 * un tanque vacío, es una entrada analítica desconectada leída fuera de rango.
 * Normalizar el valor a ausencia es correcto para no contaminar la base, pero
 * **tirar la información sería un error**: alguien tiene que ir a mirar ese
 * sensor. Por eso el valor se guarda aparte y se convierte en alerta.
 *
 * El concepto es nuestro, no del proveedor: "COMBUSTIBLE", no "COMBUSTIBLE T1".
 */
final readonly class LecturaImposible
{
    public function __construct(
        private string $concepto,
        private ?float $valorLeido,
        private string $motivo,
    ) {
        if (trim($this->concepto) === '' || trim($this->motivo) === '') {
            throw new InvalidArgumentException('La lectura imposible necesita concepto y motivo.');
        }
    }

    public function concepto(): string
    {
        return $this->concepto;
    }

    public function valorLeido(): ?float
    {
        return $this->valorLeido;
    }

    public function motivo(): string
    {
        return $this->motivo;
    }

    public function resumen(): string
    {
        $leido = $this->valorLeido === null
            ? 'sin valor'
            : number_format($this->valorLeido, 2, ',', '.');

        return $this->concepto . ': el sensor reportó ' . $leido . '. ' . $this->motivo;
    }

    /**
     * Firma estable para la clave lógica de la alerta. Incluye el valor leído
     * para que un cambio en la falla vuelva a avisar, y dos corridas con la
     * misma falla no dupliquen.
     */
    public function firma(): string
    {
        $valor = $this->valorLeido === null
            ? 'null'
            : rtrim(rtrim(number_format($this->valorLeido, 2, '.', ''), '0'), '.');

        return strtolower($this->concepto) . ':' . $valor;
    }
}