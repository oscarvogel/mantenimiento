<?php

declare(strict_types=1);

namespace App\Application\Geography;

final class PreviewLocalities
{
    private const COUNTRIES = ['ARGENTINA', 'BRASIL', 'CHILE', 'URUGUAY'];

    /** @param iterable<array<string, string>> $records */
    public function execute(iterable $records): LocalityImportPreview
    {
        $identities = [];
        $expectedCountry = null;
        $expectedSource = null;
        $expectedVersion = null;
        $metadata = [];
        $totalRows = 0;
        $errorRows = 0;
        $errorsPreview = [];

        foreach ($records as $record) {
            $index = $totalRows++;
            $errors = [];
            $country = strtoupper(trim($record['pais_codigo'] ?? ''));
            $source = strtoupper(trim($record['fuente'] ?? ''));
            $provinceCode = trim($record['codigo_provincia'] ?? '');
            $province = trim($record['provincia'] ?? '');
            $code = trim($record['codigo_localidad'] ?? '');
            $name = trim($record['localidad'] ?? '');
            $version = trim($record['version'] ?? '');

            if (! in_array($country, self::COUNTRIES, true)) {
                $errors[] = 'País no admitido. Usá ARGENTINA, BRASIL, CHILE o URUGUAY.';
            }
            if ($source === '') {
                $errors[] = 'Falta identificar la fuente oficial.';
            }
            if ($provinceCode === '' || $province === '') {
                $errors[] = 'Falta el código o el nombre de la provincia/departamento.';
            }
            if ($code === '') {
                $errors[] = 'Falta el código oficial de la localidad.';
            }
            if ($name === '') {
                $errors[] = 'Falta el nombre de la localidad.';
            }
            if ($version === '') {
                $errors[] = 'Falta la versión o fecha de la fuente.';
            }

            foreach ([
                'pais_codigo', 'fuente', 'codigo_provincia', 'provincia', 'codigo_localidad',
                'localidad', 'contexto', 'categoria', 'version',
            ] as $textField) {
                $value = $record[$textField] ?? '';
                if (! mb_check_encoding($value, 'UTF-8')) {
                    $errors[] = 'El texto del campo ' . $textField . ' no está codificado en UTF-8.';
                }
            }
            foreach (['latitud' => 90, 'longitud' => 180] as $coordinate => $limit) {
                $value = trim($record[$coordinate] ?? '');
                if ($value !== '' && (! is_numeric($value) || (float) $value < -$limit || (float) $value > $limit)) {
                    $errors[] = ucfirst($coordinate) . ' debe ser un número entre -' . $limit . ' y ' . $limit . '.';
                }
            }

            if ($expectedCountry === null) {
                $expectedCountry = $country;
                $expectedSource = $source;
                $expectedVersion = $version;
                $metadata = $record;
            } elseif ($country !== $expectedCountry || $source !== $expectedSource || $version !== $expectedVersion) {
                $errors[] = 'El archivo debe contener un solo país, fuente y versión.';
            }

            if ($country !== '' && $source !== '' && $code !== '') {
                $identity = $country . ':' . $source . ':' . $code;
                if (isset($identities[$identity])) {
                    $first = $identities[$identity];
                    $errors[] = 'Código de localidad repetido en el archivo (primera fila de datos: ' . $first['row'] . ').';
                    if (! $first['duplicate']) {
                        $errorRows++;
                        $identities[$identity]['duplicate'] = true;
                        if (count($errorsPreview) < 100) {
                            $errorsPreview[] = [
                                'row' => $first['row'],
                                'data' => ['codigo_localidad' => $code, 'localidad' => $first['name']],
                                'errors' => ['Código de localidad repetido en el archivo (fila de datos: ' . ($index + 2) . ').'],
                            ];
                        }
                    }
                } else {
                    $identities[$identity] = ['row' => $index + 2, 'name' => $name, 'duplicate' => false];
                }
            }

            if ($errors !== []) {
                $errorRows++;
                if (count($errorsPreview) < 100) {
                    $errorsPreview[] = ['row' => $index + 2, 'data' => $record, 'errors' => array_values(array_unique($errors))];
                }
            }
        }

        return new LocalityImportPreview($metadata, $totalRows, $totalRows - $errorRows, $errorRows, $errorsPreview);
    }
}
