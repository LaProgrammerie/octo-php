<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Exception;

use RuntimeException;

/**
 * Thrown when server configuration validation fails at startup.
 *
 * Collects ALL validation errors before throwing, so the operator
 * sees every problematic variable in a single error message.
 */
final class ConfigValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors map of variable name => error message
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Configuration invalide',
    ) {
        $details = implode('; ', array_map(
            static fn (string $var, string $msg): string => "{$var}: {$msg}",
            array_keys($errors),
            array_values($errors),
        ));

        parent::__construct("{$message}: {$details}");
    }
}
