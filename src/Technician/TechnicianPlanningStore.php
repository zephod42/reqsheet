<?php

declare(strict_types=1);

namespace Reqsheet\Technician;

use DateTimeImmutable;

interface TechnicianPlanningStore
{
    /** @return array{enabled:bool,colours:list<string>} */
    public function technicianHighlighting(int $organisationId): array;
    public function technicianBelongsToOrganisation(int $userId, int $organisationId): bool;
    /** @return list<array{id:int,code:string}> */
    public function roomsForOrganisation(int $organisationId): array;
    /** @return list<array{id:int,name:string}> */
    public function teachersForOrganisation(int $organisationId): array;
    /** @return list<int> */
    public function defaultRoomIds(int $organisationId, int $userId): array;
    /** @param list<int> $roomIds */
    public function saveDefaultRoomIds(int $organisationId, int $userId, array $roomIds): void;
    /** Mark one dated lesson occurrence as prepared (or not prepared). */
    public function setPrepared(int $organisationId, int $userId, int $occurrenceId, bool $prepared): bool;
    /** Set or clear one configured colour on one dated lesson occurrence. */
    public function setHighlighting(int $organisationId, int $userId, int $occurrenceId, ?int $colour): bool;
    /** @return array{version:?array,slots:list<array<string,mixed>>,occurrences:list<array<string,mixed>>} */
    public function daily(int $organisationId, DateTimeImmutable $date, array $roomIds): array;
    /** @return list<array<string,mixed>> */
    public function weekForTeacher(int $organisationId, int $teacherId, DateTimeImmutable $start): array;
    /** @return list<array<string,mixed>> */
    public function weekForRoom(int $organisationId, int $roomId, DateTimeImmutable $start): array;
}
