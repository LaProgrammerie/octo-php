<?php

declare(strict_types=1);

namespace Octo\RuntimePack;

/**
 * Adapter interface for OS signal and timer operations.
 *
 * Abstracts OpenSwoole's Process::signal(), Timer::after(), and Timer::clear()
 * to enable unit testing of GracefulShutdown without the OpenSwoole extension.
 *
 * In production, the default implementation delegates to OpenSwoole APIs.
 * In tests, a fake implementation captures registrations for assertions.
 */
interface SignalAdapter
{
    /**
     * Install a process signal handler.
     *
     * @param int $signal Signal number (SIGTERM, SIGINT, etc.)
     * @param callable $handler Signal handler callback
     */
    public function installSignal(int $signal, callable $handler): void;

    /**
     * Schedule a one-shot timer.
     *
     * @param int $ms Delay in milliseconds
     * @param callable $callback Timer callback
     * @return int Timer ID
     */
    public function scheduleTimer(int $ms, callable $callback): int;

    /**
     * Clear a previously scheduled timer.
     *
     * @param int $timerId Timer ID to clear
     */
    public function clearTimer(int $timerId): void;
}
