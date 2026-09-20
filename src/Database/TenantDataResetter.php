<?php

declare(strict_types=1);

namespace Reqsheet\Database;

use PDO;
use RuntimeException;

final class TenantDataResetter
{
    public const CONFIRMATION = 'DELETE-ALL-REQSHEET-TEST-DATA';

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['reqsheet_dev', 'reqsheet_test'];

    public function __construct(private readonly PDO $pdo, private readonly string $databaseName)
    {
        if (!in_array($databaseName, self::ALLOWED_DATABASES, true)) {
            throw new RuntimeException('Refusing reset: database is not an explicitly authorised Reqsheet test database.');
        }
    }

    /** @return array{database:string,tables:list<string>,counts:array<string,int>} */
    public function plan(): array
    {
        $tables = $this->tenantTablesInDeletionOrder();
        $counts = [];
        foreach ($tables as $table) {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table));
            $counts[$table] = (int) $statement->fetchColumn();
        }
        return ['database' => $this->databaseName, 'tables' => $tables, 'counts' => $counts];
    }

    /** @return array{database:string,tables:list<string>,counts:array<string,int>} */
    public function reset(): array
    {
        $plan = $this->plan();
        $this->pdo->beginTransaction();
        try {
            foreach ($plan['tables'] as $table) {
                $this->pdo->exec('DELETE FROM ' . $this->quoteIdentifier($table));
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        return $plan;
    }

    /** @return list<string> */
    private function tenantTablesInDeletionOrder(): array
    {
        $tables = $this->tableNames();
        if (!in_array('organisations', $tables, true) || !in_array('schema_migrations', $tables, true)) {
            throw new RuntimeException('Refusing reset: required Reqsheet schema tables are missing.');
        }
        $migration = $this->pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = :version');
        $migration->execute(['version' => '0013']);
        if ((int) $migration->fetchColumn() !== 1) {
            throw new RuntimeException('Refusing reset: migration 0013 is not recorded as applied.');
        }

        $parents = [];
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME AS table_name, REFERENCED_TABLE_NAME AS referenced_table_name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = :table_schema
               AND referenced_table_schema = :referenced_schema
               AND REFERENCED_TABLE_NAME IS NOT NULL',
        );
        $statement->execute(['table_schema' => $this->databaseName, 'referenced_schema' => $this->databaseName]);
        foreach ($statement->fetchAll() as $row) {
            $child = (string) $row['table_name'];
            $parent = (string) $row['referenced_table_name'];
            if (!isset($parents[$parent])) $parents[$parent] = [];
            $parents[$parent][] = $child;
        }

        $reachable = [];
        $visit = function (string $table) use (&$visit, &$reachable, $parents): void {
            if (isset($reachable[$table])) return;
            $reachable[$table] = true;
            foreach (array_unique($parents[$table] ?? []) as $child) $visit($child);
        };
        $visit('organisations');
        unset($reachable['schema_migrations']);

        $children = [];
        foreach ($parents as $parent => $descendants) {
            if (!isset($reachable[$parent])) continue;
            foreach (array_unique($descendants) as $child) {
                if (isset($reachable[$child])) $children[$parent][] = $child;
            }
        }

        $ordered = [];
        $visiting = [];
        $visited = [];
        $visitOrder = function (string $table) use (&$visitOrder, &$ordered, &$visiting, &$visited, $children): void {
            if (isset($visited[$table])) return;
            if (isset($visiting[$table])) throw new RuntimeException('Refusing reset: tenant table foreign-key cycle detected.');
            $visiting[$table] = true;
            foreach (array_unique($children[$table] ?? []) as $child) $visitOrder($child);
            unset($visiting[$table]);
            $visited[$table] = true;
            $ordered[] = $table;
        };
        $visitOrder('organisations');

        foreach ($ordered as $table) {
            if (!in_array($table, $tables, true)) throw new RuntimeException('Refusing reset: foreign-key metadata names a missing table.');
        }
        return $ordered;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES
             WHERE table_schema = :database_name AND table_type = \'BASE TABLE\'
             ORDER BY TABLE_NAME',
        );
        $statement->execute(['database_name' => $this->databaseName]);
        return array_map(static fn (array $row): string => (string) $row['table_name'], $statement->fetchAll());
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) throw new RuntimeException('Invalid database identifier.');
        return '`' . $identifier . '`';
    }
}
