<?php

declare(strict_types=1);

namespace Distributable\RpcHttp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** With rpc-http.port set, an RPC route answers on that port only, and no other route answers there. */
final readonly class RpcPortGate
{
    public function __construct(private RpcHttp $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $port = $this->config->getPort();

        if ($port !== null) {
            abort_unless($request->is($this->config->getPathPattern()) === ($request->getPort() === $port), 404);
        }

        return $next($request);
    }
}
