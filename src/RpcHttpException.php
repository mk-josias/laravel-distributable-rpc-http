<?php

declare(strict_types=1);

namespace Distributable\RpcHttp;

use RuntimeException;

final class RpcHttpException extends RuntimeException
{
    public static function withoutPort(): self
    {
        return new self('rpc:serve needs the port the RPC answers on: set DISTRIBUTABLE_RPC_PORT.');
    }

    public static function serverNotStarted(): self
    {
        return new self('The server did not start: is laravel/octane installed, with the pcntl extension?');
    }
}
