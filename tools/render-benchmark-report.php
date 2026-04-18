<?php

declare(strict_types=1);

const LATENCY_BIN_SIZE_MS = 5.0;
const LATENCY_MAX_MS = 1000.0;

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
    $outputFile = dirname(__DIR__) . '/runtime/benchmarks/report-' . gmdate('Ymd\THis\Z') . '.html';
    $runDirectories = [];

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

        $runDirectories[] = normalizePath($argument);
    }

    if ($runDirectories === []) {
        fwrite(
            STDERR,
            "Usage: php tools/render-benchmark-report.php [--output <report.html>] <run-dir> [<run-dir>...]\n",
        );
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
    $k6MetricsFile = $runDirectory . '/k6-metrics.json';

    foreach ([$metadataFile, $summaryFile, $dockerStatsFile] as $requiredFile) {
        if (!is_file($requiredFile)) {
            fwrite(STDERR, "Required benchmark file not found: $requiredFile\n");
            exit(1);
        }
    }

    if (!is_file($k6TimeseriesFile) && !is_file($k6MetricsFile)) {
        fwrite(STDERR, "Required benchmark file not found: {$runDirectory}/k6-timeseries.json or k6-metrics.json\n");
        exit(1);
    }

    $metadata = parseMetadata($metadataFile);
    $summary = json_decode((string) file_get_contents($summaryFile), true, 512, JSON_THROW_ON_ERROR);
    $k6Series = is_file($k6TimeseriesFile) ? parseCompactK6Timeseries($k6TimeseriesFile) : parseK6Metrics($k6MetricsFile);
    $dockerSeries = parseDockerStats($dockerStatsFile);

    $label = buildRunLabel($runDirectory, $metadata);

    return [
        'directory' => $runDirectory,
        'label' => $label,
        'metadata' => $metadata,
        'summary' => summarizeRun($summary),
        'series' => [
            'requestsPerSecond' => $k6Series['requestsPerSecond'],
            'failureRatePercent' => $k6Series['failureRatePercent'],
            'avgLatencyMs' => $k6Series['avgLatencyMs'],
            'p95LatencyMs' => $k6Series['p95LatencyMs'],
            'droppedPerSecond' => $k6Series['droppedPerSecond'],
            'virtualUsers' => $k6Series['virtualUsers'] ?? [],
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
        'httpRepsRate' => (float) ($metrics['http_reqs']['rate'] ?? 0.0),
        'httpReqsCount' => (int) ($metrics['http_reqs']['count'] ?? 0),
        'httpReqFailedValue' => (float) ($metrics['http_req_failed']['value'] ?? 0.0),
        'latencyAvgMs' => (float) ($metrics['http_req_duration']['avg'] ?? 0.0),
        'latencyP95Ms' => (float) ($metrics['http_req_duration']['p(95)'] ?? 0.0),
        'latencyP99Ms' => (float) ($metrics['http_req_duration']['p(99)'] ?? 0.0),
        'droppedIterationsCount' => (int) ($metrics['dropped_iterations']['count'] ?? 0),
        'vusMax' => (int) ($metrics['vus_max']['value'] ?? $metrics['vus_max']['max'] ?? 0),
    ];
}

function buildRunLabel(string $runDirectory, array $metadata): string
{
    $benchmarkName = trim((string) ($metadata['BENCH_NAME'] ?? ''));
    $targetName = humanizeTargetName($metadata['TARGET_NAME'] ?? basename($runDirectory));
    $mode = (($metadata['BENCH_SCRIPT'] ?? 'bench.js') === 'bench-ramp.js') ? 'Ramp' : 'Steady';
    $timestamp = formatRunTimestamp(basename($runDirectory));

    $parts = [];
    if ($benchmarkName !== '') {
        $parts[] = $benchmarkName;
    }
    $parts[] = $targetName;
    $parts[] = $mode;
    if ($timestamp !== null) {
        $parts[] = $timestamp;
    }

    return implode(' · ', $parts);
}

function humanizeTargetName(string $targetName): string
{
    return match ($targetName) {
        'home' => 'Home',
        'postgres-orders' => 'PostgreSQL Orders',
        default => ucwords(str_replace(['-', '_', '/'], ' ', $targetName)),
    };
}

function formatRunTimestamp(string $directoryName): ?string
{
    if (!preg_match('/^(\d{8}T\d{6}Z)-/', $directoryName, $matches)) {
        return null;
    }

    $timestamp = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $matches[1], new DateTimeZone('UTC'));

    return $timestamp?->format('Y-m-d H:i \U\T\C');
}

