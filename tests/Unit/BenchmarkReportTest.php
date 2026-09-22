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

    public function testLatencySeparatesNormalStagesFromCapStage(): void
    {
        $avg = [['x' => 0, 'y' => 2.0], ['x' => 30, 'y' => 6.0], ['x' => 60, 'y' => 4000.0], ['x' => 90, 'y' => 8000.0]];
        $p95 = [['x' => 0, 'y' => 4.0], ['x' => 30, 'y' => 10.0], ['x' => 60, 'y' => 7000.0], ['x' => 90, 'y' => 12000.0]];
        $summary = summarizeRun([], $avg, $p95, ['reached' => true, 'second' => 60]);
        $this->assertSame(4.0, $summary['normalLatencyAvgMs']);
        $this->assertSame(7.0, $summary['normalLatencyP95Ms']);
        $this->assertSame(4000.0, $summary['overloadedLatencyAvgMs']);
        $this->assertSame(7000.0, $summary['overloadedLatencyP95Ms']);

        $firstStageCap = summarizeRun([], $avg, $p95, ['reached' => true, 'second' => 0]);
        $this->assertNull($firstStageCap['normalLatencyAvgMs']);
        $this->assertNull($firstStageCap['normalLatencyP95Ms']);
        $this->assertSame(2.0, $firstStageCap['overloadedLatencyAvgMs']);

        $noCap = summarizeRun([], $avg, $p95, ['reached' => false]);
        $this->assertSame(3002.0, $noCap['normalLatencyAvgMs']);
        $this->assertNull($noCap['overloadedLatencyAvgMs']);
        $this->assertNull($noCap['overloadedLatencyP95Ms']);
    }

    public function testReportSeparatesRuntimeAndEndpointGroups(): void
    {
        $runs = [];
        foreach (['FrankenPHP worker', 'RoadRunner', 'Rapira worker', 'Rapira dispatcher', 'FrankenPHP classic', 'PHP-FPM + Nginx', 'Rapira classic', 'FreeUnit'] as $name) {
            foreach ([false, true] as $db) {
                $runs[] = [
                    'label' => $name . ($db ? ' DB' : ''),
                    'directory' => '/tmp/example',
                    'metadata' => ['TARGET_PATH' => $db ? '/postgres/orders' : '/', 'MODE' => 'ramp'],
                    'summary' => ['normalLatencyAvgMs' => 2.5, 'normalLatencyP95Ms' => 9.5, 'overloadedLatencyAvgMs' => 50.0, 'overloadedLatencyP95Ms' => 90.0],
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
            'Worker no DB' => ['FrankenPHP worker', 'RoadRunner', 'Rapira worker', 'Rapira dispatcher'],
            'Worker DB' => ['FrankenPHP worker DB', 'RoadRunner DB', 'Rapira worker DB', 'Rapira dispatcher DB'],
            'Non-worker no DB' => ['FrankenPHP classic', 'PHP-FPM + Nginx', 'Rapira classic', 'FreeUnit'],
            'Non-worker DB' => ['FrankenPHP classic DB', 'PHP-FPM + Nginx DB', 'Rapira classic DB', 'FreeUnit DB'],
        ], $groups);
        $this->assertSame(2, substr_count($html, '<table class="summary-table">'));
        $this->assertSame(2, substr_count($html, '>Normal latency (avg)</button>'));
        $this->assertSame(2, substr_count($html, '>Overloaded latency (avg)</button>'));
        $this->assertSame(14, substr_count($html, 'aria-sort="none"'));
    }
}
