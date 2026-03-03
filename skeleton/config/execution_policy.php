<?php

declare(strict_types=1);

/**
 * ExecutionPolicy configuration — Async safety matrix for I/O dependencies.
 *
 * This file lets you override the default execution strategies for your dependencies.
 * The runtime-pack provides sensible defaults via ExecutionPolicy::defaults($hookFlags),
 * and this file allows you to register additional dependencies or override existing ones.
 *
 * === Strategies ===
 *
 * DirectCoroutineOk
 *   The dependency is coroutine-safe — it yields to the event loop via OpenSwoole hooks.
 *   IoExecutor calls the directCallable directly in the request coroutine (zero overhead).
 *   Examples: Redis (phpredis), file_get_contents, OpenSwoole HTTP client.
 *
 * MustOffload
 *   The dependency is NOT coroutine-safe — it blocks the event loop.
 *   IoExecutor offloads to BlockingPool via a named job (process isolation).
 *   Examples: FFI, CPU-bound computation, unknown legacy libraries.
 *   This is the DEFAULT for unregistered dependencies (safe fallback).
 *
 * ProbeRequired
 *   The dependency MAY be coroutine-safe, but requires an integration proof on the prod image.
 *   IoExecutor offloads to BlockingPool + logs a debug message.
 *   Examples: PDO MySQL/PostgreSQL, Doctrine DBAL, Guzzle without SWOOLE_HOOK_CURL.
 *
 * === Default Matrix (set by runtime-pack) ===
 *
 * | Dependency       | Strategy           | Condition                              |
 * |------------------|--------------------|----------------------------------------|
 * | openswoole_http  | DirectCoroutineOk  | Always (native async)                  |
 * | redis            | DirectCoroutineOk  | Always (SWOOLE_HOOK_ALL)               |
 * | file_io          | DirectCoroutineOk  | Always (SWOOLE_HOOK_FILE)              |
 * | guzzle           | DirectCoroutineOk  | If SWOOLE_HOOK_CURL active             |
 * | guzzle           | ProbeRequired      | If SWOOLE_HOOK_CURL inactive           |
 * | pdo_mysql        | ProbeRequired      | Needs integration proof                |
 * | pdo_pgsql        | ProbeRequired      | Needs integration proof                |
 * | doctrine_dbal    | ProbeRequired      | Needs integration proof + reconnect    |
 * | ffi              | MustOffload        | Always (blocks event loop)             |
 * | cpu_bound        | MustOffload        | Always (blocks event loop)             |
 * | (unknown)        | MustOffload        | Default for unregistered dependencies  |
 *
 * === Usage in Handlers ===
 *
 * Use IoExecutor to route I/O calls automatically:
 *
 *   $result = $io->run(
 *       dependency: 'pdo_mysql',
 *       jobName: 'db.query',
 *       payload: ['sql' => 'SELECT * FROM users'],
 *       directCallable: fn() => $pdo->query('SELECT * FROM users')->fetchAll(),
 *       timeout: 5.0,
 *   );
 *
 * If the strategy is DirectCoroutineOk AND a directCallable is provided,
 * IoExecutor calls it directly (no BlockingPool overhead).
 * Otherwise, it offloads to BlockingPool via the named job.
 *
 * @param \Octo\RuntimePack\ExecutionPolicy $policy The policy instance with defaults already loaded
 * @return void
 */
return static function (object $policy): void {
    // -------------------------------------------------------------------------
    // Register your application-specific dependencies here.
    //
    // The runtime-pack defaults are already loaded — you only need to register
    // additional dependencies or override existing strategies.
    // -------------------------------------------------------------------------

    // Example: If you've validated that PDO MySQL is coroutine-safe on your prod image
    // (integration proof passed), you can promote it to DirectCoroutineOk:
    //
    // $policy->register('pdo_mysql', \Octo\RuntimePack\ExecutionStrategy::DirectCoroutineOk);

    // Example: Register a custom dependency that must always be offloaded:
    //
    // $policy->register('legacy_soap_client', \Octo\RuntimePack\ExecutionStrategy::MustOffload);

    // Example: Register a dependency that needs investigation:
    //
    // $policy->register('custom_http_client', \Octo\RuntimePack\ExecutionStrategy::ProbeRequired);
};
