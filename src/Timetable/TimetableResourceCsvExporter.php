<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableResourceCsvExporter
{
    public function __construct(private readonly ResourceTimetableStore $store) {}

    public function export(int $organisationId): BlankTimetableCsvExport
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new \RuntimeException('Unable to prepare resource CSV export.');
        $count = 0;
        try {
            self::write($stream, ['Resource Type', 'Code', 'Name']);
            foreach ($this->store->usersForOrganisation($organisationId) as $teacher) {
                if (empty($teacher['is_active']) || trim((string) ($teacher['staff_identifier'] ?? '')) === '') continue;
                self::write($stream, ['Teacher', (string) $teacher['staff_identifier'], (string) $teacher['display_name']]);
                $count++;
            }
            foreach ($this->store->classesForOrganisation($organisationId) as $class) {
                self::write($stream, ['Class', (string) $class['code'], (string) $class['code']]);
                $count++;
            }
            foreach ($this->store->roomsForOrganisation($organisationId) as $room) {
                self::write($stream, ['Room', (string) $room['code'], (string) $room['code']]);
                $count++;
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) throw new \RuntimeException('Unable to read resource CSV export.');
        } finally {
            fclose($stream);
        }
        return new BlankTimetableCsvExport('reqsheet-timetable-resources.csv', $content, $count);
    }

    /** @param resource $stream @param list<string> $row */
    private static function write($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) throw new \RuntimeException('Unable to write resource CSV export.');
    }
}
