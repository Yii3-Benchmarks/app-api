<?php

declare(strict_types=1);

use App\Environment;
use App\SwooleRequestFactory;
use Swoole\Http\Server;
use Psr\Log\LogLevel;
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
        $server = new Server('0.0.0.0', 8080);
        $server->set([
            'worker_num' => 20,
            'enable_coroutine' => false,
            'max_request' => 10000,
            'max_request_grace' => 0,
            'max_wait_time' => 30,
            'max_conn' => 4096,
            'http_compression' => false,
            'package_max_length' => 8 * 1024 * 1024,
            'heartbeat_check_interval' => 30,
            'heartbeat_idle_time' => 60,
            'log_file' => '/proc/self/fd/2',
        ]);
        $application = $container = null;
        // Bootstrap after fork so workers never share database connections.
        $server->on('WorkerStart', function () use (&$application, &$container): void {
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
        });
        $server->on('Request', static function ($incoming, $outgoing) use (&$application, &$container): void {
            $request = SwooleRequestFactory::create($incoming)
                ->withAttribute('applicationStartTime', microtime(true));
            $response = null;
            try {
                try {
                    $response = $application->handle($request);
                } catch (Throwable $throwable) {
                    $response = $container->get(ErrorCatcher::class)->process($request, new ThrowableHandler($throwable));
                }
                $outgoing->status($response->getStatusCode());
                foreach ($response->getHeaders() as $name => $values) {
                    $outgoing->header($name, $values);
                }
                $outgoing->end($request->getMethod() === 'HEAD' ? '' : (string) $response->getBody());
            } finally {
                try {
                    $application->afterEmit($response);
                } finally {
                    $container->get(StateResetter::class)->reset();
                    gc_collect_cycles();
                }
            }
        });
        $server->on('WorkerStop', static function () use (&$application): void {
            $application?->shutdown();
        });
        $server->start();
    }
};
$runner->run();
