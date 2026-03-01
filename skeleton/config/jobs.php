<?php

declare(strict_types=1);

/**
 * BlockingPool job registrations.
 *
 * Register named jobs that can be executed in the BlockingPool (isolated processes).
 * Jobs run in separate processes — they do NOT block the event loop.
 *
 * Usage in handlers:
 *   $result = $blockingPool->run('job.name', ['key' => 'value'], timeout: 10.0);
 *
 * IMPORTANT:
 * - Closures are NOT serialized — use named jobs only (security + reliability)
 * - Each job receives an array $payload and returns a mixed result
 * - Results are serialized via JSON over IPC — use simple types (string, int, array)
 * - For standardized HTTP error mapping, use runOrRespondError()
 *
 * @param object $registry The JobRegistry instance
 */
return static function (object $registry): void {
    // Example: Register a CPU-bound job
    //
    // $registry->register('pdf.generate', function (array $payload): string {
    //     $reportId = $payload['report_id'];
    //     // Heavy computation runs in an isolated process — event loop stays free
    //     return generatePdfReport($reportId);
    // });

    // Example: Register a legacy library wrapper
    //
    // $registry->register('legacy.soap', function (array $payload): array {
    //     $client = new \SoapClient($payload['wsdl']);
    //     return (array) $client->__soapCall($payload['method'], $payload['args']);
    // });
};
