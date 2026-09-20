<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Reqsheet\Monitor\MonitorReportRenderer;
use Reqsheet\Monitor\MonitorSnapshot;
use Reqsheet\Monitor\PdoMonitorStore;
use Reqsheet\Monitor\ReadOnlyGrantValidator;
use Reqsheet\Monitor\ReportFileWriter;

final class MonitorFixtureStatement extends \PDOStatement
{
    /** @var list<array<string, mixed>> */
    private array $rows;
    /** @var (\Closure(?array):list<array<string, mixed>>)|null */
    private ?\Closure $onExecute;

    /** @param list<array<string, mixed>> $rows @param (\Closure(?array):list<array<string, mixed>>)|null $onExecute */
    public function __construct(array $rows = [], ?\Closure $onExecute = null)
    {
        $this->rows = $rows;
        $this->onExecute = $onExecute;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->onExecute !== null) $this->rows = ($this->onExecute)($params);
        return true;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[0] ?? false;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[0] ?? [];
        return array_values($row)[$column] ?? false;
    }
}

final class MonitorFixturePdo extends \PDO
{
    /** @param array<string, bool> $columns */
    public function __construct(private readonly array $columns = [])
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if ($query === 'SHOW GRANTS FOR CURRENT_USER') {
            return new MonitorFixtureStatement([
                ['grant' => 'GRANT USAGE ON *.* TO `monitor`@`localhost`'],
                ['grant' => 'GRANT SELECT ON `reqsheet_test`.* TO `monitor`@`localhost`'],
            ]);
        }
        if (preg_match('/SELECT COUNT\(\*\) FROM `([^`]+)`/', $query, $match) === 1) {
            $counts = ['organisations' => 2, 'users' => 5, 'requisitions' => 9, 'timetable_versions' => 3];
            return new MonitorFixtureStatement([['count' => $counts[$match[1]]]]);
        }
        if (str_contains($query, 'FROM organisations o')) {
            if (!str_contains($query, 'WHERE u.organisation_id = o.id')
                || !str_contains($query, 'WHERE tv.organisation_id = o.id')
                || !str_contains($query, 'WHERE lo.organisation_id = o.id')) {
                throw new \RuntimeException('Per-school reporting query is not tenant-scoped.');
            }
            return new MonitorFixtureStatement([
                ['name' => 'Alpha & <Science>', 'short_code' => 'alpha"school', 'users' => '2', 'timetables' => '1', 'requisitions' => '7'],
                ['name' => 'Beta School', 'short_code' => 'beta', 'users' => '3', 'timetables' => '2', 'requisitions' => '2'],
            ]);
        }
        if (str_contains($query, 'FROM organisations')) return new MonitorFixtureStatement([['last_7' => '1', 'last_30' => '2']]);
        if (str_contains($query, 'FROM requisitions') && str_contains($query, 'updated_at > created_at')) return new MonitorFixtureStatement([['last_7' => '3', 'last_30' => '6']]);
        if (str_contains($query, 'FROM requisitions')) return new MonitorFixtureStatement([['last_7' => '4', 'last_30' => '8']]);
        if (str_contains($query, 'FROM schema_migrations')) {
            return new MonitorFixtureStatement([
                ['version' => '0001', 'applied_at' => '2026-09-01 10:00:00'],
                ['version' => '0013', 'applied_at' => '2026-09-20 12:00:00'],
            ]);
        }
        throw new \RuntimeException('Unexpected synthetic monitor query: ' . $query);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return new MonitorFixtureStatement([], function (?array $params) use ($query): array {
            if (str_contains($query, 'information_schema.TABLES')) return [['count' => 1]];
            $key = (string) ($params['table'] ?? '') . '.' . (string) ($params['column'] ?? '');
            return [['count' => ($this->columns[$key] ?? false) ? 1 : 0]];
        });
    }
}

