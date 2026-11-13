<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

final class ForumException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
