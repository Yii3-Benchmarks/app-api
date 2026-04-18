<?php

declare(strict_types=1);

const LATENCY_BIN_SIZE_MS = 5.0;
const LATENCY_MAX_MS = 1000.0;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/compact-k6-metrics.php <input-raw.json> <output-timeseries.json>\n");
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2];

if (!is_file($inputFile)) {
    fwrite(STDERR, "Input file not found: $inputFile\n");
    exit(1);
}

$handle = fopen($inputFile, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open input file: $inputFile\n");
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
        'x' => (int) $second,
        'y' => $requestCount > 0 ? round(($failedCount / $requestCount) * 100, 4) : 0.0,
    ];
}

$avgLatencySeries = [];
$p95LatencySeries = [];
foreach ($latencyBuckets as $second => $stats) {
    $avgLatencySeries[] = [
        'x' => (int) $second,
        'y' => $stats['count'] > 0 ? round($stats['sum'] / $stats['count'], 4) : 0.0,
    ];
    $p95LatencySeries[] = [
        'x' => (int) $second,
        'y' => round(histogramPercentile($stats['histogram'], 0.95), 4),
    ];
}

$payload = [
    'schema' => 'compact-k6-timeseries-v1',
    'series' => [
        'requestsPerSecond' => pointsFromBucketCounts($requestBuckets),
        'failureRatePercent' => $failureRateSeries,
        'avgLatencyMs' => $avgLatencySeries,
        'p95LatencyMs' => $p95LatencySeries,
        'droppedPerSecond' => pointsFromBucketCounts($droppedBuckets),
        'virtualUsers' => pointsFromBucketGauges($vusBuckets),
    ],
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($outputFile, $json) === false) {
    fwrite(STDERR, "Unable to write output file: $outputFile\n");
    exit(1);
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
