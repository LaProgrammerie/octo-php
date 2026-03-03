<?php

declare(strict_types=1);

namespace Octo\SymfonyBridge;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Optional reset hook for Doctrine EntityManager.
 *
 * Provided as a suggested implementation — the bridge does NOT depend on Doctrine.
 * Users who install doctrine/orm can register this hook to ensure clean state
 * between requests in a long-running process.
 *
 * Actions:
 * - Check for orphaned transactions → rollback + log warning
 * - $em->clear() to detach all managed entities
 */
final class DoctrineResetHook implements ResetHookInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Override]
    public function reset(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
            $this->logger->warning('Orphaned transaction rolled back during reset');
        }

        $this->em->clear();
    }
}
