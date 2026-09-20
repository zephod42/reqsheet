<?php

declare(strict_types=1);

namespace Reqsheet\Monitor;

use DateTimeImmutable;

final class MonitorReportRenderer
{
    public function render(MonitorSnapshot $snapshot, DateTimeImmutable $generatedAt, string $environment, ?string $commit): string
    {
        $generated = $generatedAt->format('j M Y, H:i:s T (P)');
        $cards = [
            ['Organisations', $snapshot->totals['organisations']],
            ['Users', $snapshot->totals['users']],
            ['Requisitions', $snapshot->totals['requisitions']],
            ['Timetable versions', $snapshot->totals['timetable_versions']],
        ];
        $summary = '';
        foreach ($cards as [$label, $value]) {
            $summary .= '<article class="stat"><strong>' . number_format($value) . '</strong><span>' . self::e($label) . '</span></article>';
        }

        $schools = '';
        foreach ($snapshot->schools as $school) {
            $schools .= '<tr><th scope="row">' . self::e($school['name']) . '</th><td><code>' . self::e($school['short_code']) . '</code></td>'
                . '<td>' . number_format($school['users']) . '</td><td>' . number_format($school['timetables']) . '</td><td>' . number_format($school['requisitions']) . '</td></tr>';
        }
        if ($schools === '') {
            $schools = '<tr><td colspan="5" class="empty">No organisations are registered.</td></tr>';
        }

        $activityRows = $this->activityRow('Organisation registrations', $snapshot->activity['registrations_7'], $snapshot->activity['registrations_30'])
            . $this->activityRow('Requisitions created', $snapshot->activity['requisitions_created_7'], $snapshot->activity['requisitions_created_30'])
            . $this->activityRow('Requisitions modified', $snapshot->activity['requisitions_modified_7'], $snapshot->activity['requisitions_modified_30']);

        $migrationCount = count($snapshot->migrations);
        $latest = $migrationCount === 0 ? null : $snapshot->migrations[$migrationCount - 1];
        $migrationItems = '';
        foreach (array_reverse($snapshot->migrations) as $migration) {
            $migrationItems .= '<li><code>' . self::e($migration['version']) . '</code><span>'
                . ($migration['applied_at'] === null ? 'Applied time unavailable' : self::e($migration['applied_at']) . ' database time') . '</span></li>';
        }
        if ($migrationItems === '') $migrationItems = '<li><span>No applied migration records are available.</span></li>';

        $commitText = $commit === null ? 'Unavailable' : self::e($commit);
        $latestText = $latest === null ? 'Unavailable' : self::e($latest['version']);

        return '<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>Reqsheet Monitor · ' . self::e($environment) . '</title><style>' . self::css() . '</style></head><body>'
            . '<header><div class="brand"><span class="mark">R</span><div><p class="eyebrow">Operational snapshot</p><h1>Reqsheet <b>Monitor</b></h1></div></div>'
            . '<div class="environment"><span>Environment</span><strong>' . self::e($environment) . '</strong></div></header>'
            . '<main><section class="freshness"><div><span>Generated at</span><strong>' . self::e($generated) . '</strong></div><p>This is a static report, not a live dashboard. Generate a new copy before relying on its figures.</p></section>'
            . '<section><div class="section-heading"><div><p class="eyebrow">At a glance</p><h2>Summary</h2></div><span class="status"><i></i>Database connected</span></div><div class="stats">' . $summary . '</div></section>'
            . '<section><div class="section-heading"><div><p class="eyebrow">Tenant overview</p><h2>Schools</h2></div><span>' . number_format(count($snapshot->schools)) . ' listed</span></div><div class="table-wrap"><table><thead><tr><th>Organisation</th><th>Short code</th><th>Users</th><th>Timetables</th><th>Requisitions</th></tr></thead><tbody>' . $schools . '</tbody></table></div></section>'
            . '<section><div class="section-heading"><div><p class="eyebrow">Recent records</p><h2>Activity</h2></div><span>Rolling windows</span></div><div class="table-wrap"><table class="activity"><thead><tr><th>Metric</th><th>Past 7 days</th><th>Past 30 days</th></tr></thead><tbody>' . $activityRows . '</tbody></table></div>'
            . '<p class="note">Created and modified requisitions are reported separately. A requisition created and later edited within a window can appear in both figures. Login activity is unavailable because the current schema stores no login timestamps.</p></section>'
            . '<section><div class="section-heading"><div><p class="eyebrow">Deployment state</p><h2>Application</h2></div></div><div class="application-grid">'
            . '<article><span>Database</span><strong>Connected</strong><small>Reporting identity verified as SELECT-only</small></article>'
            . '<article><span>Applied migrations</span><strong>' . number_format($migrationCount) . '</strong><small>Latest: ' . $latestText . '</small></article>'
            . '<article><span>Deployed commit</span><strong class="commit">' . $commitText . '</strong><small>Git working-copy metadata, where available</small></article></div>'
            . '<details><summary>Applied migration history</summary><ol class="migrations">' . $migrationItems . '</ol></details></section>'
            . '</main><footer><strong>Reqsheet Monitor</strong><span>Read-only · self-contained · generated ' . self::e($generated) . '</span></footer></body></html>';
    }

