<?php

declare(strict_types=1);

namespace RpcHttp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/** Guards the RPC routes: only a correctly signed, fresh call gets through. */
final readonly class VerifyRpcSignature
{
    public function __construct(private RpcSignature $signature) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = (string) $request->header(RpcSignature::CONTEXT_HEADER, '{}');

        $verified = $this->signature->verify(
            (string) $request->header(RpcSignature::TIMESTAMP_HEADER),
            (string) $request->header(RpcSignature::NONCE_HEADER),
            '/'.ltrim($request->path(), '/'),
            $request->getContent(),
            $context,
            (string) $request->header(RpcSignature::SIGNATURE_HEADER),
        );

        abort_unless($verified, 403, 'Invalid RPC signature.');

        /** @var array<string, mixed> $values */
        $values = json_decode($context, true) ?: [];
        Context::add($values);

        return $next($request);
    }
}
