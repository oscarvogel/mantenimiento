<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Vincula equipos de una integración con sus unidades externas.
 *
 * Tiene dos modos y ninguno adivina:
 *
 * - `--archivo`: mapa explícito `equipo_id,unidad_externa,rol`. Es el modo
 *   seguro y el que queda como registro.
 * - `--por-patente`: resuelve por patente **dentro de la empresa** y se niega
 *   a seguir si una patente no resuelve exactamente un equipo. La patente no
 *   se usa para el vínculo permanente: sirve sólo para ahorrar el tipeo del
 *   alta.
 *
 * La razón de la negación: la misma patente puede existir en dos empresas y
 * una coincidencia ambigua sería un vínculo silenciosamente equivocado, que
 * es la peor forma de equivocarse.
 */
final class TelemetriaVincular extends BaseCommand
{
    protected $group       = 'Mantenimiento';
    protected $name        = 'telematria:vincular';
    protected $description = 'Vincula equipos de una integracion con sus unidades externas.';
    protected $usage       = 'telematia:vincular --integracion=1 (--por-patente | --archivo=/ruta/mapeo.csv) [--dry-run]';
    protected $options     = [
        '--integracion' => 'Id de la integracion. Obligatorio.',
        '--por-patente' => 'Resuelve las unidades por patente dentro de la empresa.',
        '--archivo'     => 'CSV con columnas equipo_id,unidad_externa,rol.',
        '--rol'         => 'Rol por defecto en el modo por patente. Por defecto PRINCIPAL.',
        '--dry-run'     => 'Muestra lo que haría sin escribir.',
    ];

