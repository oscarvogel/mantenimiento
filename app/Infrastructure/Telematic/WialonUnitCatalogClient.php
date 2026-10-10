<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use DomainException;
use Throwable;

class WialonUnitCatalogClient
{
    /** @return list<array{id:string,name:string}> */
    public function listUnits(string $endpoint, string $token): array
    {
        $session = null;
        try {
            $login = $this->call($endpoint, 'token/login', ['token' => $token, 'fl' => 1]);
            $session = $login['eid'] ?? null;
            if (! is_string($session) || $session === '') {
                throw new DomainException('Wialon no aceptó el token. Revisá que esté vigente y tenga acceso a las unidades.');
            }

            $result = $this->call($endpoint, 'core/search_items', [
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
            ], $session);

            $units = [];
            foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
                $id = $item['id'] ?? null;
                $name = trim((string) ($item['nm'] ?? ''));
                if ($id !== null && $name !== '') {
                    $units[] = ['id' => (string) $id, 'name' => $name];
                }
            }

            if ($units === []) {
                throw new DomainException('Wialon conectó, pero no devolvió unidades para esta cuenta. Revisá los permisos del token.');
            }

            return $units;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DomainException('No se pudo consultar las unidades de Wialon. Intentá de nuevo en unos minutos.');
        } finally {
            if (is_string($session) && $session !== '') {
                try {
                    $this->call($endpoint, 'core/logout', new \stdClass(), $session);
                } catch (Throwable) {
                }
            }
        }
    }

    /** @param array<string,mixed>|object $params
     *  @return array<string,mixed>
     */
    private function call(string $endpoint, string $service, array|object $params, ?string $session = null): array
    {
        $payload = ['svc' => $service, 'params' => json_encode($params, JSON_THROW_ON_ERROR)];
        if ($session !== null) {
            $payload['sid'] = $session;
        }

        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new DomainException('No se pudo iniciar la conexión con Wialon.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || ! is_array($decoded) || (isset($decoded['error']) && (int) $decoded['error'] !== 0)) {
            throw new DomainException('Wialon rechazó la conexión. Revisá el token y sus permisos.');
        }

        return $decoded;
    }
}
