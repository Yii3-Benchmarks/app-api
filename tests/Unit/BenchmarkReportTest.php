<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;

require_once dirname(__DIR__, 2) . '/tools/render-benchmark-report.php';

final class BenchmarkReportTest extends Unit
{
    public function testStageCapAllowsNoiseAndFindsSaturation(): void
    {
        $stages = [
            ['targetRate' => 5000, 'durationUs' => 30_000_000, 'requests' => 149000, 'errors' => 0],
            ['targetRate' => 10000, 'durationUs' => 30_000_000, 'requests' => 270000, 'errors' => 0],
        ];
        $points = [['x' => 0, 'y' => 4966], ['x' => 30, 'y' => 9000]];
        $this->assertFalse(detectStageRpsCap([$stages[0]], [$points[0]])['reached']);
        $cap = detectStageRpsCap($stages, $points);
        $this->assertTrue($cap['reached']);
        $this->assertSame(30, $cap['second']);
        $this->assertEquals(9000, $cap['successfulRps']);
        $this->assertSame('target', $cap['basis']);
    }

    public function testErrorsReduceSuccessfulThroughput(): void
    {
        $cap = detectStageRpsCap([
            ['targetRate' => 1000, 'durationUs' => 30_000_000, 'requests' => 30000, 'errors' => 3000],
        ], [['x' => 0, 'y' => 900]]);
        $this->assertTrue($cap['reached']);
        $this->assertEquals(900, $cap['successfulRps']);
        $this->assertFalse(detectStageRpsCap([], [])['reached']);
    }

    public function testReportSeparatesRuntimeAndEndpointGroups(): void
    {
        $runs = [];
        foreach (['FrankenPHP worker', 'RoadRunner', 'Rapira', 'FrankenPHP classic', 'PHP-FPM + Nginx'] as $name) {
            foreach ([false, true] as $db) {
                $runs[] = [
                    'label' => $name . ($db ? ' DB' : ''),
                    'directory' => '/tmp/example',
                    'metadata' => ['TARGET_PATH' => $db ? '/postgres/orders' : '/', 'MODE' => 'ramp'],
                    'summary' => ['latencyAvgMs' => 2.5, 'latencyP95Ms' => 9.5],
                    'series' => ['successfulResponsesPerSecond' => [['x' => 0, 'y' => 1000]]],
                    'docker' => [],
                ];
            }
        }
        $html = renderHtmlReport($runs);
        preg_match('/const reportData = (.*);/', $html, $matches);
        $charts = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['charts'];
        $groups = [];
        foreach ($charts as $chart) {
            if (str_ends_with($chart['id'], 'requests-per-second')) {
                $groups[$chart['group']] = array_column($chart['series'], 'runLabel');
            }
        }
        $this->assertSame([
            'Worker no DB' => ['FrankenPHP worker', 'RoadRunner', 'Rapira'],
            'Worker DB' => ['FrankenPHP worker DB', 'RoadRunner DB', 'Rapira DB'],
            'Non-worker no DB' => ['FrankenPHP classic', 'PHP-FPM + Nginx'],
            'Non-worker DB' => ['FrankenPHP classic DB', 'PHP-FPM + Nginx DB'],
        ], $groups);
        $this->assertSame(2, substr_count($html, '<table class="summary-table">'));
        $this->assertSame(12, substr_count($html, 'aria-sort="none"'));
    }
}
