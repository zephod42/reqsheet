<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableCsvImportException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'The timetable CSV could not be validated.');
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
