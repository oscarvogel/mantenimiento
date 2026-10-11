<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use InvalidArgumentException;

/**
 * Medida que el proveedor reporta y el dominio no sabe interpretar.
 *
 * Se conserva con su etiqueta original para que nada se pierda en la
 * traducción, pero no entra en la lógica: no dispara alertas ni calcula
 * mantenimientos. Es el límite de la capa anticorrupción hecho explícito.
 */
final readonly class MedidaAdicional
{
    public function __construct(
        private string $etiqueta,
        private ?float $valor,
        private string $unidad,
        private string $tipoProveedor,
    ) {
        if (trim($etiqueta) === '') {
            throw new InvalidArgumentException('La medida adicional necesita etiqueta.');
        }
    }

    public function etiqueta(): string
    {
        return $this->etiqueta;
    }

    public function valor(): ?float
    {
        return $this->valor;
    }

    public function unidad(): string
    {
        return $this->unidad;
    }

    public function tipoProveedor(): string
    {
        return $this->tipoProveedor;
    }

    public function texto(): string
    {
        if ($this->valor === null) {
            return $this->etiqueta;
        }

        $valor = rtrim(rtrim(number_format($this->valor, 2, ',', '.'), '0'), ',');

        return trim($this->etiqueta . ': ' . $valor . ' ' . $this->unidad);
    }
}