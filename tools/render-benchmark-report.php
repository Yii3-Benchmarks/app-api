<?php

declare(strict_types=1);

[$outputFile, $runDirectories] = parseArguments($argv);

$runs = [];
foreach ($runDirectories as $runDirectory) {
    $runs[] = loadRun($runDirectory);
}

$outputDirectory = dirname($outputFile);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create output directory: $outputDirectory\n");
    exit(1);
}

$html = renderHtmlReport($runs);
if (file_put_contents($outputFile, $html) === false) {
    fwrite(STDERR, "Unable to write report: $outputFile\n");
    exit(1);
}

echo $outputFile . PHP_EOL;

function parseArguments(array $argv): array
{
    $outputFile = dirname(__DIR__) . '/runtime/benchmarks/report.html';
    $inputPaths = [];

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $argument = $argv[$i];

        if ($argument === '--output' || $argument === '-o') {
            $i++;
            if (!isset($argv[$i])) {
                fwrite(STDERR, "Missing value for $argument\n");
                exit(1);
            }

            $outputFile = normalizePath($argv[$i]);
            continue;
        }

        $inputPaths[] = normalizePath($argument);
    }

    if ($inputPaths === []) {
        $inputPaths[] = dirname(__DIR__) . '/runtime/benchmarks';
    }

    $runDirectories = expandRunDirectories($inputPaths);

    if ($runDirectories === []) {
        fwrite(STDERR, "No benchmark runs found.\n");
        exit(1);
    }

    return [$outputFile, $runDirectories];
}

function normalizePath(string $path): string
{
    if ($path === '') {
        return $path;
    }

    if ($path[0] === DIRECTORY_SEPARATOR) {
        return $path;
    }

    return getcwd() . DIRECTORY_SEPARATOR . $path;
}

function expandRunDirectories(array $inputPaths): array
{
    $runDirectories = [];

    foreach ($inputPaths as $inputPath) {
        if (!is_dir($inputPath)) {
            fwrite(STDERR, "Benchmark path not found: $inputPath\n");
            exit(1);
        }

        if (isRunDirectory($inputPath)) {
            $runDirectories[$inputPath] = true;
            continue;
        }

        $children = glob($inputPath . '/*', GLOB_ONLYDIR);
        if ($children === false) {
            continue;
        }

        sort($children);
        foreach ($children as $child) {
            if (isRunDirectory($child)) {
                $runDirectories[$child] = true;
            }
        }
    }

    return array_keys($runDirectories);
}

function isRunDirectory(string $directory): bool
{
    return is_file($directory . '/metadata.env')
        && is_file($directory . '/summary.json')
        && is_file($directory . '/docker-stats.csv')
        && is_file($directory . '/k6-timeseries.json');
}

function loadRun(string $runDirectory): array
{
    if (!is_dir($runDirectory)) {
        fwrite(STDERR, "Benchmark directory not found: $runDirectory\n");
        exit(1);
    }

    $metadataFile = $runDirectory . '/metadata.env';
    $summaryFile = $runDirectory . '/summary.json';
    $dockerStatsFile = $runDirectory . '/docker-stats.csv';
    $k6TimeseriesFile = $runDirectory . '/k6-timeseries.json';

    foreach ([$metadataFile, $summaryFile, $dockerStatsFile, $k6TimeseriesFile] as $requiredFile) {
        if (!is_file($requiredFile)) {
            fwrite(STDERR, "Required benchmark file not found: $requiredFile\n");
            exit(1);
        }
    }

    $metadata = parseMetadata($metadataFile);
    $summary = json_decode((string) file_get_contents($summaryFile), true, 512, JSON_THROW_ON_ERROR);
    $k6Series = parseCompactK6Timeseries($k6TimeseriesFile);
    $dockerSeries = parseDockerStats($dockerStatsFile);
    $successfulResponsesPerSecond = $k6Series['successfulResponsesPerSecond'];
    $targetRequestsPerSecond = buildTargetRequestsPerSecondSeries($metadata);
    $issuedRequestsPerSecond = $k6Series['issuedRequestsPerSecond'];
    $erroredRequestsPerSecond = deriveErroredRequestsSeries(
        $k6Series['requestsPerSecond'],
        $successfulResponsesPerSecond,
    );
    $runSummary = summarizeRun($summary);
    $runSummary['rpsCap'] = detectRpsCap(
        $issuedRequestsPerSecond,
        $successfulResponsesPerSecond,
    );
    $runSummary['errorsStart'] = detectErrorsStart(
        $issuedRequestsPerSecond,
        $erroredRequestsPerSecond,
    );

    $label = buildRunLabel($runDirectory, $metadata);

    return [
        'directory' => $runDirectory,
        'label' => $label,
        'metadata' => $metadata,
        'summary' => $runSummary,
        'series' => [
            'requestsPerSecond' => $k6Series['requestsPerSecond'],
            'issuedRequestsPerSecond' => $issuedRequestsPerSecond,
            'successfulResponsesPerSecond' => $successfulResponsesPerSecond,
            'erroredRequestsPerSecond' => $erroredRequestsPerSecond,
            'targetRequestsPerSecond' => $targetRequestsPerSecond,
            'avgLatencyMs' => $k6Series['avgLatencyMs'],
            'p95LatencyMs' => $k6Series['p95LatencyMs'],
            'droppedPerSecond' => $k6Series['droppedPerSecond'],
            'virtualUsers' => $k6Series['virtualUsers'],
        ],
        'docker' => $dockerSeries,
    ];
}

