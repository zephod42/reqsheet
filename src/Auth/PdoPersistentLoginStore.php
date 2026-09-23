<?php

declare(strict_types=1);

namespace Reqsheet\Auth;

use PDO;
use PDOStatement;

final class PdoPersistentLoginStore implements PersistentLoginStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $selector, int $organisationId): ?array
    {
        $statement = $this->prepare(
            'SELECT id, user_id, organisation_id, selector, verifier_hash, previous_verifier_hash,
                    previous_valid_until, auth_version, expires_at
             FROM persistent_login_tokens
             WHERE selector = :selector AND organisation_id = :organisation_id
             LIMIT 1',
        );
        $statement->execute(['selector' => $selector, 'organisation_id' => $organisationId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function insert(
        int $userId,
        int $organisationId,
        string $selector,
        string $verifierHash,
        int $authVersion,
        string $createdAt,
        string $expiresAt,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO persistent_login_tokens
                (user_id, organisation_id, selector, verifier_hash, auth_version, created_at, expires_at)
             VALUES (:user_id, :organisation_id, :selector, :verifier_hash, :auth_version, :created_at, :expires_at)',
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue(':selector', $selector);
        $statement->bindValue(':verifier_hash', $verifierHash, PDO::PARAM_LOB);
        $statement->bindValue(':auth_version', $authVersion, PDO::PARAM_INT);
        $statement->bindValue(':created_at', $createdAt);
        $statement->bindValue(':expires_at', $expiresAt);
        $statement->execute();
    }

    public function rotate(
        string $selector,
        string $oldVerifierHash,
        string $newVerifierHash,
        string $previousValidUntil,
        string $lastUsedAt,
    ): bool {
        $statement = $this->prepare(
            'UPDATE persistent_login_tokens
             SET previous_verifier_hash = verifier_hash,
                 previous_valid_until = :previous_valid_until,
                 verifier_hash = :new_verifier_hash,
                 last_used_at = :last_used_at
             WHERE selector = :selector AND verifier_hash = :old_verifier_hash AND expires_at > UTC_TIMESTAMP(6)',
        );
        $statement->bindValue(':previous_valid_until', $previousValidUntil);
        $statement->bindValue(':new_verifier_hash', $newVerifierHash, PDO::PARAM_LOB);
        $statement->bindValue(':last_used_at', $lastUsedAt);
        $statement->bindValue(':selector', $selector);
        $statement->bindValue(':old_verifier_hash', $oldVerifierHash, PDO::PARAM_LOB);
        $statement->execute();
        return $statement->rowCount() === 1;
    }

    public function revoke(string $selector, int $organisationId): void
    {
        $statement = $this->prepare('DELETE FROM persistent_login_tokens WHERE selector = :selector AND organisation_id = :organisation_id');
        $statement->execute(['selector' => $selector, 'organisation_id' => $organisationId]);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare persistent-login query.');
        return $statement;
    }
}
