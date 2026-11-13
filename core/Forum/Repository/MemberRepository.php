<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class MemberRepository extends Repository
{
    public function publicProfile(string $id): ?array { return $this->row("SELECT id,name,created_at FROM ce_users WHERE id=? AND status='active'", [$id]); }
}
