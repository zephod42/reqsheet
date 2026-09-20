<?php

declare(strict_types=1);

namespace Reqsheet\Recovery;

use PDO;
use PDOStatement;

final class PdoRecoveryStore implements RecoveryStore
{
    private const MAX_FAILURES = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function metadata(int $organisationId): array
    {
        $statement = $this->prepare('SELECT recovery_key_digest, recovery_key_generation, recovery_key_acknowledged_at FROM organisations WHERE id = :id');
        $statement->execute(['id' => $organisationId]);
        $row = $statement->fetch();
        if ($row === false) throw new RecoveryException('Organisation is unavailable.');
        return [
            'digest' => $row['recovery_key_digest'] === null ? null : (string) $row['recovery_key_digest'],
            'generation' => (int) $row['recovery_key_generation'],
            'acknowledged' => $row['recovery_key_acknowledged_at'] !== null,
        ];
    }

    public function issueKey(int $organisationId, int $administratorId, string $digest, bool $onlyIfMissing): int
    {
        return $this->transaction(function () use ($organisationId, $administratorId, $digest, $onlyIfMissing): int {
            $organisation = $this->prepare('SELECT recovery_key_digest, recovery_key_generation FROM organisations WHERE id = :id FOR UPDATE');
            $organisation->execute(['id' => $organisationId]);
            $row = $organisation->fetch();
            if ($row === false || ($onlyIfMissing && $row['recovery_key_digest'] !== null)) throw new RecoveryException('A recovery key has already been issued.');
            $administrator = $this->prepare('SELECT is_admin, is_active FROM users WHERE id = :user_id AND organisation_id = :organisation_id FOR UPDATE');
            $administrator->execute(['user_id' => $administratorId, 'organisation_id' => $organisationId]);
            $user = $administrator->fetch();
            if ($user === false || !(bool) $user['is_admin'] || !(bool) $user['is_active']) throw new RecoveryException('You are not authorised to manage the organisation recovery key.');
            $generation = (int) $row['recovery_key_generation'] + 1;
            $updateOrganisation = $this->prepare('UPDATE organisations SET recovery_key_digest = :digest, recovery_key_generation = :generation, recovery_key_acknowledged_at = NULL WHERE id = :id');
            $updateOrganisation->bindValue(':digest', $digest, PDO::PARAM_LOB);
            $updateOrganisation->bindValue(':generation', $generation, PDO::PARAM_INT);
            $updateOrganisation->bindValue(':id', $organisationId, PDO::PARAM_INT);
            $updateOrganisation->execute();
            $updateUser = $this->prepare("UPDATE users SET account_state = 'recovery_pending', auth_version = auth_version + 1 WHERE id = :id AND organisation_id = :organisation_id");
            $updateUser->execute(['id' => $administratorId, 'organisation_id' => $organisationId]);
            $consume = $this->prepare('UPDATE account_recovery_flows SET consumed_at = UTC_TIMESTAMP(6) WHERE organisation_id = :organisation_id AND recovery_key_generation = :generation AND consumed_at IS NULL');
            $consume->execute(['organisation_id' => $organisationId, 'generation' => (int) $row['recovery_key_generation']]);
            return $generation;
        });
    }

