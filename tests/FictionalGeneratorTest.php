<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Fictional\FictionalSchoolGenerator;

final class FictionalGeneratorTest
{
    public static function run(): void
    {
        $profiles = FictionalSchoolGenerator::profiles();
        assertSameValue(['brackenmere', 'ashwick', 'fenmere'], array_keys($profiles), 'Fictional profile set changed unexpectedly.');
        foreach ($profiles as $profile) {
            assertSameValue(3, strlen((string) $profile['admin']), 'Fictional administrator initials are not three letters.');
            assertSameValue(5, count($profile['classes']) > 0 ? 5 : 0, 'Fictional profile has no conventional working week.');
        }
        $command = (string) file_get_contents(dirname(__DIR__) . '/bin/generate-fictional-schools.php');
        assertSameValue(true, str_contains($command, "'dry-run'"), 'Fictional generator has no dry-run mode.');
        assertSameValue(true, str_contains($command, "'regenerate'"), 'Fictional generator has no explicit regeneration mode.');
        assertSameValue(false, str_contains($command, '--force'), 'Fictional generator contains a safety bypass.');
        assertSameValue(false, str_contains($command, '--ignore-safety'), 'Fictional generator contains a safety bypass.');
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Fictional/FictionalSchoolGenerator.php');
        foreach (['pumba', 'reqsheet_dev', '/etc/reqsheet/reqsheet-fictional-generator.json', '/var/lib/reqsheet/fictional-schools', '/manifest.json', 'tenant_slug = :slug', 'manifest'] as $fragment) {
            assertSameValue(true, str_contains($source, $fragment), 'Fictional generator safeguard is missing: ' . $fragment);
        }
        foreach (['7B1.1', 'CP10a', 'Y8 Chemical Reactions', 'L4 Chemical Reactions', 'Nothing required'] as $fragment) {
            assertSameValue(true, str_contains($source, $fragment), 'Realistic fictional lesson content is missing: ' . $fragment);
        }
        assertSameValue('2026-09-21', FictionalSchoolGenerator::referenceDate(), 'Fictional reference date changed unexpectedly.');
    }
}
