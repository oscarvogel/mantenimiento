<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\ActorContext;
use Throwable;

final class SessionActorContext
{
    private const KEY = 'actor_context';

    public function store(ActorContext $actor): void
    {
        session()->set(self::KEY, $actor->toArray());
    }

    public function current(): ?ActorContext
    {
        $data = session()->get(self::KEY);

        if (! is_array($data)) {
            return null;
        }

        try {
            /** @var array{user_id: int, company_id: int|null, super_admin: bool, all_company_branches: bool, roles: list<string>, permissions: list<string>, branch_ids: list<int>} $data */
            return ActorContext::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    public function clear(): void
    {
        session()->remove(self::KEY);
    }
}
