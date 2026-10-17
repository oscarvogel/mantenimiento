<?php

declare(strict_types=1);

namespace App\Legacy\Transporte;

/**
 * Lector de backups del sistema de transporte legacy (`pg_dump -Ft`).
 *
 * Un `.backup` es un TAR con tres tipos de miembro:
 *   - `restore.sql`      -> DDL, claves primarias y el indice COPY autoritativo.
 *   - `toc.dat`          -> metadatos serializados del dump (no se necesita para leer datos).
 *   - `<n>.dat`          -> un bloque `COPY ... FROM stdin` por tabla, texto plano.
 *
 * El mapeo tabla -> archivo NO se deduce por posicion: las lineas
 *   COPY <tabla> (<cols>) FROM '$$PATH$$/<n>.dat';
 * de `restore.sql` son la fuente autoritativa. Ordenar los `.dat` por numero y
 * emparejarlos con el orden del DDL funciona en algunos backups y falla en otros,
 * asi que queda prohibido para este lector.
 *
 * Esta clase NO ejecuta ninguna sentencia del contenido: solo interpreta el
 * formato de archivo y devuelve filas como arrays associativos.
 */
final class LegacyBackupReader
{
    /** @var array<string,string> */
    private array $members = [];

    private string $restoreSql = '';
    /** @var array<string,array{archivo:string, columnas:array<int,string>}> */
    private array $indice = [];
    /** @var array<string,array<int,string>> tabla => columnas de la PK declarada */
    private array $primaryKeys = [];

    private function __construct()
    {
    }

    /** Abre un `.backup` desde disco. */
    public static function fromFile(string $ruta): self
    {
        if (!is_file($ruta)) {
            throw new \RuntimeException('No existe el archivo: ' . $ruta);
        }
        if (!is_readable($ruta)) {
            throw new \RuntimeException('No se puede leer: ' . $ruta);
        }

        $instancia = new self();
        $instancia->members = self::leerTar($ruta);
        $instancia->indexar();

        return $instancia;
    }

    /**
     * @return array<string,string> nombre de miembro => contenido
     */
    private static function leerTar(string $ruta): array
    {
        $fh = fopen($ruta, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('No se pudo abrir: ' . $ruta);
        }

        $miembros = [];
        $bloque = 512;

        try {
            while (true) {
                $header = fread($fh, $bloque);
                if ($header === false || strlen($header) < $bloque) {
                    break;
                }
                if (trim($header, "\0") === '') {
                    break; // fin de archivo ustar
                }

                $nombre = rtrim(substr($header, 0, 100), "\0");
                $prefijo = rtrim(substr($header, 345, 155), "\0");
                if ($prefijo !== '') {
                    $nombre = $prefijo . '/' . $nombre;
                }

                $campo = trim(substr($header, 124, 12), " \0");
                $tamano = $campo === '' ? 0 : (int) octdec($campo);

                if ($tamano > 0) {
                    $contenido = '';
                    while (strlen($contenido) < $tamano) {
                        $leido = fread($fh, min(1048576, $tamano - strlen($contenido)));
                        if ($leido === false || $leido === '') {
                            break;
                        }
                        $contenido .= $leido;
                    }
                    $resto = $tamano % $bloque;
                    if ($resto !== 0) {
                        fread($fh, $bloque - $resto);
                    }
                    if ($contenido !== '') {
                        $miembros[$nombre] = $contenido;
                    }
                }
            }
        } finally {
            fclose($fh);
        }

        return $miembros;
    }

    private function indexar(): void
    {
        $sql = $this->members['restore.sql'] ?? null;
        if ($sql === null) {
            throw new \RuntimeException('El backup no trae restore.sql; no es un pg_dump -Ft reconocible.');
        }
        $this->restoreSql = $sql;

        $patron = '~^COPY\s+([a-zA-Z0-9_"]+)\s*\(([^)]*)\)\s+FROM\s+.?\$\$PATH\$\$/([0-9]+)\.dat.;$~m';
        if (preg_match_all($patron, $sql, $coincidencias, PREG_SET_ORDER) === false || $coincidencias === []) {
            throw new \RuntimeException('restore.sql no contiene lineas COPY con referencia a archivo de datos.');
        }

        foreach ($coincidencias as $m) {
            $tabla = trim($m[1], '"');
            $columnas = array_values(array_filter(array_map('trim', explode(',', $m[2])), static fn ($c) => $c !== ''));
            $this->indice[$tabla] = ['archivo' => $m[3] . '.dat', 'columnas' => $columnas];
        }

        $patronPk = '~ALTER TABLE ONLY\s+([a-zA-Z0-9_."]+)\s+ADD CONSTRAINT\s+\S+\s+PRIMARY KEY\s*\(([^)]*)\)~';
        if (preg_match_all($patronPk, $sql, $pks, PREG_SET_ORDER) !== false) {
            foreach ($pks as $p) {
                $tabla = trim($p[1], '"');
                $cols = [];
                foreach (explode(',', $p[2]) as $c) {
                    $cols[] = trim(trim($c), '"');
                }
                $this->primaryKeys[$tabla] = $cols;
            }
        }
    }

