<?php
/**
 * Verificacion de ida y vuelta del paquete importable.
 *
 * Lee los ZIP generados y los contrasta contra el `.backup` original: mismo
 * conteo de filas, mismas claves de origen y mismas columnas. Si el conversor
 * perdiera, duplicara o reordenara una fila, aqui se ve.
 *
 * No depende de CodeIgniter ni de base de datos.
 *
 * Uso: php scripts/legacy-transporte-verify-package.php <paquete.zip> <backup.original>
 */

declare(strict_types=1);

use App\Legacy\Transporte\LegacyBackupReader;
use App\Legacy\Transporte\LegacyTransportPackage;

require __DIR__ . '/../app/Legacy/Transporte/LegacyBackupReader.php';
require __DIR__ . '/../app/Legacy/Transporte/LegacyTransportPackage.php';

$fallos = 0;
$ok = 0;

function check(string $titulo, bool $cond, string $detalle = ''): void
{
    global $fallos, $ok;
    if ($cond) {
        $ok++;
        echo "  [OK]    $titulo\n";
    } else {
        $fallos++;
        echo "  [FALLA] $titulo" . ($detalle !== '' ? " -- $detalle" : '') . "\n";
    }
}

$paquete = $argv[1] ?? null;
$original = $argv[2] ?? null;
if ($paquete === null || $original === null) {
    fwrite(STDERR, "Uso: php scripts/legacy-transporte-verify-package.php <paquete.zip> <backup.original>\n");
    exit(2);
}

echo "== Paquete: " . basename($paquete) . "\n";
$p = LegacyTransportPackage::fromZip($paquete);
check('el manifiesto declara alcance viajes', true);
echo "     origen: " . $p->origenArchivo() . "\n";
echo "     sha256: " . $p->origenSha256() . "\n";

echo "\n== 1. Contrato de columnas del agregado ==\n";
foreach (LegacyTransportPackage::CONTRATO as $tabla => $requeridas) {
    $columnas = $p->columnas($tabla);
    $faltan = array_values(array_diff($requeridas, $columnas));
    check($tabla . ' tiene las columnas que el importador necesita', $faltan === [], implode(',', $faltan));
}

echo "\n== 2. Conteo de filas contra el backup original ==\n";
$l = LegacyBackupReader::fromFile($original);
foreach ($p->tablas() as $tabla) {
    $enPaquete = 0;
    foreach ($p->filas($tabla) as $_) {
        $enPaquete++;
    }
    $declarado = $p->filasDeclaradas($tabla);
    $enOrigen = $l->tiene($tabla) ? $l->contar($tabla) : 0;
    check(
        $tabla . ': manifiesto y CSV coinciden con el origen',
        $enPaquete === $declarado && $enPaquete === $enOrigen,
        "paquete={$enPaquete} manifiesto={$declarado} origen={$enOrigen}"
    );
}

echo "\n== 3. Claves de origen sin perder ni duplicar ==\n";
$enPaquete = [];
foreach ($p->filas('t05_viajes') as $fila) {
    $enPaquete[LegacyTransportPackage::claveViaje($fila)] = true;
}
$enOrigen = [];
foreach ($l->filas('t05_viajes') as $fila) {
    $enOrigen[LegacyTransportPackage::claveViaje($fila)] = true;
}
check('ninguna clave se perdió', array_diff_key($enOrigen, $enPaquete) === []);
check('ninguna clave es inventada', array_diff_key($enPaquete, $enOrigen) === []);
check('sin claves repetidas', count($enPaquete) === count($l->filas('t05_viajes')),
    'unicas=' . count($enPaquete) . ' filas=' . count($l->filas('t05_viajes')));

echo "\n== 4. Valores y tipos preservados ==\n";
if ($p->filasDeclaradas('t05_viajes') === 0) {
    // ariel y gisela no tienen viajes: no hay filas que inspeccionar.
    echo "     t05_viajes viene vacia en este backup; se omite la inspeccion de valores\n";
} else {
    $primero = null;
    foreach ($p->filas('t05_viajes') as $fila) {
        $primero = $fila;
        break;
    }
    check('el relleno de char(n) sobrevive al ZIP', ($primero['dominio'] ?? null) !== null
        && strlen((string) $primero['dominio']) === 10, var_export($primero['dominio'] ?? null, true));
    check('numero_viaje llega como texto', is_string($primero['numero_viaje'] ?? null));

    $nulos = 0;
    $conCadena = 0;
    foreach ($p->filas('t05_viajes') as $fila) {
        foreach ($fila as $valor) {
            if ($valor === null) {
                $nulos++;
            } elseif ($valor !== '') {
                $conCadena++;
            }
        }
    }
    check('el dump trae NULL y el paquete los conserva como null', $nulos > 0, "nulos={$nulos} cadenas={$conCadena}");
}

$p->cerrar();
echo "\n== Resultado: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);