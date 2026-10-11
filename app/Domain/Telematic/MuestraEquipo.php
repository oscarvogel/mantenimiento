<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una captura de telemetría ya consultada, con la identidad de dónde vino.
 *
 * La diferencia con `InstantaneaEquipo` es que ésta existe en la base y tiene
 * dueño: de qué empresa, de qué integración y de qué unidad externa. La
 * instantánea es un hecho del proveedor; la muestra es un hecho nuestro sobre
 * un hecho del proveedor, y por eso tiene empresa.
 *
 * **No repite las reglas de validación de `InstantaneaEquipo`.** Las contiene.
 * Un odómetro negativo, cero voltajes o litros negativos ya fueron Derivados a
 * `anomalias` al construir la instantánea. Volver a comprobarlos aquí
 * duplicaría la entrada de la alerta y su firma lógica, con lo cual el mismo
 * sensor caído avisaría dos veces. La única regla propia es la identidad, que
 * `InstantaneaEquipo` no tiene porque una señal de un proveedor todavía no sabe
 * a qué equipo de qué empresa pertenece.
 *
 * `observada_en` y `registrada_en` van separadas a propósito. La primera es la
 * del proveedor; la segunda, cuándo la consultamos nosotros. Sin esa
 * distinción no se puede decir si un punto del mapa es de ahora o de hace tres
 * días, y `desfaseMinutos()` mide justamente cuánto se separan.
 */
final readonly class MuestraEquipo
{
    public function __construct(
        private int $empresaId,
        private int $integracionId,
        private int $equipoId,
        private string $unidadExterna,
        private string $proveedor,
        private InstantaneaEquipo $instantanea,
        private DateTimeImmutable $registradaEn,
    ) {
        // El scoping por empresa es la invariante de seguridad del sistema. Una
        // muestra sin empresa no se puede leer después sin filtrarla entera, y
        // el filtro es más caro que la validación.
        if ($this->empresaId <= 0) {
            throw new InvalidArgumentException('La muestra necesita una empresa válida.');
        }

        if ($this->integracionId <= 0) {
            throw new InvalidArgumentException('La muestra necesita una integración válida.');
        }

        if ($this->equipoId <= 0) {
            throw new InvalidArgumentException('La muestra necesita un equipo válido.');
        }

        if (trim($this->unidadExterna) === '') {
            throw new InvalidArgumentException('La muestra necesita la unidad externa del proveedor.');
        }

        if (trim($this->proveedor) === '') {
            throw new InvalidArgumentException('La muestra necesita el proveedor que la reportó.');
        }
    }

    public function empresaId(): int
    {
        return $this->empresaId;
    }

    public function integracionId(): int
    {
        return $this->integracionId;
    }

    public function equipoId(): int
    {
        return $this->equipoId;
    }

    public function unidadExterna(): string
    {
        return $this->unidadExterna;
    }

    public function proveedor(): string
    {
        return $this->proveedor;
    }

    /** La captura tal como la entiende el proveedor. */
    public function instantanea(): InstantaneaEquipo
    {
        return $this->instantanea;
    }

    /** Instante reportado por el proveedor. Es el que ordena la serie. */
    public function observadaEn(): DateTimeImmutable
    {
        return $this->instantanea->observadaEn();
    }

    /** Instante en que la consultamos nosotros. */
    public function registradaEn(): DateTimeImmutable
    {
        return $this->registradaEn;
    }

    public function posicion(): ?Posicion
    {
        return $this->instantanea->posicion();
    }

    public function kilometraje(): ?int
    {
        return $this->instantanea->kilometraje();
    }

    public function horasDecimales(): ?int
    {
        return $this->instantanea->horasDecimales();
    }

    public function motorEncendido(): ?bool
    {
        return $this->instantanea->motorEncendido();
    }

    public function ralentiActivo(): ?bool
    {
        return $this->instantanea->ralentiActivo();
    }

    public function voltaje(): ?float
    {
        return $this->instantanea->voltaje();
    }

    public function combustibleLitros(): ?float
    {
        return $this->instantanea->combustibleLitros();
    }

    /** @return list<MedidaAdicional> */
    public function sensoresAdicionales(): array
    {
        return $this->instantanea->sensoresAdicionales();
    }

    /** @return list<LecturaImposible> */
    public function anomalias(): array
    {
        return $this->instantanea->anomalias();
    }

    public function tieneAnomalias(): bool
    {
        return $this->instantanea->tieneAnomalias();
    }

    /**
     * Qué antigüedad tenía la lectura cuando la consultamos.
     *
     * Es la magnitud que explica un desfase de reloj: si vale +4, el
     * proveedor llevaba cuatro minutos sin reportar, lo cual puede ocurrir
     * entre consultas cada cinco minutos. Si vale **negativo**, el reloj del proveedor
     * está adelantado y eso no es normal: significa que la serie va a mostrar
     * huecos donde no los hay, y que un cálculo de consumo sobre esos datos
     * pierde sentido.
     *
     * No se corrige ni se limita. Es información de la integración y
     * ocultarla esconde una fuente de errores.
     */
    public function demoraMinutos(): int
    {
        return intdiv(
            $this->registradaEn->getTimestamp() - $this->instantanea->observadaEn()->getTimestamp(),
            60,
        );
    }

    /** El proveedor tiene el reloj adelantado si la lectura es "del futuro". */
    public function relojProveedorAdelantado(): bool
    {
        return $this->demoraMinutos() < 0;
    }

    /** Antigüedad de la captura, no de su registro. */
    public function antiguedadMinutos(DateTimeImmutable $ahora): int
    {
        return $this->instantanea->antiguedadMinutos($ahora);
    }
}
