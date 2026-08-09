<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\AssetCatalogReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterAssetCatalogReadModel implements AssetCatalogReadModel
{
    public function __construct(private readonly BaseConnection $database) {}

    public function list(int $companyId, bool $includeInactive): array
    {
        $brands = $this->database->table('marcas')->select('id, nombre, activo')->where('empresa_id', $companyId);
        $models = $this->database->table('modelos m')
            ->select('m.id, m.marca_id, m.tipo_equipo_id, m.nombre, m.activo, ma.nombre marca_nombre, te.nombre tipo_nombre')
            ->join('marcas ma', 'ma.id = m.marca_id AND ma.empresa_id = m.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = m.tipo_equipo_id', 'inner')->where('m.empresa_id', $companyId);
        if (! $includeInactive) {
            $brands->where('activo', 1);
            $models->where('m.activo', 1)->where('ma.activo', 1)->where('te.activo', 1);
        }
        $types = $this->database->table('tipos_equipo')->select('id, nombre, controla_km, controla_horas, activo');
        if (! $includeInactive) {
            $types->where('activo', 1);
        }

        return [
            'brands' => $brands->orderBy('nombre')->get()->getResultArray(),
            'models' => $models->orderBy('ma.nombre')->orderBy('m.nombre')->get()->getResultArray(),
            'types' => $types->orderBy('nombre')->get()->getResultArray(),
        ];
    }
}
