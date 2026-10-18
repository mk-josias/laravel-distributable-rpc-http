<?php

declare(strict_types=1);

namespace RpcHttp;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Context;
use Microservices\Config\Rpc;
use Microservices\Config\Streamer;
use Microservices\Contracts\Rpc\Transport;

/** POST {host}/{service}/rpc/{method} with {contract, arguments}, signed, carrying the propagated context. */
final readonly class HttpTransport implements Transport
{
    public function __construct(
        private Http $http,
        private Rpc $rpc,
        private RpcHttp $config,
        private Streamer $streamer,
        private RpcSignature $signature,
    ) {}

    public function invoke(string $service, string $contract, string $method, array $arguments = []): mixed
    {
        $path = '/'.strtr($this->config->getPath(), ['{service}' => $service, '{method}' => $method]);
        $body = json_encode(['contract' => $contract, 'arguments' => (object) $arguments], JSON_THROW_ON_ERROR);
        $context = json_encode(Context::only($this->streamer->getPropagate()), JSON_THROW_ON_ERROR);

        $response = $this->http
            ->withHeaders(['Accept' => 'application/json', ...$this->signature->headers($path, $body, $context)])
            ->withBody($body, 'application/json')
            ->post(rtrim($this->rpc->getHost($service), '/').$path);

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return $response->json();
    }
}
