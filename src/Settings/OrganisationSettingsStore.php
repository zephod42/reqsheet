<?php

declare(strict_types=1);

namespace Reqsheet\Settings;

interface OrganisationSettingsStore
{
    /** @return array<string, mixed> */
    public function find(int $organisationId): array;

    /** @param array<string, mixed> $settings @param list<string>|null $rooms Null preserves rooms managed by the timetable builder. */
    public function save(int $organisationId, array $settings, ?array $rooms = null): void;
    public function contactEmail(int $organisationId): ?string;
    public function saveContactEmail(int $organisationId, ?string $email): void;
}
