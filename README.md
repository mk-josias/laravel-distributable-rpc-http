# HTTP RPC

The `http` RPC driver of [laravel-distributable](https://github.com/mk-josias/laravel-distributable/tree/main/packages/core): signed calls between modules.

```bash
composer require mk-josias/laravel-distributable-rpc-http
```

Its provider is discovered. It registers the driver of the shipped `http` transport, and serves
`POST {module}/rpc/{version}/{message}` for each module this process runs. Its settings are in
`config/rpc-http.php` (`php artisan vendor:publish --tag=rpc-http-config`). It declares the `http`
transport and takes it as the default when `distributable.rpc.transport` names none. The secret is
shared by caller and called:

```php
return [
    // signs every call; it falls back to APP_KEY
    'secret' => env('DISTRIBUTABLE_RPC_SECRET', env('APP_KEY', '')),
    // the same in every process
    'path' => env('DISTRIBUTABLE_RPC_PATH', '{module}/rpc/{version}/{message}'),
    // the group of those routes, which checks the signature
    'middleware_group' => 'rpc',
    // the port the RPC answers on, in a process of its own (`php artisan rpc:serve`);
    // null: wherever the application is served
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

What a program written in another language has to send is in
[Programs in other languages](https://mk-josias.github.io/laravel-distributable/docs/other-languages).