function parseMetadata(string $metadataFile): array
{
    $metadata = [];
    $lines = file($metadataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $metadata[$key] = $value;
    }

    return $metadata;
}

function summarizeRun(array $summary): array
{
    $metrics = $summary['metrics'] ?? [];

    return [
        'httpReqFailedValue' => (float) ($metrics['http_req_failed']['value'] ?? 0.0),
        'latencyAvgMs' => (float) ($metrics['http_req_duration']['avg'] ?? 0.0),
        'latencyP95Ms' => (float) ($metrics['http_req_duration']['p(95)'] ?? 0.0),
        'latencyP99Ms' => (float) ($metrics['http_req_duration']['p(99)'] ?? 0.0),
    ];
}

function detectRpsCap(array $issuedRequestsPerSecond, array $successfulResponsesPerSecond): array
{
    $issuedBySecond = indexSeriesBySecond($issuedRequestsPerSecond);
    $successfulBySecond = indexSeriesBySecond($successfulResponsesPerSecond);

    if ($issuedBySecond === [] || $successfulBySecond === []) {
        return [
            'reached' => false,
        ];
    }

    $seconds = array_values(array_intersect(array_keys($issuedBySecond), array_keys($successfulBySecond)));
    sort($seconds, SORT_NUMERIC);

    $requiredConsecutiveSeconds = 3;
    $candidate = null;
    $streak = 0;

    foreach ($seconds as $second) {
        $issued = $issuedBySecond[$second];
        $successful = $successfulBySecond[$second];

        if ($issued <= 0.0) {
            $candidate = null;
            $streak = 0;
            continue;
        }

        $difference = $issued - $successful;
        $threshold = max(25.0, $issued * 0.02);
        $isCapped = $difference > $threshold;

        if (!$isCapped) {
            $candidate = null;
            $streak = 0;
            continue;
        }

        if ($candidate === null) {
            $candidate = [
                'second' => $second,
                'issuedRps' => $issued,
                'successfulRps' => $successful,
            ];
        }

        $streak++;

        if ($streak >= $requiredConsecutiveSeconds) {
            return [
                'reached' => true,
                'second' => $candidate['second'],
                'issuedRps' => $candidate['issuedRps'],
                'successfulRps' => $candidate['successfulRps'],
            ];
        }
    }

    return [
        'reached' => false,
    ];
}

function detectErrorsStart(array $issuedRequestsPerSecond, array $erroredRequestsPerSecond): array
{
    $issuedBySecond = indexSeriesBySecond($issuedRequestsPerSecond);
    $erroredBySecond = indexSeriesBySecond($erroredRequestsPerSecond);

    if ($issuedBySecond === [] || $erroredBySecond === []) {
        return [
            'reached' => false,
        ];
    }

    $seconds = array_values(array_intersect(array_keys($issuedBySecond), array_keys($erroredBySecond)));
    sort($seconds, SORT_NUMERIC);

    $requiredConsecutiveSeconds = 3;
    $candidate = null;
    $streak = 0;

    foreach ($seconds as $second) {
        $issued = $issuedBySecond[$second];
        $errored = $erroredBySecond[$second];

        if ($issued <= 0.0) {
            $candidate = null;
            $streak = 0;
            continue;
        }

        $threshold = max(5.0, $issued * 0.005);
        $hasErrors = $errored > $threshold;

        if (!$hasErrors) {
            $candidate = null;
            $streak = 0;
            continue;
        }

        if ($candidate === null) {
            $candidate = [
                'second' => $second,
                'issuedRps' => $issued,
                'erroredRps' => $errored,
            ];
        }

        $streak++;

        if ($streak >= $requiredConsecutiveSeconds) {
            return [
                'reached' => true,
                'second' => $candidate['second'],
                'issuedRps' => $candidate['issuedRps'],
                'erroredRps' => $candidate['erroredRps'],
            ];
        }
    }

    return [
        'reached' => false,
    ];
}

function indexSeriesBySecond(array $points): array
{
    $indexed = [];

    foreach ($points as $point) {
        if (!is_array($point) || !isset($point['x'])) {
            continue;
        }

        $indexed[(int) $point['x']] = (float) ($point['y'] ?? 0.0);
    }

    return $indexed;
}

function buildRunLabel(string $runDirectory, array $metadata): string
{
    $benchmarkName = trim((string) ($metadata['BENCH_NAME'] ?? ''));
    if ($benchmarkName !== '') {
        return $benchmarkName;
    }

    return basename($runDirectory);
}

function parseCompactK6Timeseries(string $file): array
{
    $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    if (($payload['schema'] ?? '') !== 'compact-k6-timeseries-v1') {
        fwrite(STDERR, "Unsupported compact k6 timeseries schema: $file\n");
        exit(1);
    }

    $series = $payload['series'] ?? [];
    $requiredSeries = [
        'requestsPerSecond',
        'issuedRequestsPerSecond',
        'successfulResponsesPerSecond',
        'avgLatencyMs',
        'p95LatencyMs',
        'droppedPerSecond',
        'virtualUsers',
    ];

    foreach ($requiredSeries as $seriesName) {
        if (!array_key_exists($seriesName, $series)) {
            fwrite(STDERR, "Required k6 series missing in $file: $seriesName\n");
            exit(1);
        }
    }

    return $series;
}

