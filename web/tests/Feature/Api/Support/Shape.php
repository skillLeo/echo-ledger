<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Support;

/**
 * A tiny structural validator used to check API bodies against what the device apps decode.
 *
 * Scalars: 'string', 'int', 'bool', 'number', 'any'. A trailing '?' makes a field optional and nullable.
 * Composites: Shape::object([...]), Shape::list($schema), Shape::map($schema).
 */
final class Shape
{
    /** @param array<string, mixed> $fields */
    public static function object(array $fields): array
    {
        return ['object' => $fields];
    }

    /** A composite that may be absent or null. */
    public static function optional(mixed $schema): array
    {
        return ['optional' => $schema];
    }

    public static function list(mixed $items): array
    {
        return ['list' => $items];
    }

    public static function map(mixed $values): array
    {
        return ['map' => $values];
    }

    /** @return list<string> human readable violations, empty when $data conforms */
    public static function violations(mixed $data, mixed $schema, string $path = '$'): array
    {
        if (is_string($schema)) {
            return self::scalar($data, $schema, $path);
        }
        if (isset($schema['optional'])) {
            return $data === null ? [] : self::violations($data, $schema['optional'], $path);
        }
        if (isset($schema['object'])) {
            if (! is_array($data) || ($data !== [] && array_is_list($data))) {
                return ["{$path}: expected an object, got ".get_debug_type($data)];
            }
            $problems = [];
            foreach ($schema['object'] as $field => $fieldSchema) {
                $optional = (is_string($fieldSchema) && str_ends_with($fieldSchema, '?')) || isset($fieldSchema['optional']);
                if (! array_key_exists($field, $data)) {
                    if (! $optional) {
                        $problems[] = "{$path}.{$field}: required field is missing";
                    }

                    continue;
                }
                $problems = array_merge($problems, self::violations($data[$field], $fieldSchema, "{$path}.{$field}"));
            }

            return $problems;
        }
        if (isset($schema['list'])) {
            if (! is_array($data) || ! array_is_list($data)) {
                return ["{$path}: expected a list, got ".get_debug_type($data)];
            }
            $problems = [];
            foreach ($data as $i => $item) {
                $problems = array_merge($problems, self::violations($item, $schema['list'], "{$path}[{$i}]"));
            }

            return $problems;
        }
        if (isset($schema['map'])) {
            if (! is_array($data)) {
                return ["{$path}: expected a map, got ".get_debug_type($data)];
            }
            $problems = [];
            foreach ($data as $key => $value) {
                $problems = array_merge($problems, self::violations($value, $schema['map'], "{$path}.{$key}"));
            }

            return $problems;
        }

        return ["{$path}: unusable schema"];
    }

    /** @return list<string> */
    private static function scalar(mixed $data, string $type, string $path): array
    {
        $nullable = str_ends_with($type, '?');
        $type = rtrim($type, '?');
        if ($data === null) {
            return $nullable ? [] : ["{$path}: null is not allowed"];
        }
        $ok = match ($type) {
            'string' => is_string($data),
            'int' => is_int($data),
            'bool' => is_bool($data),
            'number' => is_int($data) || is_float($data),
            'any' => true,
            default => false,
        };

        return $ok ? [] : ["{$path}: expected {$type}, got ".get_debug_type($data)];
    }
}
