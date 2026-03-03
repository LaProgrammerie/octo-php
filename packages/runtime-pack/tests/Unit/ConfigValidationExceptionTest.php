<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Unit;

use Octo\RuntimePack\Exception\ConfigValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigValidationExceptionTest extends TestCase
{
    #[Test]
    public function errors_array_is_accessible(): void
    {
        $errors = [
            'APP_PORT' => 'must be between 1 and 65535, got 0',
            'MAX_CONNECTIONS' => 'must be >= 1, got -5',
        ];

        $exception = new ConfigValidationException($errors);

        self::assertSame($errors, $exception->errors);
    }

    #[Test]
    public function message_contains_all_errors(): void
    {
        $errors = [
            'APP_PORT' => 'must be between 1 and 65535, got 0',
            'APP_WORKERS' => 'must be an integer, got \'abc\'',
        ];

        $exception = new ConfigValidationException($errors);

        self::assertStringContainsString('APP_PORT', $exception->getMessage());
        self::assertStringContainsString('must be between 1 and 65535', $exception->getMessage());
        self::assertStringContainsString('APP_WORKERS', $exception->getMessage());
        self::assertStringContainsString("must be an integer", $exception->getMessage());
    }

    #[Test]
    public function custom_message_prefix(): void
    {
        $exception = new ConfigValidationException(
            ['APP_PORT' => 'invalid'],
            'Startup failed',
        );

        self::assertStringStartsWith('Startup failed:', $exception->getMessage());
    }

    #[Test]
    public function single_error(): void
    {
        $exception = new ConfigValidationException(['APP_PORT' => 'bad value']);

        self::assertCount(1, $exception->errors);
        self::assertStringContainsString('APP_PORT: bad value', $exception->getMessage());
    }
}
