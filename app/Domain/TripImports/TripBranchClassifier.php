<?php

declare(strict_types=1);

namespace App\Domain\TripImports;

/**
 * Clasifica un dominio o un texto operativo del legacy como Argentina o Brasil.
 *
 * El formato de patente Mercosur es la evidencia principal. Para las patentes
 * anteriores a Mercosur (tres letras y tres numeros) no alcanza: se resuelve por
 * mayoria de menciones de origen/destino, sin inventar un pais cuando no hay
 * evidencia suficiente.
 */
final class TripBranchClassifier
{
    public const AR = 'AR';
    public const BR = 'BR';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const UNKNOWN = 'UNKNOWN';

    private const BR_EVIDENCE = [
        'BRASIL', 'BRAZIL', 'SAO PAULO', 'SÃO PAULO', 'CURITIBA', 'PORTO ALEGRE',
        'FLORIANOPOLIS', 'FLORIANÓPOLIS', 'RIO DE JANEIRO', 'SANTA CATARINA',
        'RIO GRANDE DO SUL', 'MATO GROSSO', 'GOIAS', 'GOIÁS', 'MINAS GERAIS',
        'ESPIRITO SANTO', 'ESPÍRITO SANTO', 'CEARA', 'CEARÁ', 'PERNAMBUCO',
        'FORTALEZA', 'CAMBORIU', 'ITAJAI', 'ITAJAÍ', 'JOINVILLE', 'BLUMENAU',
        'LONDRINA', 'MARINGA', 'MARINGÁ', 'CASCAVEL', 'FOZ DO IGUACU',
        'FOZ DO IGUAÇU', 'CHAPECO', 'CHAPECÓ', 'PASSO FUNDO', 'ERECHIM',
        'CAXIAS DO SUL', 'IJUI', 'IJUÍ', 'TRES DE MAIO', 'SANTO ANGELO',
        'CRUZ ALTA', 'BAGE', 'BAGÉ',
    ];

    private const AR_EVIDENCE = [
        'ARGENTINA', 'BUENOS AIRES', 'CORDOBA', 'CÓRDOBA', 'ROSARIO', 'MENDOZA',
        'SALTA', 'TUCUMAN', 'TUCUMÁN', 'MISIONES', 'OBERA', 'POSADAS',
        'CORRIENTES', 'CHACO', 'FORMOSA', 'JUJUY', 'NEUQUEN', 'NEUQUÉN',
        'BAHIA BLANCA', 'SANTA FE', 'ENTRE RIOS', 'ENTRE RÍOS', 'CONCORDIA',
        'GOYA', 'RESISTENCIA', 'SANTIAGO DEL ESTERO', 'CATAMARCA', 'LA RIOJA',
        'SAN JUAN', 'SAN LUIS', 'LA PAMPA', 'RIO NEGRO', 'CHUBUT', 'SANTA CRUZ',
        'TIERRA DEL FUEGO', 'PUERTO MINERAL', 'LA FRONTERA', 'MIERKE',
        'VILLA ANGELA', 'CHARATA',
    ];

    public function domainKind(string $domain): string
    {
        $normalized = $this->normalizeDomain($domain);
        if (preg_match('/^[A-Z]{3}\d[A-Z]\d{2}$/', $normalized) === 1) {
            return self::BR;
        }
        if (preg_match('/^[A-Z]{2}\d{3}[A-Z]{2}$/', $normalized) === 1) {
            return self::AR;
        }
        if (preg_match('/^[A-Z]{3}\d{3}$/', $normalized) === 1) {
            return self::AMBIGUOUS;
        }

        return self::UNKNOWN;
    }

    public function normalizeDomain(string $domain): string
    {
        return preg_replace('/\s+/u', '', mb_strtoupper(trim($domain))) ?? '';
    }

    /** @return array{AR:int,BR:int} */
    public function evidence(string $text): array
    {
        $normalized = mb_strtoupper($text);

        return [
            self::AR => $this->countEvidence($normalized, self::AR_EVIDENCE),
            self::BR => $this->countEvidence($normalized, self::BR_EVIDENCE),
        ];
    }

    public function resolveAmbiguous(int $ar, int $br): ?string
    {
        if ($ar > $br) {
            return self::AR;
        }
        if ($br > $ar) {
            return self::BR;
        }

        return null;
    }

    /** @param list<string> $words */
    private function countEvidence(string $text, array $words): int
    {
        $hits = 0;
        foreach ($words as $word) {
            if (preg_match('/(?<![A-Z0-9])' . preg_quote($word, '/') . '(?![A-Z0-9])/u', $text) === 1) {
                $hits++;
            }
        }

        return $hits;
    }
}
