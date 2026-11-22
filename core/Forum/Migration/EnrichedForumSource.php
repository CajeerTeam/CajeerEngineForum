<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

/** Additional bounded source scans, available only for explicitly supported snapshots. */
interface EnrichedForumSource extends ForumSource
{
    /** Returns source counts and keyset high-water marks for actually supported phases. */
    public function additionalPlan(): array;
    /** Return normalized rows ordered strictly by the phase source ID. */
    public function additionalBatch(string $phase, int $after, int $high, int $limit): array;
}