    public function run(array $params): int
    {
        try {
            $integracionId = $this->int($params, 'integracion');
            if ($integracionId === null || $integracionId <= 0) {
                CLI::error('Falta --integracion con un id valido.');

                return EXIT_ERROR;
            }

            $integracion = $this->integracion($integracionId);
            if ($integracion === null) {
                CLI::error('La integracion ' . $integracionId . ' no existe o esta inactiva.');

                return EXIT_ERROR;
            }

            $dryRun = $this->flag($params, 'dry-run');
            if ($dryRun) {
                CLI::write('MODO SIMULACION: no se escribe nada.', 'yellow');
            }

            $vinculos = $this->flag($params, 'por-patente')
                ? $this->porPatente($integracion)
                : $this->desdeArchivo($params, $integracion);

            if ($vinculos === []) {
                CLI::error('No hay vinculos para aplicar.');

                return EXIT_ERROR;
            }

            $this->aplicar($vinculos, $integracionId, $dryRun);

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @param list<array{equipo_id:int, unidad_externa:string, rol:string, codigo:string}> $vinculos
     */
    private function aplicar(array $vinculos, int $integracionId, bool $dryRun): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $insertados = 0;
        $omitidos = 0;

        CLI::write('');
        CLI::write(sprintf('%-8s %-16s %-14s %-12s %s', 'equipo', 'codigo', 'unidad_externa', 'rol', 'estado'));

        foreach ($vinculos as $vinculo) {
            $existente = $db->table('equipo_telemetria')
                ->select('id')
                ->where('integracion_id', $integracionId)
                ->where('equipo_id', $vinculo['equipo_id'])
                ->get()
                ->getRowArray();

            if ($existente !== null) {
                $omitidos++;
                CLI::write(sprintf('%-8s %-16s %-14s %-12s %s', $vinculo['equipo_id'], $vinculo['codigo'], $vinculo['unidad_externa'], $vinculo['rol'], 'ya vinculado'));
                continue;
            }

            if (! $dryRun) {
                $ok = $db->table('equipo_telemetria')->insert([
                    'empresa_id' => $this->empresaDe($vinculo['equipo_id']),
                    'integracion_id' => $integracionId,
                    'equipo_id' => $vinculo['equipo_id'],
                    'unidad_externa' => $vinculo['unidad_externa'],
                    'rol' => $vinculo['rol'],
                    'activo' => 1,
                    'created_at' => $now,
                ]);
                if (! $ok) {
                    $error = $db->error();
                    CLI::write(sprintf('%-8s %-16s %-14s %-12s %s', $vinculo['equipo_id'], $vinculo['codigo'], $vinculo['unidad_externa'], $vinculo['rol'], 'ERROR'), 'red');
                    CLI::write('  ' . trim((string) ($error['message'] ?? '')), 'red');
                    continue;
                }
            }

            $insertados++;
            CLI::write(sprintf('%-8s %-16s %-14s %-12s %s', $vinculo['equipo_id'], $vinculo['codigo'], $vinculo['unidad_externa'], $vinculo['rol'], $dryRun ? 'a insertar' : 'vinculado'), 'green');
        }

        CLI::write('');
        CLI::write($dryRun
            ? sprintf('Simulacion: %d a insertar, %d ya estaban.', $insertados, $omitidos)
            : sprintf('Listo: %d vinculados, %d ya estaban.', $insertados, $omitidos), 'green');
    }

    /** @return list<array{equipo_id:int, unidad_externa:string, rol:string, codigo:string}> */
    private function porPatente(array $integracion): array
    {
        $empresaId = (int) $integracion['empresa_id'];
        $rol = $this->rolPorDefecto();

        $equipos = db_connect()->table('equipos')
            ->select('id, codigo, patente')
            ->where('empresa_id', $empresaId)
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $externas = $this->unidadesDelProveedor($integracion);
        if ($externas === []) {
            CLI::error('El proveedor no devolvio unidades. No se puede vincular nada.');

            return [];
        }

        $porNombre = [];
        foreach ($externas as $unidad) {
            $porNombre[strtoupper($unidad['nombre'])] = $unidad['id'];
        }

        $vinculos = [];
        $sinRespaldo = [];

        foreach ($equipos as $equipo) {
            $clave = strtoupper(trim((string) ($equipo['patente'] ?: $equipo['codigo'])));
            if ($clave === '' || ! isset($porNombre[$clave])) {
                $sinRespaldo[] = $equipo['codigo'];
                continue;
            }

            $vinculos[] = [
                'equipo_id' => (int) $equipo['id'],
                'unidad_externa' => (string) $porNombre[$clave],
                'rol' => $rol,
                'codigo' => (string) $equipo['codigo'],
            ];
        }

        if ($sinRespaldo !== []) {
            CLI::write('');
            CLI::write('Equipos de la empresa sin unidad equivalente en el proveedor:', 'yellow');
            foreach ($sinRespaldo as $codigo) {
                CLI::write('  ' . $codigo, 'yellow');
            }
            CLI::write('  Se los deja sin vincular: no se escribe nada por deduccion.', 'yellow');
        }

        return $vinculos;
    }

    /** @return list<array{id:int, nombre:string}> */
    private function unidadesDelProveedor(array $integracion): array
    {
        if (strtolower((string) $integracion['proveedor']) !== 'wialon') {
            CLI::error('El modo por patente solo esta implementado para wialon. Usá --archivo para otros proveedores.');

            return [];
        }

        $endpoint = (string) $integracion['endpoint'];
        $token = $this->descifrar((string) ($integracion['token_cifrado'] ?? ''));
        if ($token === '') {
            CLI::error('La integracion no tiene token descifrable. Rotala con telemetria:integracion.');

            return [];
        }

        $params = json_encode([
            'spec' => [
                'itemsType' => 'avl_unit',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => 65535,
            'from' => 0,
            'to' => 0,
        ], JSON_THROW_ON_ERROR);

        $login = $this->llamar($endpoint, 'token/login', json_encode(['token' => $token], JSON_THROW_ON_ERROR));
        $sid = is_array($login) ? ($login['eid'] ?? null) : null;
        if (! is_string($sid) || $sid === '') {
            CLI::error('Wialon no devolvio sesion. Token vencido o invalido.');

            return [];
        }

        $items = $this->llamar($endpoint, 'core/search_items', $params, $sid);
        $this->llamar($endpoint, 'core/logout', '{}', $sid);

        $unidades = [];
        foreach (is_array($items['items'] ?? null) ? $items['items'] : [] as $item) {
            $unidades[] = [
                'id' => (string) $item['id'],
                'nombre' => trim((string) ($item['nm'] ?? '')),
            ];
        }

        return $unidades;
    }

    /** @return array<string,mixed>|null */
    private function integracion(int $id): ?array
    {
        return db_connect()->table('integraciones_telemetria')
            ->where('id', $id)
            ->where('activo', 1)
            ->get()
            ->getRowArray() ?: null;
    }

    /** @return list<array{equipo_id:int, unidad_externa:string, rol:string, codigo:string}> */
    private function desdeArchivo(array $params, array $integracion): array
    {
        $ruta = $this->texto($params, 'archivo');
        if ($ruta === null || ! is_readable($ruta)) {
            CLI::error('Falta --archivo con una ruta legible.');

            return [];
        }

        $manejador = fopen($ruta, 'rb');
        if ($manejador === false) {
            return [];
        }

        $cabecera = fgetcsv($manejador);
        if ($cabecera === false) {
            fclose($manejador);

            return [];
        }

        $indices = array_map(static fn (string $columna): string => strtolower(trim($columna)), $cabecera);
        if (! in_array('equipo_id', $indices, true) || ! in_array('unidad_externa', $indices, true)) {
            fclose($manejador);
            CLI::error('El CSV necesita las columnas equipo_id y unidad_externa.');

            return [];
        }

        $codigos = $this->codigosDe($integracion['empresa_id']);
        $rolPorDefecto = $this->rolPorDefecto();
        $vinculos = [];

        while (($fila = fgetcsv($manejador)) !== false) {
            $fila = array_pad($fila, count($indices), null);
            $registro = array_combine($indices, array_slice($fila, 0, count($indices)));
            if ($registro === false || ($registro['equipo_id'] ?? '') === '') {
                continue;
            }

            $equipoId = (int) $registro['equipo_id'];
            if (! isset($codigos[$equipoId])) {
                CLI::error('El equipo ' . $equipoId . ' no pertenece a la empresa ' . $integracion['empresa_id'] . '.');

                continue;
            }

            $vinculos[] = [
                'equipo_id' => $equipoId,
                'unidad_externa' => trim((string) $registro['unidad_externa']),
                'rol' => trim((string) ($registro['rol'] ?? '')) !== '' ? trim((string) $registro['rol']) : $rolPorDefecto,
                'codigo' => $codigos[$equipoId],
            ];
        }

        fclose($manejador);

        return $vinculos;
    }

    /** @return array<int,string> */
    private function codigosDe(int $empresaId): array
    {
        $filas = db_connect()->table('equipos')
            ->select('id, codigo')
            ->where('empresa_id', $empresaId)
            ->get()
            ->getResultArray();

        $codigos = [];
        foreach ($filas as $fila) {
            $codigos[(int) $fila['id']] = (string) $fila['codigo'];
        }

        return $codigos;
    }

    private function empresaDe(int $equipoId): int
    {
        return (int) db_connect()->table('equipos')->select('empresa_id')->where('id', $equipoId)->get()->getRowArray()['empresa_id'];
    }

    /** @return array<string,mixed>|null */
    private function llamar(string $endpoint, string $svc, string $params, ?string $sid = null): ?array
    {
        $url = $endpoint . '?svc=' . rawurlencode($svc) . '&params=' . rawurlencode($params);
        if ($sid !== null) {
            $url .= '&sid=' . rawurlencode($sid);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $cuerpo = curl_exec($curl);
        curl_close($curl);

        $decodificado = json_decode((string) $cuerpo, true);
        if (! is_array($decodificado)) {
            return null;
        }

        if (isset($decodificado['error']) && (int) $decodificado['error'] !== 0) {
            CLI::error('Wialon devolvio el codigo ' . (int) $decodificado['error'] . ' en ' . $svc . '.');

            return null;
        }

        return $decodificado;
    }

    private function descifrar(string $valor): string
    {
        if ($valor === '') {
            return '';
        }

        $crudo = base64_decode($valor, true);

        return $crudo === false ? '' : (string) service('encrypter')->decrypt($crudo);
    }

    private function rolPorDefecto(): string
    {
        $rol = $this->texto(['rol' => CLI::getOption('rol') ?: null], 'rol');
        $rol = is_string($rol) && $rol !== '' ? $rol : 'PRINCIPAL';

        return strtoupper($rol) === 'SECUNDARIA' ? 'SECUNDARIA' : 'PRINCIPAL';
    }

    private function texto(array $params, string $name): ?string
    {
        $value = $params[$name] ?? CLI::getOption($name);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function int(array $params, string $name): ?int
    {
        $value = $params[$name] ?? CLI::getOption($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private function flag(array $params, string $name): bool
    {
        $value = $params[$name] ?? CLI::getOption($name);

        return $value !== null && $value !== false && $value !== '0';
    }
}