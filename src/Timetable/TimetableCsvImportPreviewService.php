<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableCsvImportPreviewService
{
    public function __construct(private readonly ResourceTimetableStore $store) {}

    /** @param list<array{row:int,day:string,period:string,room:string,class:string,teacher:string}> $rows */
    public function preview(int $organisationId, int $versionId, array $rows, bool $allowConjoinedPeriods): TimetableCsvImportPreview
    {
        $version = $this->store->findVersion($versionId);
        if ($version === null || $version->organisationId !== $organisationId) {
            throw new TimetableCsvImportException(['The selected timetable is not available for this organisation.']);
        }
        $rooms = $this->store->roomsForOrganisation($organisationId);
        if ($rooms === []) throw new TimetableCsvImportException(['Add at least one room before importing a timetable CSV.']);
        $slots = $this->store->slotsForVersion($versionId);
        $teaching = array_values(array_filter($slots, static fn (TimetableSlot $slot): bool => $slot->isTeaching()));
        if ($teaching === []) throw new TimetableCsvImportException(['The selected timetable has no teaching periods to import.']);

        $errors = [];
        $expected = [];
        $roomsByCode = [];
        foreach ($rooms as $room) {
            $roomsByCode[(string) $room['code']][] = $room;
        }
        foreach ($roomsByCode as $code => $matches) {
            if (count($matches) > 1) $errors[] = 'The configured room code "' . $code . '" is ambiguous.';
        }
        foreach ($teaching as $slot) {
            $day = self::dayName($slot->dayOfWeek);
            $period = self::periodLabel($slot);
            foreach ($rooms as $room) {
                $key = self::key($day, $period, (string) $room['code']);
                if (isset($expected[$key])) {
                    $errors[] = sprintf('The timetable structure contains the duplicate slot %s / %s / %s.', $day, $period, $room['code']);
                    continue;
                }
                $expected[$key] = ['slot' => $slot, 'room' => $room, 'day' => $day, 'period' => $period];
            }
        }
        if ($errors !== []) throw new TimetableCsvImportException(array_values(array_unique($errors)));

        $teachers = [];
        foreach ($this->store->usersForOrganisation($organisationId) as $teacher) {
            $code = (string) ($teacher['staff_identifier'] ?? '');
            if ($code !== '') $teachers[$code][] = $teacher;
        }
        $classes = [];
        foreach ($this->store->classesForOrganisation($organisationId) as $class) $classes[(string) $class['code']][] = $class;

        $seen = [];
        $occupied = [];
        $skippedRooms = [];
        $recognisedRows = 0;
        $newClassCodes = [];
        $missingTeachers = [];
        foreach ($rows as $row) {
            if (!isset($roomsByCode[$row['room']])) {
                $skippedRooms[$row['room']] = ($skippedRooms[$row['room']] ?? 0) + 1;
                continue;
            }
            $recognisedRows++;
            $key = self::key($row['day'], $row['period'], $row['room']);
            if (!isset($expected[$key])) {
                self::error($errors, sprintf('CSV row %d references an unknown or unexpected slot: %s / %s / %s.', $row['row'], $row['day'], $row['period'], $row['room']));
                continue;
            }
            if (isset($seen[$key])) {
                self::error($errors, sprintf('CSV row %d duplicates the slot first supplied on row %d: %s / %s / %s.', $row['row'], $seen[$key], $row['day'], $row['period'], $row['room']));
                continue;
            }
            $seen[$key] = $row['row'];
            if (($row['class'] === '') !== ($row['teacher'] === '')) {
                self::error($errors, sprintf('CSV row %d must provide both Class and Teacher, or leave both blank.', $row['row']));
                continue;
            }
            if ($row['class'] === '') continue;

            $teacherMatches = $teachers[$row['teacher']] ?? [];
            if ($teacherMatches === []) {
                $missingTeachers[$row['teacher']] = true;
                continue;
            }
            if (count($teacherMatches) !== 1 || empty($teacherMatches[0]['is_active'])) {
                self::error($errors, sprintf('CSV row %d references an unknown, inactive, or ambiguous eligible Teacher code "%s".', $row['row'], $row['teacher']));
                continue;
            }
            $classMatches = $classes[$row['class']] ?? [];
            if (count($classMatches) > 1) {
                self::error($errors, sprintf('CSV row %d references an ambiguous Class code "%s".', $row['row'], $row['class']));
                continue;
            }
            if ($classMatches === []) {
                $newClassCodes[$row['class']] = true;
                $classMatches = [['id' => 0, 'code' => $row['class']]];
            }
            $structure = $expected[$key];
            $occupied[] = [
                'row' => $row['row'], 'slot' => $structure['slot'], 'room' => $structure['room'],
                'teacher' => $teacherMatches[0], 'class' => $classMatches[0],
                'day' => $structure['day'], 'period' => $structure['period'],
            ];
        }
        foreach ($expected as $key => $slot) {
            if (!isset($seen[$key])) {
                self::error($errors, sprintf('Missing CSV slot: %s / %s / %s.', $slot['day'], $slot['period'], $slot['room']['code']));
            }
        }
        if ($recognisedRows !== count($expected)) {
            self::error($errors, sprintf('The CSV contains %d retained data rows; %d are required for this timetable structure.', $recognisedRows, count($expected)));
        }
        if ($missingTeachers !== []) {
            $codes = array_keys($missingTeachers);
            sort($codes);
            self::error($errors, 'Import failed. The following teachers do not exist or are not eligible teachers: ' . implode(', ', $codes) . '. Please add the teachers in People and run the import again.');
        }
        if ($recognisedRows === 0) $errors[] = 'The CSV contains no importable rows for rooms in the selected timetable template.';
        if ($errors !== []) throw new TimetableCsvImportException($errors);

        self::validateConflicts($occupied, $errors);
        $assignments = self::group($occupied, $slots, $allowConjoinedPeriods, $version->firstDayOfWeek, $errors);
        if ($errors !== []) throw new TimetableCsvImportException($errors);

        $identity = hash('sha256', (string) json_encode([
            'organisation_id' => $organisationId,
            'version_id' => $versionId,
            'label' => $version->label,
            'effective_from' => $version->effectiveFrom->format('Y-m-d'),
            'effective_to' => $version->effectiveTo?->format('Y-m-d'),
            'first_day' => $version->firstDayOfWeek,
            'slots' => array_map(static fn (TimetableSlot $slot): array => [$slot->id, $slot->dayOfWeek, $slot->sequenceNumber, $slot->kind, $slot->teachingPeriodNumber, $slot->label], $slots),
            'rooms' => array_map(static fn (array $room): array => [(int) $room['id'], (string) $room['code']], $rooms),
        ], JSON_THROW_ON_ERROR));

        $skipped = [];
        foreach ($skippedRooms as $code => $count) $skipped[] = ['code' => (string) $code, 'row_count' => (int) $count];
        usort($skipped, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);
        $newClassCodes = array_keys($newClassCodes);
        sort($newClassCodes);
        return new TimetableCsvImportPreview(
            $organisationId,
            $versionId,
            trim((string) $version->label) !== '' ? (string) $version->label : 'Timetable ' . $versionId,
            count($occupied),
            count($expected) - count($occupied),
            $assignments,
            $identity,
            TimetableCsvImportPreview::proposedName(trim((string) $version->label) !== '' ? (string) $version->label : 'Timetable ' . $versionId),
            $skipped,
            $newClassCodes,
        );
    }

    /** @param list<array<string,mixed>> $occupied @param list<string> $errors */
    private static function validateConflicts(array $occupied, array &$errors): void
    {
        $teachers = [];
        $classes = [];
        foreach ($occupied as $cell) {
            /** @var TimetableSlot $slot */
            $slot = $cell['slot'];
            $time = $slot->dayOfWeek . '/' . $slot->sequenceNumber;
            $teacherKey = (int) $cell['teacher']['id'] . '/' . $time;
            if (isset($teachers[$teacherKey])) {
                self::error($errors, sprintf('Teacher %s is assigned twice at %s %s (CSV rows %d and %d).', $cell['teacher']['staff_identifier'], $cell['day'], $cell['period'], $teachers[$teacherKey], $cell['row']));
            } else $teachers[$teacherKey] = $cell['row'];
            $classKey = self::classIdentity((int) $cell['class']['id'], (string) $cell['class']['code']) . '/' . $time;
            if (isset($classes[$classKey])) {
                self::error($errors, sprintf('Class %s is assigned twice at %s %s (CSV rows %d and %d).', $cell['class']['code'], $cell['day'], $cell['period'], $classes[$classKey], $cell['row']));
            } else $classes[$classKey] = $cell['row'];
        }
    }

    /** @param list<array<string,mixed>> $occupied @param list<TimetableSlot> $slots @param list<string> $errors @return list<array{teacher_id:int,teacher_code:string,teacher_name:string,class_id:int,class_code:string,room_id:int,room_code:string,day_of_week:int,day:string,start_slot_id:int,periods:list<string>,duration:int,row_numbers:list<int>}> */
    private static function group(array $occupied, array $slots, bool $allowConjoinedPeriods, int $firstDayOfWeek, array &$errors): array
    {
        usort($occupied, static function (array $left, array $right) use ($firstDayOfWeek): int {
            /** @var TimetableSlot $leftSlot */ $leftSlot = $left['slot'];
            /** @var TimetableSlot $rightSlot */ $rightSlot = $right['slot'];
            $leftDay = ($leftSlot->dayOfWeek - $firstDayOfWeek + 7) % 7;
            $rightDay = ($rightSlot->dayOfWeek - $firstDayOfWeek + 7) % 7;
            return [$leftDay, (int) $left['room']['id'], $leftSlot->sequenceNumber] <=> [$rightDay, (int) $right['room']['id'], $rightSlot->sequenceNumber];
        });
        [, $slotsByDay] = TimetableRules::indexSlots($slots);
        $groups = [];
        foreach ($occupied as $cell) {
            /** @var TimetableSlot $slot */ $slot = $cell['slot'];
            $lastIndex = array_key_last($groups);
            $last = $lastIndex === null ? null : $groups[$lastIndex];
            $continues = $last !== null
                && $last['day_of_week'] === $slot->dayOfWeek
                && $last['room_id'] === (int) $cell['room']['id']
                && $last['teacher_id'] === (int) $cell['teacher']['id']
                && self::classIdentity($last['class_id'], $last['class_code']) === self::classIdentity((int) $cell['class']['id'], (string) $cell['class']['code'])
                && self::adjacent($slotsByDay[$slot->dayOfWeek] ?? [], $last['last_slot'], $slot);
            if ($continues) {
                $groups[$lastIndex]['periods'][] = (string) $cell['period'];
                $groups[$lastIndex]['row_numbers'][] = (int) $cell['row'];
                $groups[$lastIndex]['duration']++;
                $groups[$lastIndex]['last_slot'] = $slot;
                continue;
            }
            $groups[] = [
                'teacher_id' => (int) $cell['teacher']['id'],
                'teacher_code' => (string) $cell['teacher']['staff_identifier'],
                'teacher_name' => (string) ($cell['teacher']['staff_identifier'] ?? ''),
                'class_id' => (int) $cell['class']['id'],
                'class_code' => (string) $cell['class']['code'],
                'room_id' => (int) $cell['room']['id'],
                'room_code' => (string) $cell['room']['code'],
                'day_of_week' => $slot->dayOfWeek,
                'day' => (string) $cell['day'],
                'start_slot_id' => $slot->id,
                'periods' => [(string) $cell['period']],
                'duration' => 1,
                'row_numbers' => [(int) $cell['row']],
                'last_slot' => $slot,
            ];
        }
        foreach ($groups as &$group) {
            if ($group['duration'] > 1 && !$allowConjoinedPeriods) {
                self::error($errors, sprintf('CSV rows %s form a %d-period lesson, but conjoined periods are disabled in Settings.', implode(', ', $group['row_numbers']), $group['duration']));
            }
            /** @var TimetableSlot $start */
            $start = null;
            foreach ($slotsByDay[$group['day_of_week']] ?? [] as $candidate) if ($candidate->id === $group['start_slot_id']) $start = $candidate;
            if (!$start instanceof TimetableSlot || count(TimetableRules::occupiedSequences($slotsByDay[$group['day_of_week']] ?? [], $start, $group['duration'])) !== $group['duration']) {
                self::error($errors, sprintf('CSV rows %s form a lesson that crosses a separator or invalid timetable slot.', implode(', ', $group['row_numbers'])));
            }
            unset($group['last_slot']);
        }
        unset($group);
        return $groups;
    }

    /** @param list<TimetableSlot> $daySlots */
    private static function adjacent(array $daySlots, TimetableSlot $left, TimetableSlot $right): bool
    {
        $sequences = TimetableRules::occupiedSequences($daySlots, $left, 2);
        return count($sequences) === 2 && $sequences[1] === $right->sequenceNumber;
    }

    private static function key(string $day, string $period, string $room): string
    {
        return (string) json_encode([$day, $period, $room], JSON_THROW_ON_ERROR);
    }

    private static function classIdentity(int $id, string $code): string
    {
        return $id > 0 ? 'id:' . $id : 'new:' . $code;
    }

    private static function dayName(int $day): string
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day - 1] ?? 'Day ' . $day;
    }

    private static function periodLabel(TimetableSlot $slot): string
    {
        return trim((string) $slot->label) !== '' ? (string) $slot->label : 'P' . $slot->teachingPeriodNumber;
    }

    /** @param list<string> $errors */
    private static function error(array &$errors, string $error): void
    {
        if (count($errors) < 100) $errors[] = $error;
    }
}
