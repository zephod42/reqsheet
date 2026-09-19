<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Timetable\BlankTimetableCsvExport;
use Reqsheet\Timetable\TimetableCsvExportException;
use Reqsheet\Timetable\TimetableResourceCsvExporter;

final class AdminTimetableResourceCsvExport
{
    /** @param array<string, mixed> $user */
    public function __construct(private readonly TimetableResourceCsvExporter $exporter, private readonly int $organisationId, private readonly array $user) {}

    public function create(): BlankTimetableCsvExport
    {
        if (!SessionAuth::isAdmin($this->user)) {
            throw new TimetableCsvExportException(TimetableCsvExportException::UNAUTHORISED, 'Administrator access is required.');
        }
        return $this->exporter->export($this->organisationId);
    }
}
