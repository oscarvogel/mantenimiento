<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\HistoricoMuestras;
use App\Application\Telematic\Port\HistoricoBuckets;
use App\Application\Telematic\Port\RespaldoMuestras;
use App\Application\Telematic\Port\RespaldoBuckets;
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
 * El corte usa la fecha de observación, no la de registro: se purga solo lo
 * observado antes del instante UTC de retención, que es lo que ordena la serie.
 */
final readonly class PurgarMuestrasVencidas
{
    public function __construct(
        private HistoricoMuestras $historico,
        private RespaldoMuestras $respaldo,
        private int $companyId,
        private int $diasRetencion = 90,
        private ?HistoricoBuckets $historicoBuckets = null,
        private ?RespaldoBuckets $respaldoBuckets = null,
    ) {
        if ($diasRetencion <= 0) {
            throw new \InvalidArgumentException('La retención tiene que ser positiva.');
        }
    }

    /**
     * @return array{vencidas:int, borradas:int, respaldo:?string, bucketsVencidos:int, bucketsBorrados:int, respaldoBuckets:?string}
     */
    public function execute(DateTimeImmutable $ahora, ?string $rutaRespaldo = null): array
    {
        $corte = $this->corte($ahora);

        $vencidas = $this->historico->anterioresA($this->companyId, $corte);
        $bucketsVencidos = $this->historicoBuckets?->anterioresA($this->companyId, $corte) ?? [];

        if ($vencidas === [] && $bucketsVencidos === []) {
            return [
                'vencidas' => 0,
                'borradas' => 0,
                'respaldo' => null,
                'bucketsVencidos' => 0,
                'bucketsBorrados' => 0,
                'respaldoBuckets' => null,
            ];
        }

        $esperadas = count($vencidas);
        $esperadosBuckets = count($bucketsVencidos);
        $rutaBuckets = null;

        if ($rutaRespaldo !== null) {
            if ($esperadas > 0) {
                $escritas = $this->respaldo->escribir($rutaRespaldo, $vencidas);

                if ($escritas !== $esperadas || ! $this->respaldo->verificar($rutaRespaldo, $esperadas)) {
                    throw new RuntimeException(sprintf(
                        'El respaldo de muestras quedó incompleto (%d de %d filas). No se borró nada.',
                        $escritas,
                        $esperadas,
                    ));
                }
            }

            if ($esperadosBuckets > 0) {
                if ($this->respaldoBuckets === null) {
                    throw new RuntimeException('No hay adaptador de respaldo de buckets. No se borró nada.');
                }
                $rutaBuckets = $this->rutaDeRespaldoBuckets($rutaRespaldo);
                $escritos = $this->respaldoBuckets->escribir($rutaBuckets, $bucketsVencidos);
                if ($escritos !== $esperadosBuckets || ! $this->respaldoBuckets->verificar($rutaBuckets, $esperadosBuckets)) {
                    throw new RuntimeException(sprintf(
                        'El respaldo de buckets quedó incompleto (%d de %d filas). No se borró nada.',
                        $escritos,
                        $esperadosBuckets,
                    ));
                }
            }
        } else {
            throw new RuntimeException('Falta la ruta de respaldo. No se borró nada.');
        }

        $borradas = $esperadas > 0 ? $this->historico->borrarAnterioresA($this->companyId, $corte) : 0;

        if ($borradas !== $esperadas) {
            throw new RuntimeException(sprintf(
                'Se esperaban %d filas vencidas y se borraron %d. Revisá el respaldo antes de seguir.',
                $esperadas,
                $borradas,
            ));
        }

        $bucketsBorrados = $esperadosBuckets > 0
            ? ($this->historicoBuckets?->borrarAnterioresA($this->companyId, $corte) ?? 0)
            : 0;
        if ($bucketsBorrados !== $esperadosBuckets) {
            throw new RuntimeException(sprintf(
                'Se esperaban %d buckets vencidos y se borraron %d. Revisá el respaldo antes de seguir.',
                $esperadosBuckets,
                $bucketsBorrados,
            ));
        }

        return [
            'vencidas' => $esperadas,
            'borradas' => $borradas,
            'respaldo' => $esperadas > 0 ? $rutaRespaldo : null,
            'bucketsVencidos' => $esperadosBuckets,
            'bucketsBorrados' => $bucketsBorrados,
            'respaldoBuckets' => $rutaBuckets,
        ];
    }

    /** El corte que aplica este caso de uso, para mostrarlo en la operación. */
    public function corte(DateTimeImmutable $ahora): DateTimeImmutable
    {
        return $ahora
            ->setTimezone(new \DateTimeZone('UTC'))
            ->sub(new \DateInterval('P' . $this->diasRetencion . 'D'));
    }

    private function rutaDeRespaldoBuckets(string $rutaMuestras): string
    {
        if (str_ends_with(strtolower($rutaMuestras), '.jsonl')) {
            return substr($rutaMuestras, 0, -6) . '.buckets.jsonl';
        }

        return $rutaMuestras . '.buckets.jsonl';
    }
}