function deriveErroredRequestsSeries(array $requestsPerSecond, array $successfulResponsesPerSecond): array
{
    if ($requestsPerSecond === []) {
        return [];
    }

    $successfulBySecond = [];
    foreach ($successfulResponsesPerSecond as $point) {
        if (!is_array($point) || !isset($point['x'])) {
            continue;
        }

        $successfulBySecond[(int) $point['x']] = (float) ($point['y'] ?? 0.0);
    }

    $erroredRequests = [];
    foreach ($requestsPerSecond as $point) {
        if (!is_array($point) || !isset($point['x'])) {
            continue;
        }

        $second = (int) $point['x'];
        $completed = (float) ($point['y'] ?? 0.0);
        $successful = $successfulBySecond[$second] ?? 0.0;
        $erroredRequests[] = [
            'x' => $second,
            'y' => max(0.0, round($completed - $successful, 4)),
        ];
    }

    return $erroredRequests;
}

function buildTargetRequestsPerSecondSeries(array $metadata): array
{
    $benchScript = (string) ($metadata['BENCH_SCRIPT'] ?? '');
    $timeUnitSeconds = max(1, parseDurationSeconds((string) ($metadata['TIME_UNIT'] ?? '1s')));

    if ($benchScript === 'bench-ramp.js') {
        return buildRampTargetRequestsPerSecondSeries(
            (int) ($metadata['START_RATE'] ?? 0),
            (string) ($metadata['STAGES'] ?? '[]'),
            $timeUnitSeconds,
        );
    }

    $rate = (int) ($metadata['RATE'] ?? 0);
    $durationSeconds = max(0, parseDurationSeconds((string) ($metadata['DURATION'] ?? '0s')));
    $ratePerSecond = (float) ($rate / $timeUnitSeconds);

    return [
        ['x' => 0, 'y' => $ratePerSecond],
        ['x' => $durationSeconds, 'y' => $ratePerSecond],
    ];
}

function buildRampTargetRequestsPerSecondSeries(int $startRate, string $stagesJson, int $timeUnitSeconds): array
{
    $stages = json_decode($stagesJson, true);
    if (!is_array($stages)) {
        return [];
    }

    $points = [
        ['x' => 0, 'y' => (float) ($startRate / $timeUnitSeconds)],
    ];

    $currentRate = (float) $startRate;
    $currentSecond = 0;

    foreach ($stages as $stage) {
        if (!is_array($stage)) {
            continue;
        }

        $targetRate = (float) ($stage['target'] ?? $currentRate);
        $durationSeconds = max(1, parseDurationSeconds((string) ($stage['duration'] ?? '0s')));

        for ($offset = 1; $offset <= $durationSeconds; $offset++) {
            $progress = $offset / $durationSeconds;
            $interpolatedRate = $currentRate + (($targetRate - $currentRate) * $progress);
            $points[] = [
                'x' => $currentSecond + $offset,
                'y' => $interpolatedRate / $timeUnitSeconds,
            ];
        }

        $currentSecond += $durationSeconds;
        $currentRate = $targetRate;
    }

    return $points;
}

function parseDurationSeconds(string $value): int
{
    $normalized = trim($value);
    if ($normalized === '') {
        return 0;
    }

    if (!preg_match('/^([0-9]+)(ms|s|m|h)$/', $normalized, $matches)) {
        return 0;
    }

    $amount = (int) $matches[1];
    $unit = $matches[2];

    return match ($unit) {
        'ms' => max(1, (int) round($amount / 1000)),
        's' => $amount,
        'm' => $amount * 60,
        'h' => $amount * 3600,
        default => 0,
    };
}

function parseDockerStats(string $dockerStatsFile): array
{
    $file = new SplFileObject($dockerStatsFile, 'rb');
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

    $header = null;
    $firstTimestamp = null;
    $services = [];

    foreach ($file as $row) {
        if (!is_array($row) || $row === [null]) {
            continue;
        }

        if ($header === null) {
            $header = $row;
            continue;
        }

        $record = @array_combine($header, $row);
        if ($record === false) {
            continue;
        }

        $timestamp = strtotime((string) ($record['timestamp'] ?? ''));
        if ($timestamp === false) {
            continue;
        }

        if ($firstTimestamp === null) {
            $firstTimestamp = $timestamp;
        }

        $service = (string) ($record['service'] ?? 'unknown');
        $second = max(0, (int) floor($timestamp - $firstTimestamp));

        $services[$service]['cpuPercent'][] = [
            'x' => $second,
            'y' => (float) ($record['cpu_percent'] ?? 0.0),
        ];
        $services[$service]['memoryMiB'][] = [
            'x' => $second,
            'y' => round(((float) ($record['memory_usage_bytes'] ?? 0.0)) / 1024 / 1024, 4),
        ];
    }

    return $services;
}

