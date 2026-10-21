<?php

declare(strict_types=1);

use Distributable\RpcHttp\HttpTransport;
use Distributable\RpcHttp\RpcHttpException;
use Distributable\RpcHttp\RpcHttpServiceProvider;
use Distributable\RpcHttp\RpcSignature;
use Distributable\Tests\Fixtures\Messaging\Services\MessagingClient;
use Distributable\Tests\Fixtures\Payments\Models\Payment;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/**
 * @param  array<string, mixed>  $payload
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function signedCall(string $path, array $payload = [], array $context = []): array
{
    $body = ['payload' => (object) $payload];

    return [$body, app(RpcSignature::class)->headers($path, json_encode($body), json_encode((object) $context))];
}

it('calls a module running elsewhere over signed HTTP, on the route named after it', function () {
    Http::fake(['messaging.test/*' => Http::response(['payment_id' => 7, 'amount' => 120])]);

    expect(app(MessagingClient::class)->conversationFor(7))->toBe(['payment_id' => 7, 'amount' => 120]);

    Http::assertSent(function (ClientRequest $request): bool {
        $signature = app(RpcSignature::class)->sign(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/messaging/rpc/1/conversation-for',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
        );

        return $request->url() === 'http://messaging.test/messaging/rpc/1/conversation-for'
            && $request['payload'] === ['paymentId' => 7]
            && $request->header(RpcSignature::SIGNATURE_HEADER)[0] === $signature;
    });
});

it('reads a 404 from the other module as null', function () {
    Http::fake(['messaging.test/*' => Http::response(null, 404)]);

    expect(app(MessagingClient::class)->conversationFor(7))->toBeNull();
});

it('answers its own messages on a route named after this module', function () {
    $payment = Payment::query()->create(['amount' => 120]);
    [$body, $headers] = signedCall('/payments/rpc/1/find-payment', ['id' => $payment->id]);

    $this->postJson('/payments/rpc/1/find-payment', $body, $headers)->assertOk()->assertExactJson(['id' => $payment->id, 'amount' => 120]);
});

it('answers null with a 404, an unknown message with a 400, an unsigned call with a 403', function () {
    [$body, $headers] = signedCall('/payments/rpc/1/find-payment', ['id' => 404]);
    $this->postJson('/payments/rpc/1/find-payment', $body, $headers)->assertNotFound();

    [$body, $headers] = signedCall('/payments/rpc/1/cancel-payment');
    $this->postJson('/payments/rpc/1/cancel-payment', $body, $headers)->assertStatus(400)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Module [payments] answers no'));

    $this->postJson('/payments/rpc/1/find-payment', ['payload' => ['id' => 1]])->assertForbidden();
});

it('accepts a signed call once', function () {
    [$body, $headers] = signedCall('/payments/rpc/1/find-payment', ['id' => 1]);

    $this->postJson('/payments/rpc/1/find-payment', $body, $headers)->assertNotFound();
    $this->postJson('/payments/rpc/1/find-payment', $body, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    [$body, $headers] = signedCall('/payments/rpc/1/trace-id', [], ['trace_id' => 'abc']);

    $this->postJson('/payments/rpc/1/trace-id', $body, $headers)->assertOk()->assertContent('"abc"');
});

it('calls and answers on the path rpc-http.path names, in the group it names', function () {
    config()->set('rpc-http.path', 'internal/{module}/{version}/{message}');
    config()->set('rpc-http.middleware_group', 'internal-rpc');
    (new RpcHttpServiceProvider(app()))->boot();
    Http::fake(['messaging.test/*' => Http::response(['payment_id' => 7])]);

    app(MessagingClient::class)->conversationFor(7);
    $payment = Payment::query()->create(['amount' => 120]);
    [$body, $headers] = signedCall('/internal/payments/1/find-payment', ['id' => $payment->id]);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://messaging.test/internal/messaging/1/conversation-for');
    $this->postJson('/internal/payments/1/find-payment', $body, $headers)->assertOk();
    $this->postJson('/internal/payments/1/find-payment', ['payload' => ['id' => 1]])->assertForbidden();
});

it('keeps an answer until the owner forgets it, with or without tags', function (?array $tags) {
    Http::fake(['messaging.test/*' => Http::sequence()->push(['payment_id' => 7, 'amount' => 120])->push(['payment_id' => 7, 'amount' => 90])]);
    $messaging = app(MessagingClient::class);

    expect($messaging->cachedConversationFor(7, $tags))->toBe(['payment_id' => 7, 'amount' => 120])
        ->and($messaging->cachedConversationFor(7, $tags))->toBe(['payment_id' => 7, 'amount' => 120]);

    $messaging->forgetConversation(7, $tags);

    expect($messaging->cachedConversationFor(7, $tags))->toBe(['payment_id' => 7, 'amount' => 90]);
    Http::assertSentCount(2);
})->with([null, [['messaging']]]);

it('writes the answer as JSON on Redis, under the key Laravel names, so any language reads it', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('distributable.rpc.cache', 'redis');
    Http::fake(['messaging.test/*' => Http::response(['payment_id' => 7, 'amount' => 120])]);
    $store = Cache::store('redis')->getStore();

    app(MessagingClient::class)->cachedConversationFor(7);
    $written = $store->connection()->get($store->getPrefix().'messaging:conversation:7');
    app(MessagingClient::class)->forgetConversation(7);

    expect($written)->toBe('{"payment_id":7,"amount":120}')
        ->and($store->connection()->get($store->getPrefix().'messaging:conversation:7'))->toBeIn([null, false]);
});

it('files a tagged answer under its tag on Redis, so flushing the tag drops it', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('distributable.rpc.cache', 'redis');
    Http::fake(['messaging.test/*' => Http::sequence()->push(['payment_id' => 7, 'amount' => 120])->push(['payment_id' => 7, 'amount' => 90])]);
    $messaging = app(MessagingClient::class);

    $messaging->cachedConversationFor(7, ['messaging']);
    Cache::store('redis')->tags(['messaging'])->flush();

    expect($messaging->cachedConversationFor(7, ['messaging']))->toBe(['payment_id' => 7, 'amount' => 90]);
    $messaging->forgetConversation(7, ['messaging']);
});

it('answers an RPC call on its own port only, when rpc-http.port names one', function () {
    config()->set('rpc-http.port', 8001);
    $payment = Payment::query()->create(['amount' => 120]);
    [$body, $headers] = signedCall('/payments/rpc/1/find-payment', ['id' => $payment->id]);

    $this->postJson('http://localhost:8000/payments/rpc/1/find-payment', $body, $headers)->assertNotFound();
    $this->postJson('http://localhost:8001/payments/rpc/1/find-payment', $body, $headers)->assertOk();
});

it('answers no other route on the RPC port', function () {
    Route::get('ping', fn () => 'pong');
    config()->set('rpc-http.port', 8001);

    $this->get('http://localhost:8000/ping')->assertOk();
    $this->get('http://localhost:8001/ping')->assertNotFound();
});

it('needs a port to start its own process', function () {
    app(HttpTransport::class)->serve();
})->throws(RpcHttpException::class, 'DISTRIBUTABLE_RPC_PORT');