    private function activityRow(string $label, ?int $seven, ?int $thirty): string
    {
        return '<tr><th scope="row">' . self::e($label) . '</th><td>' . self::metric($seven) . '</td><td>' . self::metric($thirty) . '</td></tr>';
    }

    private static function metric(?int $value): string
    {
        return $value === null ? '<span class="unavailable">Unavailable — timestamp not supported</span>' : number_format($value);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
:root{--ink:#181517;--muted:#6f6669;--line:#ded8da;--paper:#fff;--wash:#f6f3f4;--red:#741c2f;--red-dark:#4c1020;--red-soft:#f3e7ea;--green:#276448}*{box-sizing:border-box}body{margin:0;background:var(--wash);color:var(--ink);font:15px/1.5 ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}header,main,footer{max-width:1180px;margin:auto}header{display:flex;align-items:center;justify-content:space-between;padding:38px 32px 25px}.brand{display:flex;align-items:center;gap:16px}.mark{display:grid;place-items:center;width:48px;height:48px;background:var(--red);color:#fff;font:700 25px Georgia,serif;border-radius:5px}h1,h2,p{margin:0}h1{font-size:28px;letter-spacing:-.04em}h1 b{color:var(--red)}h2{font-size:23px;letter-spacing:-.025em}.eyebrow{text-transform:uppercase;letter-spacing:.15em;font-size:10px;font-weight:800;color:var(--red);margin-bottom:2px}.environment{text-align:right}.environment span,.application-grid span,.freshness span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.1em;font-weight:700}.environment strong{font-size:14px}main{padding:0 32px 45px}.freshness{background:var(--red-dark);color:#fff;padding:22px 25px;display:flex;justify-content:space-between;gap:28px;align-items:center;border-radius:7px;box-shadow:0 9px 25px #4c10201a}.freshness span{color:#e4cbd1}.freshness strong{font-size:20px}.freshness p{max-width:490px;color:#eadde0;font-size:13px}section:not(.freshness){background:var(--paper);margin-top:18px;padding:25px;border:1px solid var(--line);border-radius:7px}.section-heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:19px}.section-heading>span{color:var(--muted);font-size:12px}.status{display:flex;align-items:center;gap:7px}.status i{width:8px;height:8px;background:var(--green);border-radius:50%}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.stat{border-top:3px solid var(--red);background:var(--wash);padding:18px}.stat strong{display:block;font:700 31px/1.1 ui-monospace,SFMono-Regular,Menlo,monospace}.stat span{color:var(--muted);font-size:12px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:right}th,td{padding:12px 14px;border-bottom:1px solid var(--line);white-space:nowrap}thead th{background:var(--wash);color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.09em}thead th:first-child,tbody th{text-align:left}tbody th{font-weight:650}tbody tr:last-child th,tbody tr:last-child td{border-bottom:0}code,.commit{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--red-dark)}.empty{text-align:center;color:var(--muted);padding:30px}.activity th:first-child{width:60%}.unavailable{color:var(--muted);font-size:12px}.note{margin-top:14px;color:var(--muted);font-size:12px;max-width:880px}.application-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.application-grid article{padding:18px;border:1px solid var(--line);border-radius:5px}.application-grid strong{display:block;font-size:21px;margin:5px 0 2px}.application-grid small{color:var(--muted)}details{margin-top:17px;border-top:1px solid var(--line);padding-top:14px}summary{cursor:pointer;font-weight:650;color:var(--red)}.migrations{columns:3;column-gap:32px;padding-left:22px}.migrations li{break-inside:avoid;padding:5px 0}.migrations span{display:block;color:var(--muted);font-size:11px}footer{padding:0 32px 35px;display:flex;justify-content:space-between;color:var(--muted);font-size:11px}footer strong{color:var(--red)}@media(max-width:760px){header,.freshness,.section-heading,footer{align-items:flex-start;flex-direction:column}.environment{text-align:left}.stats,.application-grid{grid-template-columns:1fr 1fr}.freshness strong{font-size:17px}.migrations{columns:1}}@media(max-width:470px){header,main,footer{padding-left:16px;padding-right:16px}.stats,.application-grid{grid-template-columns:1fr}section:not(.freshness){padding:18px}}@media print{body{background:#fff}header,main,footer{max-width:none}.freshness,section:not(.freshness){box-shadow:none;break-inside:avoid}.freshness{background:#fff;color:#000;border:2px solid #000}.freshness span,.freshness p{color:#333}details{display:block}details>summary{display:none}.migrations{columns:4}footer{padding-top:10px}}
CSS;
    }
}
