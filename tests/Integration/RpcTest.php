<?php

declare(strict_types=1);

use Distributable\Contracts\Rpc\Transport as RpcTransport;
use Distributable\RpcHttp\HttpTransport;
use Distributable\RpcHttp\RpcSignature;
use Distributable\RpcHttp\Tests\IamAppTestCase;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\TransportManager as RpcTransportManager;
use Foundation\Iam\Services\IamClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(IamAppTestCase::class);

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $context
 * @return array<string, string>
 */
function signedRpcHeaders(string $path, array $body, array $context = []): array
{
    return app(RpcSignature::class)->headers($path, json_encode($body), json_encode((object) $context));
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{payload: object}
 */
function rpcBody(array $payload = []): array
{
    return ['payload' => (object) $payload];
}

/** iam runs in another process from here on: its calls leave through its host. */
function remoteIam(): void
{
    config()->set('distributable.runs', 'analytics');
    app()->forgetInstance(ModuleRegistry::class);
    app()->forgetInstance(RpcTransportManager::class);
}

it('serves the RPC endpoint of each local module inside the signed rpc group', function () {
    $route = $this->app->make(Router::class)->getRoutes()->match(Request::create('/iam/rpc/1/find-user', 'POST'));

    expect($route->gatherMiddleware())->toContain('rpc')
        ->and($route->defaults['module'])->toBe('iam');
});

it('rejects an unsigned call', function () {
    $this->postJson('/iam/rpc/1/find-user', rpcBody(['id' => 1]))->assertForbidden();
});

it('answers a signed call through the module implementation', function () {
    $body = rpcBody(['id' => 1]);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada']);
});

it('answers a missing record with a 404, read back as null', function () {
    $body = rpcBody(['id' => 9]);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))->assertNotFound();
});

it('answers a message or a version the module does not serve with a 400, not a failure', function () {
    foreach (['/iam/rpc/1/does-not-exist', '/iam/rpc/3/find-user'] as $path) {
        $body = rpcBody(['id' => 1]);

        $this->postJson($path, $body, signedRpcHeaders($path, $body))
            ->assertStatus(400)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Module [iam] answers no'));
    }
});

it('answers a payload its rules refuse with a 422', function () {
    $body = rpcBody(['id' => 'x']);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))
        ->assertStatus(422)
        ->assertJsonValidationErrors('id');
});

it('serves two versions of a message while a transition lasts', function () {
    $body = rpcBody(['id' => 1]);

    $this->postJson('/iam/rpc/2/find-user', $body, signedRpcHeaders('/iam/rpc/2/find-user', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada', 'email' => 'ada@example.test']);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada']);
});

it('rejects a stale signature', function () {
    $timestamp = (string) (time() - 3600);
    $body = rpcBody(['id' => 1]);

    $this->postJson('/iam/rpc/1/find-user', $body, [
        RpcSignature::TIMESTAMP_HEADER => $timestamp,
        RpcSignature::NONCE_HEADER => 'n-1',
        RpcSignature::CONTEXT_HEADER => '{}',
        RpcSignature::SIGNATURE_HEADER => app(RpcSignature::class)->sign($timestamp, 'n-1', '/iam/rpc/1/find-user', json_encode($body), '{}'),
    ])->assertForbidden();
});

it('rejects a signed call replayed', function () {
    $body = rpcBody(['id' => 1]);
    $headers = signedRpcHeaders('/iam/rpc/1/find-user', $body);

    $this->postJson('/iam/rpc/1/find-user', $body, $headers)->assertOk();
    $this->postJson('/iam/rpc/1/find-user', $body, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    $body = rpcBody();

    $this->postJson('/iam/rpc/1/trace-id', $body, signedRpcHeaders('/iam/rpc/1/trace-id', $body, ['trace_id' => 'abc']))
        ->assertOk()->assertContent('"abc"');
});

it('rejects a context changed after signing', function () {
    $body = rpcBody();

    $this->postJson('/iam/rpc/1/trace-id', $body, [
        ...signedRpcHeaders('/iam/rpc/1/trace-id', $body, ['trace_id' => 'abc']),
        RpcSignature::CONTEXT_HEADER => json_encode(['trace_id' => 'forged']),
    ])->assertForbidden();
});

it('sends a signed POST naming the version and the message, and decodes the answer', function () {
    config()->set('distributable.events.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $answer = app(HttpTransport::class)->invoke('iam', 'find-user', 1, ['id' => 1]);

    expect($answer)->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://iam.test/iam/rpc/1/find-user'
        && $request['payload'] === ['id' => 1]
        && $request->header(RpcSignature::CONTEXT_HEADER)[0] === '{"trace_id":"abc"}'
        && app(RpcSignature::class)->verify(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/iam/rpc/1/find-user',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
            $request->header(RpcSignature::SIGNATURE_HEADER)[0],
        ));
});

it('reaches iam over HTTP once iam runs elsewhere', function () {
    remoteIam();
    Http::fake(['iam.test/iam/rpc/1/find-user' => Http::response(['id' => 1, 'name' => 'ada'])]);

    expect(app(RpcTransport::class)->invoke('iam', 'find-user', 1, ['id' => 1]))
        ->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});

it('reaches a module running elsewhere through its client, and caches its reads', function () {
    remoteIam();
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $iam = app(IamClient::class);

    expect($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada'])
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});

it('reads a remote refusal of the payload as a validation error', function () {
    remoteIam();
    Http::fake(['iam.test/*' => Http::response(['errors' => ['id' => ['bad']]], 422)]);

    app(RpcTransport::class)->invoke('iam', 'find-user', 1, ['id' => 'x']);
})->throws(ValidationException::class);

it('reads a missing remote record as null', function () {
    remoteIam();
    Http::fake(['iam.test/*' => Http::response(null, 404)]);

    expect(app(RpcTransport::class)->invoke('iam', 'find-user', 1, ['id' => 9]))->toBeNull();
});
