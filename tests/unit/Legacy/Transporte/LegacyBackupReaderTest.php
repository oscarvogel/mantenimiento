<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\Transporte;

use App\LegacyTransporte\LegacyBackupReader;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * El lector no depende de CodeIgniter, de base ni de filesystem real salvo el TAR
 * que arma el propio test. Se cubre el formato porque el backup es la unica fuente
 * del historial y un error de lectura se descubre tarde y en produccion.
 */
final class LegacyBackupReaderTest extends CIUnitTestCase
{
    private string $fixtureDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/legacy-transporte-test-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtureDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->fixtureDir)) {
            rmdir($this->fixtureDir);
        }
        parent::tearDown();
    }

    private function crearBackup(string $sql, array $datos): string
    {
        $miembros = ['restore.sql' => $sql] + $datos;
        $contenido = '';
        foreach ($miembros as $nombre => $dato) {
            $contenido .= $this->miembroTar((string) $nombre, $dato);
        }
        $contenido .= str_repeat("\0", 1024); // dos bloques de fin de archivo

        $ruta = $this->fixtureDir . '/fixture.backup';
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    /** Un miembro ustar completo con su checksum. */
    private function miembroTar(string $nombre, string $contenido): string
    {
        $header = str_pad($nombre, 100, "\0");
        $header .= str_pad('000600 ', 8, "\0");                       // mode
        $header .= str_pad('002048 ', 8, "\0");                       // uid
        $header .= str_pad('002048 ', 8, "\0");                       // gid
        $header .= str_pad(sprintf('%011o', strlen($contenido)) . ' ', 12, "\0"); // size
        $header .= str_pad(sprintf('%011o', 1757155200) . ' ', 12, "\0");          // mtime
        $header .= str_repeat(' ', 8);                                // checksum
        $header .= '0';                                               // typeflag
        $header .= str_repeat("\0", 100);                             // linkname
        $header .= "ustar\0" . '00';
        $header = str_pad($header, 512, "\0");

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, str_pad(sprintf('%06o', $checksum) . "\0 ", 8, ' '), 148, 8);

        return $header . $contenido . str_repeat("\0", (512 - strlen($contenido) % 512) % 512);
    }

    private function sqlDeEjemplo(): string
    {
        return "SET client_encoding = 'UTF8';\n"
            . "CREATE TABLE t05_viajes (cuit_empresa character(13), dominio character(10), numero_viaje integer, fecha_salida date);\n"
            . "ALTER TABLE ONLY t05_viajes ADD CONSTRAINT t05_x_clave PRIMARY KEY (cuit_empresa, dominio, numero_viaje);\n"
            . "COPY t05_viajes (cuit_empresa, dominio, numero_viaje, fecha_salida) FROM stdin;\n"
            . "COPY t05_viajes (cuit_empresa, dominio, numero_viaje, fecha_salida) FROM '\$\$PATH\$\$/9001.dat';\n"
            . "CREATE TABLE t99_vacia (a integer, b text);\n"
            . "COPY t99_vacia (a, b) FROM '\$\$PATH\$\$/9002.dat';\n";
    }

    #[Test]
    public function mapeaLasTablasDesdeRestoreSqlYNoPorPosicion(): void
    {
        // Los numeros de archivo estan invertidos a proposito: si el lector emparejara
        // por orden numericoeria asignar 9002.dat a t05_viajes y la prueba fallaria.
        $ruta = $this->crearBackup($this->sqlDeEjemplo(), [
            '9001.dat' => "23-28059786-4\tAD738EA   \t1\t2026-01-05\n\\.\n\n\n\n",
            '9002.dat' => "\\.\n\n\n\n",
        ]);

        $lector = LegacyBackupReader::fromFile($ruta);

        $this->assertSame('9001.dat', $lector->archivoDe('t05_viajes'));
        $this->assertSame('9002.dat', $lector->archivoDe('t99_vacia'));
        $this->assertCount(1, $lector->filas('t05_viajes'));
        $this->assertSame(0, $lector->contar('t99_vacia'));
    }

    #[Test]
    public function exponeLaClavePrimariaDeclaradaDelLegacy(): void
    {
        $ruta = $this->crearBackup($this->sqlDeEjemplo(), [
            '9001.dat' => "x\ty\t1\t2026-01-05\n\\.\n",
            '9002.dat' => "\\.\n",
        ]);

        $lector = LegacyBackupReader::fromFile($ruta);

        $this->assertSame(
            ['cuit_empresa', 'dominio', 'numero_viaje'],
            $lector->primaryKey('t05_viajes')
        );
    }

    #[Test]
    public function preservaLosValoresYElRellenoDeEspaciosDelLegacy(): void
    {
        $ruta = $this->crearBackup($this->sqlDeEjemplo(), [
            '9001.dat' => "23-28059786-4\tAD738EA   \t1\t\\N\n\\.\n",
            '9002.dat' => "\\.\n",
        ]);

        $filas = LegacyBackupReader::fromFile($ruta)->filas('t05_viajes');

        $this->assertCount(1, $filas);
        $this->assertSame('23-28059786-4', $filas[0]['cuit_empresa']);
        $this->assertSame('AD738EA   ', $filas[0]['dominio'], 'el char(10) del legacy debe conservar el relleno');
        $this->assertNull($filas[0]['fecha_salida'], rtrim('el texto \N de COPY es SQL NULL'));
    }

    #[Test]
    public function decodificaLosEscapesDeControlYOctales(): void
    {
        // El separador de campo es un TAB real; los escapes van dentro del valor.
        // En PHP, una doble barra produce la barra simple que el dump emite.
        $bloque = "uno\tcon\\ttab\tfin\\.\n\\.\n";
        $filas = LegacyBackupReader::parseCopy($bloque);

        $this->assertCount(1, $filas, 'el terminador \. no debe contarse como fila');
        $this->assertCount(3, $filas[0], 'los dos TAB reales separan tres campos');
        $this->assertSame("con\ttab", $filas[0][1]);
        $this->assertSame('fin.', $filas[0][2]);
        $this->assertSame('aAb', LegacyBackupReader::parseCopy("a\\101b\tz\n")[0][0]);
        $this->assertSame('a\\b', LegacyBackupReader::parseCopy("a\\\\b\tz\n")[0][0]);
    }

    #[Test]
    public function elTextoNulDelDumpSeConvierteEnNullYNoEnCadenaVacia(): void
    {
        $filas = LegacyBackupReader::parseCopy("a\t\\N\tc\n");

        $this->assertArrayHasKey(1, $filas[0]);
        $this->assertNull($filas[0][1]);
        $this->assertSame('a', $filas[0][0]);
        $this->assertSame('c', $filas[0][2]);
    }

    #[Test]
    public function rechazaUnArchivoQueNoEsUnBackupDePgDump(): void
    {
        $ruta = $this->fixtureDir . '/roto.backup';
        file_put_contents($ruta, str_repeat("\0", 1024));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/restore\.sql|pg_dump/');
        LegacyBackupReader::fromFile($ruta);
    }
}