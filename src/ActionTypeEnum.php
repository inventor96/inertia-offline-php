<?php

declare(strict_types=1);

namespace inventor96\InertiaOffline;

enum ActionTypeEnum {
    case METHOD;
    case FUNCTION;
    case UNKNOWN;

    public static function from(mixed $action): ActionTypeEnum {
        if (
            is_array($action) // This covers both [$object, 'method'] and ['ClassName', 'method']
            && isset($action[0], $action[1]) // Ensure the required keys exist
            && (
                is_object($action[0]) // Instance method
                || is_string($action[0]) // Static method
            )
            && is_string($action[1]) // Method name should be a string
            && method_exists($action[0], $action[1]) // Check if the method actually exists
        ) {
            return static::METHOD;
        }

        if (is_callable($action)) {
            return static::FUNCTION;
        }

        return static::UNKNOWN;
    }

    public static function isSupported(mixed $action): bool {
        return static::from($action) !== static::UNKNOWN;
    }
}
