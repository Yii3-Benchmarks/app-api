<?php

declare(strict_types=1);

const LATENCY_BIN_SIZE_MS = 5.0;
const LATENCY_MAX_MS = 1000.0;
const LATENCY_MAX_BIN = 200;

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
$issuedRequestBuckets = [];
$failedRequestBuckets = [];
$droppedBuckets = [];
$latencySums = [];
$latencyCounts = [];
$latencyHistograms = [];
$latencyMaxBins = [];
$vusBuckets = [];
$startTimestamp = null;

while (($line = fgets($handle)) !== false) {
    if (!str_contains($line, '"type":"Point"')) {
        continue;
    }

    $metric = detectTrackedMetric($line);
    if ($metric === null) {
        continue;
    }

    $payload = json_decode($line, true);
    if (!is_array($payload)) {
        continue;
    }

    $data = $payload['data'] ?? null;
    if (!is_array($data)) {
        continue;
    }

    $timestamp = $data['time'] ?? null;
    if (!is_string($timestamp)) {
        continue;
    }

    $bucket = extractSecondBucket($timestamp, $startTimestamp);

    if ($metric === 'http_reqs') {
        $requestBuckets[$bucket] = ($requestBuckets[$bucket] ?? 0) + (int) ($data['value'] ?? 0);
        if (($data['tags']['expected_response'] ?? 'true') !== 'true') {
            $failedRequestBuckets[$bucket] = ($failedRequestBuckets[$bucket] ?? 0) + 1;
        }
        continue;
    }

    if ($metric === 'requests_issued') {
        $issuedRequestBuckets[$bucket] = ($issuedRequestBuckets[$bucket] ?? 0) + (int) ($data['value'] ?? 0);
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
        $latencySums[$bucket] = ($latencySums[$bucket] ?? 0.0) + $value;
        $latencyCounts[$bucket] = ($latencyCounts[$bucket] ?? 0) + 1;

        $bin = min((int) floor($value / LATENCY_BIN_SIZE_MS), LATENCY_MAX_BIN);
        $histogram = $latencyHistograms[$bucket] ?? [];
        $histogram[$bin] = ($histogram[$bin] ?? 0) + 1;
        $latencyHistograms[$bucket] = $histogram;
        $latencyMaxBins[$bucket] = max($latencyMaxBins[$bucket] ?? 0, $bin);
    }
}

fclose($handle);

ksort($requestBuckets);
ksort($issuedRequestBuckets);
ksort($droppedBuckets);
ksort($latencySums);
ksort($vusBuckets);

$failureRateSeries = [];
$successfulResponsesSeries = [];
foreach ($requestBuckets as $second => $requestCount) {
    $failedCount = $failedRequestBuckets[$second] ?? 0;
    $successfulResponsesSeries[] = [
        'x' => (int) $second,
        'y' => (float) max(0, $requestCount - $failedCount),
    ];
    $failureRateSeries[] = [
        'x' => (int) $second,
        'y' => $requestCount > 0 ? round(($failedCount / $requestCount) * 100, 4) : 0.0,
    ];
}

$avgLatencySeries = [];
$p95LatencySeries = [];
foreach ($latencySums as $second => $sum) {
    $count = $latencyCounts[$second] ?? 0;
    $avgLatencySeries[] = [
        'x' => (int) $second,
        'y' => $count > 0 ? round($sum / $count, 4) : 0.0,
    ];
    $p95LatencySeries[] = [
        'x' => (int) $second,
        'y' => round(
            histogramPercentile(
                $latencyHistograms[$second] ?? [],
                $latencyMaxBins[$second] ?? 0,
                $count,
                0.95,
            ),
            4,
        ),
    ];
}

