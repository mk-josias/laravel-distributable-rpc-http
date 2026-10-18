# HTTP RPC

The `http` RPC driver of [laravel-microservices](https://github.com/mk-josias/laravel-microservices/tree/main/packages/core): signed calls between services.

```bash
composer require mk-josias/laravel-microservices-rpc-http
```

Its provider is discovered. It registers the driver of the shipped `http` transport, and serves
`POST {service}/rpc/{method}` for each service this process runs. Its settings are in
`config/rpc-http.php` (`php artisan vendor:publish --tag=rpc-http-config`); the secret stays
`distributable.rpc.secret`, shared by caller and called:

```php
return [
    // the same in every service
    'path' => env('DISTRIBUTABLE_RPC_PATH', '{service}/rpc/{method}'),
    // the group of those routes, which checks the signature
    'middleware_group' => 'rpc',
    // seconds a signature stays valid
    'signature_ttl' => 30,
];
```

| What it guarantees | |
|---|---|
| Signed | an HMAC of the timestamp, a nonce, the path, the body and the propagated context |
| Fresh | a call older than `signature_ttl` is rejected |
| Once | each nonce is accepted once, through the cache |
| Answers | 200 the return value as JSON · 404 `null` · 400 no such contract or method · 403 bad signature |

What a service written in another language has to send is in
[Services in other languages](../core/docs/other-languages.md).
