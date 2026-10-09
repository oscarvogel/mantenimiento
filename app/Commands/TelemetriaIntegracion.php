<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;
use Throwable;

/**
 * Alta o rotación de la credencial de un proveedor de telemetría.
 *
 * El token viaja cifrado con la clave de ESTA instalación. Es la razón de que
 * el alta sea un comando y no un INSERT: un token cifrado con la clave de
 * otra máquina no descifra acá, y el síntoma es un "integración caída" que
 * nadie entiende.
 *
 * El token se lee del entorno o de `--token` y nunca se imprime.
 */
final class TelemetriaIntegracion extends BaseCommand
{
    protected $group       = 'Mantenimiento';
    protected $name        = 'telematria:integracion';
    protected $description = 'Registra o rota la credencial de un proveedor de telemetria.';
    protected $usage       = 'telematria:integracion --empresa=4 [--proveedor=wialon] [--nombre="Wialon TSA"] [--endpoint=...] [--token=...]';
    protected $arguments   = [];
    protected $options     = [
        '--empresa'   => 'Id de la empresa proprietaria de la integracion. Obligatorio.',
        '--proveedor' => 'Identificador del proveedor. Por defecto wialon.',
        '--nombre'    => 'Nombre legible de la cuenta. Por defecto "Wialon <empresa>".',
        '--endpoint'  => 'Endpoint de la API. Por defecto el del proveedor.',
        '--token'     => 'Token. Si se omite se lee del entorno (WIALON_TOKEN para wialon).',
    ];

    private const ENDPOINTS = [
        'wialon' => 'https://hst-api.wialon.com/wialon/ajax.html',
        'gestya' => null,
    ];

    public function run(array $params): int
    {
        try {
            $empresaId = $this->int($params, 'empresa');
            if ($empresaId === null || $empresaId <= 0) {
                CLI::error('Falta --empresa con un id valido.');

                return EXIT_ERROR;
            }

            $provider = $this->text($params, 'proveedor') ?? 'wialon';
            $nombre = $this->text($params, 'nombre') ?? ('Wialon ' . $empresaId);
            $endpoint = $this->text($params, 'endpoint') ?? (self::ENDPOINTS[$provider] ?? null);

            if ($endpoint === null || ! str_starts_with(strtolower($endpoint), 'https://')) {
                CLI::error('El endpoint debe ser una URL https explicita para el proveedor ' . $provider . '.');

                return EXIT_ERROR;
            }

            $token = $this->token($params, $provider);
            if ($token === '') {
                CLI::error('No hay token. Pasalo con --token o definiendo la variable de entorno correspondiente.');

                return EXIT_ERROR;
            }

            if (! $this->empresaExiste($empresaId)) {
                CLI::error('La empresa ' . $empresaId . ' no existe.');

                return EXIT_ERROR;
            }

            $integracionId = $this->guardar($empresaId, $provider, $nombre, $endpoint, $token);

            CLI::write('Integracion registrada', 'green');
            CLI::write('  id        : ' . $integracionId);
            CLI::write('  empresa   : ' . $empresaId);
            CLI::write('  proveedor : ' . $provider);
            CLI::write('  nombre    : ' . $nombre);
            CLI::write('  endpoint  : ' . $endpoint);
            CLI::write('  token     : cifrado, ' . strlen($token) . ' caracteres (no se muestra)');
            CLI::write('');
            CLI::write('Siguiente paso:', 'yellow');
            CLI::write('  php spark telemetria:vincular --integracion=' . $integracionId . ' --por-patente');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }
    }

    private function guardar(int $empresaId, string $provider, string $nombre, string $endpoint, string $token): int
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');

        $payload = [
            'endpoint' => $endpoint,
            'token_cifrado' => $this->encrypt($token),
            'activo' => 1,
            'updated_at' => $now,
        ];

        $existente = $db->table('integraciones_telemetria')
            ->select('id')
            ->where('empresa_id', $empresaId)
            ->where('proveedor', $provider)
            ->where('nombre', $nombre)
            ->get()
            ->getRowArray();

        if ($existente !== null) {
            $db->table('integraciones_telemetria')->where('id', (int) $existente['id'])->update($payload);

            return (int) $existente['id'];
        }

        $db->table('integraciones_telemetria')->insert($payload + [
            'empresa_id' => $empresaId,
            'proveedor' => $provider,
            'nombre' => $nombre,
            'created_at' => $now,
        ]);

        return (int) $db->insertID();
    }

    private function token(array $params, string $provider): string
    {
        $token = $this->text($params, 'token');
        if ($token !== null) {
            return $token;
        }

        $clave = strtoupper($provider) . '_TOKEN';

        return trim((string) env($clave, ''));
    }

    private function empresaExiste(int $empresaId): bool
    {
        return db_connect()->table('empresas')
            ->where('id', $empresaId)
            ->countAllResults() > 0;
    }

    private function encrypt(string $value): string
    {
        try {
            return base64_encode(service('encrypter')->encrypt($value));
        } catch (Throwable) {
            throw new DomainException('No se pudo cifrar el token. Verificá encryption.key antes de guardar.');
        }
    }

    private function text(array $params, string $name): ?string
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
}