<?php

declare(strict_types=1);

namespace Reqsheet\Auth;

use PDO;
use PDOStatement;

final class PdoOnboardingHandoffStore implements OnboardingHandoffStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $tokenHash, int $userId, int $organisationId, string $expiresAt): void
    {
        $statement = $this->prepare(
            'INSERT INTO onboarding_handoffs (token_hash, user_id, organisation_id, expires_at)
             VALUES (:token_hash, :user_id, :organisation_id, :expires_at)',
        );
        $statement->execute([
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'organisation_id' => $organisationId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function consume(string $tokenHash, int $organisationId): ?array
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin onboarding handoff.');
        try {
            $statement = $this->prepare(
                'SELECT user_id, organisation_id FROM onboarding_handoffs
                 WHERE token_hash = :token_hash AND organisation_id = :organisation_id
                   AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)
                 FOR UPDATE',
            );
            $statement->execute(['token_hash' => $tokenHash, 'organisation_id' => $organisationId]);
            $handoff = $statement->fetch();
            if ($handoff === false) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->prepare('UPDATE onboarding_handoffs SET consumed_at = UTC_TIMESTAMP(6) WHERE token_hash = :token_hash');
            $update->execute(['token_hash' => $tokenHash]);
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete onboarding handoff.');
            return ['user_id' => (int) $handoff['user_id'], 'organisation_id' => (int) $handoff['organisation_id']];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare onboarding handoff query.');
        return $statement;
    }
}
