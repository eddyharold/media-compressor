<?php

namespace Harorudo\MediaCompressor;

use Illuminate\Support\ServiceProvider;

class CompressionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompressionUtil::class);
    }
}
