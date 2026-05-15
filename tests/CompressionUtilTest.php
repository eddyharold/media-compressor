<?php

use Harorudo\MediaCompressor\CompressionUtil;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->util = new CompressionUtil();
});

it('rejects a disallowed storage disk', function () {
    config()->set('compression.allowed_disks', ['public']);

    $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

    $this->util->compressImage($file, disk: 'forbidden');
})->throws(InvalidArgumentException::class, "Storage disk 'forbidden' is not permitted.");

it('rejects an unsupported image MIME type', function () {
    $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

    $this->util->compressImage($file);
})->throws(InvalidArgumentException::class);

it('rejects files exceeding the configured max size', function () {
    config()->set('compression.max_file_size', 1024);

    $file = UploadedFile::fake()->create('big.jpg', 5, 'image/jpeg');

    $this->util->compressImage($file);
})->throws(InvalidArgumentException::class);

it('rejects an invalid Ghostscript preset', function () {
    $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

    $this->util->compressPdf($file, preset: 'bogus');
})->throws(InvalidArgumentException::class);

it('rejects a directory path that sanitises to empty', function () {
    $file = UploadedFile::fake()->image('photo.jpg');

    $this->util->compressImage($file, directory: '!!!');
})->throws(InvalidArgumentException::class, 'Invalid directory path.');

it('compresses an uploaded image and returns stats', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 2400, 1600);

    $result = $this->util->compressImage($file, maxWidth: 800);

    expect($result)
        ->toHaveKeys(['path', 'original_size', 'compressed_size', 'saved_bytes', 'saved_percent'])
        ->and($result['path'])->toStartWith('images/')
        ->and($result['compressed_size'])->toBeGreaterThan(0);

    Storage::disk('public')->assertExists($result['path']);
});

it('zips an uploaded file into the archive directory', function () {
    $file = UploadedFile::fake()->create('notes.txt', 5, 'text/plain');

    $result = $this->util->zipUploadedFile($file);

    expect($result['path'])->toEndWith('.zip');
    Storage::disk('public')->assertExists($result['path']);
});
