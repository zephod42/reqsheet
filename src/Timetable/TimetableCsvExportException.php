<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableCsvExportException extends \RuntimeException
{
    public const UNAUTHORISED = 'unauthorised';
    public const VERSION_UNAVAILABLE = 'version_unavailable';
    public const NO_ROOMS = 'no_rooms';
    public const NO_TEACHING_PERIODS = 'no_teaching_periods';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
