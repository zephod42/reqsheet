<?php

declare(strict_types=1);

namespace Reqsheet\Database;

final readonly class MigrationFile
{
    public function __construct(
        public string $version,
        public string $name,
        public string $path,
        public string $sql,
    ) {
    }

    public static function fromPath(string $path): self
    {
        $filename = basename($path);
        if (preg_match('/^(\d+)_([a-z0-9_]+)\.sql$/', $filename, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid migration filename: ' . $filename);
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException('Unable to read migration: ' . $filename);
        }

        return new self($matches[1], $matches[2], $path, trim($sql));
    }

    /** @return list<self> */
    public static function discover(string $directory): array
    {
        $paths = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
        if ($paths === false) {
            throw new \RuntimeException('Unable to read migration directory.');
        }

        $migrations = array_map(self::fromPath(...), $paths);

        return self::ordered($migrations);
    }

    /** @param list<self> $migrations */
    public static function ordered(array $migrations): array
    {
        usort($migrations, static function (self $left, self $right): int {
            $versionOrder = strnatcmp($left->version, $right->version);

            return $versionOrder !== 0 ? $versionOrder : strcmp($left->name, $right->name);
        });

        $versions = [];
        $names = [];
        foreach ($migrations as $migration) {
            if (isset($versions[$migration->version])) {
                throw new \RuntimeException('Duplicate migration version: ' . $migration->version);
            }
            if (isset($names[$migration->name])) {
                throw new \RuntimeException('Duplicate migration name: ' . $migration->name);
            }
            $versions[$migration->version] = true;
            $names[$migration->name] = true;
        }

        return $migrations;
    }
}
