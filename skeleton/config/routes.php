<?php

declare(strict_types=1);

/**
 * User-defined application routes.
 *
 * Return a callable that receives the OpenSwoole Request and Response objects.
 * The runtime-pack handles internal routes (/healthz, /readyz) automatically —
 * this file only defines YOUR application routes.
 *
 * The returned handler is called for every request that is NOT a health endpoint.
 * You are responsible for routing within your application (simple match, nikic/fast-route, etc.).
 *
 * When ScopeRunner/ResponseFacade are available (V1.x), the signature will evolve to:
 *   fn(Request, ResponseFacade, RequestContext, TaskScope): void
 */

use App\Handler\HomeHandler;

return static function (): callable {
    $home = new HomeHandler();

    return static function (object $request, object $response) use ($home): void {
        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'] ?? 'GET';

        // Simple routing — replace with a router library (e.g. nikic/fast-route) as your app grows.
        if ($method === 'GET' && $path === '/') {
            $home($request, $response);
            return;
        }

        // 404 — route not found
        $response->status(404);
        $response->header('Content-Type', 'application/json');
        $response->end(\json_encode(
            ['error' => 'Not Found'],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ));
    };
};
