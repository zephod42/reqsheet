<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use Reqsheet\Account\StaffIdentifier;

final class TimetableResourceService
{
    public function __construct(private readonly ResourceTimetableStore $store)
    {
    }

    public function createRoom(int $organisationId, string $code): int
    {
        return $this->create($organisationId, $code, $this->store->roomsForOrganisation($organisationId, true), 'room', $this->store->createRoom(...));
    }

    public function createClass(int $organisationId, string $code): int
    {
        return $this->create($organisationId, $code, $this->store->classesForOrganisation($organisationId), 'class', $this->store->createClass(...));
    }

    public function createTeacher(int $organisationId, string $code): int
    {
        try { $code = StaffIdentifier::normalise($code); } catch (\Reqsheet\Account\AccountValidationException $exception) { throw new TimetableValidationException($exception->errors()); }
        foreach ($this->store->usersForOrganisation($organisationId) as $user) if (strcasecmp((string) ($user['staff_identifier'] ?? ''), $code) === 0) throw new TimetableValidationException(['Teacher initials/code already exists.']);
        return $this->store->createTeacher($organisationId, $code);
    }

    private function create(int $organisationId, string $code, array $existing, string $label, callable $insert): int
    {
        $code = trim($code);
        if ($code === '') throw new TimetableValidationException([ucfirst($label) . ' code must not be blank.']);
        foreach ($existing as $row) if (strcasecmp($row['code'], $code) === 0) throw new TimetableValidationException([ucfirst($label) . ' code already exists.']);
        return $insert($organisationId, $code);
    }
}
