<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Validation;

use CajeerEngine\Forum\ForumException;

final class ForumValidator
{
    public static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value)) {
            throw new ForumException('Некорректный ID.');
        }
        return $value;
    }

    public static function text(mixed $value, string $key, int $max, int $min = 1): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new ForumException('Некорректное поле: '.$key);
        }
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw new ForumException('Некорректное поле: '.$key);
        }
        return $value;
    }

    public static function enabled(mixed $value): bool
    {
        if (!is_bool($value)) throw new ForumException('enabled должен быть boolean.');
        return $value;
    }

    public static function offset(mixed $page): int
    {
        if (!is_int($page) && (!is_string($page) || !preg_match('/^-?[0-9]+$/D', $page))) {
            throw new ForumException('Некорректная страница.', 400);
        }
        return (max(1, min(10000, (int) $page)) - 1) * 25;
    }

    public static function password(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 1024 || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new ForumException('Некорректный пароль.');
        }
        return $value;
    }
}
