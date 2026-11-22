<?php

declare(strict_types=1);
namespace CajeerEngine\Api;

/** JSON is a YAML 1.2 subset. A single OpenAPI file is used by runtime and tooling. */
final class ApiContract
{
    private array $document;
    private array $operations = [];

    public function __construct(string $root)
    {
        $this->document = json_decode((string) file_get_contents($root . '/api/openapi.yaml'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->document['paths'] ?? [] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get','post','put','patch','delete'], true) && is_array($operation)) {
                    $route = $operation['x-cajeer-route'] ?? null;
                    if (!is_string($route) || $route === '' || isset($this->operations[$route])) {
                        throw new \RuntimeException('Missing or duplicated OpenAPI route identity');
                    }
                    $this->operations[$route] = $operation;
                }
            }
        }
    }

    public function document(): array { return $this->document; }
    public function operation(string $route): ?array { return $this->operations[$route] ?? null; }

    /** Validate the OpenAPI request subset without coercing JSON objects to arrays. */
    public function validate(mixed $value, array $schema, string $path = '$', int $depth = 0): array
    {
        if ($depth > 64) return [$path . ': schema nesting limit'];
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            $prefix = '#/components/schemas/';
            if (!is_string($ref) || !str_starts_with($ref, $prefix)
                || !isset($this->document['components']['schemas'][substr($ref, strlen($prefix))])) {
                return [$path . ': invalid schema reference'];
            }
            return $this->validate($value, $this->document['components']['schemas'][substr($ref, strlen($prefix))], $path, $depth + 1);
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $operator) {
            if (!isset($schema[$operator])) continue;
            if (!is_array($schema[$operator]) || $schema[$operator] === []) return [$path . ': invalid schema alternatives'];
            $matches = 0;
            foreach ($schema[$operator] as $alternative) {
                if (!is_array($alternative)) return [$path . ': invalid schema alternative'];
                if ($this->validate($value, $alternative, $path, $depth + 1) === []) $matches++;
            }
            if (($operator === 'anyOf' && $matches === 0)
                || ($operator === 'oneOf' && $matches !== 1)
                || ($operator === 'allOf' && $matches !== count($schema[$operator]))) {
                return [$path . ': does not match ' . $operator];
            }
        }
        if (array_key_exists('const', $schema) && $schema['const'] !== $value) return [$path . ': prohibited value'];
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) return [$path . ': invalid enum value'];
        if (isset($schema['type'])) {
            $types = (array)$schema['type'];
            $valid = false;
            foreach ($types as $type) {
                $valid = $valid || match ($type) {
                    'object' => $value instanceof \stdClass,
                    'array' => is_array($value) && array_is_list($value),
                    'string' => is_string($value),
                    'boolean' => is_bool($value),
                    'integer' => is_int($value),
                    'number' => is_int($value) || is_float($value),
                    'null' => $value === null,
                    default => false,
                };
            }
            if (!$valid) return [$path . ': expected ' . implode('|', $types)];
        }
        $errors = [];
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            if (isset($schema['minProperties']) && count($fields) < $schema['minProperties']) $errors[] = $path . ': too few properties';
            if (isset($schema['maxProperties']) && count($fields) > $schema['maxProperties']) $errors[] = $path . ': too many properties';
            foreach ($schema['required'] ?? [] as $field) {
                if (!property_exists($value, $field)) $errors[] = $path . '.' . $field . ': required';
            }
            foreach ($fields as $field => $fieldValue) {
                $child = $schema['properties'][$field] ?? null;
                if (is_array($child)) {
                    $errors = array_merge($errors, $this->validate($fieldValue, $child, $path . '.' . $field, $depth + 1));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path . '.' . $field . ': unknown property';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $errors = array_merge($errors, $this->validate($fieldValue, $schema['additionalProperties'], $path . '.' . $field, $depth + 1));
                }
                if (count($errors) >= 32) break;
            }
        } elseif (is_array($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) $errors[] = $path . ': too few items';
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) $errors[] = $path . ': too many items';
            if (($schema['uniqueItems'] ?? false) === true) {
                $seen = [];
                foreach ($value as $item) {
                    $key = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                    if (isset($seen[$key])) { $errors[] = $path . ': duplicate array item'; break; }
                    $seen[$key] = true;
                }
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $i => $item) {
                    $errors = array_merge($errors, $this->validate($item, $schema['items'], $path . '[' . $i . ']', $depth + 1));
                    if (count($errors) >= 32) break;
                }
            }
        } elseif (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (preg_match_all('/./us', $value) ?: 0);
            if (isset($schema['minLength']) && $length < $schema['minLength']) $errors[] = $path . ': too short';
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) $errors[] = $path . ': too long';
            if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~D', $value) !== 1) $errors[] = $path . ': invalid format';
            if (($schema['format'] ?? '') === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[] = $path . ': invalid email';
        } elseif (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) $errors[] = $path . ': below minimum';
            if (isset($schema['maximum']) && $value > $schema['maximum']) $errors[] = $path . ': above maximum';
        }
        return array_slice($errors, 0, 32);
    }
}
