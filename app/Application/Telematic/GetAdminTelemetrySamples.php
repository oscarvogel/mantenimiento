<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\AdminTelemetrySamplesReader;

/** Sanitizes a global admin query before passing it to the telemetry read model. */
final readonly class GetAdminTelemetrySamples
{
    private const PER_PAGE = 50;

    public function __construct(private AdminTelemetrySamplesReader $reader)
    {
    }

    /** @param array<string,mixed> $query
     *  @return array<string,mixed>
     */
    public function execute(array $query): array
    {
        $filters = $this->filters($query);
        $filters['page'] = max(1, min(100000, (int) ($query['pagina'] ?? 1)));
        $filters['perPage'] = self::PER_PAGE;

        return $this->reader->read($filters);
    }

    /** @param array<string,mixed> $query
     *  @return array{view:string,rows:list<array<string,mixed>>}
     */
    public function export(array $query): array
    {
        return $this->reader->readForExport($this->filters($query));
    }

    /** @param array<string,mixed> $query
     *  @return array{companyId:?int,integrationId:?int,from:?string,to:?string,unit:string,view:string,page:int,perPage:int}
     */
    private function filters(array $query): array
    {
        return [
            'companyId' => $this->positiveInteger($query['empresa'] ?? null),
            'integrationId' => $this->positiveInteger($query['integracion'] ?? null),
            'from' => $this->date($query['desde'] ?? null),
            'to' => $this->date($query['hasta'] ?? null),
            'unit' => mb_substr(trim((string) ($query['unidad'] ?? '')), 0, 100),
            'view' => in_array(($query['vista'] ?? ''), ['buckets', 'samples'], true) ? (string) $query['vista'] : '',
            'page' => 1,
            'perPage' => self::PER_PAGE,
        ];
    }

    /** @param array<string,mixed> $query
     *  @return list<array<string,mixed>>
     */
    public function exportSamples(array $query): array
    {
        $companyId = $this->positiveInteger($query['empresa'] ?? null);
        $integrationId = $this->positiveInteger($query['integracion'] ?? null);
        if ($companyId === null || $integrationId === null) {
            return [];
        }

        return $this->reader->exportSamples([
            'companyId' => $companyId,
            'integrationId' => $integrationId,
            'from' => $this->date($query['desde'] ?? null),
            'to' => $this->date($query['hasta'] ?? null),
            'unit' => mb_substr(trim((string) ($query['unidad'] ?? '')), 0, 100),
        ]);
    }

    private function positiveInteger(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed !== false && $parsed > 0 ? $parsed : null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
