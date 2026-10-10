<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Telematic\Port\MuestraStore;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Almacén en memoria que imita la deduplicación de MySQL.
 *
 * Usa la misma clave que la tabla real —`(integracion, unidad externa, hora
 * observada)`— y devuelve 1 la primera vez y 0 después, que es exactamente lo
 * que responde `INSERT IGNORE` leyendo `affectedRows`. Un doble que devolviera
 * siempre 1 dejaría pasar un almacén que duplica la captura en cada reintento
 * del cron.
 */
final class MemoriaMuestras implements MuestraStore
{
    /** @var array<string, MuestraEquipo> */
    private array $guardadas = [];

    /** @var list<string> */
    public array $llamadas = [];

    public function guardar(MuestraEquipo $muestra): int
    {
        $clave = implode('|', [
            (string) $muestra->integracionId(),
            $muestra->unidadExterna(),
            $muestra->observadaEn()->format('Y-m-d H:i:s'),
        ]);

        $this->llamadas[] = $clave;

        if (isset($this->guardadas[$clave])) {
            return 0;
        }

        $this->guardadas[$clave] = $muestra;

        return 1;
    }

    public function total(): int
    {
        return count($this->guardadas);
    }
}

/** Lector en memoria que filtra y ordena como debe hacerlo el adaptador. */
final class MemoriaSerie implements SerieMuestrasReader
{
    /** @var list<MuestraEquipo> */
    private array $disponibles;

    /** @var list<array{empresa:int, equipo:int, desde:string, hasta:string}> */
    public array $consultas = [];

    /** @param list<MuestraEquipo> $disponibles */
    public function __construct(array $disponibles)
    {
        $this->disponibles = $disponibles;
    }

    public function serie(
        int $empresaId,
        int $equipoId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array {
        $this->consultas[] = [
            'empresa' => $empresaId,
            'equipo' => $equipoId,
            'desde' => $desde->format('Y-m-d H:i:s'),
            'hasta' => $hasta->format('Y-m-d H:i:s'),
        ];

        $filtradas = array_values(array_filter(
            $this->disponibles,
            static fn (MuestraEquipo $m): bool => $m->empresaId() === $empresaId
                && $m->equipoId() === $equipoId
                && $m->observadaEn() >= $desde
                && $m->observadaEn() <= $hasta,
        ));

        // El orden lo pone el lector, no el que llama. Un cálculo de intervalos
        // sobre una serie desordenada da consumos negativos sin avisar.
        usort(
            $filtradas,
            static fn (MuestraEquipo $a, MuestraEquipo $b): int => $a->observadaEn() <=> $b->observadaEn(),
        );

        return $filtradas;
    }
}

/**
 * Contrato de los puertos de muestras.
 *
 * Lo que se fija acá no es que el puerto exista, sino tres cosas que ya
 * aprendieron el precio de no fijarlas:
 *
 * 1. `guardar()` devuelve un entero, porque en MySQL la deduplicación es
 *    silenciosa y sin `affectedRows` no se distingue "guardé" de "ya estaba".
 * 2. `serie()` se pide siempre con empresa, equipo y ventana. El scoping no es
 *    opcional y por eso es el primer parámetro.
 * 3. La serie sale ordenada por la hora del proveedor, porque los cálculos de
 *    intervalos asumen ese orden y no avisan cuando no se cumple.
 *
 * @internal
 */
final class PuertosMuestrasTest extends \PHPUnit\Framework\TestCase
{
    public function testElAlmacenDistingueUnaCapturaNuevaDeUnaRepetida(): void
    {
        $almacen = new MemoriaMuestras();
        $muestra = $this->muestra('2026-10-10 06:15:00');

        self::assertSame(1, $almacen->guardar($muestra), 'La primera vez se guarda.');
        self::assertSame(0, $almacen->guardar($muestra), 'La segunda vez es la misma captura.');
        self::assertSame(1, $almacen->total(), 'Repetir no agrega una fila.');
    }

    public function testUnaCapturaDistintaSiSeGuardaAunqueSeaEnElMismoMinutoDeRegistro(): void
    {
        $almacen = new MemoriaMuestras();

        $almacen->guardar($this->muestra('2026-10-10 06:15:00'));
        $almacen->guardar($this->muestra('2026-10-10 06:30:00'));

        self::assertSame(2, $almacen->total(), 'La cadencia de 15 minutos se apila.');
    }

    public function testDosEquiposNoSePisanAunCompartiendoLaMismaHora(): void
    {
        $almacen = new MemoriaMuestras();

        $almacen->guardar($this->muestra('2026-10-10 06:15:00', equipoId: 42));
        $almacen->guardar($this->muestra('2026-10-10 06:15:00', equipoId: 43));

        self::assertSame(2, $almacen->total());
    }

