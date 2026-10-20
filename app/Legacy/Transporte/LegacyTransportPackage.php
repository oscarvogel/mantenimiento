<?php

declare(strict_types=1);

namespace App\Legacy\Transporte;

/**
 * Lector del paquete importable: un ZIP con `manifest.json` y `data/<tabla>.csv`.
 *
 * Es el unico formato que llega al hosting. Se genera con
 * `scripts/legacy-transporte-build.php` en una maquina con PHP, porque en Ferozo no
 * hay shell para convertir un `.backup`.
 *
 * Al abrir el paquete se valida el manifiesto y el contrato de columnas antes de leer
 * una sola fila. Un paquete que no cumple el contrato se rechaza entero: leer la mitad
 * y fallar despues deja viajes a medias.
 *
 * No depende de CodeIgniter ni de base de datos: se puede probar y ejecutar en
 * cualquier PHP 8.2+ con ext-zip.
 */
final class LegacyTransportPackage
{
    private const FORMATO = 'legacy-transporte-package';
    private const ALCANCE_ESPERADO = 'viajes';
    private const SEPARADOR = ',';
    private const CITA = '"';
    private const ESCAPE = '\\';
    private const NUL = '\N';

    /** Tablas del agregado de Viajes y su clave de origen dentro del paquete. */
    public const AGREGADO = [
        't05_viajes',
        't06_origenes_destinos_por_viaje',
        't07_cargas_combustible_por_viaje',
        't08_gastos_por_viaje',
        't11_cuenta_corriente_x_viaje',
        't12_cuenta_corriente_origenes_destinos_por_viaje',
        't13_otros_haberes_por_viaje',
        'a18_comprobantes_de_venta',
        'a19_detalle_comprobantes_de_venta',
    ];

