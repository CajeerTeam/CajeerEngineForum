<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Security;

use CajeerEngine\Forum\ForumException;

final readonly class ForumAccessPolicy
{
    public function __construct(public string $userId, private array $scopes) {}

    public static function fromAuth(mixed $auth): self
    {
        if (!is_array($auth) || !is_string($auth['user_id'] ?? null) || $auth['user_id'] === '') {
            throw new ForumException('Требуется авторизация.', 401);
        }
        return new self($auth['user_id'], is_array($auth['scopes'] ?? null) ? $auth['scopes'] : []);
    }

    public static function optional(mixed $auth): self { return is_array($auth) ? self::fromAuth($auth) : new self("", []); }

    public function has(string $scope): bool { return in_array("*", $this->scopes, true) || in_array($scope, $this->scopes, true); }

    public function require(string $scope): void
    {
        if (!in_array('*', $this->scopes, true) && !in_array($scope, $this->scopes, true)) {
            throw new ForumException('Недостаточно прав.', 403);
        }
    }

    public function assertEdit(array $post, array $thread): void
    {
        $this->require('forum:write');
        if (in_array('*', $this->scopes, true) || in_array('forum:moderate', $this->scopes, true)) return;
        if ($post['author_id'] !== $this->userId || (int) $thread['locked'] !== 0) {
            throw new ForumException('Недостаточно прав.', 403);
        }
    }
}
