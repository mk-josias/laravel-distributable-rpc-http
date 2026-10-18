<?php

declare(strict_types=1);

namespace RpcHttp\Tests;

use Microservices\Tests\TestCase as CoreTestCase;
use RpcHttp\RpcHttpServiceProvider;

/** The core's `orders` application, with the http RPC driver installed. */
abstract class TestCase extends CoreTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RpcHttpServiceProvider::class];
    }
}
