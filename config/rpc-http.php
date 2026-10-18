<?php

return [
    // Where a service answers a call, the same in every service: {service} and {method} are filled in.
    'path' => env('MICROSERVICES_RPC_PATH', '{service}/rpc/{method}'),

    // The middleware group of those routes, which checks the signature.
    'middleware_group' => 'rpc',

    // Seconds a signature stays valid.
    'signature_ttl' => 30,
];