    public function createFlow(int $organisationId, string $staffIdentifier, string $keyDigest, string $flowTokenHash, string $clientHash, \DateTimeImmutable $expiresAt): ?array
    {
        return $this->transaction(function () use ($organisationId, $staffIdentifier, $keyDigest, $flowTokenHash, $clientHash, $expiresAt): ?array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->pdo->exec("DELETE FROM account_recovery_flows WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY");
            $this->pdo->exec("DELETE FROM account_recovery_rate_limits WHERE updated_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY");
            $rate = $this->prepare('SELECT window_started_at, failure_count, blocked_until FROM account_recovery_rate_limits WHERE organisation_id = :organisation_id AND client_hash = :client_hash FOR UPDATE');
            $rate->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
            $rate->bindValue(':client_hash', $clientHash, PDO::PARAM_LOB);
            $rate->execute();
            $limit = $rate->fetch();
            if ($limit !== false && $limit['blocked_until'] !== null && new \DateTimeImmutable((string) $limit['blocked_until'], new \DateTimeZone('UTC')) > $now) return null;

            $organisation = $this->prepare('SELECT recovery_key_digest, recovery_key_generation, recovery_key_acknowledged_at FROM organisations WHERE id = :id FOR UPDATE');
            $organisation->execute(['id' => $organisationId]);
            $organisationRow = $organisation->fetch();
            $user = $this->prepare('SELECT id FROM users WHERE organisation_id = :organisation_id AND staff_identifier = :staff_identifier AND is_admin = TRUE AND is_active = TRUE LIMIT 1');
            $user->execute(['organisation_id' => $organisationId, 'staff_identifier' => $staffIdentifier]);
            $userId = $user->fetchColumn();
            $storedDigest = $organisationRow === false || $organisationRow['recovery_key_digest'] === null ? str_repeat("\0", 32) : (string) $organisationRow['recovery_key_digest'];
            $valid = $organisationRow !== false && $organisationRow['recovery_key_acknowledged_at'] !== null && $userId !== false && hash_equals($storedDigest, $keyDigest);
            if (!$valid) {
                $this->recordFailure($organisationId, $clientHash, $limit, $now);
                return null;
            }

            $clear = $this->prepare('DELETE FROM account_recovery_rate_limits WHERE organisation_id = :organisation_id AND client_hash = :client_hash');
            $clear->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
            $clear->bindValue(':client_hash', $clientHash, PDO::PARAM_LOB);
            $clear->execute();
            $insert = $this->prepare('INSERT INTO account_recovery_flows (token_hash, organisation_id, user_id, recovery_key_generation, expires_at) VALUES (:token_hash, :organisation_id, :user_id, :generation, :expires_at)');
            $insert->bindValue(':token_hash', $flowTokenHash, PDO::PARAM_LOB);
            $insert->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
            $insert->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
            $insert->bindValue(':generation', (int) $organisationRow['recovery_key_generation'], PDO::PARAM_INT);
            $insert->bindValue(':expires_at', $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
            $insert->execute();
            return ['user_id' => (int) $userId, 'generation' => (int) $organisationRow['recovery_key_generation']];
        });
    }

