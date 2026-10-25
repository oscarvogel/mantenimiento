<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentFleetLookup;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentRelation;
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
     * @param list<int> $equipmentIds
     *
     * @return array<int, list<array{id:int,label:string,plate:string,code:string}>>
     */
    public function activeTrailers(int $companyId, array $equipmentIds): array
    {
        if ($equipmentIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($equipmentIds), '?'));
        $rows = $this->database->query(
            'SELECT r.equipo_principal_id, r.equipo_relacionado_id, '
            . 'ep.codigo AS principal_codigo, ep.patente AS principal_patente, tp.nombre AS principal_tipo, '
            . 'er.codigo AS related_codigo, er.patente AS related_patente, tr.nombre AS related_tipo '
            . 'FROM equipo_relaciones r '
            . 'INNER JOIN equipos ep ON ep.id = r.equipo_principal_id AND ep.empresa_id = r.empresa_id '
            . 'AND ep.estado = ? AND ep.deleted_at IS NULL '
            . 'INNER JOIN tipos_equipo tp ON tp.id = ep.tipo_equipo_id '
            . 'INNER JOIN equipos er ON er.id = r.equipo_relacionado_id AND er.empresa_id = r.empresa_id '
            . 'AND er.estado = ? AND er.deleted_at IS NULL '
            . 'INNER JOIN tipos_equipo tr ON tr.id = er.tipo_equipo_id '
            . 'WHERE r.empresa_id = ? AND r.tipo_relacion = ? AND r.hasta IS NULL '
            . 'AND (r.equipo_principal_id IN (' . $placeholders . ') OR r.equipo_relacionado_id IN (' . $placeholders . ')) '
            . 'ORDER BY er.patente ASC, er.codigo ASC',
            array_merge(
                [Equipment::ACTIVE, Equipment::ACTIVE, $companyId, EquipmentRelation::TRACTOR_TRAILER],
                $equipmentIds,
                $equipmentIds
            ),
        )->getResultArray();

        $requested = array_fill_keys(array_map('intval', $equipmentIds), true);
        $trailers = [];
        foreach ($rows as $row) {
            $principalId = (int) $row['equipo_principal_id'];
            $relatedId = (int) $row['equipo_relacionado_id'];

            if (isset($requested[$principalId])) {
                $this->appendTrailer(
                    $trailers,
                    $principalId,
                    (int) $row['equipo_relacionado_id'],
                    (string) $row['related_codigo'],
                    (string) $row['related_patente'],
                    (string) $row['related_tipo']
                );
            }
            if (isset($requested[$relatedId])) {
                $this->appendTrailer(
                    $trailers,
                    $relatedId,
                    (int) $row['equipo_principal_id'],
                    (string) $row['principal_codigo'],
                    (string) $row['principal_patente'],
                    (string) $row['principal_tipo']
                );
            }
        }

        return $trailers;
    }

    /**
     * @param array<int, list<array{id:int,label:string,plate:string,code:string}>> $trailers
     */
    private function appendTrailer(
        array &$trailers,
        int $equipmentId,
        int $trailerId,
        string $code,
        string $plate,
        string $typeName
    ): void {
        if (! $this->isTrailer($typeName)) {
            return;
        }

        $code = trim($code);
        $plate = trim($plate);
        $trailers[$equipmentId][] = [
            'id' => $trailerId,
            'label' => $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code,
            'plate' => $plate,
            'code' => $code,
        ];
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
