<?php

declare(strict_types=1);

namespace Reqsheet\Monitor;

final class GitCommit
{
    public static function detect(string $repository): ?string
    {
        $head = @file_get_contents($repository . '/.git/HEAD');
        if (!is_string($head)) return null;
        $head = trim($head);
        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) return substr($head, 0, 12);
        if (preg_match('/^ref: (.+)$/D', $head, $matches) !== 1 || str_contains($matches[1], '..')) return null;

        $loose = @file_get_contents($repository . '/.git/' . $matches[1]);
        if (is_string($loose) && preg_match('/^[0-9a-f]{40}\s*$/i', $loose) === 1) {
            return substr(trim($loose), 0, 12);
        }
        $packed = @file($repository . '/.git/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($packed)) return null;
        foreach ($packed as $line) {
            if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($matches[1], '/') . '$/iD', $line, $ref) === 1) {
                return substr($ref[1], 0, 12);
            }
        }
        return null;
    }
}
