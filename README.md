# HTTP RPC

The `http` RPC driver of [laravel-microservices](https://github.com/mk-josias/laravel-microservices/tree/main/packages/core): signed calls between services.

```bash
composer require mk-josias/laravel-distributable-rpc-http
```

Its provider is discovered. It registers the driver of the shipped `http` transport, and serves
`POST {module}/rpc/{version}/{message}` for each module this process runs. Its settings are in
`config/rpc-http.php` (`php artisan vendor:publish --tag=rpc-http-config`); the secret stays
`distributable.rpc.secret`, shared by caller and called:

```php
return [
    // the same in every service
    'path' => env('DISTRIBUTABLE_RPC_PATH', '{module}/rpc/{version}/{message}'),
    // the group of those routes, which checks the signature
    'middleware_group' => 'rpc',
    // the port the RPC answers on, in a process of its own (`php artisan rpc:serve`); null: wherever the application is served
    'port' => env('DISTRIBUTABLE_RPC_PORT'),
    // seconds a signature stays valid
    'signature_ttl' => 30,
];
```

| What it guarantees | |
|---|---|
| Signed | an HMAC of the timestamp, a nonce, the path, the body and the propagated context |
| Fresh | a call older than `signature_ttl` is rejected |
| Once | each nonce is accepted once, through the cache |
| Answers | 200 the return value as JSON · 404 `null` · 400 no such message or version · 403 bad signature · 422 a payload its rules refuse |

What a service written in another language has to send is in
[Services in other languages](../core/docs/other-languages.md).
