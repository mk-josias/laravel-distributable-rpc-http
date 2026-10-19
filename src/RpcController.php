<?php

declare(strict_types=1);

namespace Distributable\RpcHttp;

use Distributable\Exceptions\ServiceException;
use Distributable\Services\Rpc\MessageRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The called side of HttpTransport: POST {module}/rpc/{version}/{message}, already verified by the `rpc` group. */
final readonly class RpcController
{
    public function __construct(private MessageRegistry $messages) {}

    public function __invoke(Request $request): JsonResponse
    {
        // The signed body as sent: input() would carry the global middleware's rewrites ('' becomes null).
        $body = (array) json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $payload */
        $payload = (array) ($body['payload'] ?? []);
        $module = (string) $request->route('module');
        $name = (string) $request->route('message');
        $version = (int) $request->route('version');

        // The caller's mistake, not a failure here; 404 already means a null answer.
        if (! $this->messages->answers($module, $name, $version)) {
            return response()->json(['message' => ServiceException::unknownRpcMessage($module, $name, $version)->getMessage()], 400);
        }

        $answer = $this->messages->answer($module, $name, $version, $payload);

        return $answer === null ? response()->json(null, 404) : response()->json($answer);
    }
}