final class MonitorReportTest
{
    public static function run(): void
    {
        $snapshot = (new PdoMonitorStore(new MonitorFixturePdo([
            'organisations.created_at' => true,
            'requisitions.created_at' => true,
            'requisitions.updated_at' => true,
            'schema_migrations.applied_at' => true,
        ])))->snapshot();
        $html = (new MonitorReportRenderer())->render(
            $snapshot,
            new DateTimeImmutable('2026-09-20 14:15:16', new DateTimeZone('Europe/London')),
            'Pumba <staging>',
            'abc123def456',
        );

        foreach (['Reqsheet <b>Monitor</b>', '20 Sep 2026, 14:15:16 BST (+01:00)', 'Pumba &lt;staging&gt;', '>5<', '>9<', 'abc123def456'] as $fragment) {
            if (!str_contains($html, $fragment)) throw new \RuntimeException('Monitor report omitted expected content: ' . $fragment);
        }
        if (!str_contains($html, 'Alpha &amp; &lt;Science&gt;') || !str_contains($html, 'alpha&quot;school')) {
            throw new \RuntimeException('Monitor report did not escape database-derived school values.');
        }
        if (str_contains($html, 'Alpha & <Science>') || str_contains($html, 'Pumba <staging>')) {
            throw new \RuntimeException('Monitor report contains unescaped dynamic HTML.');
        }
        if (!preg_match('/Alpha &amp; &lt;Science&gt;<\/th><td><code>alpha&quot;school<\/code><\/td><td>2<\/td><td>1<\/td><td>7<\/td>/', $html)) {
            throw new \RuntimeException('First school counts were not kept within its tenant row.');
        }
        if (!preg_match('/Beta School<\/th><td><code>beta<\/code><\/td><td>3<\/td><td>2<\/td><td>2<\/td>/', $html)) {
            throw new \RuntimeException('Second school counts were not kept within its tenant row.');
        }

        $limitedSchema = (new PdoMonitorStore(new MonitorFixturePdo()))->snapshot();
        foreach ($limitedSchema->activity as $metric) {
            assertSameValue(null, $metric, 'A missing schema timestamp was reported as measured activity.');
        }

        $unavailable = new MonitorSnapshot(
            ['organisations' => 0, 'users' => 0, 'requisitions' => 0, 'timetable_versions' => 0], [],
            ['registrations_7' => null, 'registrations_30' => null, 'requisitions_created_7' => null, 'requisitions_created_30' => null, 'requisitions_modified_7' => null, 'requisitions_modified_30' => null], [],
        );
        $emptyHtml = (new MonitorReportRenderer())->render($unavailable, new DateTimeImmutable('2026-09-20T12:00:00Z'), 'Empty', null);
        if (!str_contains($emptyHtml, 'No organisations are registered.') || substr_count($emptyHtml, 'Unavailable — timestamp not supported') !== 6) {
            throw new \RuntimeException('Empty or timestamp-limited database state was not reported clearly.');
        }
        if (!str_contains($emptyHtml, 'Login activity is unavailable') || !str_contains($emptyHtml, 'No applied migration records are available.')) {
            throw new \RuntimeException('Unsupported monitor metrics were not explained.');
        }

        ReadOnlyGrantValidator::assertSelectOnly([
            "GRANT USAGE ON *.* TO `monitor`@`localhost`",
            "GRANT SELECT ON `reqsheet_dev`.* TO `monitor`@`localhost`",
        ]);
        assertThrows(
            static fn () => ReadOnlyGrantValidator::assertSelectOnly(["GRANT SELECT, INSERT ON `reqsheet_dev`.* TO `monitor`@`localhost`"]),
            'Monitor accepted a database identity with INSERT privilege.',
        );
        assertThrows(
            static fn () => ReadOnlyGrantValidator::assertSelectOnly(["GRANT ALL PRIVILEGES ON `reqsheet_dev`.* TO `monitor`@`localhost`"]),
            'Monitor accepted an all-privileges database identity.',
        );

        $root = sys_get_temp_dir() . '/reqsheet-monitor-test-' . bin2hex(random_bytes(6));
        $public = $root . '/public';
        $reports = $root . '/reports';
        if (!mkdir($public, 0700, true) || !mkdir($reports, 0700, true)) throw new \RuntimeException('Could not create monitor output fixture.');
        try {
            $writer = new ReportFileWriter($public);
            $output = $reports . '/report.html';
            $writer->write($output, '<!doctype html><title>test</title>');
            assertSameValue('<!doctype html><title>test</title>', file_get_contents($output), 'Monitor output was not written completely.');
            assertSameValue(0600, fileperms($output) & 0777, 'Monitor output was not private.');
            assertThrows(static fn () => $writer->write($output, 'replacement'), 'Monitor silently overwrote an existing report.');
            $writer->write($output, 'replacement', true);
            assertSameValue('replacement', file_get_contents($output), 'Monitor --force replacement failed.');
            assertThrows(static fn () => $writer->write($public . '/leak.html', 'secret'), 'Monitor wrote a report under public/.');
            assertThrows(static fn () => $writer->write('relative.html', 'secret'), 'Monitor accepted a relative output path.');
            assertThrows(static fn () => $writer->write($root . '/missing/report.html', 'secret'), 'Monitor accepted a missing output directory.');
        } finally {
            if (file_exists($reports . '/report.html')) unlink($reports . '/report.html');
            rmdir($reports);
            rmdir($public);
            rmdir($root);
        }
    }
}
