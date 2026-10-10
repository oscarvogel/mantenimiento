<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\HistoricoMuestras;
use App\Application\Telematic\Port\RespaldoMuestras;
use DateTimeImmutable;
use RuntimeException;

/**
 * Purga las muestras más viejas que la ventana de retención.
 *
 * El orden importa y no es negociable: **primero se respalda, se verifica que
 * el respaldo tenga exactamente las mismas filas, y recién entonces se borra.**
 *
 * Sin ese orden, un respaldo fallido deja la base sin histórico y sin forma de
 * recuperarlo: los segundos son la serie de la que salen el consumo por viaje,
 * el porcentaje de ralentí y el exceso de velocidad, y no hay de dónde
 * regenerarlos. Perder las últimas semanas no se nota; perder las primeras
 * tampoco; perderlas todas deja los reportes de tres meses en blanco sin que
 * nadie entienda por qué.
 *
 * El corte es **inclusivo por fecha de observación**, no por registro: se borra
 * lo que el proveedor observó antes del corte, que es lo que ordena la serie.
 */
final readonly class PurgarMuestrasVencidas
{
    public function __construct(
        private HistoricoMuestras $historico,
        private RespaldoMuestras $respaldo,
        private int $companyId,
        private int $diasRetencion = 90,
    ) {
        if ($diasRetencion <= 0) {
            throw new \InvalidArgumentException('La retención tiene que ser positiva.');
        }
    }

    /**
     * @return array{vencidas:int, borradas:int, respaldo:?string}
     */
    public function execute(DateTimeImmutable $ahora, ?string $rutaRespaldo = null): array
    {
        $corte = $ahora->sub(new \DateInterval('P' . $this->diasRetencion . 'D'));

        $vencidas = $this->historico->anterioresA($this->companyId, $corte);

        if ($vencidas === []) {
            return ['vencidas' => 0, 'borradas' => 0, 'respaldo' => null];
        }

        $esperadas = count($vencidas);

        if ($rutaRespaldo !== null) {
            $escritas = $this->respaldo->escribir($rutaRespaldo, $vencidas);

            if ($escritas !== $esperadas || ! $this->respaldo->verificar($rutaRespaldo, $esperadas)) {
                // No se borra nada. El histórico intacto es recuperable; el
                // histórico borrado sin respaldo, no.
                throw new RuntimeException(sprintf(
                    'El respaldo quedó incompleto (%d de %d filas). No se borró nada.',
                    $escritas,
                    $esperadas,
                ));
            }
        }

        $borradas = $this->historico->borrarAnterioresA($this->companyId, $corte);

        if ($borradas !== $esperadas) {
            throw new RuntimeException(sprintf(
                'Se esperaban %d filas vencidas y se borraron %d. Revisá el respaldo antes de seguir.',
                $esperadas,
                $borradas,
            ));
        }

        return ['vencidas' => $esperadas, 'borradas' => $borradas, 'respaldo' => $rutaRespaldo];
    }

    /** El corte que aplica este caso de uso, para mostrarlo en la operación. */
    public function corte(DateTimeImmutable $ahora): DateTimeImmutable
    {
        return $ahora->sub(new \DateInterval('P' . $this->diasRetencion . 'D'));
    }
}