function parseCompactK6Timeseries(string $file): array
{
    $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    if (($payload['schema'] ?? '') !== 'compact-k6-timeseries-v1') {
        fwrite(STDERR, "Unsupported compact k6 timeseries schema: $file\n");
        exit(1);
    }

    return $payload['series'] ?? [];
}

function parseK6Metrics(string $k6MetricsFile): array
{
    $handle = fopen($k6MetricsFile, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open k6 metrics file: $k6MetricsFile\n");
        exit(1);
    }

    $requestBuckets = [];
    $failedRequestBuckets = [];
    $droppedBuckets = [];
    $latencyBuckets = [];
    $vusBuckets = [];
    $startTimestamp = null;

    while (($line = fgets($handle)) !== false) {
        if (
            !str_contains($line, '"type":"Point"')
            || (
                !str_contains($line, '"metric":"http_reqs"')
                && !str_contains($line, '"metric":"http_req_duration"')
                && !str_contains($line, '"metric":"dropped_iterations"')
                && !str_contains($line, '"metric":"vus"')
            )
        ) {
            continue;
        }

        $payload = json_decode($line, true);
        if (!is_array($payload) || ($payload['type'] ?? null) !== 'Point') {
            continue;
        }

        $metric = $payload['metric'] ?? '';
        $data = $payload['data'] ?? [];
        $time = isset($data['time']) ? strtotime((string) $data['time']) : false;

        if ($time === false) {
            continue;
        }

        if ($startTimestamp === null) {
            $startTimestamp = $time;
        }

        $bucket = max(0, (int) floor($time - $startTimestamp));

        if ($metric === 'http_reqs') {
            $requestBuckets[$bucket] = ($requestBuckets[$bucket] ?? 0) + (int) ($data['value'] ?? 0);
            $expectedResponse = (string) ($data['tags']['expected_response'] ?? 'true');
            if ($expectedResponse !== 'true') {
                $failedRequestBuckets[$bucket] = ($failedRequestBuckets[$bucket] ?? 0) + 1;
            }
            continue;
        }

        if ($metric === 'dropped_iterations') {
            $droppedBuckets[$bucket] = ($droppedBuckets[$bucket] ?? 0) + (int) ($data['value'] ?? 0);
            continue;
        }

        if ($metric === 'vus') {
            $vusBuckets[$bucket] = (float) ($data['value'] ?? 0.0);
            continue;
        }


        if ($metric === 'http_req_duration') {
            $value = (float) ($data['value'] ?? 0.0);
            if (!isset($latencyBuckets[$bucket])) {
                $latencyBuckets[$bucket] = [
                    'sum' => 0.0,
                    'count' => 0,
                    'histogram' => [],
                ];
            }

            $latencyBuckets[$bucket]['sum'] += $value;
            $latencyBuckets[$bucket]['count']++;

            $bin = (int) min(
                floor($value / LATENCY_BIN_SIZE_MS),
                floor(LATENCY_MAX_MS / LATENCY_BIN_SIZE_MS),
            );
            $latencyBuckets[$bucket]['histogram'][$bin] = ($latencyBuckets[$bucket]['histogram'][$bin] ?? 0) + 1;
        }
    }

    fclose($handle);

    ksort($requestBuckets);
    ksort($failedRequestBuckets);
    ksort($droppedBuckets);
    ksort($latencyBuckets);
    ksort($vusBuckets);

    $failureRateSeries = [];
    foreach ($requestBuckets as $second => $requestCount) {
        $failedCount = $failedRequestBuckets[$second] ?? 0;
        $failureRateSeries[] = [
            'x' => $second,
            'y' => $requestCount > 0 ? round(($failedCount / $requestCount) * 100, 4) : 0.0,
        ];
    }

    $avgLatencySeries = [];
    $p95LatencySeries = [];
    foreach ($latencyBuckets as $second => $stats) {
        $avgLatencySeries[] = [
            'x' => $second,
            'y' => $stats['count'] > 0 ? round($stats['sum'] / $stats['count'], 4) : 0.0,
        ];

        $p95LatencySeries[] = [
            'x' => $second,
            'y' => round(histogramPercentile($stats['histogram'], 0.95), 4),
        ];
    }

    return [
        'requestsPerSecond' => pointsFromBucketCounts($requestBuckets),
        'failureRatePercent' => $failureRateSeries,
        'avgLatencyMs' => $avgLatencySeries,
        'p95LatencyMs' => $p95LatencySeries,
        'droppedPerSecond' => pointsFromBucketCounts($droppedBuckets),
        'virtualUsers' => pointsFromBucketGauges($vusBuckets),
    ];
}

