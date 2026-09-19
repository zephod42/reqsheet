<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableCsvParser
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_DATA_ROWS = 20000;
    public const HEADER = ['Day', 'Period', 'Room', 'Class', 'Teacher'];

    /** @param array<string, mixed> $upload @return list<array{row:int,day:string,period:string,room:string,class:string,teacher:string}> */
    public function parseUpload(array $upload): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The CSV file exceeds the 2 MiB upload limit.',
                UPLOAD_ERR_NO_FILE => 'Choose a CSV file to upload.',
                default => 'The CSV upload could not be read. Please try again.',
            };
            throw new TimetableCsvImportException([$message]);
        }
        $name = (string) ($upload['name'] ?? '');
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new TimetableCsvImportException(['Upload a file with the .csv extension.']);
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_BYTES) {
            throw new TimetableCsvImportException(['The CSV file exceeds the 2 MiB upload limit.']);
        }
        $path = $upload['tmp_name'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new TimetableCsvImportException(['The CSV upload could not be read. Please try again.']);
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new TimetableCsvImportException(['The CSV upload could not be read. Please try again.']);
        try {
            $content = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($content === false) throw new TimetableCsvImportException(['The CSV upload could not be read. Please try again.']);
        return $this->parse($content);
    }

    /** @return list<array{row:int,day:string,period:string,room:string,class:string,teacher:string}> */
    public function parse(string $content): array
    {
        if ($content === '') throw new TimetableCsvImportException(['The CSV file is empty.']);
        if (strlen($content) > self::MAX_BYTES) throw new TimetableCsvImportException(['The CSV file exceeds the 2 MiB upload limit.']);
        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new TimetableCsvImportException(['The CSV file must contain valid UTF-8 text.']);
        }

        $records = $this->records($content);
        if ($records === []) throw new TimetableCsvImportException(['The CSV file is empty.']);
        if ($records[0]['fields'] !== self::HEADER) {
            throw new TimetableCsvImportException(['CSV row 1 must contain exactly: Day,Period,Room,Class,Teacher.']);
        }
        if (count($records) - 1 > self::MAX_DATA_ROWS) {
            throw new TimetableCsvImportException(['The CSV file exceeds the 20,000 data-row limit.']);
        }

        $rows = [];
        $errors = [];
        foreach (array_slice($records, 1) as $record) {
            if (count($record['fields']) !== 5) {
                $errors[] = sprintf('CSV row %d must contain exactly five columns.', $record['row']);
                continue;
            }
            $rows[] = [
                'row' => $record['row'],
                'day' => $record['fields'][0],
                'period' => $record['fields'][1],
                'room' => $record['fields'][2],
                'class' => trim($record['fields'][3]),
                'teacher' => trim($record['fields'][4]),
            ];
        }
        if ($errors !== []) throw new TimetableCsvImportException($errors);
        return $rows;
    }

    /** @return list<array{row:int,fields:list<string>}> */
    private function records(string $content): array
    {
        $records = [];
        $fields = [];
        $field = '';
        $inQuotes = false;
        $afterQuote = false;
        $line = 1;
        $recordLine = 1;
        $length = strlen($content);

        for ($index = 0; $index < $length; $index++) {
            $character = $content[$index];
            if ($inQuotes) {
                if ($character === '"') {
                    if (($content[$index + 1] ?? null) === '"') {
                        $field .= '"';
                        $index++;
                    } else {
                        $inQuotes = false;
                        $afterQuote = true;
                    }
                } else {
                    $field .= $character;
                    if ($character === "\n") $line++;
                }
                continue;
            }

            if ($afterQuote && $character !== ',' && $character !== "\r" && $character !== "\n") {
                throw new TimetableCsvImportException([sprintf('CSV row %d has characters after a closing quote.', $recordLine)]);
            }
            if ($character === '"') {
                if ($field !== '' || $afterQuote) {
                    throw new TimetableCsvImportException([sprintf('CSV row %d contains an unexpected quote.', $recordLine)]);
                }
                $inQuotes = true;
                continue;
            }
            if ($character === ',') {
                $fields[] = $field;
                $field = '';
                $afterQuote = false;
                continue;
            }
            if ($character === "\r" || $character === "\n") {
                $fields[] = $field;
                $records[] = ['row' => $recordLine, 'fields' => $fields];
                $fields = [];
                $field = '';
                $afterQuote = false;
                if ($character === "\r" && ($content[$index + 1] ?? null) === "\n") $index++;
                $line++;
                $recordLine = $line;
                if (count($records) > self::MAX_DATA_ROWS + 1) {
                    throw new TimetableCsvImportException(['The CSV file exceeds the 20,000 data-row limit.']);
                }
                continue;
            }
            $field .= $character;
        }

        if ($inQuotes) throw new TimetableCsvImportException([sprintf('CSV row %d contains an unterminated quoted field.', $recordLine)]);
        if ($field !== '' || $fields !== [] || $afterQuote) {
            $fields[] = $field;
            $records[] = ['row' => $recordLine, 'fields' => $fields];
        }
        return $records;
    }
}
