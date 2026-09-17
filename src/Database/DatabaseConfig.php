<?php

declare(strict_types=1);

namespace Reqsheet\Database;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
    ) {
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function fromEnvironment(array $environment, string $prefix = 'DB_'): self
    {
        $host = self::requiredString($environment, $prefix . 'HOST');
        $name = self::requiredString($environment, $prefix . 'NAME');
        $user = self::requiredString($environment, $prefix . 'USER');
        $password = self::requiredString($environment, $prefix . 'PASSWORD');
        $portValue = $environment[$prefix . 'PORT'] ?? '3306';

        if (!is_string($portValue) && !is_int($portValue)) {
            throw new ConfigurationException($prefix . 'PORT must be an integer.');
        }

        $port = filter_var($portValue, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new ConfigurationException($prefix . 'PORT must be between 1 and 65535.');
        }

        return new self($host, $port, $name, $user, $password);
    }

    private static function requiredString(array $environment, string $key): string
    {
        $value = $environment[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException($key . ' is required.');
        }

        return $value;
    }
}
