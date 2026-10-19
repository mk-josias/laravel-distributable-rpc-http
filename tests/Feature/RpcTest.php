<?php

declare(strict_types=1);

use Distributable\RpcHttp\HttpTransport;
use Distributable\RpcHttp\RpcHttpException;
use Distributable\RpcHttp\RpcHttpServiceProvider;
use Distributable\RpcHttp\RpcSignature;
use Distributable\Tests\Fixtures\Billing\Services\BillingClient;
use Distributable\Tests\Fixtures\Orders\Models\Order;
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
    Http::fake(['billing.test/*' => Http::response(['order_id' => 7, 'amount' => 120])]);

    expect(app(BillingClient::class)->invoiceFor(7))->toBe(['order_id' => 7, 'amount' => 120]);

    Http::assertSent(function (ClientRequest $request): bool {
        $signature = app(RpcSignature::class)->sign(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/billing/rpc/1/invoice-for',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
        );

        return $request->url() === 'http://billing.test/billing/rpc/1/invoice-for'
            && $request['payload'] === ['orderId' => 7]
            && $request->header(RpcSignature::SIGNATURE_HEADER)[0] === $signature;
    });
});

it('reads a 404 from the other module as null', function () {
    Http::fake(['billing.test/*' => Http::response(null, 404)]);

    expect(app(BillingClient::class)->invoiceFor(7))->toBeNull();
});

it('answers its own messages on a route named after this module', function () {
    $order = Order::query()->create(['total' => 120]);
    [$body, $headers] = signedCall('/orders/rpc/1/find-order', ['id' => $order->id]);

    $this->postJson('/orders/rpc/1/find-order', $body, $headers)->assertOk()->assertExactJson(['id' => $order->id, 'total' => 120]);
});

it('answers null with a 404, an unknown message with a 400, an unsigned call with a 403', function () {
    [$body, $headers] = signedCall('/orders/rpc/1/find-order', ['id' => 404]);
    $this->postJson('/orders/rpc/1/find-order', $body, $headers)->assertNotFound();

    [$body, $headers] = signedCall('/orders/rpc/1/cancel-order');
    $this->postJson('/orders/rpc/1/cancel-order', $body, $headers)->assertStatus(400)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Module [orders] answers no'));

    $this->postJson('/orders/rpc/1/find-order', ['payload' => ['id' => 1]])->assertForbidden();
});

it('accepts a signed call once', function () {
    [$body, $headers] = signedCall('/orders/rpc/1/find-order', ['id' => 1]);

    $this->postJson('/orders/rpc/1/find-order', $body, $headers)->assertNotFound();
    $this->postJson('/orders/rpc/1/find-order', $body, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    [$body, $headers] = signedCall('/orders/rpc/1/trace-id', [], ['trace_id' => 'abc']);

    $this->postJson('/orders/rpc/1/trace-id', $body, $headers)->assertOk()->assertContent('"abc"');
});

it('calls and answers on the path rpc-http.path names, in the group it names', function () {
    config()->set('rpc-http.path', 'internal/{module}/{version}/{message}');
    config()->set('rpc-http.middleware_group', 'internal-rpc');
    (new RpcHttpServiceProvider(app()))->boot();
    Http::fake(['billing.test/*' => Http::response(['order_id' => 7])]);

    app(BillingClient::class)->invoiceFor(7);
    $order = Order::query()->create(['total' => 120]);
    [$body, $headers] = signedCall('/internal/orders/1/find-order', ['id' => $order->id]);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://billing.test/internal/billing/1/invoice-for');
    $this->postJson('/internal/orders/1/find-order', $body, $headers)->assertOk();
    $this->postJson('/internal/orders/1/find-order', ['payload' => ['id' => 1]])->assertForbidden();
});

it('keeps an answer until the owner forgets it, with or without tags', function (?array $tags) {
    Http::fake(['billing.test/*' => Http::sequence()->push(['order_id' => 7, 'amount' => 120])->push(['order_id' => 7, 'amount' => 90])]);
    $billing = app(BillingClient::class);

    expect($billing->cachedInvoiceFor(7, $tags))->toBe(['order_id' => 7, 'amount' => 120])
        ->and($billing->cachedInvoiceFor(7, $tags))->toBe(['order_id' => 7, 'amount' => 120]);

    $billing->forgetInvoice(7, $tags);

    expect($billing->cachedInvoiceFor(7, $tags))->toBe(['order_id' => 7, 'amount' => 90]);
    Http::assertSentCount(2);
})->with([null, [['billing']]]);

it('writes the answer as JSON on Redis, under the key Laravel names, so any language reads it', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('distributable.rpc.cache', 'redis');
    Http::fake(['billing.test/*' => Http::response(['order_id' => 7, 'amount' => 120])]);
    $store = Cache::store('redis')->getStore();

    app(BillingClient::class)->cachedInvoiceFor(7);
    $written = $store->connection()->get($store->getPrefix().'billing:invoice:7');
    app(BillingClient::class)->forgetInvoice(7);

    expect($written)->toBe('{"order_id":7,"amount":120}')
        ->and($store->connection()->get($store->getPrefix().'billing:invoice:7'))->toBeIn([null, false]);
});

it('files a tagged answer under its tag on Redis, so flushing the tag drops it', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('distributable.rpc.cache', 'redis');
    Http::fake(['billing.test/*' => Http::sequence()->push(['order_id' => 7, 'amount' => 120])->push(['order_id' => 7, 'amount' => 90])]);
    $billing = app(BillingClient::class);

    $billing->cachedInvoiceFor(7, ['billing']);
    Cache::store('redis')->tags(['billing'])->flush();

    expect($billing->cachedInvoiceFor(7, ['billing']))->toBe(['order_id' => 7, 'amount' => 90]);
    $billing->forgetInvoice(7, ['billing']);
});

it('answers an RPC call on its own port only, when rpc-http.port names one', function () {
    config()->set('rpc-http.port', 8001);
    $order = Order::query()->create(['total' => 120]);
    [$body, $headers] = signedCall('/orders/rpc/1/find-order', ['id' => $order->id]);

    $this->postJson('http://localhost:8000/orders/rpc/1/find-order', $body, $headers)->assertNotFound();
    $this->postJson('http://localhost:8001/orders/rpc/1/find-order', $body, $headers)->assertOk();
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
