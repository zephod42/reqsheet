<?php

declare(strict_types=1);

namespace Reqsheet\Database;

final class ExternalEnvironment
{
    /**
     * Load one explicitly configured external environment file.
     *
     * Values are literal strings. Shell syntax, interpolation, and exports are
     * intentionally not supported. Existing process values always win.
     *
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public static function load(array $environment): array
    {
        $configuredPath = $environment['REQSHEET_ENV_FILE'] ?? null;
        if ($configuredPath === null) {
            return $environment;
        }
        if (!is_string($configuredPath) || trim($configuredPath) === '' || $configuredPath[0] !== '/') {
            throw new ConfigurationException('REQSHEET_ENV_FILE must be an absolute path.');
        }

        $path = realpath($configuredPath);
        $repository = realpath(dirname(__DIR__, 2));
        if ($path === false || $repository === false || self::isWithin($path, $repository)) {
            throw new ConfigurationException('Configured environment file is unavailable.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new ConfigurationException('Configured environment file is unavailable.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/D', $line, $matches) !== 1) {
                throw new ConfigurationException('Configured environment file contains an invalid entry.');
            }

            $key = $matches[1];
            if (!array_key_exists($key, $environment)) {
                $environment[$key] = $matches[2];
            }
        }

        return $environment;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
