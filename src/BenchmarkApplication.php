<?php

declare(strict_types=1);

namespace App;

use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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

/** Synchronous Yii lifecycle shared by the ReactPHP and Amp benchmark adapters. */
final class BenchmarkApplication extends ApplicationRunner
{
    private Application $application;

    public static function boot(string $rootPath): self
    {
        $runner = new self(
            rootPath: $rootPath,
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
        );
        $runner->run();
        return $runner;
    }

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

        $this->application = $container->get(Application::class);
        $this->application->start();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $container = $this->getContainer();
        $request = $request->withAttribute('applicationStartTime', microtime(true));
        $response = null;
        try {
            try {
                $response = $this->application->handle($request);
            } catch (\Throwable $throwable) {
                $response = $container->get(ErrorCatcher::class)->process($request, new ThrowableHandler($throwable));
            }
            // Detach the body before resetting Yii state; native servers emit it asynchronously.
            return new Response(
                $response->getStatusCode(),
                $response->getHeaders(),
                (new StreamFactory())->createStream((string) $response->getBody()),
                $response->getProtocolVersion(),
                $response->getReasonPhrase(),
            );
        } finally {
            try {
                $this->application->afterEmit($response);
            } finally {
                $container->get(StateResetter::class)->reset();
                gc_collect_cycles();
            }
        }
    }

    public function shutdown(): void
    {
        $this->application->shutdown();
    }
}
