<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentFleetLookup;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentFleetLookup implements EquipmentFleetLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function equipment(int $companyId, int $equipmentId): ?array
    {
        $row = $this->database->table('equipos e')
            ->select('e.id, e.sucursal_id, e.codigo, e.patente, e.estado, t.nombre AS tipo_nombre')
            ->join('tipos_equipo t', 't.id = e.tipo_equipo_id')
            ->where('e.empresa_id', $companyId)
            ->where('e.id', $equipmentId)
            ->where('e.deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return $this->toFleetItem($row);
    }

    public function equipmentByPlate(int $companyId, string $plate): ?array
    {
        $plate = trim($plate);
        if ($plate === '') {
            return null;
        }

        $row = $this->database->table('equipos e')
            ->select('e.id, e.sucursal_id, e.codigo, e.patente, e.estado, t.nombre AS tipo_nombre')
            ->join('tipos_equipo t', 't.id = e.tipo_equipo_id')
            ->where('e.empresa_id', $companyId)
            ->where('e.patente', $plate)
            ->where('e.deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return $this->toFleetItem($row);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{id:int,branchId:int,label:string,plate:string,code:string,tractor:bool,trailer:bool}
     */
    private function toFleetItem(array $row): array
    {
        $plate = trim((string) ($row['patente'] ?? ''));
        $code = trim((string) ($row['codigo'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'branchId' => (int) $row['sucursal_id'],
            'label' => $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code,
            'plate' => $plate,
            'code' => $code,
            'tractor' => $this->isTractor((string) ($row['tipo_nombre'] ?? '')),
            'trailer' => $this->isTrailer((string) ($row['tipo_nombre'] ?? '')),
        ];
    }

    /**
     * El rol fisico de la unidad se deduce del nombre del tipo de equipo.
     *
     * Es una regla de negocio que hoy vive sobre texto libre: renombrar un
     * tipo, por ejemplo "Semirremolque" a "Remolque sider", la cambia en
     * silencio. La forma definitiva es un atributo explicito en `tipos_equipo`,
     * con su carga de datos y su pantalla de catalogo. Mientras tanto, el
     * criterio vive en el contexto que es dueno del catalogo.
     */
    private function isTrailer(string $tipo): bool
    {
        return preg_match('/acoplado|semirremolque|remolque|batea/', $this->normalize($tipo)) === 1;
    }

    private function isTractor(string $tipo): bool
    {
        return preg_match('/camion|tractor|chasis|cabina|utilitario|pickup/', $this->normalize($tipo)) === 1;
    }

    private function normalize(string $tipo): string
    {
        return strtr(mb_strtolower($tipo), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }
}
