<?php

declare(strict_types=1);

namespace Distributable\RpcHttp;

use Illuminate\Contracts\Config\Repository;

/** The rpc-http.* settings of calls over HTTP, and the secret every service shares; read live, never snapshotted. */
final readonly class RpcHttp
{
    public function __construct(private Repository $config) {}

    /** Where a service answers a call, the same for caller and called: {service} and {method} are filled in. */
    public function getPath(): string
    {
        return trim((string) $this->config->get('rpc-http.path', '{service}/rpc/{method}'), '/');
    }

    /** The middleware group of the called side's routes, which checks the signature. */
    public function getMiddlewareGroup(): string
    {
        return (string) $this->config->get('rpc-http.middleware_group', 'rpc');
    }

    public function getSecret(): string
    {
        return (string) $this->config->get('distributable.rpc.secret', '');
    }

    public function getSignatureTtl(): int
    {
        return (int) $this->config->get('rpc-http.signature_ttl', 30);
    }
}
