<?php

declare(strict_types=1);

namespace Distributable\RpcHttp\Tests;

use Distributable\RpcHttp\RpcHttpServiceProvider;
use Distributable\Tests\OrdersAppTestCase as CoreTestCase;
use Illuminate\Foundation\Application;

/** The core's `orders` application, with the http RPC driver installed. */
abstract class TestCase extends CoreTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RpcHttpServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('rpc-http.secret', 'test-secret');
    }
}
