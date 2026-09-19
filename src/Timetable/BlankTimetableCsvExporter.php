<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class BlankTimetableCsvExporter
{
    public function __construct(private readonly ResourceTimetableStore $store) {}

    public function export(int $organisationId, int $versionId): BlankTimetableCsvExport
    {
        $version = $this->store->findVersion($versionId);
        if ($version === null || $version->organisationId !== $organisationId) {
            throw new TimetableCsvExportException(TimetableCsvExportException::VERSION_UNAVAILABLE, 'The timetable template is not available for this organisation.');
        }

        $rooms = $this->store->roomsForOrganisation($organisationId);
        if ($rooms === []) {
            throw new TimetableCsvExportException(TimetableCsvExportException::NO_ROOMS, 'Add at least one room before exporting a blank timetable CSV.');
        }

        $slots = array_values(array_filter(
            $this->store->slotsForVersion($versionId),
            static fn (TimetableSlot $slot): bool => $slot->isTeaching(),
        ));
        if ($slots === []) {
            throw new TimetableCsvExportException(TimetableCsvExportException::NO_TEACHING_PERIODS, 'The selected timetable has no teaching periods to export.');
        }

        $firstDay = $version->firstDayOfWeek;
        usort($slots, static function (TimetableSlot $a, TimetableSlot $b) use ($firstDay): int {
            $dayOrder = (($a->dayOfWeek - $firstDay + 7) % 7) <=> (($b->dayOfWeek - $firstDay + 7) % 7);
            return $dayOrder !== 0 ? $dayOrder : ($a->sequenceNumber <=> $b->sequenceNumber);
        });

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new \RuntimeException('Unable to prepare timetable CSV export.');
        try {
            self::writeRow($stream, ['Day', 'Period', 'Room', 'Class', 'Teacher']);
            $rowCount = 0;
            foreach ($slots as $slot) {
                foreach ($rooms as $room) {
                    self::writeRow($stream, [
                        self::dayName($slot->dayOfWeek),
                        trim((string) $slot->label) !== '' ? (string) $slot->label : 'P' . $slot->teachingPeriodNumber,
                        (string) $room['code'],
                        '',
                        '',
                    ]);
                    $rowCount++;
                }
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) throw new \RuntimeException('Unable to read timetable CSV export.');
        } finally {
            fclose($stream);
        }

        return new BlankTimetableCsvExport(self::filename($version), $content, $rowCount);
    }

    /** @param resource $stream @param list<string> $row */
    private static function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) throw new \RuntimeException('Unable to write timetable CSV export.');
    }

    private static function filename(TimetableVersion $version): string
    {
        $label = strtolower(trim((string) ($version->label ?? '')));
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', $label), '-');
        if ($label === '') $label = 'timetable-' . $version->id;
        return 'reqsheet-' . substr($label, 0, 60) . '-blank.csv';
    }

    private static function dayName(int $day): string
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day - 1] ?? 'Day ' . $day;
    }
}
