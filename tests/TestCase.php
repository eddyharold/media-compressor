<?php

namespace Harorudo\MediaCompressor\Tests;

use Harorudo\MediaCompressor\CompressionServiceProvider;
use Intervention\Image\ImageServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ImageServiceProvider::class,
            CompressionServiceProvider::class,
        ];
    }
}
