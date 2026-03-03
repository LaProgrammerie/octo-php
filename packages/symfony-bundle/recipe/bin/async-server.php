#!/usr/bin/env php
<?php

/**
 * Async Platform — Server bootstrap script.
 *
 * Boots the Symfony kernel and starts the OpenSwoole HTTP server
 * via ServerBootstrap::run().
 *
 * Usage:
 *   php bin/async-server.php
 *
 * Environment variables:
 *   APP_ENV    — Symfony environment (default: prod)
 *   APP_DEBUG  — Debug mode (default: false in prod)
 */

declare(strict_types=1);

use App\Kernel;
use Octo\RuntimePack\ServerBootstrap;
use Octo\SymfonyBridge\HttpKernelAdapter;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ($env !== 'prod'));

$kernel = new Kernel($env, $debug);
$kernel->boot();

$container = $kernel->getContainer();

// Use the container-registered adapter if the bundle is loaded,
// otherwise create one manually.
if ($container->has(HttpKernelAdapter::class)) {
    $handler = $container->get(HttpKernelAdapter::class);
} else {
    $handler = new HttpKernelAdapter(
        kernel: $kernel,
        logger: $container->has('logger') ? $container->get('logger') : new \Psr\Log\NullLogger(),
        debug: $debug,
    );
}

ServerBootstrap::run(
    appHandler: $handler,
    production: $env === 'prod',
);
