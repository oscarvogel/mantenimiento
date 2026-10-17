<?php
/**
 * Verificacion standalone del lector y de los 4 backups reales.
 * No depende de CodeIgniter, Composer ni base de datos.
 * Uso: php scripts/legacy-transporte-verify.php <archivo.backup> ...
 */

declare(strict_types=1);

use App\Legacy\Transporte\LegacyBackupReader;

require __DIR__ . '/../app/Legacy/Transporte/LegacyBackupReader.php';

$fallos = 0;
$ok = 0;

function check(string $titulo, bool $cond, string $detalle = ''): void
{
    global $fallos, $ok;
    if ($cond) {
        $ok++;
        echo "  [OK]   $titulo\n";
    } else {
        $fallos++;
        echo "  [FALLA] $titulo" . ($detalle !== '' ? " -- $detalle" : '') . "\n";
    }
}

echo "== 1. Formato: escapes y valores del bloque COPY ==\n";
// El separador de campo del dump es un TAB REAL; los escapes aparecen dentro del valor.
// En PHP, "una doble barra" produce una sola barra, que es lo que el dump emite.
$f = LegacyBackupReader::parseCopy("uno\tcon\\ttab\tfin\\.\n\\.\n");
check('el TAB real separa campos', count($f) === 1 && count($f[0]) === 3, 'campos=' . count($f[0]));
check('escape de tabulador dentro del valor se decodifica', ($f[0][1] ?? null) === "con\ttab", var_export($f[0][1] ?? null, true));
check('escape de punto al final del valor', ($f[0][2] ?? null) === 'fin.', var_export($f[0][2] ?? null, true));
check('el terminador \\. no es una fila', count($f) === 1, 'filas=' . count($f));
$f2 = LegacyBackupReader::parseCopy("a\t\\N\tc\n");
check('\\N es NULL', array_key_exists(1, $f2[0]) && $f2[0][1] === null, var_export($f2[0] ?? null, true));
check('los campos vecinos sobreviven al NULL', ($f2[0][0] ?? '') === 'a' && ($f2[0][2] ?? '') === 'c');
$f3 = LegacyBackupReader::parseCopy("a\\101b\tz\n");
check('escape octal \\101', ($f3[0][0] ?? '') === 'aAb');
$f4 = LegacyBackupReader::parseCopy("a\\\\b\tz\n");
check('escape de barra invertida', ($f4[0][0] ?? '') === 'a\\b');
$f5 = LegacyBackupReader::parseCopy("23-28059786-4\tAD738EA   \t1\t2026-01-05\n");
check('preserva el relleno de char(n)', ($f5[0][1] ?? '') === 'AD738EA   ', var_export($f5[0][1] ?? null, true));
check('no convierte el char a numero', is_string($f5[0][2] ?? null));

$archivos = array_slice($argv, 1);
if ($archivos === []) {
    fwrite(STDERR, "Uso: php scripts/legacy-transporte-verify.php <archivo.backup> ...\n");
    exit(2);
}

echo "\n== 2. Lectura de los backups reales ==\n";
$tablasOperativas = ['t05_viajes','t06_origenes_destinos_por_viaje','t07_cargas_combustible_por_viaje',
    't08_gastos_por_viaje','t11_cuenta_corriente_x_viaje','t12_cuenta_corriente_origenes_destinos_por_viaje',
    't13_otros_haberes_por_viaje','t01_choferes','t02_dominios','t03_origen_destino','t04_conceptos_choferes'];

$global = [];
foreach ($archivos as $ruta) {
    $nombre = basename($ruta);
    echo "\n-- $nombre\n";
    $l = LegacyBackupReader::fromFile($ruta);
    check('restore.sql indexado', count($l->tablas()) > 0, 'tablas=' . count($l->tablas()));

    $mismatch = 0;
    foreach ($l->tablas() as $t) {
        if ($l->contar($t) === 0) { continue; }
        $primera = $l->filas($t)[0] ?? [];
        if (count($primera) !== count($l->columnas($t))) { $mismatch++; }
    }
    check('todas las tablas no vacias tienen el ancho declarado', $mismatch === 0, "mismatches=$mismatch");

    $pk = $l->primaryKey('t05_viajes');
    check('PK de t05_viajes es (cuit_empresa, dominio, numero_viaje)',
        $pk === ['cuit_empresa', 'dominio', 'numero_viaje'], implode(',', $pk));

    foreach ($tablasOperativas as $t) {
        if (!$l->tiene($t)) { continue; }
        $n = $l->contar($t);
        printf("     %-42s %8d filas\n", $t, $n);
        if ($t === 't05_viajes') {
            $cc = 'cuit_empresa'; $cd = 'dominio'; $cn = 'numero_viaje';
            foreach ($l->filas($t) as $fila) {
                $k = ($fila[$cc] ?? '') . '|' . trim((string)($fila[$cd] ?? '')) . '|' . ($fila[$cn] ?? '');
                $global[$k][] = $nombre;
            }
        }
    }
}

echo "\n== 3. Unicidad de la clave de origen entre archivos ==\n";
$dup = array_filter($global, static fn ($x) => count($x) > 1);
check('sin colisiones (cuit|dominio|numero_viaje)', count($dup) === 0, 'colisiones=' . count($dup));
echo "     viajes totales leidos: " . count($global) . "\n";

echo "\n== Resultado: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);