function renderHtmlReport(array $runs): string
{
    $palette = [
        '#d1495b',
        '#00798c',
        '#edae49',
        '#30638e',
        '#66a182',
        '#9c6644',
        '#6a4c93',
        '#ef476f',
    ];

    $chartDefinitions = [
        [
            'id' => 'requests-per-second',
            'title' => 'Request Rate Per Second',
            'series' => array_merge(
                collectRunSeries($runs, 'issuedRequestsPerSecond', $palette, ' issued', false, [2, 4]),
                collectRunSeries($runs, 'successfulResponsesPerSecond', $palette, ' successful'),
                collectRunSeries($runs, 'erroredRequestsPerSecond', $palette, ' errored', false, [8, 4]),
            ),
            'xAxisTargetSeries' => collectRunSeries($runs, 'targetRequestsPerSecond', $palette),
            'styleLegend' => [
                ['label' => 'successful', 'dash' => []],
                ['label' => 'issued', 'dash' => [2, 4]],
                ['label' => 'errored', 'dash' => [8, 4]],
            ],
            'format' => 'integer',
            'smoothingWindow' => 5,
        ],
        [
            'id' => 'latency',
            'title' => 'Latency (ms)',
            'series' => array_merge(
                collectRunSeries($runs, 'avgLatencyMs', $palette, ' avg'),
                collectRunSeries($runs, 'p95LatencyMs', $palette, ' p95', false, [8, 4]),
            ),
            'xAxisTargetSeries' => collectRunSeries($runs, 'targetRequestsPerSecond', $palette),
            'styleLegend' => [
                ['label' => 'avg', 'dash' => []],
                ['label' => 'p95', 'dash' => [8, 4]],
            ],
            'format' => 'milliseconds-integer',
            'smoothingWindow' => 5,
        ],
        [
            'id' => 'dropped-iterations',
            'title' => 'Load Generator Dropped Iterations Per Second',
            'series' => collectRunSeries($runs, 'droppedPerSecond', $palette),
            'xAxisTargetSeries' => collectRunSeries($runs, 'targetRequestsPerSecond', $palette),
            'format' => 'integer',
        ],
        [
            'id' => 'virtual-users',
            'title' => 'Virtual Users',
            'series' => collectRunSeries($runs, 'virtualUsers', $palette),
            'xAxisTargetSeries' => collectRunSeries($runs, 'targetRequestsPerSecond', $palette),
            'format' => 'integer',
        ],
    ];

    foreach (collectDockerServices($runs) as $serviceName) {
        $chartDefinitions[] = [
            'id' => 'cpu-' . $serviceName,
            'title' => strtoupper($serviceName) . ' CPU (% of one core, 100% = 1 core)',
            'series' => collectDockerSeries($runs, $serviceName, 'cpuPercent', $palette),
            'xAxisTargetSeries' => collectRunSeries($runs, 'targetRequestsPerSecond', $palette),
            'format' => 'percent-integer',
            'smoothingWindow' => 5,
        ];
        $chartDefinitions[] = [
            'id' => 'memory-' . $serviceName,
            'title' => strtoupper($serviceName) . ' Memory (MiB)',
            'series' => collectDockerSeries($runs, $serviceName, 'memoryMiB', $palette),
            'xAxisTargetSeries' => collectRunSeries($runs, 'targetRequestsPerSecond', $palette),
            'format' => 'integer',
        ];
    }

    $reportData = [
        'charts' => $chartDefinitions,
    ];

    $summaryRows = '';
    foreach ($runs as $run) {
        $summary = $run['summary'];
        $summaryRows .= '<tr>'
            . '<td>' . h($run['label']) . '</td>'
            . '<td>' . h($run['metadata']['TARGET_PATH'] ?? '') . '</td>'
            . '<td>' . h($run['metadata']['BENCH_SCRIPT'] ?? '') . '</td>'
            . '<td>' . h(formatRpsCap($summary['rpsCap'] ?? ['reached' => false])) . '</td>'
            . '<td>' . h(formatErrorsStart($summary['errorsStart'] ?? ['reached' => false])) . '</td>'
            . '<td>' . formatPercent($summary['httpReqFailedValue'] * 100) . '</td>'
            . '<td>' . formatMilliseconds($summary['latencyAvgMs']) . '</td>'
            . '<td>' . formatMilliseconds($summary['latencyP95Ms']) . '</td>'
            . '</tr>';
    }

    $metadataBlocks = '';
    foreach ($runs as $run) {
        $items = '';
        foreach ($run['metadata'] as $key => $value) {
            $items .= '<tr><th>' . h($key) . '</th><td>' . h($value) . '</td></tr>';
        }

        $metadataBlocks .= <<<HTML
<section class="panel">
  <h2>{$run['label']}</h2>
  <p class="path">{$run['directory']}</p>
  <table class="metadata-table">
    {$items}
  </table>
</section>
HTML;
    }

    $chartSections = '';
    foreach ($chartDefinitions as $chart) {
        $chartId = h($chart['id']);
        $chartTitle = h($chart['title']);
        $styleLegendHtml = '';
        $styleLegendItems = $chart['styleLegend'] ?? [];
        if (hasAnyRpsCap($runs)) {
            $styleLegendItems[] = ['label' => 'RPS cap', 'type' => 'cap-marker'];
        }
        if (hasAnyErrorsStart($runs)) {
            $styleLegendItems[] = ['label' => 'Errors start', 'type' => 'error-marker'];
        }
        if (($chart['xAxisTargetSeries'] ?? []) !== []) {
            $styleLegendItems[] = ['label' => 'Target RPS', 'type' => 'target'];
        }

        if ($styleLegendItems !== []) {
            $styleItems = '';
            foreach ($styleLegendItems as $styleItem) {
                if (($styleItem['type'] ?? '') === 'target') {
                    $label = h((string) ($styleItem['label'] ?? ''));
                    $styleItems .= <<<HTML
<span class="style-legend-item">
  <span class="style-legend-target">T</span>
  {$label}
</span>
HTML;
                    continue;
                }

                if (($styleItem['type'] ?? '') === 'cap-marker') {
                    $label = h((string) ($styleItem['label'] ?? ''));
                    $styleItems .= <<<HTML
<span class="style-legend-item">
  <svg class="style-legend-swatch" viewBox="0 0 24 12" aria-hidden="true">
    <circle cx="12" cy="6" r="4" fill="none" stroke="#1f2933" stroke-width="2"></circle>
  </svg>
  {$label}
</span>
HTML;
                    continue;
                }

                if (($styleItem['type'] ?? '') === 'error-marker') {
                    $label = h((string) ($styleItem['label'] ?? ''));
                    $styleItems .= <<<HTML
<span class="style-legend-item">
  <svg class="style-legend-swatch" viewBox="0 0 24 12" aria-hidden="true">
    <line x1="8" y1="2" x2="16" y2="10" stroke="#1f2933" stroke-width="2" stroke-linecap="round"></line>
    <line x1="16" y1="2" x2="8" y2="10" stroke="#1f2933" stroke-width="2" stroke-linecap="round"></line>
  </svg>
  {$label}
</span>
HTML;
                    continue;
                }

                $dash = ($styleItem['dash'] ?? []) !== [] ? implode(' ', $styleItem['dash']) : '';
                $label = h((string) ($styleItem['label'] ?? ''));
                $styleItems .= <<<HTML
<span class="style-legend-item">
  <svg class="style-legend-swatch" viewBox="0 0 24 8" aria-hidden="true">
    <line x1="0" y1="4" x2="24" y2="4" stroke="#1f2933" stroke-width="2" stroke-dasharray="{$dash}" stroke-linecap="round"></line>
  </svg>
  {$label}
</span>
HTML;
            }

            $styleLegendHtml = <<<HTML
  <div class="style-legend">{$styleItems}</div>
HTML;
        }
        $chartSections .= <<<HTML
<section class="panel chart-panel">
  <div class="chart-header">
    <h2>{$chartTitle}</h2>
{$styleLegendHtml}
  </div>
  <div class="chart-wrap">
    <canvas id="chart-{$chartId}" class="chart" width="1400" height="380"></canvas>
  </div>
  <div id="legend-{$chartId}" class="legend"></div>
</section>
HTML;
    }

    $reportJson = json_encode($reportData, JSON_THROW_ON_ERROR);
    $generatedAt = gmdate('Y-m-d H:i:s') . ' UTC';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Benchmark Report</title>
  <style>
    :root {
      --bg: #f4f1ea;
      --panel: #fffdf8;
      --text: #1f2933;
      --muted: #5c6b73;
      --line: #d6d0c4;
      --accent: #b56576;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Iowan Old Style", "Palatino Linotype", Georgia, serif;
      background:
        radial-gradient(circle at top left, rgba(181, 101, 118, 0.12), transparent 30%),
        linear-gradient(180deg, #f8f4ec 0%, var(--bg) 100%);
      color: var(--text);
    }
    main {
      max-width: 1280px;
      margin: 0 auto;
      padding: 28px 24px 48px;
    }
    h1, h2 {
      margin: 0 0 12px;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    h1 {
      font-size: 2.2rem;
    }
    h2 {
      font-size: 1.25rem;
    }
    p {
      margin: 0 0 8px;
      color: var(--muted);
      line-height: 1.45;
    }
    .path {
      font-family: "SFMono-Regular", Menlo, Consolas, monospace;
      font-size: 0.9rem;
      word-break: break-all;
    }
    .grid {
      display: grid;
      gap: 20px;
    }
    .panel {
      background: var(--panel);
      border: 1px solid rgba(31, 41, 51, 0.08);
      border-radius: 18px;
      padding: 20px 22px;
      box-shadow: 0 18px 42px rgba(31, 41, 51, 0.06);
    }
    .summary-table, .metadata-table {
      width: 100%;
      border-collapse: collapse;
    }
    .summary-table th,
    .summary-table td,
    .metadata-table th,
    .metadata-table td {
      border-top: 1px solid var(--line);
      padding: 10px 8px;
      text-align: left;
      vertical-align: top;
      font-size: 0.95rem;
    }
    .summary-table th,
    .metadata-table th {
      font-family: "SFMono-Regular", Menlo, Consolas, monospace;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
      width: 15%;
    }
    .chart-panel {
      overflow: hidden;
    }
    .chart-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 12px;
    }
    .chart-header h2 {
      margin-bottom: 0;
    }
    .chart-wrap {
      width: 100%;
      overflow-x: hidden;
    }
    .chart {
      width: 100%;
      height: auto;
      display: block;
      background: linear-gradient(180deg, rgba(255,255,255,0.55), rgba(246,241,233,0.95));
      border-radius: 14px;
      border: 1px solid rgba(31, 41, 51, 0.08);
    }
    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 18px;
      margin-top: 12px;
      color: var(--muted);
      font-size: 0.92rem;
    }
    .style-legend {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 12px 18px;
      color: var(--muted);
      font-size: 0.92rem;
    }
    .legend-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .style-legend-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .legend-swatch {
      width: 12px;
      height: 12px;
      display: inline-block;
      border-radius: 999px;
    }
    .style-legend-swatch {
      width: 24px;
      height: 8px;
      display: inline-block;
    }
    .style-legend-target {
      font-family: "SFMono-Regular", Menlo, Consolas, monospace;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--text);
    }
    .meta-grid {
      display: grid;
      gap: 20px;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    }
    @media (max-width: 720px) {
      main { padding: 20px 14px 36px; }
      h1 { font-size: 1.8rem; }
      .panel { padding: 16px; }
      .chart-header { align-items: flex-start; flex-direction: column; }
      .style-legend { justify-content: flex-start; }
      .summary-table th, .summary-table td, .metadata-table th, .metadata-table td { padding: 8px 6px; }
    }
  </style>
