<?php

return [
    // Where a module answers a message, the same in every module: {module}, {version} and {message} are filled in.
    'path' => env('DISTRIBUTABLE_RPC_PATH', '{module}/rpc/{version}/{message}'),

    // The middleware group of those routes, which checks the signature.
    'middleware_group' => 'rpc',

    // Seconds a signature stays valid.
    'signature_ttl' => 30,
];
