<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableCsvImportException extends \RuntimeException
{
    /** @param list<string> $errors @param list<string> $missingRooms @param list<string> $missingTeachers */
    public function __construct(private readonly array $errors, private readonly array $missingRooms = [], private readonly array $missingTeachers = [])
    {
        parent::__construct($errors[0] ?? 'The timetable CSV could not be validated.');
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function missingRooms(): array { return $this->missingRooms; }

    /** @return list<string> */
    public function missingTeachers(): array { return $this->missingTeachers; }
}
