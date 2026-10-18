<?php

declare(strict_types=1);

namespace RpcHttp;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/** Signs and checks calls between services: an HMAC over the timestamp, nonce, path, body and context. */
final readonly class RpcSignature
{
    public const string TIMESTAMP_HEADER = 'X-Rpc-Timestamp';

    public const string NONCE_HEADER = 'X-Rpc-Nonce';

    public const string SIGNATURE_HEADER = 'X-Rpc-Signature';

    public const string CONTEXT_HEADER = 'X-Rpc-Context';

    public function __construct(
        private RpcHttp $config,
        private Cache $cache,
    ) {}

    /** @return array<string, string> every header a signed call carries */
    public function headers(string $path, string $body, string $context = '{}'): array
    {
        $timestamp = (string) time();
        $nonce = (string) Str::uuid7();

        return [
            self::TIMESTAMP_HEADER => $timestamp,
            self::NONCE_HEADER => $nonce,
            self::CONTEXT_HEADER => $context,
            self::SIGNATURE_HEADER => $this->sign($timestamp, $nonce, $path, $body, $context),
        ];
    }

    public function sign(string $timestamp, string $nonce, string $path, string $body, string $context): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, $path, $body, $context]), $this->config->getSecret());
    }

    /** A valid signature is accepted once: its nonce is spent. */
    public function verify(string $timestamp, string $nonce, string $path, string $body, string $context, string $signature): bool
    {
        $ttl = $this->config->getSignatureTtl();

        return $this->config->getSecret() !== ''
            && $timestamp !== ''
            && $nonce !== ''
            && abs(time() - (int) $timestamp) <= $ttl
            && hash_equals($this->sign($timestamp, $nonce, $path, $body, $context), $signature)
            && $this->cache->add("microservices:rpc:nonce:{$nonce}", true, $ttl * 2);
    }
}
