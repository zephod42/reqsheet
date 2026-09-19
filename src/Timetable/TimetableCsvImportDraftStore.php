<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use Reqsheet\Http\SessionAuth;

final class TimetableCsvImportDraftStore
{
    private const SESSION_KEY = 'timetable_csv_import_draft';
    public const LIFETIME_SECONDS = 900;

    /** @return array<string, mixed> */
    public function save(TimetableCsvImportPreview $preview, int $userId, ?int $now = null): array
    {
        SessionAuth::start();
        $now ??= time();
        $draft = [
            'id' => bin2hex(random_bytes(16)),
            'organisation_id' => $preview->organisationId,
            'user_id' => $userId,
            'version_id' => $preview->versionId,
            'version_name' => $preview->versionName,
            'assignments' => $preview->assignments,
            'occupied_periods' => $preview->occupiedPeriods,
            'free_slots' => $preview->freeSlots,
            'structure_identity' => $preview->structureIdentity,
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

    public function discardOwned(string $draftId, int $organisationId, int $userId, ?int $now = null): bool
    {
        $draft = $this->load($draftId, $organisationId, $userId, $now);
        if ($draft === null) return false;
        unset($_SESSION[self::SESSION_KEY]);
        return true;
    }
}