    /** @return array<int,string> tablas presentes en el backup, en orden alfabetico */
    public function tablas(): array
    {
        $t = array_keys($this->indice);
        sort($t);

        return $t;
    }

    public function tiene(string $tabla): bool
    {
        return isset($this->indice[$tabla]);
    }

    /** @return array<int,string> */
    public function columnas(string $tabla): array
    {
        if (!isset($this->indice[$tabla])) {
            throw new \RuntimeException('Tabla desconocida en el backup: ' . $tabla);
        }

        return $this->indice[$tabla]['columnas'];
    }

    /** @return array<int,string> columnas de la PK declarada, vacio si no tiene */
    public function primaryKey(string $tabla): array
    {
        return $this->primaryKeys[$tabla] ?? [];
    }

    public function archivoDe(string $tabla): string
    {
        if (!isset($this->indice[$tabla])) {
            throw new \RuntimeException('Tabla desconocida en el backup: ' . $tabla);
        }

        return $this->indice[$tabla]['archivo'];
    }

    /**
     * Filas de una tabla como arrays asociativos columna => valor (null = NULL).
     *
     * @return array<int,array<string,string|null>>
     */
    public function filas(string $tabla): array
    {
        if (!isset($this->indice[$tabla])) {
            return [];
        }
        $columnas = $this->indice[$tabla]['columnas'];
        $crudo = $this->members[$this->indice[$tabla]['archivo']] ?? '';
        $crudos = self::parseCopy($crudo);

        $salida = [];
        foreach ($crudos as $fila) {
            $assoc = [];
            foreach ($columnas as $i => $col) {
                $assoc[$col] = $fila[$i] ?? null;
            }
            $salida[] = $assoc;
        }

        return $salida;
    }

    /**
     * Cuenta filas sin materializarlas todas.
     */
    public function contar(string $tabla): int
    {
        if (!isset($this->indice[$tabla])) {
            return 0;
        }
        $crudo = $this->members[$this->indice[$tabla]['archivo']] ?? '';
        if ($crudo === '') {
            return 0;
        }

        $n = 0;
        foreach (preg_split('/\r\n|\n|\r/', $crudo) as $linea) {
            if ($linea === '' || $linea === '\\.') {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /**
     * Interpreta un bloque `COPY` en formato texto de PostgreSQL.
     * `\N` es NULL; el resto de escapes octales y de control se resuelven.
     *
     * @return array<int,array<int,string|null>>
     */
    public static function parseCopy(string $bloque): array
    {
        $filas = [];

        foreach (preg_split('/\r\n|\n|\r/', $bloque) as $linea) {
            if ($linea === '' || $linea === '\\.') {
                continue;
            }

            $campos = [];
            $actual = '';
            $esNull = false;
            $i = 0;
            $n = strlen($linea);

            while ($i < $n) {
                $c = $linea[$i];

                if ($c === '\\') {
                    $i++;
                    if ($i >= $n) {
                        $actual .= '\\';
                        break;
                    }
                    $e = $linea[$i];
                    switch ($e) {
                        case 'N': $esNull = true; $i++; break;
                        case 'n': $actual .= "\n"; $i++; break;
                        case 't': $actual .= "\t"; $i++; break;
                        case 'r': $actual .= "\r"; $i++; break;
                        case 'b': $actual .= "\x08"; $i++; break;
                        case 'f': $actual .= "\x0c"; $i++; break;
                        case 'v': $actual .= "\x0b"; $i++; break;
                        case '\\': $actual .= '\\'; $i++; break;
                        default:
                            if ($e >= '0' && $e <= '7') {
                                $oct = '';
                                $k = 0;
                                while ($k < 3 && $i < $n && $linea[$i] >= '0' && $linea[$i] <= '7') {
                                    $oct .= $linea[$i];
                                    $i++;
                                    $k++;
                                }
                                $actual .= chr(((int) octdec($oct)) & 0xFF);
                            } else {
                                $actual .= $e;
                                $i++;
                            }
                    }
                    continue;
                }

                if ($c === "\t") {
                    $campos[] = $esNull ? null : $actual;
                    $actual = '';
                    $esNull = false;
                    $i++;
                    continue;
                }

                $actual .= $c;
                $i++;
            }

            $campos[] = $esNull ? null : $actual;
            $filas[] = $campos;
        }

        return $filas;
    }

    /** SQL original del dump. Solo para inspeccion; no debe ejecutarse. */
    public function sqlOriginal(): string
    {
        return $this->restoreSql;
    }
}