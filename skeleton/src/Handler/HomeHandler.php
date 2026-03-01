<?php

declare(strict_types=1);

namespace App\Handler;

/**
 * Example handler: GET / → 200 {"message":"Hello, Async PHP!"}
 *
 * This is a minimal async-safe handler demonstrating the request/response pattern.
 * The handler receives the raw OpenSwoole Request and Response objects.
 *
 * When ScopeRunner/ResponseFacade are available (Tasks 16-19), the signature
 * will evolve to: fn(Request, ResponseFacade, RequestContext, TaskScope): void
 */
final class HomeHandler
{
    /**
     * @param object $request  OpenSwoole\Http\Request
     * @param object $response OpenSwoole\Http\Response
     */
    public function __invoke(object $request, object $response): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(\json_encode(
            ['message' => 'Hello, Async PHP!'],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ));
    }
}
