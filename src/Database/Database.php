<?php

declare(strict_types=1);

namespace Reqsheet\Database;

use Closure;
use PDO;

final class Database
{
    private ?PDO $connection = null;

    /**
     * @param Closure(string, string, string, array<int, mixed>): PDO|null $connectionFactory
     */
    public function __construct(
        private readonly DatabaseConfig $config,
        ?Closure $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory ?? static function (
            string $dsn,
            string $user,
            string $password,
            array $options,
        ): PDO {
            return new PDO($dsn, $user, $password, $options);
        };
    }

    /** @var Closure(string, string, string, array<int, mixed>): PDO */
    private readonly Closure $connectionFactory;

    public function connection(): PDO
    {
        if ($this->connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->host,
                $this->config->port,
                $this->config->name,
            );

            $this->connection = ($this->connectionFactory)(
                $dsn,
                $this->config->user,
                $this->config->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        }

        return $this->connection;
    }

    public function ping(): bool
    {
        return $this->connection()->query('SELECT 1') !== false;
    }
}