    public function testLaSerieSePideConEmpresaEquipoYVentanaTemporal(): void
    {
        $serie = new MemoriaSerie([]);

        $serie->serie(
            7,
            42,
            new DateTimeImmutable('2026-10-01 00:00:00'),
            new DateTimeImmutable('2026-10-10 23:59:59'),
        );

        self::assertCount(1, $serie->consultas);
        self::assertSame(7, $serie->consultas[0]['empresa'], 'El scoping por empresa no es opcional.');
        self::assertSame(42, $serie->consultas[0]['equipo']);
        self::assertSame('2026-10-01 00:00:00', $serie->consultas[0]['desde']);
        self::assertSame('2026-10-10 23:59:59', $serie->consultas[0]['hasta']);
    }

    public function testLaSerieDevuelveLasCapturasOrdenadasPorLaHoraDelProveedor(): void
    {
        $disponibles = [
            $this->muestra('2026-10-10 06:30:00'),
            $this->muestra('2026-10-10 06:15:00'),
            $this->muestra('2026-10-10 06:22:30'),
        ];

        $serie = (new MemoriaSerie($disponibles))->serie(
            7,
            42,
            new DateTimeImmutable('2026-10-10 00:00:00'),
            new DateTimeImmutable('2026-10-10 23:59:59'),
        );

        $horas = array_map(
            static fn (MuestraEquipo $m): string => $m->observadaEn()->format('H:i:s'),
            $serie,
        );

        self::assertSame(['06:15:00', '06:22:30', '06:30:00'], $horas);
    }

    public function testLaSerieNoDevuelveMuestrasDeOtraEmpresaNiDeOtroEquipo(): void
    {
        $disponibles = [
            $this->muestra('2026-10-10 06:15:00'),
            $this->muestra('2026-10-10 06:16:00', empresaId: 9),
            $this->muestra('2026-10-10 06:17:00', equipoId: 43),
            $this->muestra('2026-10-09 06:18:00'),
            $this->muestra('2026-10-11 06:19:00'),
        ];

        $serie = (new MemoriaSerie($disponibles))->serie(
            7,
            42,
            new DateTimeImmutable('2026-10-10 00:00:00'),
            new DateTimeImmutable('2026-10-10 23:59:59'),
        );

        self::assertCount(1, $serie);
        self::assertSame(42, $serie[0]->equipoId());
        self::assertSame(7, $serie[0]->empresaId());
    }

    public function testNingunPuertoDeclaraTiposDeCodeIgniter(): void
    {
        // La regla de dependencia es hacia adentro: si un puerto de
        // Application menciona CodeIgniter, el dominio y los casos de uso
        // quedan atados al framework y dejan de poder probarse sin él.
        foreach ([MuestraStore::class, SerieMuestrasReader::class] as $puerto) {
            foreach ((new \ReflectionClass($puerto))->getMethods() as $metodo) {
                foreach ($this->tiposDe($metodo) as $tipo) {
                    self::assertStringStartsNotWith(
                        'CodeIgniter\\',
                        $tipo,
                        sprintf('%s::%s() no puede declarar tipos del framework.', $puerto, $metodo->getName()),
                    );
                }
            }
        }
    }

    public function testGuardarDevuelveUnEnteroYNoUnVacio(): void
    {
        $retorno = (new ReflectionMethod(MuestraStore::class, 'guardar'))->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $retorno);
        self::assertSame('int', $retorno->getName());
    }

    /** @return list<string> */
    private function tiposDe(ReflectionMethod $metodo): array
    {
        $tipos = [];

        foreach ($metodo->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            $tipos = array_merge($tipos, $this->nombresDe($tipo));
        }

        $tipos = array_merge($tipos, $this->nombresDe($metodo->getReturnType()));

        return $tipos;
    }

    /** @return list<string> */
    private function nombresDe(mixed $tipo): array
    {
        if ($tipo instanceof ReflectionNamedType) {
            return [$tipo->getName()];
        }

        if ($tipo instanceof ReflectionUnionType) {
            $nombres = [];

            foreach ($tipo->getTypes() as $interno) {
                $nombres = array_merge($nombres, $this->nombresDe($interno));
            }

            return $nombres;
        }

        return [];
    }

    private function muestra(
        string $observada,
        int $empresaId = 7,
        int $equipoId = 42,
    ): MuestraEquipo {
        $instante = new DateTimeImmutable($observada);

        return new MuestraEquipo(
            $empresaId,
            10,
            $equipoId,
            'WIALON-' . $equipoId,
            'WIALON',
            new InstantaneaEquipo(
                $instante,
                new Posicion(-33.0944, -68.88, 0.0, 216, 955.4, 25, $instante),
                487500,
                40625,
                false,
                false,
                24.51,
                660.48,
            ),
            new DateTimeImmutable('2026-10-10 06:20:00'),
        );
    }
}