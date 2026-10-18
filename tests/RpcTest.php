<?php

declare(strict_types=1);

use Distributable\Tests\Microservices\Fixtures\App\Contracts\OrdersService;
use Distributable\Tests\Microservices\Fixtures\App\Models\Order;
use Distributable\Tests\Microservices\Fixtures\Billing\Contracts\BillingService;
use Distributable\Tests\Microservices\Fixtures\Billing\Services\BillingRpcService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RpcHttp\RpcHttpServiceProvider;
use RpcHttp\RpcSignature;

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function signedCall(string $path, string $contract, array $arguments = [], array $context = []): array
{
    $body = ['contract' => $contract, 'arguments' => (object) $arguments];

    return [$body, app(RpcSignature::class)->headers($path, json_encode($body), json_encode((object) $context))];
}

it('calls a service running elsewhere over signed HTTP, on the route named after it', function () {
    Http::fake(['billing.test/*' => Http::response(['order_id' => 7, 'amount' => 120])]);

    expect(app(BillingService::class))->toBeInstanceOf(BillingRpcService::class)
        ->and(app(BillingService::class)->invoiceFor(7))->toBe(['order_id' => 7, 'amount' => 120]);

    Http::assertSent(function (ClientRequest $request): bool {
        $signature = app(RpcSignature::class)->sign(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/billing/rpc/invoiceFor',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
        );

        return $request->url() === 'http://billing.test/billing/rpc/invoiceFor'
            && $request['contract'] === BillingService::class
            && $request['arguments'] === ['orderId' => 7]
            && $request->header(RpcSignature::SIGNATURE_HEADER)[0] === $signature;
    });
});

it('reads a 404 from the other service as null', function () {
    Http::fake(['billing.test/*' => Http::response(null, 404)]);

    expect(app(BillingService::class)->invoiceFor(7))->toBeNull();
});

it('answers its own contract on a route named after this service', function () {
    $order = Order::query()->create(['total' => 120]);
    [$body, $headers] = signedCall('/orders/rpc/find', OrdersService::class, ['id' => $order->id]);

    $this->postJson('/orders/rpc/find', $body, $headers)->assertOk()->assertExactJson(['id' => $order->id, 'total' => 120]);
});

it('answers null with a 404, an unknown method with a 400, an unsigned call with a 403', function () {
    [$body, $headers] = signedCall('/orders/rpc/find', OrdersService::class, ['id' => 404]);
    $this->postJson('/orders/rpc/find', $body, $headers)->assertNotFound();

    [$body, $headers] = signedCall('/orders/rpc/cancel', OrdersService::class);
    $this->postJson('/orders/rpc/cancel', $body, $headers)->assertStatus(400)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Service [orders] answers no'));

    $this->postJson('/orders/rpc/find', ['contract' => OrdersService::class, 'arguments' => ['id' => 1]])->assertForbidden();
});

it('accepts a signed call once', function () {
    [$body, $headers] = signedCall('/orders/rpc/find', OrdersService::class, ['id' => 1]);

    $this->postJson('/orders/rpc/find', $body, $headers)->assertNotFound();
    $this->postJson('/orders/rpc/find', $body, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    [$body, $headers] = signedCall('/orders/rpc/traceId', OrdersService::class, [], ['trace_id' => 'abc']);

    $this->postJson('/orders/rpc/traceId', $body, $headers)->assertOk()->assertContent('"abc"');
});

it('calls and answers on the path rpc-http.path names, in the group it names', function () {
    config()->set('rpc-http.path', 'internal/{service}/{method}');
    config()->set('rpc-http.middleware_group', 'internal-rpc');
    (new RpcHttpServiceProvider(app()))->boot();
    Http::fake(['billing.test/*' => Http::response(['order_id' => 7])]);

    app(BillingService::class)->invoiceFor(7);
    $order = Order::query()->create(['total' => 120]);
    [$body, $headers] = signedCall('/internal/orders/find', OrdersService::class, ['id' => $order->id]);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://billing.test/internal/billing/invoiceFor');
    $this->postJson('/internal/orders/find', $body, $headers)->assertOk();
    $this->postJson('/internal/orders/find', ['contract' => OrdersService::class, 'arguments' => ['id' => 1]])->assertForbidden();
});

it('keeps an answer until the owner forgets it, with or without tags', function (?array $tags) {
    Http::fake(['billing.test/*' => Http::sequence()->push(['order_id' => 7, 'amount' => 120])->push(['order_id' => 7, 'amount' => 90])]);
    $billing = app(BillingRpcService::class);

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

    config()->set('microservices.rpc.cache', 'redis');
    Http::fake(['billing.test/*' => Http::response(['order_id' => 7, 'amount' => 120])]);
    $store = Cache::store('redis')->getStore();

    app(BillingRpcService::class)->cachedInvoiceFor(7);
    $written = $store->connection()->get($store->getPrefix().'billing:invoice:7');
    app(BillingRpcService::class)->forgetInvoice(7);

    expect($written)->toBe('{"order_id":7,"amount":120}')
        ->and($store->connection()->get($store->getPrefix().'billing:invoice:7'))->toBeIn([null, false]);
});

it('files a tagged answer under its tag on Redis, so flushing the tag drops it', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('microservices.rpc.cache', 'redis');
    Http::fake(['billing.test/*' => Http::sequence()->push(['order_id' => 7, 'amount' => 120])->push(['order_id' => 7, 'amount' => 90])]);
    $billing = app(BillingRpcService::class);

    $billing->cachedInvoiceFor(7, ['billing']);
    Cache::store('redis')->tags(['billing'])->flush();

    expect($billing->cachedInvoiceFor(7, ['billing']))->toBe(['order_id' => 7, 'amount' => 90]);
    $billing->forgetInvoice(7, ['billing']);
});
