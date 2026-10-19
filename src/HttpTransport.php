<?php

declare(strict_types=1);

namespace Distributable\RpcHttp;

use Distributable\Config\Rpc;
use Distributable\Config\Streamer;
use Distributable\Contracts\Rpc\ReportsRpcProblems;
use Distributable\Contracts\Rpc\ServesRpc;
use Distributable\Contracts\Rpc\Transport;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

/** POST {host}/{module}/rpc/{version}/{message} with {payload}, signed, carrying the propagated context. */
final readonly class HttpTransport implements ReportsRpcProblems, ServesRpc, Transport
{
    public function __construct(
        private Http $http,
        private Rpc $rpc,
        private RpcHttp $config,
        private Streamer $streamer,
        private RpcSignature $signature,
    ) {}

    public function problems(): array
    {
        return $this->config->getSecret() === '' ? ['rpc-http.secret is empty: set DISTRIBUTABLE_RPC_SECRET or APP_KEY.'] : [];
    }

    public function serve(): int
    {
        $port = $this->config->getPort() ?? throw RpcHttpException::withoutPort();

        // Octane replaces this process: its signals and its exit code are the server's own.
        pcntl_exec(PHP_BINARY, [base_path('artisan'), 'octane:start', "--port={$port}", '--host=0.0.0.0']);

        throw RpcHttpException::serverNotStarted();
    }

    public function invoke(string $module, string $name, int $version, array $payload): mixed
    {
        $path = '/'.strtr($this->config->getPath(), ['{module}' => $module, '{version}' => (string) $version, '{message}' => $name]);
        $body = json_encode(['payload' => (object) $payload], JSON_THROW_ON_ERROR);
        $context = json_encode(Context::only($this->streamer->getPropagate()), JSON_THROW_ON_ERROR);

        $response = $this->http
            ->withHeaders(['Accept' => 'application/json', ...$this->signature->headers($path, $body, $context)])
            ->withBody($body, 'application/json')
            ->post(rtrim($this->rpc->getHost($module), '/').$path);

        if ($response->notFound()) {
            return null;
        }

        if ($response->status() === 422) {
            throw ValidationException::withMessages((array) $response->json('errors'));
        }

        $response->throw();

        return $response->json();
    }
}
