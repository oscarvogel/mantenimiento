<?php

declare(strict_types=1);

namespace App\Infrastructure\TheoreticalCost;

use App\Application\TheoreticalCost\Port\TheoreticalCostScenarioStore;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterTheoreticalCostScenarioStore implements TheoreticalCostScenarioStore
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function listAssets(int $companyId, ?array $branchIds): array
    {
        $rows = $this->assetsQuery($companyId, $branchIds)->get()->getResultArray();
        $tractors = [];
        $semis = [];
        foreach ($rows as $row) {
            $asset = [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'descripcion' => trim((string) $row['tipo_nombre'] . ' ' . (string) ($row['patente'] ?? '')),
                'sucursal_id' => (int) $row['sucursal_id'],
                'sucursal' => (string) $row['sucursal_nombre'],
            ];
            if ($this->isTractorType((string) $row['tipo_nombre'])) {
                $tractors[] = $asset;
            }
            if ($this->isSemiType((string) $row['tipo_nombre'])) {
                $semis[] = $asset;
            }
        }

        return ['tractores' => $tractors, 'semis' => $semis];
    }

    public function equipmentPair(int $companyId, ?array $branchIds, int $tractorId, int $semiId): ?array
    {
        if ($branchIds === []) {
            return null;
        }
        $rows = $this->assetsQuery($companyId, $branchIds)
            ->whereIn('e.id', [$tractorId, $semiId])->get()->getResultArray();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $tractor = $byId[$tractorId] ?? null;
        $semi = $byId[$semiId] ?? null;
        if ($tractor === null || $semi === null || ! $this->isTractorType((string) $tractor['tipo_nombre']) || ! $this->isSemiType((string) $semi['tipo_nombre'])) {
            return null;
        }

        return [
            'tractor' => ['id' => $tractorId, 'sucursal_id' => (int) $tractor['sucursal_id']],
            'semi' => ['id' => $semiId, 'sucursal_id' => (int) $semi['sucursal_id']],
        ];
    }

    public function listScenarios(int $companyId, ?array $branchIds, array $filters = []): array
    {
        $builder = $this->scopedScenarioQuery($companyId, $branchIds);
        if (! empty($filters['tractor_id'])) {
            $builder->where('s.tractor_id', (int) $filters['tractor_id']);
        }
        if (! empty($filters['semi_id'])) {
            $builder->where('s.semi_id', (int) $filters['semi_id']);
        }
        if (! empty($filters['desde'])) {
            $builder->where('v.guardado_en >=', $filters['desde'] . ' 00:00:00');
        }
        if (! empty($filters['hasta'])) {
            $builder->where('v.guardado_en <=', $filters['hasta'] . ' 23:59:59');
        }
        $rows = $builder->select('s.id escenario_id, s.nombre, s.tractor_id, s.semi_id, s.sucursal_tractor_id, s.sucursal_semi_id, t.codigo tractor_codigo, st.nombre tractor_tipo, sp.nombre tractor_sucursal, sm.codigo semi_codigo, spt.nombre semi_tipo, sps.nombre semi_sucursal, v.numero_version, v.guardado_en, v.cobertura, v.costo_ars_km, v.costo_usd_km, v.tarifa_ars_km, v.tarifa_usd_km')
            ->join('costos_teoricos_versiones v', 'v.escenario_id = s.id', 'left')
            ->join('equipos t', 't.empresa_id = s.empresa_id AND t.id = s.tractor_id', 'left')
            ->join('tipos_equipo st', 'st.id = t.tipo_equipo_id', 'left')
            ->join('sucursales sp', 'sp.empresa_id = t.empresa_id AND sp.id = t.sucursal_id', 'left')
            ->join('equipos sm', 'sm.empresa_id = s.empresa_id AND sm.id = s.semi_id', 'left')
            ->join('tipos_equipo spt', 'spt.id = sm.tipo_equipo_id', 'left')
            ->join('sucursales sps', 'sps.empresa_id = sm.empresa_id AND sps.id = sm.sucursal_id', 'left')
            ->orderBy('s.id', 'DESC')->orderBy('v.numero_version', 'DESC')->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $id = (int) $row['escenario_id'];
            if (! isset($grouped[$id])) {
                $grouped[$id] = [
                    'id' => $id,
                    'nombre' => (string) $row['nombre'],
                    'tractor' => $row['tractor_id'] === null ? null : ['id' => (int) $row['tractor_id'], 'codigo' => (string) $row['tractor_codigo'], 'tipo' => (string) $row['tractor_tipo'], 'sucursal' => (string) $row['tractor_sucursal']],
                    'semi' => $row['semi_id'] === null ? null : ['id' => (int) $row['semi_id'], 'codigo' => (string) $row['semi_codigo'], 'tipo' => (string) $row['semi_tipo'], 'sucursal' => (string) $row['semi_sucursal']],
                    'version' => null,
                    'guardado_en' => null,
                    'cobertura' => null,
                    'costo_ars_km' => null,
                    'costo_usd_km' => null,
                    'tarifa_ars_km' => null,
                    'tarifa_usd_km' => null,
                    'versiones' => [],
                ];
            }
            if ($row['numero_version'] !== null) {
                $version = [
                    'numero' => (int) $row['numero_version'],
                    'guardado_en' => (string) $row['guardado_en'],
                    'cobertura' => (string) $row['cobertura'],
                    'costo_ars_km' => $row['costo_ars_km'],
                    'costo_usd_km' => $row['costo_usd_km'],
                    'tarifa_ars_km' => $row['tarifa_ars_km'],
                    'tarifa_usd_km' => $row['tarifa_usd_km'],
                ];
                if ($grouped[$id]['version'] === null) {
                    $grouped[$id]['version'] = $version['numero'];
                    $grouped[$id]['guardado_en'] = $version['guardado_en'];
                    $grouped[$id]['cobertura'] = $version['cobertura'];
                    $grouped[$id]['costo_ars_km'] = $version['costo_ars_km'];
                    $grouped[$id]['costo_usd_km'] = $version['costo_usd_km'];
                    $grouped[$id]['tarifa_ars_km'] = $version['tarifa_ars_km'];
                    $grouped[$id]['tarifa_usd_km'] = $version['tarifa_usd_km'];
                }
                $grouped[$id]['versiones'][] = $version;
            }
        }
        return array_values($grouped);
    }

    public function findVersion(int $companyId, ?array $branchIds, int $scenarioId, int $versionNumber): ?array
    {
        $row = $this->scopedScenarioQuery($companyId, $branchIds)
            ->select('s.id escenario_id, s.nombre, s.tractor_id, s.semi_id, s.sucursal_tractor_id, s.sucursal_semi_id, t.codigo tractor_codigo, st.nombre tractor_tipo, sp.nombre tractor_sucursal, sm.codigo semi_codigo, spt.nombre semi_tipo, sps.nombre semi_sucursal, v.*')
            ->join('costos_teoricos_versiones v', 'v.escenario_id = s.id', 'inner')
            ->join('equipos t', 't.empresa_id = s.empresa_id AND t.id = s.tractor_id', 'left')
            ->join('tipos_equipo st', 'st.id = t.tipo_equipo_id', 'left')
            ->join('sucursales sp', 'sp.empresa_id = t.empresa_id AND sp.id = t.sucursal_id', 'left')
            ->join('equipos sm', 'sm.empresa_id = s.empresa_id AND sm.id = s.semi_id', 'left')
            ->join('tipos_equipo spt', 'spt.id = sm.tipo_equipo_id', 'left')
            ->join('sucursales sps', 'sps.empresa_id = sm.empresa_id AND sps.id = sm.sucursal_id', 'left')
            ->where('s.id', $scenarioId)->where('v.numero_version', $versionNumber)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $costRows = $this->database->table('costos_teoricos_rubros')
            ->where('version_id', (int) $row['id'])->orderBy('id', 'ASC')->get()->getResultArray();
        $monthly = [];
        $other = [];
        $breakdown = [];
        foreach ($costRows as $cost) {
            $entry = [
                'codigo' => (string) $cost['codigo'], 'nombre' => (string) $cost['nombre'],
                'importe' => $cost['importe'], 'moneda' => $cost['moneda'], 'unidad' => (string) $cost['unidad'],
                'fecha' => $cost['fecha_referencia'], 'fuente' => $cost['fuente'],
            ];
            if ($cost['tipo'] === 'mensual') {
                $monthly[] = $entry;
            } elseif ($cost['tipo'] === 'otro') {
                $other[] = $entry;
            }
            $breakdown[] = [
                'codigo' => (string) $cost['codigo'], 'nombre' => (string) $cost['nombre'],
                'estado' => (string) $cost['estado'], 'unidad' => (string) $cost['unidad'],
                'importe_origen' => $cost['importe'], 'moneda_origen' => $cost['moneda'],
                'ars_km' => $cost['ars_km'], 'usd_km' => $cost['usd_km'],
            ];
        }
        $fx = ['ars_por_usd' => $row['cotizacion_ars_usd'], 'fecha' => $row['cotizacion_fecha'], 'fuente' => $row['cotizacion_fuente']];
        $parameters = [
            'km_mensuales' => $row['km_mensuales'], 'fx' => $fx,
            'tractor' => ['valor_usd' => $row['tractor_valor_usd'], 'residual_usd' => $row['tractor_residual_usd'], 'vida_util_anios' => $row['tractor_vida_anios']],
            'semi' => ['valor_usd' => $row['semi_valor_usd'], 'residual_usd' => $row['semi_residual_usd'], 'vida_util_anios' => $row['semi_vida_anios']],
            'combustible' => ['litros_100km' => $row['combustible_litros_100km'], 'precio' => $row['combustible_precio'], 'moneda' => $row['combustible_moneda'], 'fecha' => $row['combustible_fecha']],
            'neumaticos' => ['costo_juego' => $row['neumaticos_costo_juego'], 'recapados' => $row['neumaticos_recapados'], 'vida_util_km' => $row['neumaticos_vida_km'], 'moneda' => $row['neumaticos_moneda']],
            'mantenimiento_pct' => $row['mantenimiento_pct'], 'recargo_pct' => $row['recargo_pct'],
            'costos_mensuales' => $monthly, 'otros_rubros' => $other,
        ];
        $trip = $row['viaje_km_facturables'] === null && $row['viaje_km_no_facturados'] === null && $row['viaje_peajes'] === null ? null : [
            'km_facturables' => $row['viaje_km_facturables'], 'km_no_facturados' => $row['viaje_km_no_facturados'],
                'peajes' => $row['viaje_peajes'], 'moneda' => $row['viaje_peajes_moneda'], 'peajes_ars' => $row['viaje_peajes_ars'],
        ];
        $result = [
            'cobertura' => (string) $row['cobertura'],
            'faltantes' => json_decode((string) $row['faltantes_json'], true) ?: [],
            'moneda_base' => 'ARS',
            'cotizacion' => ['ars_por_usd' => $row['cotizacion_ars_usd'], 'fecha' => $row['cotizacion_fecha'], 'fuente' => $row['cotizacion_fuente'], 'valida' => $row['cotizacion_ars_usd'] !== null && $row['cotizacion_fecha'] !== null && $row['cotizacion_fuente'] !== null],
            'km_mensuales' => $row['km_mensuales'], 'recargo_pct' => $row['recargo_pct'], 'mantenimiento_pct' => $row['mantenimiento_pct'],
            'costo_km' => ['ars' => $row['costo_ars_km'], 'usd' => $row['costo_usd_km']],
            'tarifa_km' => ['ars' => $row['tarifa_ars_km'], 'usd' => $row['tarifa_usd_km']],
            'rubros' => $breakdown,
            'viaje' => $trip === null ? null : [
                'km_facturables' => $row['viaje_km_facturables'], 'km_no_facturados' => $row['viaje_km_no_facturados'],
                'peajes' => $row['viaje_peajes'], 'peajes_moneda' => $row['viaje_peajes_moneda'],
                'peajes_ars' => $row['viaje_peajes_ars'], 'costo_ars' => $row['viaje_costo_ars'],
                'tarifa_ars_km' => $row['viaje_tarifa_ars_km'], 'tarifa_usd_km' => $row['viaje_tarifa_usd_km'],
                'estado' => $row['cobertura'] === 'completa' ? 'calculado' : 'incompleto',
                'cobertura' => (string) $row['cobertura'],
            ],
        ];

        return [
            'id' => (int) $row['escenario_id'], 'nombre' => (string) $row['nombre'], 'version' => (int) $row['numero_version'],
            'guardado_en' => (string) $row['guardado_en'],
            'tractor' => $row['tractor_id'] === null ? null : ['id' => (int) $row['tractor_id'], 'codigo' => (string) $row['tractor_codigo'], 'tipo' => (string) $row['tractor_tipo'], 'sucursal' => (string) $row['tractor_sucursal']],
            'semi' => $row['semi_id'] === null ? null : ['id' => (int) $row['semi_id'], 'codigo' => (string) $row['semi_codigo'], 'tipo' => (string) $row['semi_tipo'], 'sucursal' => (string) $row['semi_sucursal']],
            'parametros' => $parameters, 'viaje' => $trip, 'resultado' => $result,
        ];
    }

    public function appendVersion(int $companyId, int $userId, ?array $branchIds, ?int $scenarioId, string $name, ?int $tractorId, ?int $semiId, array $payload, array $result): array
    {
        $pair = null;
        if ($tractorId !== null && $semiId !== null) {
            $pair = $this->equipmentPair($companyId, $branchIds, $tractorId, $semiId);
            if ($pair === null) {
                throw new DomainException('El tractor o el semi quedó fuera de tu alcance antes de guardar.');
            }
        } elseif ($tractorId !== null || $semiId !== null) {
            throw new DomainException('Seleccioná tractor y semi juntos o dejá ambos sin asociar.');
        }
        $this->database->transBegin();
        try {
            if ($scenarioId === null) {
                $this->database->table('costos_teoricos_escenarios')->insert([
                    'empresa_id' => $companyId,
                    'sucursal_tractor_id' => $pair['tractor']['sucursal_id'] ?? null,
                    'sucursal_semi_id' => $pair['semi']['sucursal_id'] ?? null,
                    'tractor_id' => $tractorId, 'semi_id' => $semiId, 'nombre' => $name,
                    'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $scenarioId = (int) $this->database->insertID();
                $versionNumber = 1;
            } else {
                $query = $this->database->table('costos_teoricos_escenarios')->where('id', $scenarioId)->where('empresa_id', $companyId);
                if ($branchIds === [] && ($tractorId !== null || $semiId !== null)) {
                    throw new DomainException('No tenés sucursales habilitadas para este escenario.');
                }
                if ($branchIds !== null && $branchIds !== [] && ($tractorId !== null || $semiId !== null)) {
                    $query->whereIn('sucursal_tractor_id', $branchIds)->whereIn('sucursal_semi_id', $branchIds);
                } elseif ($branchIds === []) {
                    $query->where('sucursal_tractor_id', null)->where('sucursal_semi_id', null);
                }
                $existing = $this->database->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray();
                if ($existing === null) {
                    throw new DomainException('No se encontró el escenario solicitado dentro de tu alcance.');
                }
                $existingTractorId = $existing['tractor_id'] === null ? null : (int) $existing['tractor_id'];
                $existingSemiId = $existing['semi_id'] === null ? null : (int) $existing['semi_id'];
                if ($existingTractorId !== $tractorId || $existingSemiId !== $semiId) {
                    throw new DomainException('Una versión nueva debe conservar el tractor y el semi del escenario original.');
                }
                $scenarioId = (int) $existing['id'];
                $versionNumber = ((int) $this->database->table('costos_teoricos_versiones')->selectMax('numero_version')->where('escenario_id', $scenarioId)->get()->getRowArray()['numero_version']) + 1;
                $name = (string) $existing['nombre'];
            }

            $parameters = is_array($payload['parametros'] ?? null) ? $payload['parametros'] : [];
            $this->database->table('costos_teoricos_versiones')->insert($this->versionRow($scenarioId, $versionNumber, $userId, $parameters, $payload['viaje'] ?? null, $result));
            $versionId = (int) $this->database->insertID();
            $this->saveRubrics($versionId, $parameters, $result);
            if ($this->database->transStatus() === false) {
                throw new DomainException('No se pudo guardar la versión del escenario.');
            }
            $this->database->transCommit();
            $saved = $this->findVersion($companyId, $branchIds, $scenarioId, $versionNumber);
            if ($saved === null) {
                throw new DomainException('El escenario se guardó, pero no se pudo volver a consultar.');
            }
            return $saved;
        } catch (\Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    private function assetsQuery(int $companyId, ?array $branchIds)
    {
        $builder = $this->database->table('equipos e')
            ->select('e.id, e.empresa_id, e.sucursal_id, e.codigo, e.patente, e.estado, e.deleted_at, te.nombre tipo_nombre, te.controla_km, te.activo tipo_activo, s.nombre sucursal_nombre')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('sucursales s', 's.empresa_id = e.empresa_id AND s.id = e.sucursal_id', 'inner')
            ->where('e.empresa_id', $companyId)->where('e.estado', 'ACTIVO')->where('e.deleted_at', null)
            ->where('te.activo', 1)->where('te.controla_km', 1)->where('s.estado', 1);
        if ($branchIds !== null) {
            if ($branchIds === []) {
                $builder->where('e.id', 0);
            } else {
                $builder->whereIn('e.sucursal_id', $branchIds);
            }
        }
        return $builder;
    }

    private function scopedScenarioQuery(int $companyId, ?array $branchIds)
    {
        $builder = $this->database->table('costos_teoricos_escenarios s')->where('s.empresa_id', $companyId);
        if ($branchIds !== null) {
            if ($branchIds === []) {
                $builder->groupStart()->where('s.sucursal_tractor_id', null)->where('s.sucursal_semi_id', null)->groupEnd();
            } else {
                $builder->groupStart()
                    ->groupStart()->where('s.sucursal_tractor_id', null)->where('s.sucursal_semi_id', null)->groupEnd()
                    ->orGroupStart()->whereIn('s.sucursal_tractor_id', $branchIds)->whereIn('s.sucursal_semi_id', $branchIds)->groupEnd()
                    ->groupEnd();
            }
        }
        return $builder;
    }

    private function isTractorType(string $type): bool
    {
        return $this->isSemiType($type) === false && preg_match('/cami[oó]n|tractor/iu', $type) === 1;
    }

    private function isSemiType(string $type): bool
    {
        return preg_match('/acoplado|semi|semirremolque/iu', $type) === 1;
    }

    private function versionRow(int $scenarioId, int $versionNumber, int $userId, array $p, mixed $trip, array $result): array
    {
        $fx = is_array($p['fx'] ?? null) ? $p['fx'] : [];
        $tractor = is_array($p['tractor'] ?? null) ? $p['tractor'] : [];
        $semi = is_array($p['semi'] ?? null) ? $p['semi'] : [];
        $fuel = is_array($p['combustible'] ?? null) ? $p['combustible'] : [];
        $tires = is_array($p['neumaticos'] ?? null) ? $p['neumaticos'] : [];
        $trip = is_array($trip) ? $trip : [];
        $tripResult = is_array($result['viaje'] ?? null) ? $result['viaje'] : [];
        return [
            'escenario_id' => $scenarioId, 'numero_version' => $versionNumber,
            'cotizacion_ars_usd' => $this->decimal($fx['ars_por_usd'] ?? null, 6), 'cotizacion_fecha' => $this->date($fx['fecha'] ?? null), 'cotizacion_fuente' => $this->nullableText($fx['fuente'] ?? null),
            'km_mensuales' => $this->decimal($p['km_mensuales'] ?? null, 2),
            'tractor_valor_usd' => $this->decimal($tractor['valor_usd'] ?? null, 2), 'tractor_residual_usd' => $this->decimal($tractor['residual_usd'] ?? null, 2), 'tractor_vida_anios' => $this->decimal($tractor['vida_util_anios'] ?? null, 2),
            'semi_valor_usd' => $this->decimal($semi['valor_usd'] ?? null, 2), 'semi_residual_usd' => $this->decimal($semi['residual_usd'] ?? null, 2), 'semi_vida_anios' => $this->decimal($semi['vida_util_anios'] ?? null, 2),
            'combustible_litros_100km' => $this->decimal($fuel['litros_100km'] ?? null, 3), 'combustible_precio' => $this->decimal($fuel['precio'] ?? null, 4), 'combustible_moneda' => $this->currency($fuel['moneda'] ?? null), 'combustible_fecha' => $this->date($fuel['fecha'] ?? null),
            'neumaticos_costo_juego' => $this->decimal($tires['costo_juego'] ?? null, 4), 'neumaticos_recapados' => $this->decimal($tires['recapados'] ?? null, 4), 'neumaticos_vida_km' => $this->decimal($tires['vida_util_km'] ?? null, 2), 'neumaticos_moneda' => $this->currency($tires['moneda'] ?? null),
            'mantenimiento_pct' => $this->decimal($p['mantenimiento_pct'] ?? null, 4), 'recargo_pct' => $this->decimal($p['recargo_pct'] ?? null, 4),
            'viaje_km_facturables' => $this->decimal($trip['km_facturables'] ?? null, 2), 'viaje_km_no_facturados' => $this->decimal($trip['km_no_facturados'] ?? null, 2), 'viaje_peajes' => $this->decimal($trip['peajes'] ?? null, 4), 'viaje_peajes_moneda' => $this->currency($trip['moneda'] ?? null), 'viaje_peajes_ars' => $this->decimal($tripResult['peajes_ars'] ?? null, 2),
            'cobertura' => (string) ($result['cobertura'] ?? 'parcial'), 'costo_ars_km' => $this->decimal($result['costo_km']['ars'] ?? null, 6), 'costo_usd_km' => $this->decimal($result['costo_km']['usd'] ?? null, 6),
            'tarifa_ars_km' => $this->decimal($result['tarifa_km']['ars'] ?? null, 6), 'tarifa_usd_km' => $this->decimal($result['tarifa_km']['usd'] ?? null, 6),
            'viaje_costo_ars' => $this->decimal($tripResult['costo_ars'] ?? null, 2), 'viaje_tarifa_ars_km' => $this->decimal($tripResult['tarifa_ars_km'] ?? null, 6), 'viaje_tarifa_usd_km' => $this->decimal($tripResult['tarifa_usd_km'] ?? null, 6),
            'faltantes_json' => json_encode($result['faltantes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'creado_por' => $userId, 'guardado_en' => date('Y-m-d H:i:s'),
        ];
    }

    private function saveRubrics(int $versionId, array $p, array $result): void
    {
        $params = [
            'mensual' => is_array($p['costos_mensuales'] ?? null) ? $p['costos_mensuales'] : [],
            'otro' => is_array($p['otros_rubros'] ?? null) ? $p['otros_rubros'] : [],
        ];
        $resultByCode = [];
        foreach (($result['rubros'] ?? []) as $line) {
            if (is_array($line)) {
                $resultByCode[(string) ($line['codigo'] ?? '')] = $line;
            }
        }
        $savedCodes = [];
        foreach ($params as $type => $rows) {
            foreach ($rows as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $code = (string) ($line['codigo'] ?? $type . '_' . $index);
                $resultLine = $resultByCode[$code] ?? [];
                $this->insertRubric($versionId, $code, (string) ($line['nombre'] ?? 'Rubro'), $type, $line, $resultLine, (string) ($line['unidad'] ?? ($type === 'mensual' ? 'por_mes' : 'por_km')));
                $savedCodes[$code] = true;
            }
        }
        foreach (($result['rubros'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $code = (string) ($line['codigo'] ?? 'rubro');
            if (isset($savedCodes[$code])) {
                continue;
            }
            $savedCodes[$code] = true;
            $this->insertRubric($versionId, $code, (string) ($line['nombre'] ?? 'Rubro'), $type, [
                'importe' => $line['importe_origen'] ?? null,
                'moneda' => $line['moneda_origen'] ?? null,
                'unidad' => $line['unidad'] ?? '',
            ], $line, (string) ($line['unidad'] ?? 'por_km'));
        }
    }

    private function insertRubric(int $versionId, string $code, string $name, string $type, array $raw, array $calculated, string $unit): void
    {
        $this->database->table('costos_teoricos_rubros')->insert([
            'version_id' => $versionId, 'codigo' => mb_substr($code, 0, 80), 'nombre' => mb_substr($name, 0, 120), 'tipo' => $type,
            'importe' => $this->decimal($raw['importe'] ?? $raw['importe_origen'] ?? null, 4), 'moneda' => $this->currency($raw['moneda'] ?? $raw['moneda_origen'] ?? null),
            'unidad' => mb_substr($unit, 0, 20), 'fecha_referencia' => $this->date($raw['fecha'] ?? null), 'fuente' => $this->nullableText($raw['fuente'] ?? null),
            'ars_km' => $this->decimal($calculated['ars_km'] ?? null, 6), 'usd_km' => $this->decimal($calculated['usd_km'] ?? null, 6), 'estado' => (string) ($calculated['estado'] ?? 'faltante'),
        ]);
    }

    private function decimal(mixed $value, int $scale): ?string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }
        return number_format((float) $value, $scale, '.', '');
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        return mb_substr(trim($value), 0, 150);
    }

    private function currency(mixed $value): ?string
    {
        $currency = strtoupper(trim((string) $value));
        return in_array($currency, ['ARS', 'USD'], true) ? $currency : null;
    }
}
