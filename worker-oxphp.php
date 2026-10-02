<?php

declare(strict_types=1);

use App\Environment;
use OxPHP\Server\Worker;
use Psr\Log\LogLevel;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\PsrEmitter\SapiEmitter;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;
use Yiisoft\Yii\Runner\Http\RequestFactory;

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
        $requestFactory = $container->get(RequestFactory::class);
        $emitter = new SapiEmitter();
        $maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
        $requests = 0;

        $application->start();
        oxphp_worker(static function () use ($application, $container, $requestFactory, $emitter, $maxRequests, &$requests): void {
            $startTime = microtime(true);
            $request = $requestFactory->create()->withAttribute('applicationStartTime', $startTime);
            $response = null;
            try {
                try {
                    $response = $application->handle($request);
                } catch (Throwable $throwable) {
                    $response = $container->get(ErrorCatcher::class)->process($request, new ThrowableHandler($throwable));
                }
                $emitter->emit($response);
            } finally {
                $application->afterEmit($response);
                // Resolve after handling so lazily created services are included.
                $container->get(StateResetter::class)->reset();
                gc_collect_cycles();
                if ($maxRequests > 0 && ++$requests >= $maxRequests) {
                    Worker::current()->scheduleExit();
                }
            }
        });
        $application->shutdown();
    }
};
$runner->run();
