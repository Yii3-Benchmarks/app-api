<?php

declare(strict_types=1);

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use App\AmpRequestFactory;
use App\BenchmarkApplication;
use Psr\Log\LogLevel;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\EventDriver;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

require __DIR__ . '/src/bootstrap.php';

EventLoop::setDriver(new EventDriver());
$application = BenchmarkApplication::boot(__DIR__);
$logger = new Logger([(new StreamTarget())->setLevels([LogLevel::EMERGENCY, LogLevel::ERROR, LogLevel::WARNING])]);
$server = SocketHttpServer::createForDirectAccess(
    $logger,
    enableCompression: false,
    connectionLimit: 4096,
    connectionLimitPerIp: 4096,
    concurrencyLimit: 1,
    httpDriverFactory: new DefaultHttpDriverFactory(
        $logger,
        streamTimeout: 30,
        connectionTimeout: 60,
        bodySizeLimit: 8 * 1024 * 1024,
        http2Enabled: false,
    ),
);
$server->expose('0.0.0.0:8080', (new BindContext())->withReusePort()->withBacklog(1024)->withTcpNoDelay());
$stopping = false;
$requests = 0;
$stop = static function () use (&$stopping, $server): void {
    if ($stopping) {
        return;
    }
    $stopping = true;
    $server->stop();
    EventLoop::getDriver()->stop();
};
$server->start(new ClosureRequestHandler(
    static function (Request $incoming) use ($application, &$requests, $stop): Response {
        $request = AmpRequestFactory::create($incoming, $incoming->getBody()->buffer(limit: 8 * 1024 * 1024));
        $response = $application->handle($request);
        $outgoing = new Response($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
        if (++$requests === 10000) {
            EventLoop::defer($stop);
        }
        return $outgoing;
    },
), new DefaultErrorHandler());
EventLoop::onSignal(SIGTERM, $stop);
EventLoop::onSignal(SIGINT, $stop);
try {
    EventLoop::run();
} finally {
    $application->shutdown();
}
