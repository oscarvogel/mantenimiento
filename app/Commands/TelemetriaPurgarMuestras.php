<?php

declare(strict_types=1);

namespace App\Commands;

use App\Application\Telematic\PurgarMuestrasVencidas;
use CodeIgniter\CLI\BaseCommand;
use Throwable;

/**
 * Purga las muestras de telemetría que superaron la ventana de retención.
 *
 * Es un comando y no parte del ciclo de ingesta a propósito: borrar histórico
 * es una decisión operativa con fecha, no algo que deba pasar cada quince
 * minutos. El respaldo se escribe **antes** de borrar y se verifica; si algo no
 * cuadra, no se borra nada y el comando sale con error.
 *
 * El respaldo queda en `writable/telemetria/`, que no se versiona y no se
 * publica. Copiarlo a otro lado es responsabilidad de quien lo ejecute.
 */
final class TelemetriaPurgarMuestras extends BaseCommand
{
    use OpcionLegible;

    protected $group       = 'Mantenimiento';
    protected $name        = 'telematria:purgar-muestras';
    protected $description = 'Purga las muestras de telemetria fuera de la ventana de retencion.';
    protected $usage       = 'telematria:purgar-muestras --empresa=4 [--dias=90] [--respaldo=writable/telemetria/muestras-<fecha>.jsonl]';
    protected $arguments   = [];
    protected $options     = [
        '--empresa'   => 'Id de la empresa. Obligatorio.',
        '--dias'      => 'Dias de retencion. Por defecto 90.',
        '--respaldo'  => 'Ruta del archivo de respaldo. Por defecto writable/telemetria/muestras.jsonl',
    ];

    public function run(array $params): int
    {
        $empresaId = $this->opcionEntera($params, 'empresa', 0);

        if ($empresaId <= 0) {
            CLI::error('Falta --empresa con un id valido.');

            return EXIT_ERROR;
        }

        $dias = $this->opcionEntera($params, 'dias', 90);

        if ($dias <= 0) {
            CLI::error('--dias tiene que ser un numero positivo.');

            return EXIT_ERROR;
        }

        $respaldo = (string) $this->opcion($params, 'respaldo', 'writable/telemetria/muestras.jsonl');

        try {
            $purga = new PurgarMuestrasVencidas(
                \Config\Services::muestrasHistorico(),
                \Config\Services::muestrasRespaldo(),
                $empresaId,
                $dias,
            );

            $resultado = $purga->execute(new \DateTimeImmutable('now'), $respaldo);
        } catch (Throwable $exception) {
            // El mensaje importa: es la diferencia entre "no se borró nada" y un
            // error genérico. Si el respaldo falló, el histórico sigue entero.
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            'Corte %s | vencidas %d | borradas %d | respaldo %s',
            $purga->corte(new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            $resultado['vencidas'],
            $resultado['borradas'],
            $resultado['respaldo'] ?? 'sin archivo',
        ));

        return EXIT_SUCCESS;
    }
}