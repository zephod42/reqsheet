<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Timetable\BlankTimetableCsvExport;
use Reqsheet\Timetable\BlankTimetableCsvExporter;
use Reqsheet\Timetable\TimetableCsvExportException;

final class AdminTimetableCsvExport
{
    /** @param array<string, mixed> $user */
    public function __construct(private readonly BlankTimetableCsvExporter $exporter, private readonly int $organisationId, private readonly array $user) {}

    public function create(int $versionId): BlankTimetableCsvExport
    {
        if (!SessionAuth::isAdmin($this->user)) {
            throw new TimetableCsvExportException(TimetableCsvExportException::UNAUTHORISED, 'Administrator access is required.');
        }
        return $this->exporter->export($this->organisationId, $versionId);
    }
}
