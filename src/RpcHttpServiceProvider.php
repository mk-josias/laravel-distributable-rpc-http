<?php

declare(strict_types=1);

namespace Distributable\RpcHttp;

use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\TransportManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class RpcHttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rpc-http.php', 'rpc-http');

        $this->callAfterResolving(TransportManager::class, static function (TransportManager $transports): void {
            $transports->extend('http', static fn (Container $app): HttpTransport => $app->make(HttpTransport::class));
        });
    }

    /** The called side of HttpTransport, one endpoint per local module: nothing unsigned reaches it. */
    public function boot(): void
    {
        /** @var FoundationKernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(RpcPortGate::class);

        $this->publishes([__DIR__.'/../config/rpc-http.php' => config_path('rpc-http.php')], 'rpc-http-config');

        $config = $this->app->make(RpcHttp::class);
        $this->app->make(Router::class)->pushMiddlewareToGroup($config->getMiddlewareGroup(), VerifyRpcSignature::class);

        foreach ($this->app->make(ModuleRegistry::class)->localNames() as $module) {
            Route::post(str_replace('{module}', $module, $config->getPath()), RpcController::class)
                ->where('version', '[0-9]+')
                ->middleware($config->getMiddlewareGroup())
                ->defaults('module', $module)
                ->name("distributable.rpc.{$module}");
        }
    }
}
