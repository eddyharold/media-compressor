<?php

namespace Harorudo\MediaCompressor\Console;

use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'compression:doctor';

    protected $description = 'Verify that compression prerequisites (Ghostscript, GD/Imagick) are available.';

    public function handle(): int
    {
        $checks = [
            $this->checkPhpExtension('zip'),
            $this->checkImageBackend(),
            $this->checkGhostscript(),
        ];

        if (!in_array(false, $checks, true)) {
            $this->info('All compression prerequisites look good.');
            return self::SUCCESS;
        }

        $this->error('One or more prerequisites are missing. See messages above.');
        return self::FAILURE;
    }

    private function checkPhpExtension(string $name): bool
    {
        if (extension_loaded($name)) {
            $this->line("  <fg=green>✓</> PHP extension: {$name}");
            return true;
        }

        $this->line("  <fg=red>✗</> PHP extension '{$name}' is not loaded.");
        return false;
    }

    private function checkImageBackend(): bool
    {
        if (extension_loaded('gd') || extension_loaded('imagick')) {
            $backend = extension_loaded('imagick') ? 'imagick' : 'gd';
            $this->line("  <fg=green>✓</> Image backend: {$backend}");
            return true;
        }

        $this->line('  <fg=red>✗</> Neither GD nor Imagick is installed. Intervention Image needs one of them.');
        return false;
    }

    private function checkGhostscript(): bool
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['gswin64c.exe', 'gswin32c.exe']
            : ['gs'];

        foreach ($candidates as $bin) {
            if ($this->findInPath($bin) !== null) {
                $this->line("  <fg=green>✓</> Ghostscript: {$bin}");
                return true;
            }
        }

        $this->line('  <fg=red>✗</> Ghostscript not found in PATH. PDF compression will fail until it is installed.');
        $this->line('     See: https://www.ghostscript.com/releases/');
        return false;
    }

    private function findInPath(string $binary): ?string
    {
        $path = getenv('PATH') ?: '';
        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';

        foreach (explode($separator, $path) as $dir) {
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