</head>
<body>
  <main class="grid">
    <section class="panel">
      <h1>Benchmark Report</h1>
      <p>Generated {$generatedAt}. This report combines k6 summary and time-series data with Docker CPU and memory samples.</p>
      <p>Request rate, latency, and CPU charts use a 5s moving average over faint raw samples. RPS cap and Errors start still use raw data.</p>
    </section>

    <section class="panel">
      <h2>Run Summary</h2>
      <table class="summary-table">
        <thead>
          <tr>
            <th>Run</th>
            <th>Path</th>
            <th>Script</th>
            <th>RPS Cap</th>
            <th>Errors Start</th>
            <th>Failure Rate</th>
            <th>Avg Latency</th>
            <th>P95 Latency</th>
          </tr>
        </thead>
        <tbody>
          {$summaryRows}
        </tbody>
      </table>
    </section>

    {$chartSections}

    <section class="meta-grid">
      {$metadataBlocks}
    </section>
  </main>

  <script>
    const reportData = {$reportJson};
    const X_AXIS_TICK_DIVISIONS = 10;

    function getGlobalXAxisDomain() {
      const xValues = [];

      reportData.charts.forEach((chart) => {
        (chart.series || []).forEach((item) => {
          (item.points || []).forEach((point) => {
            xValues.push(point.x);
          });
        });

        (chart.xAxisTargetSeries || []).forEach((item) => {
          (item.points || []).forEach((point) => {
            xValues.push(point.x);
          });
        });
      });

      if (xValues.length === 0) {
        return { min: 0, max: 1 };
      }

      return {
        min: Math.min(...xValues),
        max: Math.max(...xValues),
      };
    }

    const globalXAxisDomain = getGlobalXAxisDomain();

    function formatValue(value, format) {
      const fixed = (number, digits) => Number(number).toFixed(digits);
      const trimZeros = (text) => text.replace(/\.?0+$/, '');

      if (format === 'integer') {
        return String(Math.round(value));
      }
      if (format === 'percent') {
        return fixed(value, 2) + '%';
      }
      if (format === 'percent-integer') {
        return Math.round(value) + '%';
      }
      if (format === 'milliseconds-integer') {
        return Math.round(value) + ' ms';
      }
      if (format === 'milliseconds') {
        return fixed(value, 2) + ' ms';
      }
      return trimZeros(fixed(value, 2));
    }

    function formatElapsedSeconds(totalSeconds) {
      const seconds = Math.max(0, Math.round(totalSeconds));
      const minutes = Math.floor(seconds / 60);
      const remainderSeconds = seconds % 60;

      if (minutes === 0) {
        return seconds + 's';
      }

      if (remainderSeconds === 0) {
        return minutes + 'm';
      }

      return minutes + 'm ' + remainderSeconds + 's';
    }

    function sampledPointValue(points, xValue) {
      if (!points || points.length === 0) {
        return null;
      }

      const target = Math.max(0, Math.round(xValue));
      let currentValue = points[0].y;

      for (let index = 0; index < points.length; index++) {
        const point = points[index];
        if (point.x > target) {
          break;
        }
        currentValue = point.y;
      }

      return currentValue;
    }

    function movingAverageSeries(points, windowSize) {
      if (!points || points.length === 0 || windowSize <= 1) {
        return points || [];
      }

      const result = [];
      let sum = 0;
      const values = [];

      points.forEach((point, index) => {
        const value = Number(point.y || 0);
        values.push(value);
        sum += value;

        if (values.length > windowSize) {
          sum -= values.shift();
        }

        result.push({
          x: point.x,
          y: sum / values.length,
        });
      });

      return result;
    }

    function formatTargetTickLabel(chart, xValue) {
      const targetSeries = (chart.xAxisTargetSeries || []).filter((item) => item.points.length > 0);
      if (targetSeries.length === 0) {
        return '';
      }

      const values = targetSeries.map((item) => sampledPointValue(item.points, xValue));
      if (values.some((value) => value === null)) {
        return '';
      }

      const roundedValues = values.map((value) => Math.round(value));
      const firstValue = roundedValues[0];
      const sameAcrossRuns = roundedValues.every((value) => value === firstValue);

      if (!sameAcrossRuns) {
        return '';
      }

      return 'T ' + String(firstValue);
    }

    function drawChart(canvasId, legendId, chart) {
      const canvas = document.getElementById(canvasId);
      const legend = document.getElementById(legendId);
      if (!canvas || !legend) {
        return;
      }

      const series = chart.series.filter((item) => item.points.length > 0);
      if (series.length === 0) {
        legend.textContent = 'No data available.';
        return;
      }

      const smoothingWindow = Math.max(0, Math.round(Number(chart.smoothingWindow || 0)));
      const displaySeries = series.map((item) => ({
        ...item,
        displayPoints: smoothingWindow > 1 ? movingAverageSeries(item.points, smoothingWindow) : item.points,
      }));

      const dpr = window.devicePixelRatio || 1;
      const cssWidth = canvas.clientWidth || canvas.width;
      const cssHeight = canvas.clientHeight || canvas.height;
      canvas.width = cssWidth * dpr;
      canvas.height = cssHeight * dpr;

      const ctx = canvas.getContext('2d');
      ctx.scale(dpr, dpr);
      ctx.clearRect(0, 0, cssWidth, cssHeight);

      const margin = { top: 18, right: 24, bottom: 52, left: 64 };
      const width = cssWidth - margin.left - margin.right;
      const height = cssHeight - margin.top - margin.bottom;

      const yValues = displaySeries.flatMap((item) => item.displayPoints.map((point) => point.y));
      const xMin = globalXAxisDomain.min;
      const xMax = globalXAxisDomain.max;
      const rawYMax = Math.max(...yValues);
      const yMax = rawYMax > 0 ? rawYMax * 1.08 : 1;

      ctx.strokeStyle = 'rgba(31, 41, 51, 0.14)';
      ctx.lineWidth = 1;
      ctx.fillStyle = '#5c6b73';
      ctx.font = '12px Menlo, Consolas, monospace';
      ctx.textBaseline = 'middle';
      ctx.textAlign = 'right';

      for (let i = 0; i <= 5; i++) {
        const y = margin.top + (height / 5) * i;
        ctx.beginPath();
        ctx.moveTo(margin.left, y);
        ctx.lineTo(margin.left + width, y);
        ctx.stroke();

        const value = yMax - (yMax / 5) * i;
        ctx.fillText(formatValue(value, chart.format), margin.left - 10, y);
      }

      ctx.textBaseline = 'top';
      ctx.textAlign = 'center';
      ctx.font = '12px Menlo, Consolas, monospace';
      for (let i = 0; i <= X_AXIS_TICK_DIVISIONS; i++) {
        const x = margin.left + (width / X_AXIS_TICK_DIVISIONS) * i;
        ctx.beginPath();
        ctx.moveTo(x, margin.top);
        ctx.lineTo(x, margin.top + height);
        ctx.stroke();

        const value = xMin + ((xMax - xMin) / X_AXIS_TICK_DIVISIONS) * i;
        ctx.fillText(formatElapsedSeconds(value), x, margin.top + height + 10);

        const targetLabel = formatTargetTickLabel(chart, value);
        if (targetLabel !== '') {
          ctx.fillText(targetLabel, x, margin.top + height + 24);
        }
      }

      ctx.strokeStyle = '#1f2933';
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      ctx.moveTo(margin.left, margin.top);
      ctx.lineTo(margin.left, margin.top + height);
      ctx.lineTo(margin.left + width, margin.top + height);
      ctx.stroke();

      const toCanvasX = (x) => margin.left + ((x - xMin) / Math.max(1, xMax - xMin)) * width;
      const toCanvasY = (y) => margin.top + height - (y / yMax) * height;

      displaySeries.forEach((item) => {
        if (smoothingWindow > 1) {
          drawLine(ctx, toCanvasX, toCanvasY, item.points, item.color, item.dash, 1.2, 0.22);
        }

        drawLine(ctx, toCanvasX, toCanvasY, item.displayPoints, item.color, item.dash, 2.2, 1);

        if (chart.showPoints || item.showPoints) {
          ctx.fillStyle = item.color;
          item.displayPoints.forEach((point) => {
            const x = toCanvasX(point.x);
            const y = toCanvasY(point.y);
            ctx.beginPath();
            ctx.arc(x, y, 2.2, 0, Math.PI * 2);
            ctx.fill();
          });
        }
      });

      displaySeries.forEach((item) => {
        drawMarker(ctx, toCanvasX, toCanvasY, item.displayPoints, item.capSecond, item.color, 'circle');
        drawMarker(ctx, toCanvasX, toCanvasY, item.displayPoints, item.errorStartSecond, item.color, 'cross');
      });

      const runs = [];
      const seenRuns = new Set();
      series.forEach((item) => {
        const key = (item.runLabel || item.label) + '|' + item.color;
        if (seenRuns.has(key)) {
          return;
        }
        seenRuns.add(key);
        runs.push({
          label: item.runLabel || item.label,
          color: item.color,
        });
      });

      legend.innerHTML = runs.map((item) => (
        '<span class="legend-item"><span class="legend-swatch" style="background:' + item.color + '"></span>' +
        item.label +
        '</span>'
      )).join('');
    }

    function render() {
      reportData.charts.forEach((chart) => {
        drawChart('chart-' + chart.id, 'legend-' + chart.id, chart);
      });
    }

    function drawLine(ctx, toCanvasX, toCanvasY, points, color, dash, lineWidth, alpha) {
      ctx.save();
      ctx.beginPath();
      ctx.strokeStyle = color;
      ctx.lineWidth = lineWidth;
      ctx.globalAlpha = alpha;
      if (dash && dash.length > 0) {
        ctx.setLineDash(dash);
      } else {
        ctx.setLineDash([]);
      }

      points.forEach((point, index) => {
        const x = toCanvasX(point.x);
        const y = toCanvasY(point.y);
        if (index === 0) {
          ctx.moveTo(x, y);
        } else {
          ctx.lineTo(x, y);
        }
      });

      ctx.stroke();
      ctx.restore();
    }

    function drawMarker(ctx, toCanvasX, toCanvasY, points, second, color, markerType) {
      if (second === null || second === undefined) {
        return;
      }

      const yValue = sampledPointValue(points, second);
      if (yValue === null) {
        return;
      }

      const x = toCanvasX(second);
      const y = toCanvasY(yValue);

      ctx.save();
      ctx.setLineDash([]);
      ctx.lineCap = 'round';

      if (markerType === 'circle') {
        ctx.strokeStyle = '#fffdf8';
        ctx.lineWidth = 5;
        ctx.beginPath();
        ctx.arc(x, y, 5, 0, Math.PI * 2);
        ctx.stroke();

        ctx.strokeStyle = color;
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.arc(x, y, 5, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
        return;
      }

      ctx.strokeStyle = '#fffdf8';
      ctx.lineWidth = 5;
      ctx.beginPath();
      ctx.moveTo(x - 5, y - 5);
      ctx.lineTo(x + 5, y + 5);
      ctx.moveTo(x + 5, y - 5);
      ctx.lineTo(x - 5, y + 5);
      ctx.stroke();

      ctx.strokeStyle = color;
      ctx.lineWidth = 2.5;
      ctx.beginPath();
      ctx.moveTo(x - 5, y - 5);
      ctx.lineTo(x + 5, y + 5);
      ctx.moveTo(x + 5, y - 5);
      ctx.lineTo(x - 5, y + 5);
      ctx.stroke();
      ctx.restore();
    }

    window.addEventListener('resize', render);
    render();
  </script>
</body>
</html>
HTML;
}

