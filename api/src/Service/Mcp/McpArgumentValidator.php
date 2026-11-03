<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Validates tool arguments against the JSON Schema subset used by the tool
 * definitions: type, enum, minimum/maximum, minLength/maxLength, minItems,
 * format "date", properties/required/additionalProperties and items.
 *
 * Messages are Czech (the language of the app); they go straight to the model
 * as a tool error so it can correct the call.
 */
final class McpArgumentValidator
{
    /**
     * @param array<string,mixed> $schema
     * @return list<string> Human readable problems, empty when valid
     */
    public static function validate(array $schema, mixed $value, string $path = ''): array
    {
        $label = $path === '' ? 'Argumenty' : 'Argument `' . $path . '`';

        $types = isset($schema['type']) ? (array) $schema['type'] : [];
        if ($types !== [] && !self::matchesAnyType($types, $value)) {
            return [$label . ': očekávaný typ ' . implode('|', $types) . '.'];
        }

        $errors = [];

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = $label . ': povolené hodnoty jsou ' . implode(', ', array_map('strval', $schema['enum'])) . '.';
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = $label . ': minimální délka je ' . $schema['minLength'] . '.';
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = $label . ': maximální délka je ' . $schema['maxLength'] . '.';
            }
            if (($schema['format'] ?? null) === 'date' && !self::isDate($value)) {
                $errors[] = $label . ': očekávané datum ve formátu YYYY-MM-DD.';
            }
        }

        if ((is_int($value) || is_float($value)) && !is_bool($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = $label . ': minimální hodnota je ' . $schema['minimum'] . '.';
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = $label . ': maximální hodnota je ' . $schema['maximum'] . '.';
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = $label . ': minimální počet položek je ' . $schema['minItems'] . '.';
            }
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...self::validate($schema['items'], $item, $path . '[' . $i . ']')];
            }
        }

        $isObject = is_array($value) && ($value === [] ? in_array('object', $types, true) : !array_is_list($value));
        if ($isObject) {
            $errors = [...$errors, ...self::validateObject($schema, $value, $path, $label)];
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $value
     * @return list<string>
     */
    private static function validateObject(array $schema, array $value, string $path, string $label): array
    {
        $errors = [];
        $properties = (array) ($schema['properties'] ?? []);

        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (!array_key_exists($name, $value) || $value[$name] === null) {
                $errors[] = 'Chybí povinný argument `' . ($path === '' ? '' : $path . '.') . $name . '`.';
            }
        }

        foreach ($value as $name => $item) {
            $childPath = $path === '' ? (string) $name : $path . '.' . $name;
            if (!isset($properties[$name])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = 'Neznámý argument `' . $childPath . '`.';
                }
                continue;
            }
            if ($item === null) {
                continue; // reported above when required, otherwise treated as omitted
            }
            $errors = [...$errors, ...self::validate($properties[$name], $item, $childPath)];
        }

        return $errors;
    }

    /** @param list<string> $types */
    private static function matchesAnyType(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string'  => is_string($value),
                'integer' => is_int($value) || (is_float($value) && $value == floor($value) && is_finite($value)),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array'   => is_array($value) && array_is_list($value),
                // json_decode(assoc: true) turns `{}` into [], so an empty array is a valid object.
                'object'  => is_array($value) && ($value === [] || !array_is_list($value)),
                default   => false,
            };
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
