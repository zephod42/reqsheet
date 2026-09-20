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

    public function organisationTenantSlugExists(string $tenantSlug): bool
    {
        $statement = $this->prepare('SELECT 1 FROM organisations WHERE tenant_slug = :tenant_slug LIMIT 1');
        $statement->execute(['tenant_slug' => $tenantSlug]);
        return $statement->fetchColumn() !== false;
    }

    public function findOrganisationTenantSlug(int $organisationId): ?string
    {
        $statement = $this->prepare('SELECT tenant_slug FROM organisations WHERE id = :id');
        $statement->execute(['id' => $organisationId]);
        $slug = $statement->fetchColumn();
        return $slug === false ? null : (string) $slug;
    }

    public function findOrganisationByTenantSlug(string $tenantSlug): ?array
    {
        $statement = $this->prepare('SELECT id, name, tenant_slug FROM organisations WHERE tenant_slug = :tenant_slug LIMIT 1');
        $statement->execute(['tenant_slug' => $tenantSlug]);
        $row = $statement->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'tenant_slug' => (string) $row['tenant_slug'],
        ];
    }

    public function findLogin(string $login, ?int $organisationId = null): ?array
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, display_name, staff_identifier, operational_role, is_admin,
                    is_teacher, is_technician, teacher_number, password_hash, account_state, auth_version, is_active
             FROM users WHERE staff_identifier = :login
               AND (:organisation_id_filter IS NULL OR organisation_id = :organisation_id_match)
             ORDER BY id LIMIT 1',
        );
        $statement->execute(['login' => $login, 'organisation_id_filter' => $organisationId, 'organisation_id_match' => $organisationId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->account($row);
    }

    public function findUserById(int $userId): ?array
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, display_name, staff_identifier, operational_role, is_admin,
                    is_teacher, is_technician, teacher_number, password_hash, account_state, auth_version, is_active
             FROM users WHERE id = :id LIMIT 1',
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->account($row);
    }

    public function findPeopleForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, display_name, staff_identifier, operational_role, is_admin,
                    is_teacher, is_technician, teacher_number, password_hash, account_state, auth_version, is_active
             FROM users WHERE organisation_id = :organisation_id ORDER BY display_name, id',
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(fn (array $row): array => $this->account($row), $statement->fetchAll());
    }

    public function activeAdministratorCount(int $organisationId): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM users WHERE organisation_id = :organisation_id AND is_active = TRUE AND is_admin = TRUE');
        $statement->execute(['organisation_id' => $organisationId]);
        return (int) $statement->fetchColumn();
    }

    public function createPerson(int $organisationId, string $displayName, string $staffIdentifier, array $roles): int
    {
        return $this->withOrganisationLock($organisationId, function () use ($organisationId, $displayName, $staffIdentifier, $roles): int {
            $teacherNumber = in_array('teacher', $roles, true) ? $this->nextTeacherNumber($organisationId) : null;
            $statement = $this->prepare(
                'INSERT INTO users
                    (organisation_id, display_name, staff_identifier, operational_role, is_admin, is_teacher, is_technician, teacher_number, account_state)
                 VALUES (:organisation_id, :display_name, :staff_identifier, :operational_role, :is_admin, :is_teacher, :is_technician, :teacher_number, \'awaiting_first_login\')',
            );
            $statement->execute($this->personParameters($organisationId, $displayName, $staffIdentifier, $roles, $teacherNumber));
            return (int) $this->pdo->lastInsertId();
        });
    }

    public function updatePerson(int $organisationId, int $userId, string $displayName, string $staffIdentifier, array $roles): void
    {
        $this->withOrganisationLock($organisationId, function () use ($organisationId, $userId, $displayName, $staffIdentifier, $roles): void {
            $current = $this->findUserById($userId);
            if ($current === null || (int) $current['organisation_id'] !== $organisationId) throw new AccountValidationException(['That person is not part of this organisation.']);
            $teacherNumber = (int) ($current['teacher_number'] ?? 0) ?: (in_array('teacher', $roles, true) ? $this->nextTeacherNumber($organisationId) : null);
            $statement = $this->prepare(
                'UPDATE users SET display_name = :display_name, staff_identifier = :staff_identifier,
                    operational_role = :operational_role, is_admin = :is_admin, is_teacher = :is_teacher,
                    is_technician = :is_technician, teacher_number = :teacher_number,
                    auth_version = auth_version + 1
                 WHERE id = :id AND organisation_id = :organisation_id',
            );
            $parameters = $this->personParameters($organisationId, $displayName, $staffIdentifier, $roles, $teacherNumber);
            $parameters['id'] = $userId;
            $statement->execute($parameters);
        });
    }

    public function createFirstOrganisation(string $organisationName, string $displayName, string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug = ''): int
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin first-run setup.');
        try {
            if ($this->organisationCount() !== 0) throw new AccountValidationException(['First-run setup is no longer available.']);
            $organisationId = $this->insertOrganisationAdmin($organisationName, $displayName, $staffIdentifier, $role, $passwordHash, $tenantSlug);
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete first-run setup.');
            return $organisationId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createOrganisationAdmin(string $organisationName, string $displayName, string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug = ''): int
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin organisation signup.');
        try {
            $organisationId = $this->insertOrganisationAdmin($organisationName, $displayName, $staffIdentifier, $role, $passwordHash, $tenantSlug);
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete organisation signup.');
            return $organisationId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int
    {
        $roles = [$role];
        if ($isAdmin) $roles[] = 'administrator';
        return $this->createPerson($organisationId, $displayName, $staffIdentifier, $roles);
    }

    public function claimFirstLogin(int $userId, string $passwordHash): void
    {
        $statement = $this->prepare(
            "UPDATE users SET password_hash = :password_hash, account_state = 'claimed', auth_version = auth_version + 1
             WHERE id = :id AND is_active = TRUE
               AND account_state = 'awaiting_first_login' AND password_hash IS NULL",
        );
        $statement->execute(['id' => $userId, 'password_hash' => $passwordHash]);
        if ($statement->rowCount() !== 1) throw new AccountValidationException(['This account has already been claimed or is unavailable.']);
    }

    public function updatePassword(int $userId, int $organisationId, string $passwordHash): void
    {
        $statement = $this->prepare(
            "UPDATE users SET password_hash = :password_hash, account_state = 'claimed', auth_version = auth_version + 1
             WHERE id = :id AND organisation_id = :organisation_id AND is_active = TRUE",
        );
        $statement->execute(['id' => $userId, 'organisation_id' => $organisationId, 'password_hash' => $passwordHash]);
        if ($statement->rowCount() !== 1) throw new AccountValidationException(['This account is unavailable.']);
    }

    public function resetPassword(int $userId, int $organisationId): void
    {
        $statement = $this->prepare("UPDATE users SET password_hash = NULL, account_state = 'awaiting_first_login', auth_version = auth_version + 1 WHERE id = :id AND organisation_id = :organisation_id AND is_active = TRUE AND is_admin = FALSE");
        $statement->execute(['id' => $userId, 'organisation_id' => $organisationId]);
        if ($statement->rowCount() !== 1) throw new AccountValidationException(['That person is not available for password reset.']);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare account query.');
        return $statement;
    }

    /** @return array<string, mixed> */
    private function account(array $row): array
    {
        $roles = [];
        if ((bool) ($row['is_teacher'] ?? false) || ($row['operational_role'] ?? null) === 'teacher') $roles[] = 'teacher';
        if ((bool) ($row['is_technician'] ?? false) || ($row['operational_role'] ?? null) === 'technician') $roles[] = 'technician';
        if ((bool) ($row['is_admin'] ?? false)) $roles[] = 'administrator';
        return [
            'id' => (int) $row['id'], 'organisation_id' => (int) $row['organisation_id'],
            'display_name' => (string) $row['display_name'],
            'staff_identifier' => $row['staff_identifier'] === null ? null : (string) $row['staff_identifier'],
            'operational_role' => $row['operational_role'] === null ? null : (string) $row['operational_role'],
            'roles' => $roles, 'is_admin' => in_array('administrator', $roles, true),
            'is_teacher' => in_array('teacher', $roles, true), 'is_technician' => in_array('technician', $roles, true),
            'teacher_number' => $row['teacher_number'] === null ? null : (int) $row['teacher_number'],
            'password_hash' => $row['password_hash'] === null ? null : (string) $row['password_hash'],
            'account_state' => (string) $row['account_state'], 'is_active' => (bool) $row['is_active'],
            'auth_version' => (int) ($row['auth_version'] ?? 1),
        ];
    }

    /** @param list<string> $roles @return array<string, mixed> */
    private function personParameters(int $organisationId, string $displayName, ?string $staffIdentifier, array $roles, ?int $teacherNumber): array
    {
        return [
            'organisation_id' => $organisationId, 'display_name' => $displayName, 'staff_identifier' => $staffIdentifier,
            'operational_role' => in_array('teacher', $roles, true) ? 'teacher' : (in_array('technician', $roles, true) ? 'technician' : null),
            'is_admin' => in_array('administrator', $roles, true) ? 1 : 0,
            'is_teacher' => in_array('teacher', $roles, true) ? 1 : 0,
            'is_technician' => in_array('technician', $roles, true) ? 1 : 0,
            'teacher_number' => $teacherNumber,
        ];
    }

    private function nextTeacherNumber(int $organisationId): int
    {
        $statement = $this->prepare('SELECT COALESCE(MAX(teacher_number), 0) + 1 FROM users WHERE organisation_id = :organisation_id');
        $statement->execute(['organisation_id' => $organisationId]);
        return (int) $statement->fetchColumn();
    }

    private function withOrganisationLock(int $organisationId, callable $operation): mixed
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin people change.');
        try {
            $lock = $this->prepare('SELECT id FROM organisations WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $organisationId]);
            if ($lock->fetchColumn() === false) throw new AccountValidationException(['Organisation is invalid.']);
            $result = $operation();
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete people change.');
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function insertOrganisationAdmin(string $organisationName, string $displayName, ?string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug): int
    {
        $organisation = $this->prepare('INSERT INTO organisations (name, tenant_slug) VALUES (:name, :tenant_slug)');
        $organisation->execute(['name' => $organisationName, 'tenant_slug' => $tenantSlug]);
        $organisationId = (int) $this->pdo->lastInsertId();
        $user = $this->prepare(
            'INSERT INTO users
                (organisation_id, display_name, staff_identifier, operational_role, is_admin, is_teacher, is_technician, teacher_number, password_hash, account_state)
             VALUES (:organisation_id, :display_name, :staff_identifier, :role, TRUE, :is_teacher, :is_technician, :teacher_number, :password_hash, \'claimed\')',
        );
        $teacherNumber = $role === 'teacher' ? 1 : null;
        $user->execute([
            'organisation_id' => $organisationId,
            'display_name' => $displayName,
            'staff_identifier' => $staffIdentifier,
            'role' => $role,
            'is_teacher' => $role === 'teacher' ? 1 : 0,
            'is_technician' => $role === 'technician' ? 1 : 0,
            'teacher_number' => $teacherNumber,
            'password_hash' => $passwordHash,
        ]);
        return $organisationId;
    }

    public function organisationAccountStatus(int $organisationId): array
    {
        $statement = $this->prepare('SELECT subscription_state, subscription_until FROM organisations WHERE id = :id');
        $statement->execute(['id' => $organisationId]);
        $row = $statement->fetch();
        if ($row === false) throw new AccountValidationException(['Organisation is invalid.']);
        return [
            'state' => $row['subscription_state'] === null ? null : (string) $row['subscription_state'],
            'until' => $row['subscription_until'] === null ? null : (string) $row['subscription_until'],
        ];
    }
}
