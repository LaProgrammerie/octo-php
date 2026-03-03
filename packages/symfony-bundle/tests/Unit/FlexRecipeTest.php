<?php

declare(strict_types=1);

namespace Octo\SymfonyBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Verifies the Flex recipe file structure is correct and contains expected content.
 */
final class FlexRecipeTest extends TestCase
{
    private string $recipeDir;

    protected function setUp(): void
    {
        $this->recipeDir = dirname(__DIR__, 2) . '/recipe';
    }

    public function testConfigPackageFileExists(): void
    {
        $path = $this->recipeDir . '/config/packages/octo.yaml';
        self::assertFileExists($path);
    }

    public function testConfigPackageContainsAsyncPlatformKey(): void
    {
        $content = file_get_contents($this->recipeDir . '/config/packages/octo.yaml');
        self::assertStringContainsString('octo:', $content);
        self::assertStringContainsString('memory_warning_threshold', $content);
        self::assertStringContainsString('reset_warning_ms', $content);
        self::assertStringContainsString('kernel_reboot_every', $content);
    }

    public function testBootstrapScriptExists(): void
    {
        $path = $this->recipeDir . '/bin/async-server.php';
        self::assertFileExists($path);
    }

    public function testBootstrapScriptContainsServerBootstrap(): void
    {
        $content = file_get_contents($this->recipeDir . '/bin/async-server.php');
        self::assertStringContainsString('ServerBootstrap::run', $content);
        self::assertStringContainsString('HttpKernelAdapter', $content);
        self::assertStringContainsString('Kernel', $content);
    }

    public function testEnvFileExists(): void
    {
        $path = $this->recipeDir . '/.env';
        self::assertFileExists($path);
    }

    public function testEnvFileContainsAsyncPlatformVariables(): void
    {
        $content = file_get_contents($this->recipeDir . '/.env');
        self::assertStringContainsString('OCTOP_SYMFONY_MEMORY_WARNING_THRESHOLD', $content);
        self::assertStringContainsString('OCTOP_SYMFONY_RESET_WARNING_MS', $content);
        self::assertStringContainsString('OCTOP_SYMFONY_KERNEL_REBOOT_EVERY', $content);
    }

    public function testEnvFileHasFlexMarkers(): void
    {
        $content = file_get_contents($this->recipeDir . '/.env');
        self::assertStringContainsString('###> octo-php/symfony-bundle ###', $content);
        self::assertStringContainsString('###< octo-php/symfony-bundle ###', $content);
    }
}