function histogramPercentile(array $histogram, float $percentile): float
{
    if ($histogram === []) {
        return 0.0;
    }

    ksort($histogram);
    $total = array_sum($histogram);
    $target = max(1, (int) ceil($total * $percentile));
    $seen = 0;

    foreach ($histogram as $bin => $count) {
        $seen += $count;
        if ($seen >= $target) {
            return ((int) $bin + 1) * LATENCY_BIN_SIZE_MS;
        }
    }

    return ((int) array_key_last($histogram) + 1) * LATENCY_BIN_SIZE_MS;
}

function pointsFromBucketCounts(array $buckets): array
{
    $points = [];
    foreach ($buckets as $second => $value) {
        $points[] = [
            'x' => (int) $second,
            'y' => (float) $value,
        ];
    }

    return $points;
}

function pointsFromBucketGauges(array $buckets): array
{
    $points = [];
    foreach ($buckets as $second => $value) {
        $points[] = [
            'x' => (int) $second,
            'y' => (float) $value,
        ];
    }

    return $points;
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
            'title' => 'Requests Per Second',
            'series' => collectRunSeries($runs, 'requestsPerSecond', $palette),
            'format' => 'integer',
        ],
        [
            'id' => 'failure-rate',
            'title' => 'Failure Rate (%)',
            'series' => collectRunSeries($runs, 'failureRatePercent', $palette),
            'format' => 'percent',
        ],
        [
            'id' => 'avg-latency',
            'title' => 'Average Latency (ms)',
            'series' => collectRunSeries($runs, 'avgLatencyMs', $palette),
            'format' => 'milliseconds-integer',
        ],
        [
            'id' => 'p95-latency',
            'title' => 'P95 Latency (ms)',
            'series' => collectRunSeries($runs, 'p95LatencyMs', $palette),
            'format' => 'milliseconds-integer',
        ],
        [
            'id' => 'dropped-iterations',
            'title' => 'Load Generator Dropped Iterations Per Second',
            'series' => collectRunSeries($runs, 'droppedPerSecond', $palette),
            'format' => 'integer',
        ],
        [
            'id' => 'virtual-users',
            'title' => 'Virtual Users',
            'series' => collectRunSeries($runs, 'virtualUsers', $palette, '', true),
            'format' => 'integer',
            'showPoints' => true,
        ],
    ];

    foreach (collectDockerServices($runs) as $serviceName) {
        $chartDefinitions[] = [
            'id' => 'cpu-' . $serviceName,
            'title' => strtoupper($serviceName) . ' CPU (% of one core, 100% = 1 core)',
            'series' => collectDockerSeries($runs, $serviceName, 'cpuPercent', $palette),
            'format' => 'percent-integer',
        ];
        $chartDefinitions[] = [
            'id' => 'memory-' . $serviceName,
            'title' => strtoupper($serviceName) . ' Memory (MiB)',
            'series' => collectDockerSeries($runs, $serviceName, 'memoryMiB', $palette),
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
            . '<td>' . formatInteger((int) round($summary['httpRepsRate'])) . '</td>'
            . '<td>' . formatInteger($summary['httpReqsCount']) . '</td>'
            . '<td>' . formatPercent($summary['httpReqFailedValue'] * 100) . '</td>'
            . '<td>' . formatMilliseconds($summary['latencyAvgMs']) . '</td>'
            . '<td>' . formatMilliseconds($summary['latencyP95Ms']) . '</td>'
            . '<td>' . formatInteger($summary['droppedIterationsCount']) . '</td>'
            . '<td>' . formatInteger($summary['vusMax']) . '</td>'
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
        $chartSections .= <<<HTML
<section class="panel chart-panel">
  <h2>{$chartTitle}</h2>
  <div class="chart-wrap">
    <canvas id="chart-{$chartId}" class="chart" width="1100" height="320"></canvas>
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
    .chart-wrap {
      width: 100%;
      overflow-x: auto;
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
    .legend-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .legend-swatch {
      width: 12px;
      height: 12px;
      border-radius: 999px;
      display: inline-block;
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
      .summary-table th, .summary-table td, .metadata-table th, .metadata-table td { padding: 8px 6px; }
    }
  </style>
</head>
<body>
  <main class="grid">
    <section class="panel">
      <h1>Benchmark Report</h1>
      <p>Generated {$generatedAt}. This report combines k6 summary and time-series data with Docker CPU and memory samples.</p>
    </section>

    <section class="panel">
      <h2>Run Summary</h2>
      <table class="summary-table">
        <thead>
          <tr>
            <th>Run</th>
            <th>Path</th>
            <th>Script</th>
            <th>RPS</th>
            <th>Requests</th>
            <th>Failure Rate</th>
            <th>Avg Latency</th>
            <th>P95 Latency</th>
            <th>Dropped</th>
            <th>Max VUs</th>
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

      const dpr = window.devicePixelRatio || 1;
      const cssWidth = canvas.clientWidth || canvas.width;
      const cssHeight = canvas.clientHeight || canvas.height;
      canvas.width = cssWidth * dpr;
      canvas.height = cssHeight * dpr;

      const ctx = canvas.getContext('2d');
      ctx.scale(dpr, dpr);
      ctx.clearRect(0, 0, cssWidth, cssHeight);

      const margin = { top: 18, right: 24, bottom: 34, left: 64 };
      const width = cssWidth - margin.left - margin.right;
      const height = cssHeight - margin.top - margin.bottom;

      const xValues = series.flatMap((item) => item.points.map((point) => point.x));
      const yValues = series.flatMap((item) => item.points.map((point) => point.y));
      const xMin = Math.min(...xValues);
      const xMax = Math.max(...xValues);
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
      for (let i = 0; i <= 5; i++) {
        const x = margin.left + (width / 5) * i;
        ctx.beginPath();
        ctx.moveTo(x, margin.top);
        ctx.lineTo(x, margin.top + height);
        ctx.stroke();

        const value = xMin + ((xMax - xMin) / 5) * i;
        ctx.fillText(formatElapsedSeconds(value), x, margin.top + height + 12);
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

      series.forEach((item) => {
        ctx.beginPath();
        ctx.strokeStyle = item.color;
        ctx.lineWidth = 2;
        if (item.dash && item.dash.length > 0) {
          ctx.setLineDash(item.dash);
        } else {
          ctx.setLineDash([]);
        }
        item.points.forEach((point, index) => {
          const x = toCanvasX(point.x);
          const y = toCanvasY(point.y);
          if (index === 0) {
            ctx.moveTo(x, y);
          } else {
            ctx.lineTo(x, y);
          }
        });
        ctx.stroke();
        ctx.setLineDash([]);

        if (chart.showPoints || item.showPoints) {
          ctx.fillStyle = item.color;
          item.points.forEach((point) => {
            const x = toCanvasX(point.x);
            const y = toCanvasY(point.y);
            ctx.beginPath();
            ctx.arc(x, y, 2.2, 0, Math.PI * 2);
            ctx.fill();
          });
        }
      });

      legend.innerHTML = series.map((item) => (
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
            'color' => $palette[$index % count($palette)],
            'points' => $points,
            'showPoints' => $showPoints,
            'dash' => $dash,
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
            'color' => $palette[$index % count($palette)],
            'points' => $points,
        ];
    }

    return $series;
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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
