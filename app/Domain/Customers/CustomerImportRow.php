<?php

declare(strict_types=1);

namespace App\Domain\Customers;

final class CustomerImportRow
{
    public const FIELDS = ['origen', 'codigo_origen', 'nombre', 'identificacion_fiscal', 'identificacion_fiscal_pais', 'direccion', 'provincia', 'localidad', 'contacto', 'telefono', 'email', 'activo'];

    public static function locationKey(array $data): string
    {
        // Fiscal country is deliberately excluded: it is not the domicile country.
        return hash('sha256', json_encode([$data['origen'] ?? '', $data['provincia'] ?? '', $data['localidad'] ?? ''], JSON_THROW_ON_ERROR));
    }

    public static function validate(array $fields): array
    {
        $data = [];
        $errors = [];
        $warnings = [];
        foreach (self::FIELDS as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            $limit = match ($key) {
                'origen', 'codigo_origen' => 80,
                'identificacion_fiscal', 'identificacion_fiscal_pais', 'telefono' => 50,
                'direccion' => 500,
                default => 255,
            };
            if (mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                $errors[] = "$key: longitud o caracteres no permitidos.";
            }
            $data[$key] = $value;
        }
        foreach (['origen', 'codigo_origen', 'nombre'] as $key) {
            if ($data[$key] === '') {
                $errors[] = "$key: es obligatorio.";
            }
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email: dirección no válida.';
        }
        if (!in_array($data['activo'], ['0', '1'], true)) {
            $errors[] = 'activo: usar 0 o 1.';
        }
        $digits = preg_replace('/\D/', '', $data['identificacion_fiscal']);
        if (trim($digits, '0') === '') {
            $warnings[] = 'Sin identificación fiscal utilizable.';
        } elseif (strlen($digits) !== 11) {
            $warnings[] = 'Revisar identificación fiscal; puede ser extranjera.';
        } else {
            $sum = 0;
            foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $weight) {
                $sum += (int) $digits[$i] * $weight;
            }
            $check = 11 - $sum % 11;
            $check = $check === 11 ? 0 : ($check === 10 ? 9 : $check);
            if ($check !== (int) $digits[10]) {
                $warnings[] = 'Identificación fiscal sin validar: revisar CUIT o documento extranjero.';
            }
        }
        if (mb_strtoupper($data['nombre']) === 'CLIENTES VARIOS') {
            $warnings[] = 'Cliente genérico.';
        }
        if ($data['provincia'] !== '' || $data['localidad'] !== '') {
            $warnings[] = 'Ubicación de origen pendiente de equivalencia geográfica confirmada.';
        }
        return ['data' => $data, 'errors' => $errors, 'warnings' => $warnings];
    }
}
