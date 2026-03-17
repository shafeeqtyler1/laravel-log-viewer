<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer;

use Illuminate\Support\ServiceProvider;
use Shafeeq\LogViewer\Services\CallFlowService;
use Shafeeq\LogViewer\Services\LogParserService;
use Shafeeq\LogViewer\Services\LogReaderService;

class LogViewerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge default config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/log-viewer.php',
            'log-viewer'
        );

        // Register services as singletons
        $this->app->singleton(LogParserService::class, fn () => new LogParserService());

        $this->app->singleton(LogReaderService::class, function ($app) {
            return new LogReaderService($app->make(LogParserService::class));
        });

        $this->app->singleton(CallFlowService::class, fn () => new CallFlowService());

        // Register facade accessor
        $this->app->bind('log-viewer', fn ($app) => $app->make(LogReaderService::class));
    }

    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/Routes/web.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'log-viewer');

        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__ . '/../config/log-viewer.php' => config_path('log-viewer.php'),
            ], 'log-viewer-config');

            // Publish views
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/log-viewer'),
            ], 'log-viewer-views');
        }
    }
}
