<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\ConsumoConfigStore;
use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Persistencia de los umbrales de consumo por empresa.
 *
 * Una fila por empresa, con `empresa_id` único. El `upsert` evita el
 * select-previo por la misma razón que el almacén de muestras: dos requests
 * simultáneos sobre el mismo formulario de configuración no pueden decidir
 * los dos que la fila no existe y terminar insertándola dos veces.
 *
 * Los nulos se guardan como nulos. Un `null` es «sin configurar» y un `0` es
 * una decisión; confundirlos al leer cambiaría la clasificación de todas las
 * muestras de la empresa.
 */
final class CodeIgniterConsumoConfigStore implements ConsumoConfigStore
{
    private const TABLA = 'telemetria_consumo_config';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function obtener(int $empresaId): ?ConsumoConfigEmpresa
    {
        if ($empresaId <= 0 || $this->mysql->tableExists(self::TABLA) === false) {
            return null;
        }

        $fila = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->get()
            ->getRowArray();

        return $fila === null ? null : $this->config($fila);
    }

    public function guardar(ConsumoConfigEmpresa $config): ConsumoConfigEmpresa
    {
        if ($this->mysql->tableExists(self::TABLA) === false) {
            throw new \RuntimeException(
                'Falta la tabla ' . self::TABLA . '. Corré las migraciones antes de configurar los umbrales.',
            );
        }

        $fila = $this->valores($config);
        $existe = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $config->empresaId())
            ->get()
            ->getRowArray();

        if ($existe === null) {
            $this->mysql->table(self::TABLA)->insert($fila);
        } else {
            $this->mysql->table(self::TABLA)
                ->where('id', (int) $existe['id'])
                ->update($fila);
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function valores(ConsumoConfigEmpresa $config): array
    {
        $v = $config->valores();

        return [
            'empresa_id' => $config->empresaId(),
            'delta_minimo_litros' => $v['deltaMinimoLitros'],
            'umbral_carga_litros' => $v['umbralCargaLitros'],
            'hueco_maximo_atribucion_minutos' => $v['huecoMaximoAtribucion'],
            'km_minimos_viaje' => $v['kmMinimosViaje'],
            'minutos_minimos_viaje' => $v['minutosMinimosViaje'],
            'intervalos_parado_para_cerrar' => $v['intervalosParadoParaCerrar'],
            'cobertura_minima' => $v['coberturaMinima'],
            'hueco_maximo_cobertura_minutos' => $v['huecoMaximoCobertura'],
            'velocidad_minima_plausible_kmh' => $v['velocidadMinimaPlausibleKmh'],
            'velocidad_maxima_plausible_kmh' => $v['velocidadMaximaPlausibleKmh'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $fila */
    private function config(array $fila): ConsumoConfigEmpresa
    {
        return new ConsumoConfigEmpresa(
            (int) $fila['empresa_id'],
            $fila['delta_minimo_litros'] === null ? null : (float) $fila['delta_minimo_litros'],
            $fila['umbral_carga_litros'] === null ? null : (float) $fila['umbral_carga_litros'],
            $fila['hueco_maximo_atribucion_minutos'] === null ? null : (int) $fila['hueco_maximo_atribucion_minutos'],
            $fila['km_minimos_viaje'] === null ? null : (int) $fila['km_minimos_viaje'],
            $fila['minutos_minimos_viaje'] === null ? null : (int) $fila['minutos_minimos_viaje'],
            $fila['intervalos_parado_para_cerrar'] === null ? null : (int) $fila['intervalos_parado_para_cerrar'],
            $fila['cobertura_minima'] === null ? null : (float) $fila['cobertura_minima'],
            $fila['hueco_maximo_cobertura_minutos'] === null ? null : (int) $fila['hueco_maximo_cobertura_minutos'],
            $fila['velocidad_minima_plausible_kmh'] === null ? null : (float) $fila['velocidad_minima_plausible_kmh'],
            $fila['velocidad_maxima_plausible_kmh'] === null ? null : (float) $fila['velocidad_maxima_plausible_kmh'],
        );
    }
}