function collectRunSeries(
    array $runs,
    string $metric,
    array $palette,
    string $labelSuffix = '',
    bool $showPoints = false,
    array $dash = [],
): array
{
    $series = [];

    foreach ($runs as $index => $run) {
        $points = $run['series'][$metric] ?? [];
        if ($points === []) {
            continue;
        }

        $series[] = [
            'label' => $run['label'] . $labelSuffix,
            'runLabel' => $run['label'],
            'color' => $palette[$index % count($palette)],
            'points' => $points,
            'showPoints' => $showPoints,
            'dash' => $dash,
            'capSecond' => (($run['summary']['rpsCap']['reached'] ?? false) === true)
                ? (int) ($run['summary']['rpsCap']['second'] ?? 0)
                : null,
            'errorStartSecond' => (($run['summary']['errorsStart']['reached'] ?? false) === true)
                ? (int) ($run['summary']['errorsStart']['second'] ?? 0)
                : null,
        ];
    }

    return $series;
}

function collectDockerServices(array $runs): array
{
    $services = [];

    foreach ($runs as $run) {
        foreach (array_keys($run['docker']) as $serviceName) {
            $services[$serviceName] = true;
        }
    }

    $serviceNames = array_keys($services);
    sort($serviceNames);

    return $serviceNames;
}

