<?php

declare(strict_types=1);

namespace Reqsheet\Monitor;

final class ReportFileWriter
{
    public function __construct(private readonly string $publicDirectory)
    {
    }

    public function write(string $outputPath, string $contents, bool $overwrite = false): void
    {
        if ($outputPath === '' || $outputPath[0] !== '/') {
            throw new \RuntimeException('The report output path must be absolute.');
        }
        $directory = realpath(dirname($outputPath));
        $public = realpath($this->publicDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new \RuntimeException('The report output directory does not exist.');
        }
        if ($public !== false && ($directory === $public || str_starts_with($directory . '/', rtrim($public, '/') . '/'))) {
            throw new \RuntimeException('Refusing to write a monitor report inside the public document root.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('The report output directory is not writable.');
        }
        if (is_link($outputPath)) {
            throw new \RuntimeException('Refusing to replace a symbolic-link output path.');
        }
        if (file_exists($outputPath) && !$overwrite) {
            throw new \RuntimeException('The report output already exists; pass --force to replace it.');
        }

        $temporary = $directory . '/.reqsheet-monitor-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) throw new \RuntimeException('Unable to create a temporary report file.');
        try {
            if (!@chmod($temporary, 0600)) throw new \RuntimeException('Unable to protect the temporary report file.');
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('The complete report could not be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) throw new \RuntimeException('The complete report could not be written.');
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($handle);
        }

        if (!@rename($temporary, $outputPath)) {
            @unlink($temporary);
            throw new \RuntimeException('The report could not be moved into its final location.');
        }
        @chmod($outputPath, 0600);
    }
}
