<?php

declare(strict_types=1);

namespace Reqsheet\Account;

use PDO;
use PDOStatement;

final class PdoAccountStore implements AccountStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function organisationCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM organisations')->fetchColumn();
    }

    public function organisationExists(int $organisationId): bool
    {
        $statement = $this->prepare('SELECT 1 FROM organisations WHERE id = :id');
        $statement->execute(['id' => $organisationId]);
        return $statement->fetchColumn() !== false;
    }

    public function findLogin(string $login): ?array
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, display_name, operational_role, is_admin,
                    password_hash, account_state, is_active
             FROM users WHERE display_name = :login LIMIT 1',
        );
        $statement->execute(['login' => $login]);
        $row = $statement->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'organisation_id' => (int) $row['organisation_id'],
            'display_name' => (string) $row['display_name'],
            'operational_role' => (string) $row['operational_role'],
            'is_admin' => (bool) $row['is_admin'],
            'password_hash' => $row['password_hash'] === null ? null : (string) $row['password_hash'],
            'account_state' => (string) $row['account_state'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    public function createFirstOrganisation(string $organisationName, string $displayName, ?string $staffIdentifier, string $role, string $passwordHash): int
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin first-run setup.');
        try {
            if ($this->organisationCount() !== 0) throw new AccountValidationException(['First-run setup is no longer available.']);
            $organisation = $this->prepare('INSERT INTO organisations (name) VALUES (:name)');
            $organisation->execute(['name' => $organisationName]);
            $organisationId = (int) $this->pdo->lastInsertId();
            $user = $this->prepare(
                'INSERT INTO users
                    (organisation_id, display_name, staff_identifier, operational_role, is_admin, password_hash, account_state)
                 VALUES (:organisation_id, :display_name, :staff_identifier, :role, TRUE, :password_hash, \'claimed\')',
            );
            $user->execute([
                'organisation_id' => $organisationId,
                'display_name' => $displayName,
                'staff_identifier' => $staffIdentifier,
                'role' => $role,
                'password_hash' => $passwordHash,
            ]);
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete first-run setup.');
            return $organisationId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int
    {
        $statement = $this->prepare(
            'INSERT INTO users
                (organisation_id, display_name, staff_identifier, operational_role, is_admin, account_state)
             VALUES (:organisation_id, :display_name, :staff_identifier, :role, :is_admin, \'awaiting_first_login\')',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'display_name' => $displayName,
            'staff_identifier' => $staffIdentifier,
            'role' => $role,
            'is_admin' => $isAdmin ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function claimFirstLogin(int $userId, string $passwordHash): void
    {
        $statement = $this->prepare(
            "UPDATE users SET password_hash = :password_hash, account_state = 'claimed'
             WHERE id = :id AND is_active = TRUE
               AND account_state = 'awaiting_first_login' AND password_hash IS NULL",
        );
        $statement->execute(['id' => $userId, 'password_hash' => $passwordHash]);
        if ($statement->rowCount() !== 1) throw new AccountValidationException(['This account has already been claimed or is unavailable.']);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare account query.');
        return $statement;
    }
}
