<?php

declare(strict_types=1);

namespace App\Commands;

use App\Application\Telematic\CalcularConsumoPorEquipo;
use App\Application\Telematic\ConfigurarConsumoPorEmpresa;
use App\Infrastructure\Telematic\CodeIgniterConsumoConfigStore;
use App\Infrastructure\Telematic\CodeIgniterSerieMuestrasReader;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DateTimeImmutable;

/**
 * Diagnóstico del histórico de muestras y del consumo calculado.
 *
 * El comando tiene dos propósitos, y los dos dependen de que diga la verdad
 * sobre su propia validez:
 *
 * 1. **Hoy.** Ver si hay datos y qué sale, sin acceso a la base.
 *
 * 2. **En unas semanas, ya en producción.** Medir el ruido real del sensor de
 *    combustible y ajustar `deltaMinimoLitros` y `umbralCargaLitros`, que
 *    arrancan como valores de fábrica que nadie verificó.
 *
 * Por eso imprime los umbrales que aplicó **y avisa que no están calibrados**.
 * Un diagnóstico que oculta su propia incertidumbre sirve para confirmar que el
 * código corre, no para decidir.
 */
final class TelemetriaMuestras extends BaseCommand
{
    use OpcionLegible;

    protected $group       = 'Mantenimiento';
    protected $name        = 'telematria:muestras';
    protected $description = 'Diagnostico del historico de muestras y del consumo calculado.';
    protected $usage       = 'telematria:muestras --empresa=4 [--equipo=42] [--dias=7]';
    protected $arguments   = [];
    protected $options     = [
        '--empresa' => 'Id de la empresa. Obligatorio.',
        '--equipo'  => 'Id del equipo. Si falta, se recorren los principales de la empresa.',
        '--dias'    => 'Dias a mirar hacia atras. Por defecto 7.',
    ];

    public function run(array $params): int
    {
        $empresaId = $this->opcionEntera($params, 'empresa', 0);

        if ($empresaId <= 0) {
            CLI::error('Falta --empresa con un id valido.');

            return EXIT_ERROR;
        }

        $dias = $this->opcionEntera($params, 'dias', 7);

        if ($dias <= 0) {
            CLI::error('--dias tiene que ser un numero positivo.');

            return EXIT_ERROR;
        }

        $hasta = new DateTimeImmutable('now');
        $desde = $hasta->sub(new DateInterval('P' . $dias . 'D'));

        $db = \Config\Database::connect();
        $equipoId = $this->opcionEntera($params, 'equipo', 0);
        $equipos = $equipoId > 0 ? [$equipoId] : $this->equiposPrincipales($db, $empresaId);

        CLI::write(sprintf(
            'Empresa %d | ventana %s a %s (%d dias)',
            $empresaId,
            $desde->format('Y-m-d H:i'),
            $hasta->format('Y-m-d H:i'),
            $dias,
        ));
        CLI::newline();

        if ($equipos === []) {
            CLI::error('La empresa no tiene equipos con telemetria registrada.');

            return EXIT_ERROR;
        }

        $avisos = [];
        $config = new ConfigurarConsumoPorEmpresa(new CodeIgniterConsumoConfigStore($db));
        $politica = $config->politica($empresaId);

        foreach ($equipos as $id) {
            $reporte = (new CalcularConsumoPorEquipo(new CodeIgniterSerieMuestrasReader($db), $politica))
                ->execute($empresaId, $id, $desde, $hasta);

            $this->equipo($reporte);
            $avisos = array_merge($avisos, $this->avisos($politica, $empresaId));
        }

        CLI::newline();
        CLI::write('Umbrales aplicados', 'yellow');
        CLI::write($this->avisos === [] ? '  (sin datos para resumir)' : '  ' . implode("\n  ", array_unique($avisos)));

        return EXIT_SUCCESS;
    }

    private function equipo(\App\Domain\Telematic\Consumo\ReporteConsumo $reporte): void
    {
        $cobertura = $reporte->cobertura();
        $distribucion = $reporte->distribucionCombustible();

        CLI::write(sprintf(
            'Equipo %d | %d muestras',
            $reporte->equipoId(),
            $reporte->muestrasLeidas(),
        ));

        $porcentaje = (int) ($cobertura->cobertura() * 100);

        CLI::write(sprintf(
            '  cobertura    %d%% (%d de %d min) | huecos: %s',
            $porcentaje,
            $cobertura->minutosCubiertos(),
            $cobertura->minutosEsperados(),
            $cobertura->huecosMinutos() === []
                ? 'ninguno'
                : implode(', ', $cobertura->huecosMinutos()) . ' min',
        ));

        if (! $cobertura->suficiente()) {
            CLI::write('  ' . (string) $cobertura->motivoInsuficiente(), 'yellow');
        }

        CLI::write(sprintf(
            '  combustible  %d intervalos con lectura, %d sin | mediana %s | rango %s a %s',
            $distribucion->intervalosConLectura(),
            $distribucion->sinLectura(),
            $this->litros($distribucion->medianaAbsoluta()),
            $this->litros($distribucion->minimoAbsoluto()),
            $this->litros($distribucion->maximoAbsoluto()),
        ));

        $clases = [];

        foreach ($distribucion->porClasificacion() as $clave => $cantidad) {
            $clases[] = $clave . '=' . $cantidad;
        }

        CLI::write('  intervalos   ' . ($clases === [] ? 'ninguno' : implode('  ', $clases)));
        CLI::write(sprintf(
            '  viajes       %d | con l/h %d | sin l/100km %d',
            count($reporte->viajes()),
            $reporte->viajesConHora(),
            $reporte->viajesSinConsumo(),
        ));
    }

    /**
     * Imprime los umbrales aplicados marcando de dónde sale cada uno.
     *
     * Mostrar sólo el número no alcanza: cuando la empresa todavía usa los
     * valores de fábrica hay que poder distinguir "esto lo eligió alguien" de
     * "esto se decidió en el código y nadie lo verificó".
     */
private function avisos(\App\Domain\Telematic\Consumo\PoliticaConsumo $politica, int $empresaId): array
    {
        $config = (new CodeIgniterConsumoConfigStore())->obtener($empresaId);
        $origen = $politica->valoresConOrigen(
            $config ?? new \App\Domain\Telematic\Consumo\ConsumoConfigEmpresa($empresaId),
        );

        $avisos = [];

        foreach ($origen as $clave => $dato) {
            $avisos[] = sprintf('%-32s %-8s %s', $clave, $this->numero((float) $dato['valor']), $dato['origen']);
        }

        if (! $politica->calibrada) {
            array_unshift(
                $avisos,
                'SIN CALIBRAR: la empresa no configuro umbrales propios.',
                '  Los valores marcados "fabrica" son un punto de partida razonable,',
                '  no una medicion. Verifique con datos reales antes de confiar en el informe.',
            );
        }

        return $avisos;
    }

    private function numero(float $valor): string
    {
        $texto = rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');

        return $texto === '' ? '0' : $texto;
    }

    private function litros(?float $valor): string
    {
        return $valor === null
            ? 'sin datos'
            : rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',') . ' l';
    }

    /** @return list<int> */
    private function equiposPrincipales(BaseConnection $db, int $empresaId): array
    {
        if ($db->tableExists('equipo_telemetria') === false) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (array $fila): int => (int) $fila['equipo_id'],
            $db->table('equipo_telemetria')
                ->select('equipo_id')
                ->where('empresa_id', $empresaId)
                ->where('rol', 'PRINCIPAL')
                ->where('activo', 1)
                ->get()
                ->getResultArray(),
        )));
    }
}