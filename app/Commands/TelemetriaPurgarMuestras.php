<?php

declare(strict_types=1);

namespace App\Commands;

use App\Application\Telematic\PurgarMuestrasVencidas;
use CodeIgniter\CLI\BaseCommand;
use DateTimeImmutable;
use DateTimeZone;
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
    protected $group       = 'Mantenimiento';
    protected $name        = 'telematria:purgar-muestras';
    protected $description = 'Purga las muestras de telemetria fuera de la ventana de retencion.';
    protected $usage       = 'telematria:purgar-muestras --empresa=4 [--dias=90] [--respaldo=writable/telemetria/muestras-<fecha>.jsonl]';
    protected $arguments   = [];
    protected $options     = [
        '--empresa'   => 'Id de la empresa. Obligatorio.',
        '--dias'      => 'Dias de retencion. Por defecto 90.',
        '--respaldo'  => 'Ruta del archivo. Por defecto se crea uno nuevo en writable/telemetria/.',
    ];

    public function run(array $params): int
    {
        $empresaId = (int) ($params['empresa'] ?? 0);

        if ($empresaId <= 0) {
            CLI::error('Falta --empresa con un id valido.');

            return self::EXIT_USAGE;
        }

        $dias = (int) ($params['dias'] ?? 90);

        if ($dias <= 0) {
            CLI::error('--dias tiene que ser un numero positivo.');

            return self::EXIT_USAGE;
        }

        $ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $respaldo = (string) ($params['respaldo'] ?? sprintf(
            'writable/telemetria/muestras-%s.jsonl',
            $ahora->format('Ymd-His-u'),
        ));

        try {
            $purga = new PurgarMuestrasVencidas(
                \Config\Services::muestrasHistorico(),
                \Config\Services::muestrasRespaldo(),
                $empresaId,
                $dias,
                \Config\Services::muestrasBucketsHistorico(),
                \Config\Services::muestrasBucketsRespaldo(),
            );

            $resultado = $purga->execute($ahora, $respaldo);
        } catch (Throwable $exception) {
            // El mensaje importa: es la diferencia entre "no se borró nada" y un
            // error genérico. Si el respaldo falló, el histórico sigue entero.
            CLI::error($exception->getMessage());

            return self::FAILURE;
        }

        CLI::write(sprintf(
            'Corte %s | muestras vencidas %d | borradas %d | buckets vencidos %d | borrados %d | respaldo muestras %s | respaldo buckets %s',
            $purga->corte($ahora)->format('Y-m-d H:i:s'),
            $resultado['vencidas'],
            $resultado['borradas'],
            $resultado['bucketsVencidos'],
            $resultado['bucketsBorrados'],
            $resultado['respaldo'] ?? 'sin archivo',
            $resultado['respaldoBuckets'] ?? 'sin archivo',
        ));

        return self::SUCCESS;
    }
}
