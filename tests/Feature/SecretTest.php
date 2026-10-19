<?php

declare(strict_types=1);

use Distributable\RpcHttp\HttpTransport;

it('makes http the default RPC transport once installed', function () {
    expect(config('distributable.rpc.transport'))->toBe('http');
});

it('falls back to the application key for the RPC secret', function () {
    putenv('DISTRIBUTABLE_RPC_SECRET');
    putenv('APP_KEY=app-key-value');

    try {
        expect((require dirname(__DIR__, 2).'/config/rpc-http.php')['secret'])->toBe('app-key-value');
    } finally {
        putenv('APP_KEY');
    }
});

it('tells modules:doctor when no secret signs the calls', function () {
    config()->set('rpc-http.secret', '');

    expect(app(HttpTransport::class)->problems())->toBe(['rpc-http.secret is empty: set DISTRIBUTABLE_RPC_SECRET or APP_KEY.']);

    config()->set('rpc-http.secret', 'a-secret');

    expect(app(HttpTransport::class)->problems())->toBe([]);
});

it('fails modules:doctor when modules serve RPC messages and no secret signs the calls', function () {
    config()->set('rpc-http.secret', '');

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('rpc-http.secret is empty')
        ->assertFailed();
});
