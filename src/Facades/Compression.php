<?php

namespace Harorudo\MediaCompressor\Facades;

use Illuminate\Support\Facades\Facade;
use Harorudo\MediaCompressor\CompressionUtil;

class Compression extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CompressionUtil::class;
    }
}
