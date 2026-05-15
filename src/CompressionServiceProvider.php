<?php

namespace Harorudo\MediaCompressor;

use Harorudo\MediaCompressor\Console\DoctorCommand;
use Illuminate\Support\ServiceProvider;

class CompressionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/compression.php', 'compression');
        $this->app->singleton(CompressionUtil::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/compression.php' => config_path('compression.php'),
            ], 'compression-config');

            $this->commands([
                DoctorCommand::class,
            ]);
        }
    }
}
