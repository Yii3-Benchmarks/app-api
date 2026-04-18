<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php tools/sample-docker-stats.php <output.csv> <interval-seconds> <alias=container>...\n");
    exit(1);
}

$outputFile = $argv[1];
$interval = max(0.1, (float) $argv[2]);
$targets = array_slice($argv, 3);

$handle = fopen($outputFile, 'wb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open output file: $outputFile\n");
    exit(1);
}

fputcsv($handle, [
    'timestamp',
    'service',
    'container_id',
    'container_name',
    'cpu_percent',
    'memory_usage_bytes',
    'memory_limit_bytes',
    'memory_percent',
    'net_input_bytes',
    'net_output_bytes',
    'block_input_bytes',
    'block_output_bytes',
    'pids',
]);
fflush($handle);

$format = '{{.Container}}|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}';
$targetMap = [];
$containers = [];

foreach ($targets as $target) {
    [$service, $container] = explode('=', $target, 2);
    $targetMap[$container] = $service;
    $containers[] = $container;
}

while (true) {
    $timestamp = gmdate('c');
    $command = array_merge(
        ['docker', 'stats', '--no-stream', '--format', $format],
        $containers,
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to execute docker stats.\n");
        fclose($handle);
        exit(1);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, $stderr !== '' ? $stderr : "docker stats exited with code $exitCode.\n");
        break;
    }

    $lines = preg_split('/\R/', trim($stdout));
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        [$containerId, $containerName, $cpuPercent, $memUsage, $memPercent, $netIo, $blockIo, $pids] = explode('|', $line, 8);
        [$memoryUsageBytes, $memoryLimitBytes] = parsePair($memUsage);
        [$netInputBytes, $netOutputBytes] = parsePair($netIo);
        [$blockInputBytes, $blockOutputBytes] = parsePair($blockIo);

        fputcsv($handle, [
            $timestamp,
            $targetMap[$containerId] ?? $containerName,
            $containerId,
            $containerName,
            parsePercent($cpuPercent),
            $memoryUsageBytes,
            $memoryLimitBytes,
            parsePercent($memPercent),
            $netInputBytes,
            $netOutputBytes,
            $blockInputBytes,
            $blockOutputBytes,
            trim($pids),
        ]);
    }

    fflush($handle);
    usleep((int) round($interval * 1_000_000));
}

fclose($handle);

function parsePair(string $value): array
{
    $parts = preg_split('/\s*\/\s*/', trim($value));
    $left = $parts[0] ?? '0B';
    $right = $parts[1] ?? '0B';

    return [parseBytes($left), parseBytes($right)];
}

function parsePercent(string $value): float
{
    return (float) rtrim(trim($value), '%');
}

function parseBytes(string $value): int
{
    $normalized = trim($value);
    if ($normalized === '' || $normalized === '--') {
        return 0;
    }

    if (!preg_match('/^([0-9]*\.?[0-9]+)\s*([a-zA-Z]+)?$/', $normalized, $matches)) {
        return 0;
    }

    $number = (float) $matches[1];
    $unit = strtoupper($matches[2] ?? 'B');

    $multipliers = [
        'B' => 1,
        'KB' => 1000,
        'KIB' => 1024,
        'MB' => 1000 ** 2,
        'MIB' => 1024 ** 2,
        'GB' => 1000 ** 3,
        'GIB' => 1024 ** 3,
        'TB' => 1000 ** 4,
        'TIB' => 1024 ** 4,
        'PB' => 1000 ** 5,
        'PIB' => 1024 ** 5,
    ];

    $multiplier = $multipliers[$unit] ?? 1;

    return (int) round($number * $multiplier);
}
