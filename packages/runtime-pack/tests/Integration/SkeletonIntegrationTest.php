<?php

declare(strict_types=1);

namespace Octo\RuntimePack\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the skeleton create-project.
 *
 * Verifies that the skeleton project starts and serves the expected response.
 */
#[Group('integration')]
#[RequiresPhpExtension('openswoole')]
final class SkeletonIntegrationTest extends TestCase
{
    use ServerProcessTrait;

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    // =========================================================================
    // 15.7 — skeleton create-project → functional project, async:serve starts,
    //        GET / → 200 {"message":"Hello, Async PHP!"}
    // =========================================================================

    public function testSkeletonAsyncServeStartsAndHomeReturns200(): void
    {
        $skeletonDir = $this->getSkeletonDir();

        // Verify skeleton structure exists
        $this->assertFileExists($skeletonDir . '/bin/console', 'Skeleton bin/console must exist');
        $this->assertFileExists($skeletonDir . '/config/routes.php', 'Skeleton config/routes.php must exist');
        $this->assertFileExists($skeletonDir . '/src/Handler/HomeHandler.php', 'Skeleton HomeHandler must exist');

        $this->startServer('async:serve', [], $skeletonDir);
        $this->waitForServerReady();

        // GET / → 200 {"message":"Hello, Async PHP!"}
        $response = $this->httpGet('/');

        $this->assertSame(200, $response['status']);

        $body = json_decode($response['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('Hello, Async PHP!', $body['message']);
    }

    public function testSkeletonHealthEndpointsWork(): void
    {
        $this->startServer('async:serve', [], $this->getSkeletonDir());
        $this->waitForServerReady();

        // /healthz
        $healthz = $this->httpGet('/healthz');
        $this->assertSame(200, $healthz['status']);
        $this->assertSame('alive', json_decode($healthz['body'], true)['status']);

        // /readyz
        $readyz = $this->httpGet('/readyz');
        $this->assertSame(200, $readyz['status']);
        $this->assertSame('ready', json_decode($readyz['body'], true)['status']);
    }

    public function testSkeletonReturns404ForUnknownRoutes(): void
    {
        $this->startServer('async:serve', [], $this->getSkeletonDir());
        $this->waitForServerReady();

        $response = $this->httpGet('/nonexistent');

        $this->assertSame(404, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertSame('Not Found', $body['error']);
    }
}
