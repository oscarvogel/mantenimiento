<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

final readonly class CircuitOverviewPagination
{
    public const LISTS = ['equipments', 'plans', 'notices', 'orders', 'readings'];
    public const ALLOWED_PAGE_SIZES = [5, 10, 25];
    public const DEFAULT_PAGE_SIZE = 10;

    /** @var array<string,int> */
    private array $pages;
    /** @var array<string,int> */
    private array $pageSizes;

    /** @param array<string,mixed> $pages @param array<string,mixed> $pageSizes */
    public function __construct(array $pages = [], array $pageSizes = [])
    {
        $normalizedPages = [];
        $normalizedSizes = [];
        foreach (self::LISTS as $list) {
            $page = filter_var($pages[$list] ?? 1, FILTER_VALIDATE_INT);
            $size = filter_var($pageSizes[$list] ?? self::DEFAULT_PAGE_SIZE, FILTER_VALIDATE_INT);
            $normalizedPages[$list] = $page === false ? 1 : max(1, (int) $page);
            $normalizedSizes[$list] = in_array($size, self::ALLOWED_PAGE_SIZES, true)
                ? (int) $size
                : self::DEFAULT_PAGE_SIZE;
        }
        $this->pages = $normalizedPages;
        $this->pageSizes = $normalizedSizes;
    }

    public function page(string $list): int
    {
        return $this->pages[$list] ?? 1;
    }

    public function pageSize(string $list): int
    {
        return $this->pageSizes[$list] ?? self::DEFAULT_PAGE_SIZE;
    }

    /** @return array<string,int> */
    public function pages(): array { return $this->pages; }
    /** @return array<string,int> */
    public function pageSizes(): array { return $this->pageSizes; }
}
