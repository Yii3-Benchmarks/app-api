<?php

declare(strict_types=1);

use App\Environment;
use App\WorkermanRequestFactory;
use Psr\Log\LogLevel;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Event;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;

require_once __DIR__ . '/src/bootstrap.php';

$runner = new class (
    rootPath: __DIR__,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    bootstrapGroup: 'bootstrap-web',
    eventsGroup: 'events-web',
    diGroup: 'di-web',
    diProvidersGroup: 'di-providers-web',
    diDelegatesGroup: 'di-delegates-web',
    diTagsGroup: 'di-tags-web',
    paramsGroup: 'params-web',
    nestedParamsGroups: ['params'],
    nestedEventsGroups: ['events'],
) extends ApplicationRunner {
    public function run(): void
    {
        Worker::$pidFile = '/tmp/yii3-workerman.pid';
        // Foreground Workerman logs already go to Docker stdout.
        Worker::$logFile = '/dev/null';
        Worker::$stopTimeout = 30;
        $server = new Worker('http://0.0.0.0:8080');
        $server->count = 20;
        $server->name = 'yii3';
        // The event loop processes each synchronous Yii request to completion.
        $server->eventLoop = Event::class;
        TcpConnection::$defaultMaxPackageSize = 8 * 1024 * 1024;
        $application = $container = null;
        // Bootstrap after fork so workers never share database connections.
        $server->onWorkerStart = function () use (&$application, &$container): void {
            $temporaryErrorHandler = new ErrorHandler(
                new Logger([(new StreamTarget())->setLevels([LogLevel::EMERGENCY, LogLevel::ERROR, LogLevel::WARNING])]),
                new PlainTextRenderer(),
            );
            $temporaryErrorHandler->register();
            $container = $this->getContainer();
            $errorHandler = $container->get(ErrorHandler::class);
            $temporaryErrorHandler->unregister();
            if ($this->debug) {
                $errorHandler->debug();
            }
            $errorHandler->register();
            $this->runBootstrap();
            $this->checkEvents();

            $application = $container->get(Application::class);
            $application->start();
        };
        $requests = 0;
        $server->onMessage = static function (TcpConnection $connection, Request $incoming) use (&$application, &$container, &$requests): void {
            $request = WorkermanRequestFactory::create($incoming, $connection->getRemoteIp(), $connection->getRemotePort())
                ->withAttribute('applicationStartTime', microtime(true));
            $response = null;
            try {
                try {
                    $response = $application->handle($request);
                } catch (Throwable $throwable) {
                    $response = $container->get(ErrorCatcher::class)->process($request, new ThrowableHandler($throwable));
                }
                $closeConnection = strtolower($incoming->header('connection', '')) === 'close'
                    || ($incoming->protocolVersion() === '1.0'
                        && strtolower($incoming->header('connection', '')) !== 'keep-alive');
                $headers = $response->getHeaders();
                if ($closeConnection) {
                    $headers['Connection'] = 'close';
                }
                $outgoing = new Response(
                    $response->getStatusCode(),
                    $headers,
                    $request->getMethod() === 'HEAD' ? '' : (string) $response->getBody(),
                );
                if ($closeConnection) {
                    $connection->close($outgoing);
                } else {
                    $connection->send($outgoing);
                }
            } finally {
                try {
                    $application->afterEmit($response);
                } finally {
                    $container->get(StateResetter::class)->reset();
                    gc_collect_cycles();
                    if (++$requests >= 10000) {
                        // Workerman closes connections after flushing pending responses.
                        Worker::stopAll();
                    }
                }
            }
        };
        $server->onWorkerStop = static function () use (&$application): void {
            $application?->shutdown();
        };
        Worker::runAll();
    }
};
$runner->run();