$payload = [
    'schema' => 'compact-k6-timeseries-v1',
    'series' => [
        'requestsPerSecond' => pointsFromBuckets($requestBuckets),
        'issuedRequestsPerSecond' => pointsFromBuckets($issuedRequestBuckets),
        'successfulResponsesPerSecond' => $successfulResponsesSeries,
        'failureRatePercent' => $failureRateSeries,
        'avgLatencyMs' => $avgLatencySeries,
        'p95LatencyMs' => $p95LatencySeries,
        'droppedPerSecond' => pointsFromBuckets($droppedBuckets),
        'virtualUsers' => pointsFromBuckets($vusBuckets),
    ],
];

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($outputFile, $json) === false) {
    fwrite(STDERR, "Unable to write output file: $outputFile\n");
    exit(1);
}

function detectTrackedMetric(string $line): ?string
{
    if (str_contains($line, '"metric":"http_req_duration"')) {
        return 'http_req_duration';
    }
    if (str_contains($line, '"metric":"http_reqs"')) {
        return 'http_reqs';
    }
    if (str_contains($line, '"metric":"requests_issued"')) {
        return 'requests_issued';
    }
    if (str_contains($line, '"metric":"dropped_iterations"')) {
        return 'dropped_iterations';
    }
    if (str_contains($line, '"metric":"vus"')) {
        return 'vus';
    }

    return null;
}

function extractSecondBucket(
    string $timestamp,
    ?int &$startTimestamp,
): int {
    $currentTimestamp = timestampFromIso8601($timestamp);

    if ($startTimestamp === null) {
        $startTimestamp = $currentTimestamp;
    }

    return max(0, $currentTimestamp - $startTimestamp);
}

function timestampFromIso8601(string $timestamp): int
{
    if (
        strlen($timestamp) < 19
        || $timestamp[4] !== '-'
        || $timestamp[7] !== '-'
        || $timestamp[10] !== 'T'
        || $timestamp[13] !== ':'
        || $timestamp[16] !== ':'
    ) {
        $unix = strtotime($timestamp);
        if ($unix === false) {
            return 0;
        }

        return (int) $unix;
    }

    $year = fourDigitAt($timestamp, 0);
    $month = twoDigitAt($timestamp, 5);
    $day = twoDigitAt($timestamp, 8);
    $hour = twoDigitAt($timestamp, 11);
    $minute = twoDigitAt($timestamp, 14);
    $second = twoDigitAt($timestamp, 17);

    $daysSinceUnixEpoch = daysFromCivil($year, $month, $day);

    return ($daysSinceUnixEpoch * 86400) + ($hour * 3600) + ($minute * 60) + $second;
}

function daysFromCivil(int $year, int $month, int $day): int
{
    $year -= $month <= 2 ? 1 : 0;
    $era = intdiv($year >= 0 ? $year : $year - 399, 400);
    $yearOfEra = $year - ($era * 400);
    $dayOfYear = intdiv((153 * ($month + ($month > 2 ? -3 : 9))) + 2, 5) + $day - 1;
    $dayOfEra = ($yearOfEra * 365) + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

    return ($era * 146097) + $dayOfEra - 719468;
}

function fourDigitAt(string $value, int $offset): int
{
    return ((ord($value[$offset]) - 48) * 1000)
        + ((ord($value[$offset + 1]) - 48) * 100)
        + ((ord($value[$offset + 2]) - 48) * 10)
        + (ord($value[$offset + 3]) - 48);
}

function twoDigitAt(string $value, int $offset): int
{
    return ((ord($value[$offset]) - 48) * 10) + (ord($value[$offset + 1]) - 48);
}

function histogramPercentile(array $histogram, int $maxBin, int $count, float $percentile): float
{
    if ($histogram === [] || $count <= 0) {
        return 0.0;
    }

    $target = max(1, (int) ceil($count * $percentile));
    $seen = 0;

    for ($bin = 0; $bin <= $maxBin; $bin++) {
        $seen += $histogram[$bin] ?? 0;
        if ($seen >= $target) {
            return ($bin + 1) * LATENCY_BIN_SIZE_MS;
        }
    }

    return ($maxBin + 1) * LATENCY_BIN_SIZE_MS;
}

function pointsFromBuckets(array $buckets): array
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
