<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Application\Platform\PresentationProfiles\FrontendProfileCatalog;
use PHPUnit\Framework\TestCase;

final class FrontendProfileCatalogTest extends TestCase
{
    public function testBaseProfileIsTheSharedFallback(): void
    {
        $catalog = new FrontendProfileCatalog();

        self::assertSame('base', $catalog->defaultKey());
        self::assertSame('base', $catalog->find('base')['key']);
        self::assertTrue($catalog->find('base')['shareable']);
    }

    public function testVogelProfileIsRegisteredAndExclusive(): void
    {
        $profile = (new FrontendProfileCatalog())->find('vogel');

        self::assertNotNull($profile);
        self::assertSame('vogel', $profile['key']);
        self::assertFalse($profile['shareable']);
    }

    public function testUnknownOrEmptyProfileKeyIsNotRegistered(): void
    {
        $catalog = new FrontendProfileCatalog();

        self::assertNull($catalog->find('unknown-profile'));
        self::assertNull($catalog->find(''));
    }
}