    public function completeFlow(int $organisationId, string $flowTokenHash, string $passwordHash, string $replacementDigest): ?array
    {
        return $this->transaction(function () use ($organisationId, $flowTokenHash, $passwordHash, $replacementDigest): ?array {
            // Lock the organisation before an individual flow. Authenticated
            // replacement and acknowledgement use the same lock order.
            $organisation = $this->prepare('SELECT recovery_key_generation FROM organisations WHERE id = :id FOR UPDATE');
            $organisation->execute(['id' => $organisationId]);
            $generation = $organisation->fetchColumn();
            if ($generation === false) return null;
            $flow = $this->prepare('SELECT user_id, recovery_key_generation, expires_at, consumed_at FROM account_recovery_flows WHERE token_hash = :token_hash AND organisation_id = :organisation_id FOR UPDATE');
            $flow->bindValue(':token_hash', $flowTokenHash, PDO::PARAM_LOB);
            $flow->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
            $flow->execute();
            $row = $flow->fetch();
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($row === false || $row['consumed_at'] !== null || new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')) <= $now) return null;

            if ((int) $generation !== (int) $row['recovery_key_generation']) return null;
            $user = $this->prepare('SELECT is_admin, is_active FROM users WHERE id = :id AND organisation_id = :organisation_id FOR UPDATE');
            $user->execute(['id' => (int) $row['user_id'], 'organisation_id' => $organisationId]);
            $account = $user->fetch();
            if ($account === false || !(bool) $account['is_admin'] || !(bool) $account['is_active']) return null;

            $newGeneration = (int) $generation + 1;
            $updateUser = $this->prepare("UPDATE users SET password_hash = :password_hash, account_state = 'recovery_pending', auth_version = auth_version + 1 WHERE id = :id AND organisation_id = :organisation_id AND is_admin = TRUE AND is_active = TRUE");
            $updateUser->execute(['password_hash' => $passwordHash, 'id' => (int) $row['user_id'], 'organisation_id' => $organisationId]);
            $updateOrganisation = $this->prepare('UPDATE organisations SET recovery_key_digest = :digest, recovery_key_generation = :generation, recovery_key_acknowledged_at = NULL WHERE id = :id AND recovery_key_generation = :old_generation');
            $updateOrganisation->bindValue(':digest', $replacementDigest, PDO::PARAM_LOB);
            $updateOrganisation->bindValue(':generation', $newGeneration, PDO::PARAM_INT);
            $updateOrganisation->bindValue(':id', $organisationId, PDO::PARAM_INT);
            $updateOrganisation->bindValue(':old_generation', (int) $generation, PDO::PARAM_INT);
            $updateOrganisation->execute();
            $consume = $this->prepare('UPDATE account_recovery_flows SET consumed_at = UTC_TIMESTAMP(6) WHERE organisation_id = :organisation_id AND recovery_key_generation = :generation AND consumed_at IS NULL');
            $consume->execute(['organisation_id' => $organisationId, 'generation' => (int) $generation]);
            return ['user_id' => (int) $row['user_id'], 'generation' => $newGeneration];
        });
    }

    public function acknowledge(int $organisationId, int $administratorId, int $generation): void
    {
        $this->transaction(function () use ($organisationId, $administratorId, $generation): void {
            $organisation = $this->prepare('SELECT recovery_key_generation, recovery_key_digest, recovery_key_acknowledged_at FROM organisations WHERE id = :id FOR UPDATE');
            $organisation->execute(['id' => $organisationId]);
            $row = $organisation->fetch();
            $user = $this->prepare('SELECT account_state, is_admin, is_active FROM users WHERE id = :id AND organisation_id = :organisation_id FOR UPDATE');
            $user->execute(['id' => $administratorId, 'organisation_id' => $organisationId]);
            $account = $user->fetch();
            if ($row === false || $account === false || (int) $row['recovery_key_generation'] !== $generation || $row['recovery_key_digest'] === null
                || !(bool) $account['is_admin'] || !(bool) $account['is_active'] || $account['account_state'] !== 'recovery_pending') {
                throw new RecoveryException('This recovery-key presentation is no longer current. Issue another replacement key.');
            }
            $ack = $this->prepare('UPDATE organisations SET recovery_key_acknowledged_at = UTC_TIMESTAMP(6) WHERE id = :id AND recovery_key_generation = :generation');
            $ack->execute(['id' => $organisationId, 'generation' => $generation]);
            $claim = $this->prepare("UPDATE users SET account_state = 'claimed', auth_version = auth_version + 1 WHERE id = :id AND organisation_id = :organisation_id");
            $claim->execute(['id' => $administratorId, 'organisation_id' => $organisationId]);
        });
    }

    public function cancelFlow(int $organisationId, string $flowTokenHash): void
    {
        $statement = $this->prepare('UPDATE account_recovery_flows SET consumed_at = UTC_TIMESTAMP(6) WHERE organisation_id = :organisation_id AND token_hash = :token_hash AND consumed_at IS NULL');
        $statement->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue(':token_hash', $flowTokenHash, PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @param array<string,mixed>|false $limit */
    private function recordFailure(int $organisationId, string $clientHash, array|false $limit, \DateTimeImmutable $now): void
    {
        $windowStart = $limit === false ? $now : new \DateTimeImmutable((string) $limit['window_started_at'], new \DateTimeZone('UTC'));
        $count = $limit === false || $windowStart <= $now->modify('-15 minutes') ? 1 : (int) $limit['failure_count'] + 1;
        if ($windowStart <= $now->modify('-15 minutes')) $windowStart = $now;
        $blockedUntil = $count >= self::MAX_FAILURES ? $now->modify('+15 minutes')->format('Y-m-d H:i:s.u') : null;
        $statement = $this->prepare('INSERT INTO account_recovery_rate_limits (organisation_id, client_hash, window_started_at, failure_count, blocked_until) VALUES (:organisation_id, :client_hash, :window_started_at, :failure_count, :blocked_until) ON DUPLICATE KEY UPDATE window_started_at = VALUES(window_started_at), failure_count = VALUES(failure_count), blocked_until = VALUES(blocked_until)');
        $statement->bindValue(':organisation_id', $organisationId, PDO::PARAM_INT);
        $statement->bindValue(':client_hash', $clientHash, PDO::PARAM_LOB);
        $statement->bindValue(':window_started_at', $windowStart->format('Y-m-d H:i:s.u'));
        $statement->bindValue(':failure_count', $count, PDO::PARAM_INT);
        $statement->bindValue(':blocked_until', $blockedUntil);
        $statement->execute();
    }

    private function transaction(callable $operation): mixed
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin recovery operation.');
        try {
            $result = $operation();
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete recovery operation.');
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare recovery query.');
        return $statement;
    }
}
