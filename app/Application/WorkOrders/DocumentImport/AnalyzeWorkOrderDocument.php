<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\MaintenanceServiceCatalog;
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
        private readonly MaintenanceServiceCatalog $serviceCatalog,
        private readonly PreventivePlanMatcher $planMatcher = new PreventivePlanMatcher(),
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
            $proposal = $this->buildProposal($importId, $companyId, (int) $import['sucursal_id'], $analysis);
            $this->imports->saveProposal($importId, $companyId, $proposal);
            return $proposal;
        } catch (Throwable $exception) {
            $this->imports->saveAnalysis($importId, $companyId, [], WorkOrderDocumentImport::STATUS_ERROR, mb_substr($exception->getMessage(), 0, 2000));
            throw $exception;
        }
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function buildProposal(int $importId, int $companyId, int $branchId, array $analysis): array
    {
        $plate = self::normalizePlate((string) ($analysis['plate'] ?? ''));
        $equipmentMatches = [];
        if ($plate !== '') {
            $rows = $this->db->table('equipos')
                ->select('id,codigo,patente,sucursal_id,km_actual,horas_actuales,marca_id,modelo_id')
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', $branchId)
                ->where('estado', 'ACTIVO')
                ->where('deleted_at', null)
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
            $lastReading = $this->db->table('lecturas_equipo')
                ->where('empresa_id', $companyId)
                ->where('equipo_id', (int) $equipment['id'])
                ->where('anulada', 0)
                ->orderBy('fecha_lectura', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();
            $plans = $this->plansForEquipment($companyId, (int) $equipment['id']);
        }

        $works = array_map(static function (array $work): array {
            $classification = strtolower(trim((string) ($work['classification'] ?? 'revisar')));
            if (! in_array($classification, ['correctivo', 'preventivo', 'revisar'], true)) {
                $classification = 'revisar';
            }
            return [...$work, 'classification' => $classification, 'included' => true];
        }, is_array($analysis['works'] ?? null) ? $analysis['works'] : []);
        $materials = is_array($analysis['materials'] ?? null) ? $analysis['materials'] : [];
        $plans = $this->planMatcher->match($plans, $this->serviceCatalog->listForCompany($companyId), $works, $materials);
        $suggestedPlan = null;
        foreach ($plans as $plan) {
            if (($plan['suggested'] ?? false) === true) {
                $suggestedPlan = (int) $plan['id'];
                break;
            }
        }

        $possibleDuplicates = $this->possibleDuplicates(
            $importId,
            $companyId,
            $branchId,
            $equipment === null ? null : (int) $equipment['id'],
            $plate,
            $analysis,
            $works,
        );

        return [
            'analysis' => $analysis,
            'normalizedPlate' => $plate,
            'selectedEquipmentId' => $equipment === null ? null : (int) $equipment['id'],
            'serviceDate' => $analysis['serviceDate'] ?? null,
            'readingType' => $analysis['readingType'] ?? null,
            'readingValue' => $analysis['readingValue'] ?? null,
            'supplier' => $analysis['supplier'] ?? null,
            'concept' => $analysis['concept'] ?? null,
            'observations' => $analysis['observations'] ?? null,
            'totalAmount' => $analysis['totalAmount'] ?? null,
            'currency' => $analysis['currency'] ?? null,
            'correctiveAmount' => null,
            'preventiveAmount' => null,
            'selectedPlanId' => $suggestedPlan,
            'equipment' => $equipment,
            'equipmentMatches' => $equipmentMatches,
            'equipmentResolution' => $equipment !== null ? 'UNICA' : ($equipmentMatches === [] ? 'NO_ENCONTRADO' : 'AMBIGUA'),
            'lastReading' => $lastReading,
            'readingWarning' => $this->readingWarning($analysis, $equipment),
            'works' => $works,
            'materials' => $materials,
            'preventivePlans' => $plans,
            'possibleDuplicates' => $possibleDuplicates,
            'confirmPossibleDuplicate' => false,
            'canCreateCorrective' => count(array_filter($works, static fn (array $w): bool => ($w['classification'] ?? '') === 'correctivo')) > 0,
            'canCreatePreventive' => count(array_filter($works, static fn (array $w): bool => ($w['classification'] ?? '') === 'preventivo')) > 0,
        ];
    }

    /** @param array<string,mixed> $analysis @param list<array<string,mixed>> $works @return list<array<string,mixed>> */
    private function possibleDuplicates(int $importId, int $companyId, int $branchId, ?int $equipmentId, string $plate, array $analysis, array $works): array
    {
        $rows = $this->db->table('ot_document_imports')
            ->select('id,status,equipo_id,analysis_json,proposal_json,created_at')
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->where('id !=', $importId)
            ->whereIn('status', [WorkOrderDocumentImport::STATUS_ANALYZED, WorkOrderDocumentImport::STATUS_CONFIRMED])
            ->orderBy('id', 'DESC')
            ->get(50)
            ->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $candidateAnalysis = $this->decodeJson($row['analysis_json'] ?? null);
            $candidateProposal = $this->decodeJson($row['proposal_json'] ?? null);
            if ($candidateAnalysis === [] && $candidateProposal === []) continue;

            $score = 0;
            $reasons = [];
            $candidateEquipmentId = isset($candidateProposal['selectedEquipmentId']) ? (int) $candidateProposal['selectedEquipmentId'] : (isset($row['equipo_id']) ? (int) $row['equipo_id'] : null);
            $candidatePlate = self::normalizePlate((string) ($candidateProposal['normalizedPlate'] ?? $candidateAnalysis['plate'] ?? ''));
            if ($equipmentId !== null && $candidateEquipmentId === $equipmentId) {
                $score += 4; $reasons[] = 'mismo equipo';
            } elseif ($plate !== '' && $candidatePlate === $plate) {
                $score += 4; $reasons[] = 'misma patente';
            }

            $date = (string) ($analysis['serviceDate'] ?? '');
            $candidateDate = (string) ($candidateProposal['serviceDate'] ?? $candidateAnalysis['serviceDate'] ?? '');
            if ($date !== '' && $candidateDate === $date) {
                $score += 2; $reasons[] = 'misma fecha';
            }

            $readingType = strtolower((string) ($analysis['readingType'] ?? ''));
            $candidateReadingType = strtolower((string) ($candidateProposal['readingType'] ?? $candidateAnalysis['readingType'] ?? ''));
            $reading = $analysis['readingValue'] ?? null;
            $candidateReading = $candidateProposal['readingValue'] ?? $candidateAnalysis['readingValue'] ?? null;
            if ($reading !== null && $candidateReading !== null && $readingType === $candidateReadingType && abs((float) $reading - (float) $candidateReading) < 0.01) {
                $score += 2; $reasons[] = 'misma lectura';
            }

            $amount = $analysis['totalAmount'] ?? null;
            $candidateAmount = $candidateProposal['totalAmount'] ?? $candidateAnalysis['totalAmount'] ?? null;
            if ($amount !== null && $candidateAmount !== null && abs((float) $amount - (float) $candidateAmount) < 0.01) {
                $score += 2; $reasons[] = 'mismo importe';
            }

            $supplier = self::normalizeText((string) ($analysis['supplier'] ?? ''));
            $candidateSupplier = self::normalizeText((string) ($candidateProposal['supplier'] ?? $candidateAnalysis['supplier'] ?? ''));
            if ($supplier !== '' && $candidateSupplier !== '' && $supplier === $candidateSupplier) {
                $score += 1; $reasons[] = 'mismo taller/proveedor';
            }

            $candidateWorks = is_array($candidateProposal['works'] ?? null) ? $candidateProposal['works'] : (is_array($candidateAnalysis['works'] ?? null) ? $candidateAnalysis['works'] : []);
            if ($this->hasMatchingWork($works, $candidateWorks)) {
                $score += 1; $reasons[] = 'trabajos coincidentes';
            }

            if ($score < 6) continue;
            $result[] = [
                'importId' => (int) $row['id'],
                'status' => (string) $row['status'],
                'score' => $score,
                'reasons' => $reasons,
                'createdAt' => (string) ($row['created_at'] ?? ''),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($result, 0, 3);
    }

    /** @param list<array<string,mixed>> $left @param list<array<string,mixed>> $right */
    private function hasMatchingWork(array $left, array $right): bool
    {
        $rightTexts = array_values(array_filter(array_map(static fn (array $row): string => self::normalizeText((string) ($row['description'] ?? '')), $right)));
        foreach ($left as $row) {
            $text = self::normalizeText((string) ($row['description'] ?? ''));
            if ($text === '') continue;
            foreach ($rightTexts as $candidate) {
                if ($text === $candidate || (mb_strlen($text) >= 8 && (str_contains($candidate, $text) || str_contains($text, $candidate)))) return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $json): array
    {
        if (! is_string($json) || trim($json) === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string,mixed>> */
    private function plansForEquipment(int $companyId, int $equipmentId): array
    {
        return $this->db->table('planes_mantenimiento p')
            ->select('p.id,p.tipo_servicio_id,p.intervalo_km,p.intervalo_horas,p.intervalo_dias,p.proximo_km,p.proximas_horas,p.proxima_fecha,p.prioridad,ts.nombre AS servicio_nombre')
            ->join('tipos_servicio ts', 'ts.id=p.tipo_servicio_id', 'left')
            ->where('p.empresa_id', $companyId)
            ->where('p.equipo_id', $equipmentId)
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->get()->getResultArray();
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed>|null $equipment */
    private function readingWarning(array $analysis, ?array $equipment): ?string
    {
        $value = isset($analysis['readingValue']) ? (float) $analysis['readingValue'] : null;
        if ($value === null || $equipment === null) return null;
        $type = strtolower((string) ($analysis['readingType'] ?? ''));
        $current = $type === 'horas' ? ($equipment['horas_actuales'] ?? null) : ($equipment['km_actual'] ?? null);
        if ($current !== null && $value < (float) $current) return 'La lectura detectada es menor que la lectura actual del equipo y requiere revisión.';
        return null;
    }

    private static function normalizePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $plate));
    }

    private static function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
