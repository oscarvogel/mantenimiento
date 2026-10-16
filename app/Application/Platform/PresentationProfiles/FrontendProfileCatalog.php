<?php

declare(strict_types=1);

namespace App\Application\Platform\PresentationProfiles;

/**
 * Allowlist of frontend profiles compiled with this application release.
 */
final class FrontendProfileCatalog
{
    /** @var array<string, array{key: string, label: string, shareable: bool}> */
    private const PROFILES = [
        'base' => [
            'key' => 'base',
            'label' => 'Base',
            'shareable' => true,
        ],
        'vogel' => [
            'key' => 'vogel',
            'label' => 'Vogel',
            'shareable' => false,
        ],
    ];

    /**
     * @return array{key: string, label: string, shareable: bool}|null
     */
    public function find(string $key): ?array
    {
        return self::PROFILES[$key] ?? null;
    }

    public function defaultKey(): string
    {
        return 'base';
    }
}
