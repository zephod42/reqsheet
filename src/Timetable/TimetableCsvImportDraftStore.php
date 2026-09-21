<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use Reqsheet\Http\SessionAuth;

final class TimetableCsvImportDraftStore
{
    private const SESSION_KEY = 'timetable_csv_import_draft';
    private const SUCCESS_KEY = 'timetable_csv_import_success';
    public const LIFETIME_SECONDS = 900;

    /** @return array<string, mixed> */
    public function save(TimetableCsvImportPreview $preview, int $userId, string|int $csvContent = '', ?int $now = null): array
    {
        SessionAuth::start();
        if (is_int($csvContent)) {
            $now = $csvContent;
            $csvContent = '';
        }
        $now ??= time();
        $draft = [
            'id' => bin2hex(random_bytes(16)),
            'organisation_id' => $preview->organisationId,
            'user_id' => $userId,
            'version_id' => $preview->versionId,
            'version_name' => $preview->versionName,
            'proposed_version_name' => $preview->proposedVersionName,
            'assignments' => $preview->assignments,
            'occupied_periods' => $preview->occupiedPeriods,
            'free_slots' => $preview->freeSlots,
            'structure_identity' => $preview->structureIdentity,
            'skipped_rooms' => $preview->skippedRooms,
            'new_class_codes' => $preview->newClassCodes,
            'created_at' => $now,
            'expires_at' => $now + self::LIFETIME_SECONDS,
            'csv_content' => $csvContent,
        ];
        $_SESSION[self::SESSION_KEY] = $draft;
        return $draft;
    }

    /** @param list<string> $errors @param list<string> $missingRooms @param list<string> $missingTeachers @param list<string> $archivedRooms */
    public function saveValidationFailure(int $organisationId, int $versionId, int $userId, string $csvContent, array $errors, array $missingRooms = [], array $missingTeachers = [], array $archivedRooms = [], ?int $now = null): array
    {
        SessionAuth::start();
        $now ??= time();
        $draft = [
            'id' => bin2hex(random_bytes(16)),
            'organisation_id' => $organisationId,
            'user_id' => $userId,
            'version_id' => $versionId,
            'csv_content' => $csvContent,
            'errors' => array_values($errors),
            'missing_rooms' => array_values($missingRooms),
            'missing_teachers' => array_values($missingTeachers),
            'archived_rooms' => array_values($archivedRooms),
            'created_at' => $now,
            'expires_at' => $now + self::LIFETIME_SECONDS,
        ];
        $_SESSION[self::SESSION_KEY] = $draft;
        return $draft;
    }

    /** @return array<string, mixed>|null */
    public function load(string $draftId, int $organisationId, int $userId, ?int $now = null): ?array
    {
        SessionAuth::start();
        $draft = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($draft)) return null;
        $now ??= time();
        if ((int) ($draft['expires_at'] ?? 0) <= $now) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        if (!hash_equals((string) ($draft['id'] ?? ''), $draftId)
            || (int) ($draft['organisation_id'] ?? 0) !== $organisationId
            || (int) ($draft['user_id'] ?? 0) !== $userId) {
            return null;
        }
        return $draft;
    }

    public function discard(): void
    {
        SessionAuth::start();
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function saveSuccess(TimetableCsvImportResult $result, int $organisationId, int $userId): void
    {
        SessionAuth::start();
        $_SESSION[self::SUCCESS_KEY] = ['organisation_id' => $organisationId, 'user_id' => $userId, 'result' => $result];
    }

    /** @return TimetableCsvImportResult|null */
    public function takeSuccess(int $organisationId, int $userId): ?TimetableCsvImportResult
    {
        SessionAuth::start();
        $flash = $_SESSION[self::SUCCESS_KEY] ?? null;
        unset($_SESSION[self::SUCCESS_KEY]);
        if (!is_array($flash) || (int) ($flash['organisation_id'] ?? 0) !== $organisationId || (int) ($flash['user_id'] ?? 0) !== $userId || !(($flash['result'] ?? null) instanceof TimetableCsvImportResult)) return null;
        return $flash['result'];
    }

    public function discardOwned(string $draftId, int $organisationId, int $userId, ?int $now = null): bool
    {
        $draft = $this->load($draftId, $organisationId, $userId, $now);
        if ($draft === null) return false;
        unset($_SESSION[self::SESSION_KEY]);
        return true;
    }
}
