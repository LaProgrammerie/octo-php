<?php

declare(strict_types=1);

namespace AsyncPlatform\SymfonyBridge;

use Symfony\Component\HttpFoundation\Request;

/**
 * Monolog processor that adds request_id to all log records.
 *
 * Reads _request_id from the current request's attributes and injects it
 * into extra.request_id on every log record.
 *
 * Usage: register as a service tagged monolog.processor in Symfony config.
 *
 * Note: This class conditionally implements Monolog\Processor\ProcessorInterface
 * when Monolog is installed. It works standalone regardless.
 */
final class RequestIdProcessor
{
    private ?Request $currentRequest = null;

    public function setCurrentRequest(?Request $request): void
    {
        $this->currentRequest = $request;
    }

    public function getCurrentRequest(): ?Request
    {
        return $this->currentRequest;
    }

    /**
     * Processes a Monolog LogRecord, adding extra.request_id.
     *
     * @param mixed $record Monolog\LogRecord when Monolog is installed
     * @return mixed The modified record
     */
    public function __invoke(mixed $record): mixed
    {
        $requestId = $this->currentRequest?->attributes->get('_request_id');

        if ($requestId !== null) {
            // Monolog 3.x uses LogRecord (immutable value object)
            if (\is_object($record) && \method_exists($record, 'toArray')) {
                // LogRecord: extra is an array, we return a new LogRecord with updated extra
                $record->extra['request_id'] = $requestId;
            } elseif (\is_array($record)) {
                // Legacy Monolog 2.x array format
                $record['extra']['request_id'] = $requestId;
            }
        }

        return $record;
    }
}
