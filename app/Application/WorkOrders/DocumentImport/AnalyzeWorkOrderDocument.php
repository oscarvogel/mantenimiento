<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentAnalyzer;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentImportRepository;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentStorage;
use App\Domain\WorkOrders\WorkOrderDocumentImport;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

final class AnalyzeWorkOrderDocument
{
    public function __construct(
        private readonly WorkOrderDocumentImportRepository $imports,
        private readonly WorkOrderDocumentStorage $storage,
        private readonly WorkOrderDocumentAnalyzer $analyzer,
        private readonly BaseConnection $db,
    ) {}

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $importId): array
    {
        [$companyId, $branchIds] = $this->scope($actor);
        $import = $this->imports->findForActor($importId, $companyId, $branchIds);
        if ($import === null) {
            throw new DomainException('La importación no existe o no está autorizada.');
        }

        $this->imports->saveAnalysis($importId, $companyId, [], WorkOrderDocumentImport::STATUS_ANALYZING);
        try {
            $analysis = $this->analyzer->analyze(
                $this->storage->absolutePath((string) $import['private_relative_path']),
                (string) $import['mime_type'],
            )->toArray();
            $this->imports->saveAnalysis($importId, $companyId, $analysis, WorkOrderDocumentImport::STATUS_ANALYZED);
            $proposal = $this->buildProposal($companyId, (int) $import['sucursal_id'], $analysis);
            $this->imports->saveProposal($importId, $companyId, $proposal);
            return $proposal;
        } catch (Throwable $exception) {
            $this->imports->saveAnalysis($importId, $companyId, [], WorkOrderDocumentImport::STATUS_ERROR, mb_substr($exception->getMessage(), 0, 2000));
            throw $exception;
        }
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function buildProposal(int $companyId, int $branchId, array $analysis): array
    {
        $plate = self::normalizePlate((string) ($analysis['plate'] ?? ''));
        $equipmentMatches = [];
        if ($plate !== '') {
            $rows = $this->db->table('equipos')
                ->select('id,codigo,patente,marca,modelo,sucursal_id,km_actual,horas_actuales,controla_km,controla_horas')
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', $branchId)
                ->where('estado', 'ACTIVO')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                if (self::normalizePlate((string) ($row['patente'] ?? '')) === $plate) {
                    $equipmentMatches[] = $row;
                }
            }
        }

        $equipment = count($equipmentMatches) === 1 ? $equipmentMatches[0] : null;
        $lastReading = null;
        $plans = [];
        if ($equipment !== null) {
            $lastReading = $this->db->table('equipo_lecturas')
                ->where('empresa_id', $companyId)
                ->where('equipo_id', (int) $equipment['id'])
                ->orderBy('fecha_lectura', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();
            $plans = $this->db->table('planes_mantenimiento p')
                ->select('p.id,p.nombre,p.estado,p.tipo_servicio_id,ts.nombre AS servicio_nombre')
                ->join('tipos_servicio ts', 'ts.id=p.tipo_servicio_id', 'left')
                ->where('p.empresa_id', $companyId)
                ->where('p.equipo_id', (int) $equipment['id'])
                ->where('p.estado', 'ACTIVO')
                ->get()->getResultArray();
        }

        $works = array_map(static function (array $work): array {
            $classification = strtolower(trim((string) ($work['classification'] ?? 'revisar')));
            if (! in_array($classification, ['correctivo', 'preventivo', 'revisar'], true)) {
                $classification = 'revisar';
            }
            return [...$work, 'classification' => $classification, 'included' => true];
        }, is_array($analysis['works'] ?? null) ? $analysis['works'] : []);

        return [
            'analysis' => $analysis,
            'normalizedPlate' => $plate,
            'equipment' => $equipment,
            'equipmentMatches' => $equipmentMatches,
            'equipmentResolution' => $equipment !== null ? 'UNICA' : ($equipmentMatches === [] ? 'NO_ENCONTRADO' : 'AMBIGUA'),
            'lastReading' => $lastReading,
            'readingWarning' => $this->readingWarning($analysis, $equipment),
            'works' => $works,
            'materials' => $analysis['materials'] ?? [],
            'preventivePlans' => $plans,
            'canCreateCorrective' => count(array_filter($works, static fn (array $w): bool => ($w['classification'] ?? '') === 'correctivo')) > 0,
            'canCreatePreventive' => count(array_filter($works, static fn (array $w): bool => ($w['classification'] ?? '') === 'preventivo')) > 0,
        ];
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed>|null $equipment */
    private function readingWarning(array $analysis, ?array $equipment): ?string
    {
        $value = isset($analysis['readingValue']) ? (float) $analysis['readingValue'] : null;
        if ($value === null || $equipment === null) {
            return null;
        }
        $type = strtolower((string) ($analysis['readingType'] ?? ''));
        $current = $type === 'horas' ? ($equipment['horas_actuales'] ?? null) : ($equipment['km_actual'] ?? null);
        if ($current !== null && $value < (float) $current) {
            return 'La lectura detectada es menor que la lectura actual del equipo y requiere revisión.';
        }
        return null;
    }

    private static function normalizePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $plate));
    }

    /** @return array{0:int,1:list<int>|null} */
    private function scope(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('ordenes.editar')) {
            throw new DomainException('No tenés permiso para analizar órdenes de taller.');
        }
        return [$actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds()];
    }
}
