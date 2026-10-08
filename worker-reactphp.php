<?php

declare(strict_types=1);

use App\BenchmarkApplication;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\ExtEventLoop;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

$loader = require __DIR__ . '/vendor/autoload.php';
// Stable React HTTP requires PSR-7 v1. The image contains the pinned release
// with only PSR-7 v2 return-type declarations added; see the checked-in patch.
$loader->addPsr4('React\\Http\\', '/opt/react-http/src');
require __DIR__ . '/src/bootstrap.php';

$loop = new ExtEventLoop();
Loop::set($loop);
$application = BenchmarkApplication::boot(__DIR__);
$socket = new SocketServer('0.0.0.0:8080', ['tcp' => ['so_reuseport' => true, 'backlog' => 1024, 'tcp_nodelay' => true]], $loop);
$connections = new SplObjectStorage();
$stopping = false;
$requests = 0;
$stop = static function () use (&$stopping, $socket, $connections, $loop): void {
    if ($stopping) {
        return;
    }
    $stopping = true;
    $socket->close();
    // Flush buffered responses, close idle keepalives, then let Supervisor replace this process.
    foreach (clone $connections as $connection) {
        $connection->end();
    }
    if (count($connections) === 0) {
        $loop->stop();
    } else {
        $loop->addTimer(30, static fn() => $loop->stop());
    }
};
$http = new HttpServer(
    $loop,
    new StreamingRequestMiddleware(),
    new LimitConcurrentRequestsMiddleware(1),
    new RequestBodyBufferMiddleware(8 * 1024 * 1024),
    new RequestBodyParserMiddleware(),
    static function (ServerRequestInterface $request) use ($application, &$requests, $loop, $stop) {
        $response = $application->handle($request);
        if (++$requests === 10000) {
            $loop->futureTick($stop);
        }
        return $response;
    },
);
$http->on('error', static function (Throwable $error): void {
    error_log((string) $error);
});
$http->listen($socket);
$socket->on('connection', static function (ConnectionInterface $connection) use ($connections, &$stopping, $loop): void {
    $connections->offsetSet($connection);
    $connection->on('close', static function () use ($connection, $connections, &$stopping, $loop): void {
        $connections->offsetUnset($connection);
        if ($stopping && count($connections) === 0) {
            $loop->stop();
        }
    });
});
$loop->addSignal(SIGTERM, $stop);
$loop->addSignal(SIGINT, $stop);
try {
    $loop->run();
} finally {
    $application->shutdown();
}