function collectDockerSeries(array $runs, string $serviceName, string $metric, array $palette): array
{
    $series = [];

    foreach ($runs as $index => $run) {
        $points = $run['docker'][$serviceName][$metric] ?? [];
        if ($points === []) {
            continue;
        }

        $series[] = [
            'label' => $run['label'],
            'runLabel' => $run['label'],
            'color' => $palette[$index % count($palette)],
            'points' => $points,
            'capSecond' => (($run['summary']['rpsCap']['reached'] ?? false) === true)
                ? (int) ($run['summary']['rpsCap']['second'] ?? 0)
                : null,
            'errorStartSecond' => (($run['summary']['errorsStart']['reached'] ?? false) === true)
                ? (int) ($run['summary']['errorsStart']['second'] ?? 0)
                : null,
        ];
    }

    return $series;
}

function hasAnyRpsCap(array $runs): bool
{
    foreach ($runs as $run) {
        if (($run['summary']['rpsCap']['reached'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

function hasAnyErrorsStart(array $runs): bool
{
    foreach ($runs as $run) {
        if (($run['summary']['errorsStart']['reached'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

function formatNumber(float|int $value): string
{
    return is_float($value) ? rtrim(rtrim(sprintf('%.2F', $value), '0'), '.') : (string) $value;
}

function formatInteger(int $value): string
{
    return (string) $value;
}

function formatPercent(float $value): string
{
    return sprintf('%.2F%%', $value);
}

function formatMilliseconds(float $value): string
{
    return sprintf('%.2F ms', $value);
}

function formatRpsCap(array $rpsCap): string
{
    if (($rpsCap['reached'] ?? false) !== true) {
        return 'Not reached';
    }

    $second = (int) ($rpsCap['second'] ?? 0);
    $issuedRps = (int) round((float) ($rpsCap['issuedRps'] ?? 0.0));
    $successfulRps = (int) round((float) ($rpsCap['successfulRps'] ?? 0.0));

    return sprintf(
        '%s @ %s issued / %s successful',
        formatElapsedSecondsForSummary($second),
        formatInteger($issuedRps),
        formatInteger($successfulRps),
    );
}

function formatErrorsStart(array $errorsStart): string
{
    if (($errorsStart['reached'] ?? false) !== true) {
        return 'Not reached';
    }

    $second = (int) ($errorsStart['second'] ?? 0);
    $erroredRps = (int) round((float) ($errorsStart['erroredRps'] ?? 0.0));

    return sprintf(
        '%s @ %s errored',
        formatElapsedSecondsForSummary($second),
        formatInteger($erroredRps),
    );
}

function formatElapsedSecondsForSummary(int $totalSeconds): string
{
    $totalSeconds = max(0, $totalSeconds);
    $hours = intdiv($totalSeconds, 3600);
    $minutes = intdiv($totalSeconds % 3600, 60);
    $seconds = $totalSeconds % 60;

    if ($hours > 0) {
        return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
    }

    if ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $seconds);
    }

    return sprintf('%ds', $seconds);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
