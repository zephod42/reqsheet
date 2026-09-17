<?php

declare(strict_types=1);

namespace Reqsheet\Database;

use PDO;
use PDOException;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $connection,
        private readonly string $directory,
    ) {
    }

    public function run(): int
    {
        $migrations = MigrationFile::discover($this->directory);
        $applied = $this->appliedVersions();
        $knownVersions = array_fill_keys(array_map(
            static fn (MigrationFile $migration): string => $migration->version,
            $migrations,
        ), true);

        foreach ($applied as $version) {
            if (!isset($knownVersions[$version])) {
                throw new \RuntimeException('Database contains an unknown migration: ' . $version);
            }
        }

        $pending = self::pending($migrations, $applied);

        foreach ($pending as $migration) {
            // MySQL DDL implicitly commits, so the migration and its record are
            // deliberately not wrapped in a transaction that would imply atomicity.
            if ($this->connection->exec($migration->sql) === false) {
                throw new \RuntimeException('Migration SQL execution failed: ' . $migration->version);
            }

            $statement = $this->connection->prepare(
                'INSERT INTO schema_migrations (version) VALUES (:version)',
            );
            if ($statement === false || !$statement->execute(['version' => $migration->version])) {
                throw new \RuntimeException('Migration record could not be written: ' . $migration->version);
            }
        }

        return count($pending);
    }

    /** @param list<MigrationFile> $migrations @param list<string> $applied */
    public static function pending(array $migrations, array $applied): array
    {
        return array_values(array_filter(
            $migrations,
            static fn (MigrationFile $migration): bool => !in_array($migration->version, $applied, true),
        ));
    }

    /** @return list<string> */
    private function appliedVersions(): array
    {
        try {
            $statement = $this->connection->query(
                'SELECT version FROM schema_migrations ORDER BY version',
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '42S02') {
                throw $exception;
            }

            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['version'],
            $statement->fetchAll(),
        );
    }
}
