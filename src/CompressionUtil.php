<?php

namespace Harorudo\MediaCompressor;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use ZipArchive;

class CompressionUtil
{
    // ─── Images ─────────────────────────────────────────────────────────────────

    /**
     * Compress an uploaded image and store it.
     *
     * @param  string  $format  'original' | 'webp' | 'jpeg' | 'png'
     * @return array{path: string, original_size: int, compressed_size: int, saved_bytes: int, saved_percent: float}
     */
    public function compressImage(
        UploadedFile $file,
        string $disk = 'public',
        string $directory = 'images',
        int $quality = 75,
        ?int $maxWidth = 1920,
        string $format = 'original'
    ): array {
        $this->assertDiskAllowed($disk);
        $this->assertMimeType($file, ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
        $this->assertMaxSize($file);

        $directory = $this->sanitizeDirectory($directory);
        $originalSize = $file->getSize();
        $image = Image::make($file);

        if ($maxWidth && $image->width() > $maxWidth) {
            $image->resize($maxWidth, null, fn($c) => $c->aspectRatio()->upsize());
        }

        $extension = match ($format) {
            'webp' => 'webp',
            'jpeg' => 'jpg',
            'png' => 'png',
            default => $file->extension(),
        };

        $filename = $directory . '/' . pathinfo($file->hashName(), PATHINFO_FILENAME) . '.' . $extension;
        $encoded = $image->encode($extension, $quality);

        Storage::disk($disk)->put($filename, (string) $encoded);

        return $this->buildStats($filename, $originalSize, Storage::disk($disk)->size($filename));
    }

    /**
     * Compress multiple uploaded images.
     *
     * @param  UploadedFile[]  $files
     */
    public function compressImages(
        array $files,
        string $disk = 'public',
        string $directory = 'images',
        int $quality = 75,
        ?int $maxWidth = 1920,
        string $format = 'original'
    ): array {
        return array_map(
            fn($file) => $this->compressImage($file, $disk, $directory, $quality, $maxWidth, $format),
            $files
        );
    }

    /**
     * Re-compress an already-stored image in place.
     *
     * @param  string  $storagePath  e.g. 'images/avatar.jpg'
     */
    public function recompressStoredImage(
        string $storagePath,
        string $disk = 'public',
        int $quality = 75
    ): array {
        $this->assertDiskAllowed($disk);

        $originalSize = Storage::disk($disk)->size($storagePath);
        $content = Storage::disk($disk)->get($storagePath);
        $extension = pathinfo($storagePath, PATHINFO_EXTENSION);

        $image = Image::make($content)->encode($extension, $quality);
        Storage::disk($disk)->put($storagePath, (string) $image);

        return $this->buildStats($storagePath, $originalSize, Storage::disk($disk)->size($storagePath));
    }

    // ─── PDF ────────────────────────────────────────────────────────────────────

    /**
     * Compress an uploaded PDF using Ghostscript and store it.
     *
     * Presets:
     *   'screen'   — 72 dpi,  smallest file, screen viewing only
     *   'ebook'    — 150 dpi, good balance for most use cases (default)
     *   'printer'  — 300 dpi, high quality, moderate compression
     *   'prepress' — 300 dpi + colour preservation, near-lossless
     */
    public function compressPdf(
        UploadedFile $file,
        string $disk = 'public',
        string $directory = 'pdfs',
        string $preset = 'ebook'
    ): array {
        $this->assertDiskAllowed($disk);
        $this->assertMimeType($file, ['application/pdf']);
        $this->assertMaxSize($file);

        $directory = $this->sanitizeDirectory($directory);
        $originalSize = $file->getSize();
        $outputPath = $this->tempPath('pdf');

        try {
            $this->runGhostscript($file->getRealPath(), $outputPath, $preset);

            $storagePath = $directory . '/' . pathinfo($file->hashName(), PATHINFO_FILENAME) . '.pdf';
            Storage::disk($disk)->put($storagePath, file_get_contents($outputPath));
        } finally {
            $this->cleanTemp($outputPath);
        }

        return $this->buildStats($storagePath, $originalSize, Storage::disk($disk)->size($storagePath));
    }

    /**
     * Re-compress an already-stored PDF in place.
     *
     * @param  string  $storagePath  e.g. 'pdfs/report.pdf'
     */
    public function recompressStoredPdf(
        string $storagePath,
        string $disk = 'public',
        string $preset = 'ebook'
    ): array {
        $this->assertDiskAllowed($disk);

        $originalSize = Storage::disk($disk)->size($storagePath);
        $absolutePath = Storage::disk($disk)->path($storagePath);
        $outputPath = $this->tempPath('pdf');

        try {
            $this->runGhostscript($absolutePath, $outputPath, $preset);
            Storage::disk($disk)->put($storagePath, file_get_contents($outputPath));
        } finally {
            $this->cleanTemp($outputPath);
        }

        return $this->buildStats($storagePath, $originalSize, Storage::disk($disk)->size($storagePath));
    }

    /**
     * Bulk re-compress multiple stored PDFs.
     *
     * @param  string[]  $storagePaths
     */
    public function recompressStoredPdfs(
        array $storagePaths,
        string $disk = 'public',
        string $preset = 'ebook'
    ): array {
        return array_map(
            fn($path) => $this->recompressStoredPdf($path, $disk, $preset),
            $storagePaths
        );
    }

    // ─── ZIP ────────────────────────────────────────────────────────────────────

    /**
     * Zip a set of already-stored files into a single archive.
     *
     * @param  string[]  $storagePaths  Paths relative to $sourceDisk
     */
    public function zipFiles(
        array $storagePaths,
        string $archiveName = 'archive',
        string $sourceDisk = 'public',
        string $archiveDisk = 'public',
        string $archiveDir = 'archives'
    ): array {
        $this->assertDiskAllowed($sourceDisk);
        $this->assertDiskAllowed($archiveDisk);

        $archiveDir = $this->sanitizeDirectory($archiveDir);
        $archiveName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $archiveName);
        $tmpPath = $this->tempPath('zip');
        $zip = new ZipArchive();

        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip archive.");
        }

