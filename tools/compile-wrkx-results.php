<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/compile-wrkx-results.php <output-directory> <rate:duration:file>...\n");
    exit(1);
}

$outputDirectory = $argv[1];
$series = [
    'requestsPerSecond' => [],
    'issuedRequestsPerSecond' => [],
    'successfulResponsesPerSecond' => [],
    'avgLatencyMs' => [],
    'p95LatencyMs' => [],
    'droppedPerSecond' => [],
    'connections' => [],
];
$runs = [];
$second = 0;

foreach (array_slice($argv, 2) as $specification) {
    [$rate, $duration, $connections, $file] = explode(':', $specification, 4);
    $contents = (string) file_get_contents($file);
    if (!preg_match('/^WRKX_RESULT (\{.*\})$/m', $contents, $matches)) {
        fwrite(STDERR, "No wrkx result found in $file\n");
        exit(1);
    }

    $run = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    $durationSeconds = max(0.001, ((float) $run['durationUs']) / 1_000_000);
    $completedRps = ((int) $run['requests']) / $durationSeconds;
    $successfulRps = max(0, ((int) $run['requests'] - (int) $run['errors'])) / $durationSeconds;
    $point = static fn(float $value): array => ['x' => $second, 'y' => round($value, 4)];

    $series['requestsPerSecond'][] = $point($completedRps);
    $series['issuedRequestsPerSecond'][] = $point((float) $rate);
    $series['successfulResponsesPerSecond'][] = $point($successfulRps);
    $series['avgLatencyMs'][] = $point(((float) $run['latencyAvgUs']) / 1000);
    $series['p95LatencyMs'][] = $point(((float) $run['latencyP95Us']) / 1000);
    $series['droppedPerSecond'][] = $point(max(0, (float) $rate - $completedRps));
    $series['connections'][] = $point((float) $connections);
    $run['targetRate'] = (int) $rate;
    $runs[] = $run;
    $second += (int) $duration;
}

file_put_contents(
    $outputDirectory . '/wrkx-timeseries.json',
    json_encode(['schema' => 'compact-wrkx-timeseries-v1', 'series' => $series], JSON_THROW_ON_ERROR),
);
file_put_contents(
    $outputDirectory . '/summary.json',
    json_encode(['schema' => 'wrkx-summary-v1', 'runs' => $runs], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
