<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

/** Normalized read-only source consumed by the transactional importer. */
interface ForumSource
{
    public const PHASES=['groups','users','forums','topics','posts','finalize','attachments','polls','votes','watches','board_watches'];
    public function platform(): string;
    public function identity(): string;
    public function version(): string;
    public function plan(): array;
    public function batch(string $phase,int $cursor,int $high,int $limit): array;
    public function memberships(int $user): array;
    public function options(int $topic): array;
    public function watchers(string $kind,int $id,int $after,int $limit): array;
    public function ballot(int $topic,int $user): array;
    public function unsupported(): array;
}