        $originalSize = 0;

        foreach ($storagePaths as $storagePath) {
            $absolutePath = Storage::disk($sourceDisk)->path($storagePath);
            $originalSize += Storage::disk($sourceDisk)->size($storagePath);
            $zip->addFile($absolutePath, basename($storagePath));
        }

        $zip->close();

        $archivePath = $archiveDir . '/' . $archiveName . '.zip';
        Storage::disk($archiveDisk)->put($archivePath, file_get_contents($tmpPath));
        $this->cleanTemp($tmpPath);

        return $this->buildStats($archivePath, $originalSize, Storage::disk($archiveDisk)->size($archivePath));
    }

    /**
     * Zip a single uploaded file.
     */
    public function zipUploadedFile(
        UploadedFile $file,
        string $disk = 'public',
        string $directory = 'archives'
    ): array {
        $this->assertDiskAllowed($disk);
        $this->assertMaxSize($file);

        $directory = $this->sanitizeDirectory($directory);
        $originalSize = $file->getSize();
        $tmpPath = $this->tempPath('zip');
        $zip = new ZipArchive();

        if ($zip->open($tmpPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Cannot create zip file.");
        }

        $zip->addFile($file->getRealPath(), basename($file->hashName()) . '.' . $file->extension());
        $zip->close();

        $archivePath = $directory . '/' . pathinfo($file->hashName(), PATHINFO_FILENAME) . '.zip';
        Storage::disk($disk)->put($archivePath, file_get_contents($tmpPath));
        $this->cleanTemp($tmpPath);

        return $this->buildStats($archivePath, $originalSize, Storage::disk($disk)->size($archivePath));
    }

    // ─── Security guards ────────────────────────────────────────────────────────

    private function assertDiskAllowed(string $disk): void
    {
        $allowed = config('compression.allowed_disks', ['public', 'local', 's3']);

        if (!in_array($disk, $allowed, true)) {
            throw new \InvalidArgumentException("Storage disk '{$disk}' is not permitted.");
        }
    }

    private function assertMimeType(UploadedFile $file, array $allowed): void
    {
        $mime = $file->getMimeType();

        if (!in_array($mime, $allowed, true)) {
            throw new \InvalidArgumentException(
                "File type '{$mime}' is not allowed. Allowed: " . implode(', ', $allowed)
            );
        }
    }

    private function assertMaxSize(UploadedFile $file): void
    {
        $max = config('compression.max_file_size', 20 * 1024 * 1024);

        if ($file->getSize() > $max) {
            throw new \InvalidArgumentException(
                "File exceeds the maximum allowed size of " . ($max / 1048576) . " MB."
            );
        }
    }

    private function sanitizeDirectory(string $directory): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $directory);

        if (empty($clean)) {
            throw new \InvalidArgumentException("Invalid directory path.");
        }

        return $clean;
    }

    // ─── Ghostscript ────────────────────────────────────────────────────────────

    private function ghostscriptBinary(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['gswin64c', 'gswin32c'] as $bin) {
                exec("where {$bin} 2>NUL", $out, $code);
                if ($code === 0 && !empty($out)) {
                    return $bin;
                }
            }

            throw new \RuntimeException(
                'Ghostscript not found. Download it from https://www.ghostscript.com/releases/ ' .
                'and ensure gswin64c.exe is in your PATH.'
            );
        }

        exec('which gs 2>/dev/null', $out, $code);

        if ($code !== 0) {
            throw new \RuntimeException(
                'Ghostscript (gs) not found. Install with: sudo apt-get install ghostscript'
            );
        }

        return 'gs';
    }

    private function runGhostscript(string $input, string $output, string $preset): void
    {
        $allowed = ['screen', 'ebook', 'printer', 'prepress'];

        if (!in_array($preset, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid preset '{$preset}'. Allowed: " . implode(', ', $allowed)
            );
        }

        $bin = $this->ghostscriptBinary();
        $command = sprintf(
            '%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/%s ' .
            '-dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s 2>&1',
            $bin,
            $preset,
            escapeshellarg($output),
            escapeshellarg($input)
        );

        exec($command, $cmdOutput, $exitCode);

        if ($exitCode !== 0 || !file_exists($output) || filesize($output) === 0) {
            throw new \RuntimeException(
                'Ghostscript compression failed: ' . implode("\n", $cmdOutput)
            );
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/' . uniqid('compression_') . '.' . $suffix;
    }

    private function cleanTemp(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function buildStats(string $path, int $originalSize, int $compressedSize): array
    {
        $savedBytes = max(0, $originalSize - $compressedSize);
        $savedPercent = $originalSize > 0
            ? round(($savedBytes / $originalSize) * 100, 2)
            : 0.0;

        return [
            'path' => $path,
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'saved_bytes' => $savedBytes,
            'saved_percent' => $savedPercent,
        ];
    }
}
