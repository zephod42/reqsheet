<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableCsvImportService
{
    public function __construct(
        private readonly TimetableCsvImportStore $store,
        private readonly BlankTimetableCsvExporter $blankExporter,
        private readonly TimetableCsvParser $parser,
        private readonly TimetableCsvImportPreviewService $validator,
    ) {}

    /** @param array<string, mixed> $draft */
    public function import(int $organisationId, array $draft): TimetableCsvImportResult
    {
        $versionId = (int) ($draft['version_id'] ?? 0);
        $expectedIdentity = $draft['structure_identity'] ?? null;
        $draftAssignments = $draft['assignments'] ?? null;
        if ($versionId < 1 || !is_string($expectedIdentity) || $expectedIdentity === '' || !is_array($draftAssignments)) {
            throw new TimetableCsvImportException(['The import preview is invalid. Upload and validate the CSV again.']);
        }

        try {
            $allowConjoinedPeriods = $this->store->beginCsvImport($organisationId, $versionId);
            $blankRows = $this->parser->parse($this->blankExporter->export($organisationId, $versionId)->content);
            $currentBlank = $this->validator->preview($organisationId, $versionId, $blankRows, $allowConjoinedPeriods);
            if (!hash_equals($expectedIdentity, $currentBlank->structureIdentity)) {
                throw new TimetableCsvImportException(['The timetable structure or rooms changed after preview. Upload and validate the CSV again.']);
            }

            $rows = $this->applyDraftAssignments($blankRows, $draftAssignments);
            $current = $this->validator->preview($organisationId, $versionId, $rows, $allowConjoinedPeriods);
            if (!$this->sameProposal($draft, $current)) {
                throw new TimetableCsvImportException(['A teacher, class, room, or timetable setting changed after preview. Upload and validate the CSV again.']);
            }

            foreach ($current->assignments as $assignment) {
                $this->store->insertCsvImportLesson(
                    $organisationId,
                    $versionId,
                    $assignment['teacher_id'],
                    $assignment['day_of_week'],
                    $assignment['start_slot_id'],
                    $assignment['duration'],
                    $assignment['class_id'],
                    $assignment['room_id'],
                );
            }
            $this->store->commitCsvImport();
            return new TimetableCsvImportResult($versionId, count($current->assignments), $current->occupiedPeriods, $current->freeSlots);
        } catch (TimetableCsvImportException $exception) {
            $this->store->rollbackCsvImport();
            throw $exception;
        } catch (TimetableCsvExportException $exception) {
            $this->store->rollbackCsvImport();
            throw new TimetableCsvImportException(['The timetable structure or rooms changed after preview. Upload and validate the CSV again.']);
        } catch (\Throwable $exception) {
            $this->store->rollbackCsvImport();
            throw new TimetableCsvImportTechnicalException('The timetable import transaction failed.', 0, $exception);
        }
    }

    /**
     * @param list<array{row:int,day:string,period:string,room:string,class:string,teacher:string}> $blankRows
     * @param array<mixed> $draftAssignments
     * @return list<array{row:int,day:string,period:string,room:string,class:string,teacher:string}>
     */
    private function applyDraftAssignments(array $blankRows, array $draftAssignments): array
    {
        $occupied = [];
        foreach ($draftAssignments as $assignment) {
            if (!is_array($assignment)
                || !is_string($assignment['day'] ?? null)
                || !is_string($assignment['room_code'] ?? null)
                || !is_string($assignment['class_code'] ?? null)
                || !is_string($assignment['teacher_code'] ?? null)
                || !is_array($assignment['periods'] ?? null)) {
                throw new TimetableCsvImportException(['The import preview is invalid. Upload and validate the CSV again.']);
            }
            foreach ($assignment['periods'] as $period) {
                if (!is_string($period)) throw new TimetableCsvImportException(['The import preview is invalid. Upload and validate the CSV again.']);
                $key = self::key($assignment['day'], $period, $assignment['room_code']);
                if (isset($occupied[$key])) throw new TimetableCsvImportException(['The import preview is invalid. Upload and validate the CSV again.']);
                $occupied[$key] = ['class' => $assignment['class_code'], 'teacher' => $assignment['teacher_code']];
            }
        }

        $matched = [];
        foreach ($blankRows as &$row) {
            $key = self::key($row['day'], $row['period'], $row['room']);
            if (!isset($occupied[$key])) continue;
            $row['class'] = $occupied[$key]['class'];
            $row['teacher'] = $occupied[$key]['teacher'];
            $matched[$key] = true;
        }
        unset($row);
        if (count($matched) !== count($occupied)) {
            throw new TimetableCsvImportException(['The timetable structure or rooms changed after preview. Upload and validate the CSV again.']);
        }
        return $blankRows;
    }

    /** @param array<string, mixed> $draft */
    private function sameProposal(array $draft, TimetableCsvImportPreview $current): bool
    {
        if ((int) ($draft['occupied_periods'] ?? -1) !== $current->occupiedPeriods
            || (int) ($draft['free_slots'] ?? -1) !== $current->freeSlots
            || !is_array($draft['assignments'] ?? null)) {
            return false;
        }
        return self::normaliseAssignments($draft['assignments']) === self::normaliseAssignments($current->assignments);
    }

    /** @param array<mixed> $assignments @return list<array<string, mixed>> */
    private static function normaliseAssignments(array $assignments): array
    {
        $normalised = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) return [];
            $normalised[] = [
                'teacher_id' => (int) ($assignment['teacher_id'] ?? 0),
                'teacher_code' => (string) ($assignment['teacher_code'] ?? ''),
                'teacher_name' => (string) ($assignment['teacher_name'] ?? ''),
                'class_id' => (int) ($assignment['class_id'] ?? 0),
                'class_code' => (string) ($assignment['class_code'] ?? ''),
                'room_id' => (int) ($assignment['room_id'] ?? 0),
                'room_code' => (string) ($assignment['room_code'] ?? ''),
                'day_of_week' => (int) ($assignment['day_of_week'] ?? 0),
                'day' => (string) ($assignment['day'] ?? ''),
                'start_slot_id' => (int) ($assignment['start_slot_id'] ?? 0),
                'periods' => array_values(array_map('strval', is_array($assignment['periods'] ?? null) ? $assignment['periods'] : [])),
                'duration' => (int) ($assignment['duration'] ?? 0),
            ];
        }
        usort($normalised, static fn (array $left, array $right): int => [$left['day_of_week'], $left['start_slot_id'], $left['room_id']] <=> [$right['day_of_week'], $right['start_slot_id'], $right['room_id']]);
        return $normalised;
    }

    private static function key(string $day, string $period, string $room): string
    {
        return (string) json_encode([$day, $period, $room], JSON_THROW_ON_ERROR);
    }
}