    /** Columnas que el importador necesita de cada tabla. Si falta alguna, se rechaza. */
    public const CONTRATO = [
        't05_viajes' => ['cuit_empresa', 'dominio', 'numero_viaje', 'fecha_salida'],
        't06_origenes_destinos_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
        't07_cargas_combustible_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
        't08_gastos_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
        't11_cuenta_corriente_x_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
        't12_cuenta_corriente_origenes_destinos_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item', 'sub_item'],
        't13_otros_haberes_por_viaje' => ['cuit_empresa', 'dominio', 'numero_viaje', 'item'],
    ];

    /**
     * Facturacion historica. Es opcional para no romper paquetes viejos, pero si
     * la tabla viene en el ZIP debe cumplir el contrato completo.
     */
    public const CONTRATO_FACTURACION = [
        'a18_comprobantes_de_venta' => [
            'clave', 'tipo_de_comprobante', 'desc_tipo', 'punto_venta', 'nro_comprobante',
            'fecha', 'total', 'moneda', 'cotizacion', 'cuit_empresa', 'codigo_cliente',
            'subtotal', 'iva', 'impuestos', 'dgr', 'cae',
        ],
        'a19_detalle_comprobantes_de_venta' => [
            'clave', 'item', 'nombre', 'cantidad', 'precio_de_venta', 'iva',
            'codigo_articulo', 'codigo_alternativo',
        ],
    ];

    private \ZipArchive $zip;
    /** @var array<string, mixed> */
    private array $manifiesto;
    /** @var array<string, array<string, mixed>> tabla => entrada del manifiesto */
    private array $tablas;
    private string $ruta;
    private bool $cerrado = false;

    private function __construct(\ZipArchive $zip, array $manifiesto, array $tablas, string $ruta)
    {
        $this->zip = $zip;
        $this->manifiesto = $manifiesto;
        $this->tablas = $tablas;
        $this->ruta = $ruta;
    }

    /**
     * Abre y valida un paquete.
     *
     * @throws \RuntimeException si el ZIP no existe, no se puede leer, no trae manifiesto
     *                           o no cumple el contrato de columnas del agregado.
     */
    public static function fromZip(string $ruta): self
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new \RuntimeException('Paquete no encontrado o ilegible: ' . $ruta);
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Falta ext-zip en PHP; no se puede leer el paquete.');
        }

        $zip = new \ZipArchive();
        $codigo = $zip->open($ruta);
        if ($codigo !== true) {
            throw new \RuntimeException('No se pudo abrir el ZIP: ' . basename($ruta));
        }

        try {
            $crudo = $zip->getFromName('manifest.json');
            if ($crudo === false) {
                throw new \RuntimeException('El paquete no trae manifest.json.');
            }
            $manifiesto = json_decode($crudo, true);
            if (!is_array($manifiesto)) {
                throw new \RuntimeException('manifest.json no es JSON valido: ' . json_last_error_msg());
            }

            $instancia = new self($zip, $manifiesto, self::indizarTablas($manifiesto), $ruta);
            $instancia->validar();

            return $instancia;
        } catch (\Throwable $e) {
            $zip->close();

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $manifiesto
     *
     * @return array<string, array<string, mixed>>
     */
    private static function indizarTablas(array $manifiesto): array
    {
        if (!isset($manifiesto['tablas']) || !is_array($manifiesto['tablas'])) {
            throw new \RuntimeException('El manifiesto no declara la lista de tablas.');
        }

        $tablas = [];
        foreach ($manifiesto['tablas'] as $entrada) {
            if (!is_array($entrada) || !isset($entrada['tabla'])) {
                throw new \RuntimeException('Entrada de tabla incompleta en el manifiesto.');
            }
            $tablas[(string) $entrada['tabla']] = $entrada;
        }

        return $tablas;
    }

    private function validar(): void
    {
        $formato = (string) ($this->manifiesto['formato'] ?? '');
        if ($formato !== self::FORMATO) {
            throw new \RuntimeException('Formato de paquete no reconocido: ' . $formato);
        }

        $alcance = (string) ($this->manifiesto['alcance'] ?? '');
        if ($alcance !== self::ALCANCE_ESPERADO) {
            throw new \RuntimeException(
                'El paquete trae alcance "' . $alcance . '" y el importador de Viajes solo '
                . 'acepta "' . self::ALCANCE_ESPERADO . '". Generalo con --alcance=viajes.'
            );
        }

        foreach (self::CONTRATO as $tabla => $requeridas) {
            if (!isset($this->tablas[$tabla])) {
                throw new \RuntimeException('El paquete no trae la tabla del agregado: ' . $tabla);
            }
            $columnas = array_map(
                static fn ($c) => (string) $c,
                (array) ($this->tablas[$tabla]['columnas'] ?? [])
            );
            foreach ($requeridas as $columna) {
                if (!in_array($columna, $columnas, true)) {
                    throw new \RuntimeException(
                        'La tabla ' . $tabla . ' no trae la columna requerida ' . $columna
                    );
                }
            }
        }

        foreach (self::CONTRATO_FACTURACION as $tabla => $requeridas) {
            if (!isset($this->tablas[$tabla])) {
                continue;
            }
            $columnas = array_map(
                static fn ($c) => (string) $c,
                (array) ($this->tablas[$tabla]['columnas'] ?? [])
            );
            foreach ($requeridas as $columna) {
                if (!in_array($columna, $columnas, true)) {
                    throw new \RuntimeException(
                        'La tabla de facturacion ' . $tabla . ' no trae la columna requerida ' . $columna
                    );
                }
            }
        }
    }

    public function origenArchivo(): string
    {
        return (string) ($this->manifiesto['origen_archivo'] ?? '');
    }

    public function origenSha256(): string
    {
        return (string) ($this->manifiesto['origen_sha256'] ?? '');
    }

    public function generadoEn(): string
    {
        return (string) ($this->manifiesto['generado_en'] ?? '');
    }

    /** @return array<int,string> */
    public function tablas(): array
    {
        return array_keys($this->tablas);
    }

    public function filasDeclaradas(string $tabla): int
    {
        return (int) ($this->tablas[$tabla]['filas'] ?? 0);
    }

    /** @return array<int,string> */
    public function columnas(string $tabla): array
    {
        return array_map(static fn ($c) => (string) $c, (array) ($this->tablas[$tabla]['columnas'] ?? []));
    }

    /**
     * Recorre las filas de una tabla sin cargarlas todas en memoria.
     *
     * @return \Generator<int, array<string, string|null>>
     */
    public function filas(string $tabla): \Generator
    {
        if (!isset($this->tablas[$tabla])) {
            throw new \RuntimeException('El paquete no trae la tabla: ' . $tabla);
        }

        $csv = $this->zip->getFromName((string) $this->tablas[$tabla]['csv']);
        if ($csv === false) {
            throw new \RuntimeException('El manifiesto apunta a un CSV inexistente: ' . $tabla);
        }

        $manejador = fopen('php://temp/maxmemory:8388608', 'r+b');
        if ($manejador === false) {
            throw new \RuntimeException('No se pudo abrir el buffer de lectura.');
        }
        fwrite($manejador, $csv);
        rewind($manejador);

        $encabezado = fgetcsv($manejador, 0, self::SEPARADOR, self::CITA, self::ESCAPE);
        if ($encabezado === false) {
            fclose($manejador);

            throw new \RuntimeException('El CSV de ' . $tabla . ' no tiene encabezado.');
        }
        $columnas = array_map(static fn ($c) => (string) $c, $encabezado);

        $declaradas = $this->columnas($tabla);
        if ($columnas !== $declaradas) {
            fclose($manejador);

            throw new \RuntimeException(
                'El encabezado del CSV de ' . $tabla . ' no coincide con el manifiesto.'
            );
        }

        while (($linea = fgetcsv($manejador, 0, self::SEPARADOR, self::CITA, self::ESCAPE)) !== false) {
            if ($linea === [null] || $linea === []) {
                continue; // linea en blanco al final
            }
            $fila = [];
            foreach ($columnas as $i => $columna) {
                $valor = $linea[$i] ?? null;
                $fila[$columna] = ($valor === self::NUL) ? null : $valor;
            }
            yield $fila;
        }

        fclose($manejador);
    }

    /**
     * Clave de origen del viaje, tal como la declara el legacy.
     * No deduplica: dos viajes con el mismo numero y distinto dominio son distintos.
     */
    public static function claveViaje(array $fila): string
    {
        return implode('|', [
            trim((string) ($fila['cuit_empresa'] ?? '')),
            trim((string) ($fila['dominio'] ?? '')),
            trim((string) ($fila['numero_viaje'] ?? '')),
        ]);
    }

    public function cerrar(): void
    {
        if ($this->cerrado) {
            return;
        }
        $this->cerrado = true;
        $this->zip->close();
    }

    public function __destruct()
    {
        try {
            $this->cerrar();
        } catch (\Throwable) {
            // El ZIP puede quedar sin inicializar si la apertura fallo. Nada que hacer.
        }
    }
}