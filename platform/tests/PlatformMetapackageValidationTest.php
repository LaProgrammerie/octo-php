<?php

declare(strict_types=1);

namespace Octo\Platform\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Validates the octo-php/platform metapackage structure.
 */
final class PlatformMetapackageValidationTest extends TestCase
{
    private const PACKAGE_DIR = __DIR__ . '/..';

    private const EXPECTED_PACKAGES = [
        'php',
        'octo-php/runtime-pack',
        'octo-php/symfony-bridge',
        'octo-php/symfony-bundle',
        'octo-php/symfony-messenger',
        'octo-php/symfony-otel',
        'octo-php/symfony-realtime',
    ];

    private array $composerData;

    protected function setUp(): void
    {
        $composerJsonPath = self::PACKAGE_DIR . '/composer.json';
        $this->assertFileExists($composerJsonPath, 'composer.json must exist');

        $content = file_get_contents($composerJsonPath);
        $this->assertNotFalse($content);

        $this->composerData = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testPackageName(): void
    {
        $this->assertSame('octo-php/platform', $this->composerData['name'] ?? null);
    }

    public function testTypeIsMetapackage(): void
    {
        $this->assertArrayHasKey('type', $this->composerData);
        $this->assertSame('metapackage', $this->composerData['type']);
    }

    public function testRequiresAllExpectedPackages(): void
    {
        $this->assertArrayHasKey('require', $this->composerData);
        $require = $this->composerData['require'];

        foreach (self::EXPECTED_PACKAGES as $package) {
            $this->assertArrayHasKey(
                $package,
                $require,
                sprintf('Missing required package: %s', $package),
            );
            $this->assertNotEmpty(
                $require[$package],
                sprintf('Version constraint for %s must not be empty', $package),
            );
        }
    }

    public function testNoExtraneousDependencies(): void
    {
        $require = $this->composerData['require'] ?? [];
        $extraPackages = array_diff(array_keys($require), self::EXPECTED_PACKAGES);

        $this->assertEmpty(
            $extraPackages,
            sprintf('Unexpected dependencies found: %s', implode(', ', $extraPackages)),
        );
    }

    public function testNoSrcDirectory(): void
    {
        $this->assertDirectoryDoesNotExist(
            self::PACKAGE_DIR . '/src',
            'Metapackage must not contain a src/ directory',
        );
    }

    public function testNoAutoloadSection(): void
    {
        $this->assertArrayNotHasKey(
            'autoload',
            $this->composerData,
            'Metapackage must not have an autoload section',
        );
    }

    public function testNoAutoloadDevSection(): void
    {
        $this->assertArrayNotHasKey(
            'autoload-dev',
            $this->composerData,
            'Metapackage must not have an autoload-dev section',
        );
    }

    public function testNoRequireDevSection(): void
    {
        $this->assertArrayNotHasKey(
            'require-dev',
            $this->composerData,
            'Metapackage must not have a require-dev section',
        );
    }

    public function testNoScriptsSection(): void
    {
        $this->assertArrayNotHasKey(
            'scripts',
            $this->composerData,
            'Metapackage must not have a scripts section',
        );
    }
}
